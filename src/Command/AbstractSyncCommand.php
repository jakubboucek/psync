<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Config\Config;
use JakubBoucek\Psync\Console\Reporter;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\State\StateCache;
use JakubBoucek\Psync\Sync\Comparator;
use JakubBoucek\Psync\Sync\FileEntry;
use JakubBoucek\Psync\Sync\IgnoreMatcher;
use JakubBoucek\Psync\Sync\PathRelativizer;
use JakubBoucek\Psync\Sync\Walker;
use JakubBoucek\Psync\Transport\HttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;

/**
 * Shared base for commands that work with the config and a relative path (scope).
 */
abstract class AbstractSyncCommand extends Command
{
    /** Local state cache – never synchronized. */
    private const string STATE_FILE = '.psync-state.json';

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Relative path (file/subdirectory) to limit the operation to.',
                '',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to the configuration file.',
                '.psync.php',
            )
            ->addOption(
                'checksum',
                null,
                InputOption::VALUE_NONE,
                'Always compute the hash (ignore mtime and cache), like rsync -c.',
            );
    }

    protected function loadConfig(InputInterface $input): Config
    {
        return Config::load((string) $input->getOption('config'));
    }

    /** Normalized relative scope path (without leading/trailing slashes), '' = the whole tree. */
    protected function scope(InputInterface $input): string
    {
        return trim((string) $input->getArgument('path'), '/');
    }

    protected function buildHttpClient(Config $config, ?Reporter $reporter = null): HttpClient
    {
        return new HttpClient(
            $config->url,
            new Signer($config->requirePrivateKey()),
            $config->scopeRelPath(),
            $reporter,
        );
    }

    /**
     * Builds the ignore matcher and additionally never synchronizes the state
     * cache, the config file itself (it holds the private key) – regardless of
     * how it was named or located via --config – nor the deployed agent file.
     *
     * The agent is the first line of defense for protecting itself: it must never
     * be uploaded over, nor (with --delete) marked for removal just because it is
     * missing locally. The agent enforces the same rule server-side as a backstop.
     */
    protected function buildIgnore(Config $config): IgnoreMatcher
    {
        $patterns = $config->ignore;
        $patterns[] = '/' . self::STATE_FILE;

        $configRel = $this->relativeToRoot($config->configPath, $config->localRoot);
        if ($configRel !== null) {
            $patterns[] = '/' . $configRel;
        }

        $agentRel = $this->agentRelativeToRoot($config);
        if ($agentRel !== null) {
            $patterns[] = '/' . $agentRel;
        }

        return new IgnoreMatcher($patterns);
    }

    /**
     * The deployed agent file as a path relative to the sync-root, or null when
     * the agent lives outside the synchronized tree (scope climbs above it, so it
     * is never walked anyway). Derived purely from the config's relative fields
     * (agent-dir + sync-root), exactly like the baked scope, so it cannot drift.
     */
    private function agentRelativeToRoot(Config $config): ?string
    {
        if ($config->agentFile === '') {
            return null;
        }
        $dirRel = PathRelativizer::relativize($config->syncRoot, $config->agentDir);
        if ($dirRel === '..' || str_starts_with($dirRel, '../')) {
            return null; // agent-dir is above/aside the sync-root → outside the tree
        }
        return $dirRel === '' ? $config->agentFile : $dirRel . '/' . $config->agentFile;
    }

    /**
     * If $path is inside $root, returns its path relative to $root; otherwise null
     * (a file outside the synced tree is never walked, so it needs no ignore).
     */
    private function relativeToRoot(?string $path, string $root): ?string
    {
        if ($path === null) {
            return null;
        }
        $prefix = rtrim($root, '/') . '/';
        return str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : null;
    }

    /**
     * Protect matcher – files that are never deleted (the second line of defense is in the agent).
     */
    protected function buildProtect(Config $config): IgnoreMatcher
    {
        return new IgnoreMatcher($config->protect);
    }

    /**
     * Prints a warning for each file-vs-directory type conflict. These are never
     * auto-resolved (it would be destructive) and are skipped from the transfer;
     * the user resolves them manually.
     *
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $conflict
     */
    protected function reportConflicts(OutputInterface $output, array $conflict): void
    {
        foreach ($conflict as $rel => $pair) {
            $output->writeln(sprintf(
                '<fg=magenta>! type conflict: %s</> <fg=gray>(local %s / remote %s – skipped, resolve manually)</>',
                $rel,
                $pair['local']->isDir() ? 'dir' : 'file',
                $pair['remote']->isDir() ? 'dir' : 'file',
            ));
        }
    }

    /**
     * Orders entries deepest-first (by path depth), so a file or nested directory
     * is always deleted before its containing directory - a prerequisite for the
     * non-recursive rmdir on either side.
     *
     * @param list<FileEntry> $entries
     * @return list<FileEntry>
     */
    protected function sortDeepestFirst(array $entries): array
    {
        usort(
            $entries,
            static fn(FileEntry $a, FileEntry $b): int => substr_count($b->path, '/') <=> substr_count($a->path, '/'),
        );
        return $entries;
    }

    /**
     * Whether deletions of extra entries on the target are allowed. The CLI flag
     * `--delete` always wins; otherwise the config's `allowDelete` decides. There
     * is no opposite flag – disabling is done only by editing the config.
     */
    protected function deleteEnabled(Config $config, InputInterface $input): bool
    {
        return (bool) $input->getOption('delete') || $config->allowDelete;
    }

    /**
     * Whether the run is a preview only (transfers/deletes nothing). A command-line
     * flag always overrides the config: `--dry-run` forces a preview, `--run` forces
     * execution. Without either, the config's `testMode` decides (default: execute,
     * so the legacy behavior is unchanged). Passing both flags is a usage error.
     */
    protected function dryRunEnabled(Config $config, InputInterface $input): bool
    {
        $dryFlag = (bool) $input->getOption('dry-run');
        $run = (bool) $input->getOption('run');
        if ($dryFlag && $run) {
            throw new RuntimeException('The --dry-run and --run options are mutually exclusive.');
        }
        return $dryFlag || ($config->testMode && !$run);
    }

    protected function buildComparator(Config $config, InputInterface $input, HttpClient $http, ?Reporter $reporter = null): Comparator
    {
        $ignore = $this->buildIgnore($config);
        $walker = new Walker($config->localRoot, $ignore);
        $cache = new StateCache($config->localRoot . '/' . self::STATE_FILE);
        $checksum = $config->checksum || (bool) $input->getOption('checksum');

        return new Comparator($http, $walker, $ignore, $cache, $config->localRoot, $checksum, $reporter);
    }
}
