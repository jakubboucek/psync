<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Sync\Downloader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `download` – downloads from the server the files that are missing locally or differ (remote → local).
 * With --delete it additionally deletes files that are extra locally (outside the protect-list).
 */
final class DownloadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('download')
            ->setDescription('Downloads the differing files from the server (remote → local).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be transferred/deleted.')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete local files that are extra compared to the server.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);
        $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));

        // remote → local: missing locally + differing in content
        $files = $comparison->remoteOnly;
        foreach ($comparison->modified as $rel => $pair) {
            $files[$rel] = $pair['remote'];
        }

        $delete = (bool) $input->getOption('delete');
        $protect = $this->buildProtect($config);
        $toDelete = [];
        $protectedCount = 0;
        if ($delete) {
            foreach ($comparison->localOnly as $rel => $e) {
                if ($protect->matches($rel)) {
                    $protectedCount++;
                } else {
                    $toDelete[] = $rel;
                }
            }
        }

        if ($files === [] && $toDelete === []) {
            $io->success('Nothing to download – the local copy is in sync.');
            if ($protectedCount > 0) {
                $io->note("$protectedCount local files are extra but protected by the protect-list.");
            }
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            foreach ($files as $rel => $e) {
                $output->writeln("<fg=green>↓ $rel</>");
            }
            foreach ($toDelete as $rel) {
                $output->writeln("<fg=red>␡ $rel</>");
            }
            $io->note(sprintf('dry run: %d to download, %d to delete locally.', count($files), count($toDelete)));
            return Command::SUCCESS;
        }

        $downloader = new Downloader($http, $config->localRoot, $config->compress, $config->compressSkipExt);
        $ok = 0;
        $fail = 0;
        $downloader->download($files, static function (string $rel, bool $success, ?string $err) use ($output, &$ok, &$fail): void {
            if ($success) {
                $ok++;
                $output->writeln("<fg=green>↓ $rel</>");
            } else {
                $fail++;
                $output->writeln("<fg=red>✗ $rel</> <fg=gray>($err)</>");
            }
        });

        $deleted = 0;
        foreach ($toDelete as $rel) {
            $abs = $config->localRoot . '/' . $rel;
            if (!is_file($abs) || @unlink($abs)) {
                $deleted++;
                $output->writeln("<fg=red>␡ $rel</>");
            } else {
                $fail++;
                $output->writeln("<fg=red>✗ delete $rel</>");
            }
        }

        $io->newLine();
        $io->writeln(sprintf('<info>Downloaded %d, deleted %d, errors %d.</info>', $ok, $deleted, $fail));
        if ($protectedCount > 0) {
            $io->note("$protectedCount extra files kept (protect-list).");
        }
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
