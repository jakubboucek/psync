<?php

declare(strict_types=1);

namespace PhpSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `install` – vygeneruje pár klíčů, zazdí veřejný do agenta a vypíše privátní.
 * Plná implementace ve fázi 4.
 */
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Vygeneruje serverového agenta a pár klíčů.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>install: zatím neimplementováno (fáze 4).</comment>');
        return Command::SUCCESS;
    }
}
