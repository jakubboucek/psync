<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Společný základ příkazů pracujících s configem a relativní cestou (scope).
 */
abstract class AbstractSyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Relativní cesta (soubor/podadresář), na kterou se operace omezí.',
                '',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Cesta ke konfiguračnímu souboru.',
                'php-sync.php',
            );
    }

    protected function loadConfig(InputInterface $input): Config
    {
        return Config::load((string) $input->getOption('config'));
    }

    /** Normalizovaná relativní cesta scope (bez vodicích/koncových lomítek), '' = celý strom. */
    protected function scope(InputInterface $input): string
    {
        return trim((string) $input->getArgument('path'), '/');
    }
}
