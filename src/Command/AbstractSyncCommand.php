<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Config\Config;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\State\StateCache;
use JakubBoucek\Psync\Sync\Comparator;
use JakubBoucek\Psync\Sync\IgnoreMatcher;
use JakubBoucek\Psync\Sync\Walker;
use JakubBoucek\Psync\Transport\HttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared base for commands that work with the config and a relative path (scope).
 */
abstract class AbstractSyncCommand extends Command
{
    /** Local state cache – never synchronized. */
    private const STATE_FILE = '.psync-state.json';

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Relative path (file/subdirectory) to limit the operation to.',
                '',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to the configuration file.',
                'psync.php',
            )
            ->addOption(
                'checksum',
                null,
                InputOption::VALUE_NONE,
                'Always compute the hash (ignore mtime and cache), like rsync -c.',
            );
    }

    protected function loadConfig(InputInterface $input): Config
    {
        return Config::load((string) $input->getOption('config'));
    }

    /** Normalized relative scope path (without leading/trailing slashes), '' = the whole tree. */
    protected function scope(InputInterface $input): string
    {
        return trim((string) $input->getArgument('path'), '/');
    }

    protected function buildHttpClient(Config $config): HttpClient
    {
        return new HttpClient($config->url, new Signer($config->requirePrivateKey()));
    }

    /**
     * Builds the ignore matcher and additionally never synchronizes the state cache.
     */
    protected function buildIgnore(Config $config): IgnoreMatcher
    {
        $patterns = $config->ignore;
        $patterns[] = '/' . self::STATE_FILE;
        return new IgnoreMatcher($patterns);
    }

    /**
     * Protect matcher – files that are never deleted (the second line of defense is in the agent).
     */
    protected function buildProtect(Config $config): IgnoreMatcher
    {
        return new IgnoreMatcher($config->protect);
    }

    protected function buildComparator(Config $config, InputInterface $input, HttpClient $http): Comparator
    {
        $ignore = $this->buildIgnore($config);
        $walker = new Walker($config->localRoot, $ignore);
        $cache = new StateCache($config->localRoot . '/' . self::STATE_FILE);
        $checksum = $config->checksum || (bool) $input->getOption('checksum');

        return new Comparator($http, $walker, $ignore, $cache, $config->localRoot, $checksum);
    }
}
