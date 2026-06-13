<?php

declare(strict_types=1);

namespace PhpSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `compare` – 2-fázové porovnání local↔remote. Plná implementace ve fázi 5.
 */
final class CompareCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('compare')
            ->setDescription('Porovná lokální a vzdálené soubory a vypíše rozdíly.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>compare: zatím neimplementováno (fáze 5).</comment>');
        return Command::SUCCESS;
    }
}
