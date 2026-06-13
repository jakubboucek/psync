<?php

declare(strict_types=1);

namespace PhpSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `upload` – local → remote. Plná implementace ve fázi 6.
 */
final class UploadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('upload')
            ->setDescription('Nahraje rozdílné soubory na server (s --delete maže přebývající).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>upload: zatím neimplementováno (fáze 6).</comment>');
        return Command::SUCCESS;
    }
}
