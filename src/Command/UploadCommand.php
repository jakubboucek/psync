<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Wire;
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
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('upload')
            ->setDescription('Uploads the differing files to the server (local → remote).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be transferred/deleted.')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete files on the server that are extra compared to local.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);
        $caps = $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));

        // local → remote: missing on the server + differing in content
        $files = $comparison->localOnly;
        foreach ($comparison->modified as $rel => $pair) {
            $files[$rel] = $pair['local'];
        }

        $delete = (bool) $input->getOption('delete');
        $protect = $this->buildProtect($config);
        $toDelete = [];
        $protectedCount = 0;
        if ($delete) {
            foreach ($comparison->remoteOnly as $rel => $e) {
                if ($protect->matches($rel)) {
                    $protectedCount++;
                } else {
                    $toDelete[] = $rel;
                }
            }
        }

        if ($files === [] && $toDelete === []) {
            $io->success('Nothing to upload – the server is in sync.');
            if ($protectedCount > 0) {
                $io->note("$protectedCount files are extra but protected by the protect-list.");
            }
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            foreach ($files as $rel => $e) {
                $output->writeln("<fg=green>↑ $rel</>");
            }
            foreach ($toDelete as $rel) {
                $output->writeln("<fg=red>␡ $rel</>");
            }
            $io->note(sprintf('dry run: %d to upload, %d to delete.', count($files), count($toDelete)));
            return Command::SUCCESS;
        }

        $uploader = new Uploader($http, $config->localRoot, $caps, $config->compress, $config->compressSkipExt);
        $ok = 0;
        $fail = 0;
        $uploader->upload($files, static function (string $rel, bool $success, ?string $err) use ($output, &$ok, &$fail): void {
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
            $paths = array_map(static fn(string $r): string => Wire::encPath($r), $toDelete);
            foreach ($http->postJson(Protocol::ACTION_DELETE, ['paths' => $paths]) as $r) {
                if (!isset($r['p'])) {
                    continue;
                }
                $rel = Wire::decPath((string) $r['p']);
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
        $io->writeln(sprintf('<info>Uploaded %d, deleted %d, errors %d.</info>', $ok, $deleted, $fail));
        if ($protectedCount > 0) {
            $io->note("$protectedCount extra files kept (protect-list).");
        }
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
