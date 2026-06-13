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
 * S --delete navíc smaže soubory přebývající lokálně (mimo protect-list).
 */
final class DownloadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('download')
            ->setDescription('Stáhne rozdílné soubory ze serveru (remote → local).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš, co by se přeneslo/smazalo.')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Smaž lokálně soubory přebývající oproti serveru.');
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
            $io->success('Není co stahovat – lokální kopie je v synchronizaci.');
            if ($protectedCount > 0) {
                $io->note("$protectedCount lokálních souborů přebývá, ale je chráněno protect-listem.");
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
            $io->note(sprintf('dry-run: %d stáhnout, %d smazat lokálně.', count($files), count($toDelete)));
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
                $output->writeln("<fg=red>✗ smazání $rel</>");
            }
        }

        $io->newLine();
        $io->writeln(sprintf('<info>Staženo %d, smazáno %d, chyb %d.</info>', $ok, $deleted, $fail));
        if ($protectedCount > 0) {
            $io->note("$protectedCount přebývajících souborů ponecháno (protect-list).");
        }
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
