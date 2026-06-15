<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Config\Config;
use JakubBoucek\Psync\Install\AgentBuilder;
use JakubBoucek\Psync\Protocol\Signer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `self-update` – re-renders the server agent from the current template, reusing
 * the **existing** key and agent filename taken from the config. Use it after a
 * `Protocol::VERSION` bump (or a template change): unlike `install`, it generates
 * no new key and no new URL, so the config needs no changes – you only re-upload
 * the regenerated agent over the old one via FTP.
 */
final class SelfUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Regenerates the server agent from the current template, reusing the existing key and agent filename.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', '.psync.php')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the rendered agent (default: derived from the config url).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configPath = (string) $input->getOption('config');
        $outputOverride = $input->getOption('output');

        return $this->generate($io, $configPath, $outputOverride !== null ? (string) $outputOverride : null);
    }

    /**
     * Renders the agent from the existing config (key + agent filename) and writes it.
     * Shared with `install` so its "did you mean self-update?" prompt can delegate here.
     */
    public function generate(SymfonyStyle $io, string $configPath, ?string $outputOverride): int
    {
        if (!is_file($configPath)) {
            $io->error("Configuration file does not exist: $configPath. Run `install` first.");
            return Command::FAILURE;
        }

        $config = Config::load($configPath);
        $publicKey = Signer::publicKeyFromPrivate($config->requirePrivateKey());

        $agentPath = $outputOverride ?? self::agentFilenameFromUrl($config->url);
        if ($agentPath === null) {
            $io->error("Unable to derive the agent filename from the config url '{$config->url}'. Pass it explicitly with -o.");
            return Command::FAILURE;
        }

        $agent = new AgentBuilder()->build($publicKey, '', $config->protect);

        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Unable to write the agent to '$agentPath'.");
            return Command::FAILURE;
        }

        $io->success("Agent regenerated: $agentPath");
        $io->writeln('Re-upload it to the server via FTP, overwriting the old agent file.');
        $io->writeln('Same URL, same key – the config needs <comment>no</comment> changes.');

        return Command::SUCCESS;
    }

    /** Extracts the agent's basename from the config url (e.g. .../psync-agent-ab12cd.php). */
    private static function agentFilenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $base = basename($path);
        return $base !== '' ? $base : null;
    }
}
