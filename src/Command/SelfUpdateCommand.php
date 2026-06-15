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
 * everything from the existing config: the key (the public key is derived from
 * the private one), the agent filename, the scope (agent-dir → sync-root) and the
 * protect-list. Use it after a `Protocol::VERSION` bump or a template change: it
 * generates no new key and changes no config, so you only re-upload the agent.
 */
final class SelfUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Regenerates the server agent from the current template, reusing the existing key, filename and scope.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', '.psync.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        return $this->generate($io, (string) $input->getOption('config'));
    }

    /**
     * Re-renders the agent from the existing config and writes it under the same
     * filename. Shared with `install` so its "did you mean self-update?" prompt
     * can delegate here.
     */
    public function generate(SymfonyStyle $io, string $configPath): int
    {
        if (!is_file($configPath)) {
            $io->error("Configuration file does not exist: $configPath. Run `install` first.");
            return Command::FAILURE;
        }

        // Config::load rejects the pre-v1.1 format outright (pointing at `install --force`);
        // here we additionally require the filename that self-update writes.
        $config = Config::load($configPath);
        if ($config->agentFile === '') {
            $io->error("The config has no 'agentFile' – it cannot be self-updated. Re-create it with `psync install --force`.");
            return Command::FAILURE;
        }

        $publicKey = Signer::publicKeyFromPrivate($config->requirePrivateKey());
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

        $io->success("Agent regenerated: $agentPath");
        $io->writeln(sprintf(
            'Re-upload it via FTP into <comment>%s</comment>, overwriting the old agent file.',
            $config->agentDir === '' ? 'the sync-root' : $config->agentDir,
        ));
        $io->writeln('Same URL, same key, same scope – the config needs <comment>no</comment> changes.');

        return Command::SUCCESS;
    }
}
