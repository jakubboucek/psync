<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Console\Reporter;
use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Wire;
use JakubBoucek\Psync\Sync\FileEntry;
use JakubBoucek\Psync\Sync\TransferItem;
use JakubBoucek\Psync\Sync\Uploader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `upload` – uploads to the server the files that are missing there or differ (local → remote).
 * With --delete it additionally deletes files that are extra on the server (outside the protect-list).
 */
final class UploadCommand extends AbstractSyncCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('upload')
            ->setDescription('Uploads the differing files to the server (local → remote).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be transferred/deleted.')
            ->addOption('run', null, InputOption::VALUE_NONE, 'Force execution even when the config sets testMode (opposite of --dry-run).')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete files on the server that are extra compared to local.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $reporter = new Reporter($output);
        $http = $this->buildHttpClient($config, $reporter);
        $caps = $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http, $reporter)->compare($this->scope($input));

        // local → remote: missing on the server (source = target = local name) +
        // differing in content (written under the existing remote name to avoid
        // normalization duplicates).
        $items = [];
        $mkdirs = []; // remote directories to create (incl. empty ones)
        foreach ($comparison->localOnly as $e) {
            if ($e->isDir()) {
                $mkdirs[] = $e->path;
            } else {
                $items[] = new TransferItem($e->path, $e->path, $e->size);
            }
        }
        foreach ($comparison->modified as $pair) {
            $items[] = new TransferItem($pair['local']->path, $pair['remote']->path, $pair['local']->size);
        }

        $delete = $this->deleteEnabled($config, $input);
        $protect = $this->buildProtect($config);
        /** @var list<FileEntry> $toDelete remote entries to delete (files + dirs) */
        $toDelete = [];
        $protectedCount = 0;
        if ($delete) {
            foreach ($comparison->remoteOnly as $rel => $e) {
                if ($protect->matches($rel)) {
                    $protectedCount++;
                } else {
                    $toDelete[] = $e;
                }
            }
        }

        $this->reportConflicts($output, $comparison->conflict);

        if ($items === [] && $mkdirs === [] && $toDelete === []) {
            $io->success('Nothing to upload – the server is in sync.');
            if ($protectedCount > 0) {
                $io->note("$protectedCount files are extra but protected by the protect-list.");
            }
            return $comparison->conflict === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($this->dryRunEnabled($config, $input)) {
            foreach ($mkdirs as $rel) {
                $output->writeln("<fg=green>↑ $rel/</>");
            }
            foreach ($items as $item) {
                $output->writeln("<fg=green>↑ {$item->targetPath}</>");
            }
            foreach ($toDelete as $e) {
                $output->writeln('<fg=red>␡ ' . $e->path . ($e->isDir() ? '/' : '') . '</>');
            }
            $io->note(sprintf(
                'dry run: %d dirs to create, %d to upload, %d to delete.',
                count($mkdirs),
                count($items),
                count($toDelete),
            ));
            return Command::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        $created = 0;

        // Create directories first so empty ones exist regardless of file outcomes
        // (file uploads also mkdir -p their parents, but empty dirs need this).
        if ($mkdirs !== []) {
            $paths = array_map(Wire::encPath(...), $mkdirs);
            foreach ($http->postJson(Protocol::ACTION_MKDIR, ['paths' => $paths]) as $r) {
                if (!isset($r['p'])) {
                    continue;
                }
                $rel = Wire::decPath((string) $r['p']);
                if (($r['ok'] ?? false) === true) {
                    $created++;
                    $output->writeln("<fg=green>↑ $rel/</>");
                } else {
                    $fail++;
                    $output->writeln("<fg=red>✗ mkdir $rel/</> <fg=gray>(" . (string) ($r['err'] ?? '?') . ")</>");
                }
            }
        }

        $uploader = new Uploader($http, $config->localRoot, $caps, $config->compress, $config->compressSkipExt);
        $uploader->upload($items, static function (string $rel, bool $success, ?string $err) use ($output, &$ok, &$fail): void {
            if ($success) {
                $ok++;
                $output->writeln("<fg=green>↑ $rel</>");
            } else {
                $fail++;
                $output->writeln("<fg=red>✗ $rel</> <fg=gray>($err)</>");
            }
        });

        $deleted = 0;
        if ($toDelete !== []) {
            // Deepest-first so a directory's contents are removed before the
            // directory itself; each entry carries its type so the agent rmdir/
            // unlinks strictly (a directory it carries t='d').
            $isDir = []; // encoded path => directory? (to render the trailing slash on the result)
            $paths = [];
            foreach ($this->sortDeepestFirst($toDelete) as $e) {
                $enc = Wire::encPath($e->path);
                $isDir[$enc] = $e->isDir();
                $paths[] = $e->isDir() ? ['p' => $enc, 't' => 'd'] : ['p' => $enc];
            }
            foreach ($http->postJson(Protocol::ACTION_DELETE, ['paths' => $paths]) as $r) {
                if (!isset($r['p'])) {
                    continue;
                }
                $enc = (string) $r['p'];
                $rel = Wire::decPath($enc) . (($isDir[$enc] ?? false) ? '/' : '');
                if (($r['ok'] ?? false) === true) {
                    $deleted++;
                    $output->writeln("<fg=red>␡ $rel</>");
                } else {
                    $fail++;
                    $output->writeln("<fg=red>✗ delete $rel</> <fg=gray>(" . (string) ($r['err'] ?? '?') . ")</>");
                }
            }
        }

        $io->newLine();
        $io->writeln(sprintf(
            '<info>Created %d dirs, uploaded %d, deleted %d, errors %d.</info>',
            $created,
            $ok,
            $deleted,
            $fail,
        ));
        if ($protectedCount > 0) {
            $io->note("$protectedCount extra files kept (protect-list).");
        }
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
