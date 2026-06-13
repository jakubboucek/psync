<?php

declare(strict_types=1);

namespace PhpSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `download` – remote → local. Plná implementace ve fázi 6.
 */
final class DownloadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('download')
            ->setDescription('Stáhne rozdílné soubory ze serveru (s --delete maže přebývající lokálně).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>download: zatím neimplementováno (fáze 6).</comment>');
        return Command::SUCCESS;
    }
}
