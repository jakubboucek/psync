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
use JakubBoucek\Psync\Sync\Walker;
use JakubBoucek\Psync\Transport\HttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
        return new HttpClient($config->url, new Signer($config->requirePrivateKey()), $reporter);
    }

    /**
     * Builds the ignore matcher and additionally never synchronizes the state
     * cache, nor the config file itself (it holds the private key) – regardless
     * of how it was named or located via --config.
     */
    protected function buildIgnore(Config $config): IgnoreMatcher
    {
        $patterns = $config->ignore;
        $patterns[] = '/' . self::STATE_FILE;

        $configRel = $this->relativeToRoot($config->configPath, $config->localRoot);
        if ($configRel !== null) {
            $patterns[] = '/' . $configRel;
        }

        return new IgnoreMatcher($patterns);
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

    protected function buildComparator(Config $config, InputInterface $input, HttpClient $http, ?Reporter $reporter = null): Comparator
    {
        $ignore = $this->buildIgnore($config);
        $walker = new Walker($config->localRoot, $ignore);
        $cache = new StateCache($config->localRoot . '/' . self::STATE_FILE);
        $checksum = $config->checksum || (bool) $input->getOption('checksum');

        return new Comparator($http, $walker, $ignore, $cache, $config->localRoot, $checksum, $reporter);
    }
}
