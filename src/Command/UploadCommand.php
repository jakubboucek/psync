<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Protocol\Protocol;
use PhpSync\Sync\Uploader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `upload` – nahraje na server soubory, které tam chybí nebo se liší (local → remote).
 * Mazání přebývajících na serveru přibude ve fázi 7 (--delete).
 */
final class UploadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('upload')
            ->setDescription('Nahraje rozdílné soubory na server (local → remote).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš, co by se přeneslo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);
        $caps = $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));

        // local → remote: chybějící na serveru + obsahově odlišné
        $files = $comparison->localOnly;
        foreach ($comparison->modified as $rel => $pair) {
            $files[$rel] = $pair['local'];
        }

        if ($files === []) {
            $io->success('Není co nahrávat – server je v synchronizaci.');
            return Command::SUCCESS;
        }
        if ((bool) $input->getOption('dry-run')) {
            foreach ($files as $rel => $e) {
                $output->writeln("<fg=green>↑ $rel</>");
            }
            $io->note(sprintf('dry-run: %d souborů by se nahrálo.', count($files)));
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

        $io->newLine();
        $io->writeln(sprintf('<info>Nahráno %d, chyb %d.</info>', $ok, $fail));
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
