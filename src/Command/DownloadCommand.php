<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Sync\Downloader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `download` – stáhne ze serveru soubory, které lokálně chybí nebo se liší (remote → local).
 * Mazání přebývajících lokálně přibude ve fázi 7 (--delete).
 */
final class DownloadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('download')
            ->setDescription('Stáhne rozdílné soubory ze serveru (remote → local).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš, co by se přeneslo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);
        $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));

        // remote → local: chybějící lokálně + obsahově odlišné
        $files = $comparison->remoteOnly;
        foreach ($comparison->modified as $rel => $pair) {
            $files[$rel] = $pair['remote'];
        }

        if ($files === []) {
            $io->success('Není co stahovat – lokální kopie je v synchronizaci.');
            return Command::SUCCESS;
        }
        if ((bool) $input->getOption('dry-run')) {
            foreach ($files as $rel => $e) {
                $output->writeln("<fg=green>↓ $rel</>");
            }
            $io->note(sprintf('dry-run: %d souborů by se stáhlo.', count($files)));
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

        $io->newLine();
        $io->writeln(sprintf('<info>Staženo %d, chyb %d.</info>', $ok, $fail));
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
