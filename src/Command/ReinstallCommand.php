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
 * `re-install` (alias `reinstall`) – re-renders the server agent from the current
 * template, reusing the agent filename and the scope (agent-dir → sync-root) from
 * the existing config. Think `apt reinstall`: it rebuilds the deployed artifact but
 * keeps the configuration. Use it after a `Protocol::VERSION` bump, a template
 * change, or to rotate the key – then re-upload the agent.
 *
 * By default it **rotates the key**: a fresh pair is generated, the new public key
 * is baked into the agent and the new private key replaces the old one in the
 * config. Pass `--preserve-key` to keep the existing key instead (the public key is
 * then derived from the stored private one and the config is left untouched).
 */
final class ReinstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('re-install')
            ->setAliases(['reinstall'])
            ->setDescription('Regenerates the server agent from the current template (rotating the key by default), reusing the filename and scope.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', '.psync.php')
            ->addOption('preserve-key', null, InputOption::VALUE_NONE, 'Keep the existing key instead of rotating it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        return $this->generate($io, (string) $input->getOption('config'), !(bool) $input->getOption('preserve-key'));
    }

    /**
     * Re-renders the agent from the existing config and writes it under the same
     * filename. With $rotateKey a fresh pair is generated and the new private key
     * is written back into the config; otherwise the existing key is reused.
     * Shared with `install` so its "did you mean re-install?" prompt can delegate here.
     */
    public function generate(SymfonyStyle $io, string $configPath, bool $rotateKey = true): int
    {
        if (!is_file($configPath)) {
            $io->error("Configuration file does not exist: $configPath. Run `install` first.");
            return Command::FAILURE;
        }

        // Config::load rejects the pre-v1.1 format outright (pointing at `install --force`);
        // here we additionally require the filename that re-install writes.
        $config = Config::load($configPath);
        if ($config->agentFile === '') {
            $io->error("The config has no 'agentFile' – it cannot be re-installed. Re-create it with `psync install --force`.");
            return Command::FAILURE;
        }

        if ($rotateKey) {
            // Rotation must place the new private key into the config, so an existing one
            // has to be there to replace (otherwise it is a partial/broken config).
            $oldPrivateKey = $config->privateKey;
            if ($oldPrivateKey === null) {
                $io->error("The config has no 'privateKey' to rotate. Re-create it with `psync install --force` or pass --preserve-key.");
                return Command::FAILURE;
            }
            $pair = Signer::generateKeyPair();
            $publicKey = $pair['public'];
        } else {
            $publicKey = Signer::publicKeyFromPrivate($config->requirePrivateKey());
        }

        $scopeRelPath = $config->scopeRelPath();
        $agent = new AgentBuilder()->build($publicKey, $scopeRelPath, $config->protect);

        $projectRoot = dirname((string) $config->configPath);
        $agentLocalDir = ($config->agentDir !== '' && is_dir($projectRoot . '/' . $config->agentDir))
            ? $projectRoot . '/' . $config->agentDir
            : $projectRoot;
        $agentPath = $agentLocalDir . '/' . $config->agentFile;

        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Unable to write the agent to '$agentPath'.");
            return Command::FAILURE;
        }

        // On rotation, write the new private key into the config by surgically replacing
        // the old base64 value in the raw file, preserving comments and every other key.
        if ($rotateKey) {
            if (!$this->rotateConfigKey((string) $config->configPath, $oldPrivateKey, $pair['private'])) {
                $io->error("Could not locate the private key in '$config->configPath' to rotate it. The agent was written but the config was NOT updated.");
                return Command::FAILURE;
            }
        }

        $io->success("Agent regenerated: $agentPath");
        $io->writeln(sprintf(
            'Re-upload it via FTP into <comment>%s</comment>, overwriting the old agent file.',
            $config->agentDir === '' ? 'the sync-root' : $config->agentDir,
        ));

        if ($rotateKey) {
            $io->warning(
                'The key was rotated. The server will reject every request (HTTP 403) until you upload '
                . 'this new agent. Keep the new private key in the config secret.',
            );
        } else {
            $io->writeln('Same URL, same key, same scope – the config needs <comment>no</comment> changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Replaces the old private key with the new one directly in the raw config file,
     * leaving everything else (URL, scope, ignore/protect, comments) intact.
     */
    private function rotateConfigKey(string $configPath, string $oldPrivateKey, string $newPrivateKey): bool
    {
        $raw = @file_get_contents($configPath);
        if ($raw === false || !str_contains($raw, $oldPrivateKey)) {
            return false;
        }
        $raw = str_replace($oldPrivateKey, $newPrivateKey, $raw);
        return file_put_contents($configPath, $raw) !== false;
    }
}
