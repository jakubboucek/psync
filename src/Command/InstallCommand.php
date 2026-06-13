<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Install\AgentBuilder;
use JakubBoucek\Psync\Protocol\Signer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `install` – generates a key pair (Ed25519), renders the agent with the public
 * key (to upload via FTP) and prints the private key for the client config.
 */
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Generates the server agent and a key pair.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the rendered agent (default: a randomized psync-agent-<nonce>.php).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', 'psync.php')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files without asking.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Randomized filename so the agent URL cannot be guessed/scanned for –
        // a safety net should a vulnerability ever be found in the agent.
        $agentFile = 'psync-agent-' . bin2hex(random_bytes(3)) . '.php';
        $explicitOutput = $input->getOption('output');
        $agentPath = $explicitOutput !== null ? (string) $explicitOutput : $agentFile;
        $configPath = (string) $input->getOption('config');
        $force = (bool) $input->getOption('force');

        if (is_file($agentPath) && !$force) {
            $io->error("File '$agentPath' already exists. Use --force to overwrite.");
            return Command::FAILURE;
        }

        // Take the protect-list from the existing config (if any) so it gets baked into the agent.
        $protect = $this->readProtect($configPath);

        $pair = Signer::generateKeyPair();
        $agent = (new AgentBuilder())->build($pair['public'], $protect);

        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Unable to write the agent to '$agentPath'.");
            return Command::FAILURE;
        }

        $io->success("Agent generated: $agentPath");
        $io->writeln('Upload it to the server via FTP (into the directory that should be the remote root).');
        $io->writeln("Then point the config <comment>url</comment> at it, e.g. <comment>https://example.com/" . basename($agentPath) . "</comment>");

        // Config: if it is missing, offer to generate a template with the private key.
        if (!is_file($configPath)) {
            file_put_contents($configPath, $this->configTemplate($pair['private'], basename($agentPath)));
            $io->success("Configuration file generated with the private key: $configPath");
            $io->writeln('Fill in <comment>url</comment> and <comment>mapping.local</comment> in it.');
        } else {
            $io->section('Private key – add it to the config as "privateKey"');
            $io->writeln("<info>{$pair['private']}</info>");
            $io->warning('Keep the private key secret and store it outside of git.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function readProtect(string $configPath): array
    {
        if (!is_file($configPath)) {
            return [];
        }
        $raw = @include $configPath;
        if (!is_array($raw) || !isset($raw['protect']) || !is_array($raw['protect'])) {
            return [];
        }
        return array_values(array_map('strval', $raw['protect']));
    }

    private function configTemplate(string $privateKey, string $agentFile): string
    {
        $key = var_export($privateKey, true);
        return <<<PHP
        <?php

        // psync configuration. Keep the private key secret (outside of public git).
        return [
            'url'        => 'https://example.com/$agentFile',
            'privateKey' => $key,
            'mapping'    => [
                'local'  => __DIR__,
                'remote' => '/',
            ],
            'ignore'     => ['/.git', '/vendor', '*.log', '/temp', '/uploads'],
            'protect'    => ['/uploads', '/temp'],
            'checksum'   => false,
            'compress'   => true,
        ];

        PHP;
    }
}
