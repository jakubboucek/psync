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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the rendered agent (default: a randomized psync-agent-<nonce>.php).', 'psync-agent-<nonce>.php')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', '.psync.php')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files without asking.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = (string) $input->getOption('config');
        $force = (bool) $input->getOption('force');

        // An existing config almost always means the user wants `self-update` (keep the key
        // and URL, just re-render the agent after a protocol bump), not a brand-new install
        // that overwrites the config. Ask – unless --force forces a fresh install outright.
        if (is_file($configPath) && !$force) {
            if ($io->confirm("An existing config '$configPath' was found. `install` creates a brand-new key and a new agent, and overwrites the config. Did you mean `self-update` instead (regenerate the agent, keep the existing key and URL)?", true)) {
                return new SelfUpdateCommand()->generate($io, $configPath, null);
            }
            $io->warning("Proceeding with a fresh install – '$configPath' will be overwritten with a new key.");
        }

        $agentPath = (string) $input->getOption('output');
        // If the value contanins <nonce>, replace it with a random nonce to avoid overwriting existing agents.
        $agentPath = str_replace('<nonce>', bin2hex(random_bytes(3)), $agentPath);

        if (is_file($agentPath) && !$force) {
            $io->error("File '$agentPath' already exists. Use --force to overwrite.");
            return Command::FAILURE;
        }

        // Take the protect-list from the existing config (if any) so it gets baked into the agent.
        $protect = $this->readProtect($configPath);

        $pair = Signer::generateKeyPair();
        $agent = new AgentBuilder()->build($pair['public'], '', $protect);

        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Unable to write the agent to '$agentPath'.");
            return Command::FAILURE;
        }

        $io->success("Agent generated: $agentPath");
        $io->writeln('Upload it to the server via FTP (into the directory that should be the remote root).');
        $io->writeln("Then point the config <comment>url</comment> at it, e.g. <comment>https://example.com/" . basename($agentPath) . "</comment>");

        // Fresh install: write (or overwrite) the config template with the new private key.
        // The existing-config case already returned early via the self-update prompt above,
        // so reaching here means either no config or an explicit fresh install (--force / declined).
        $configExisted = is_file($configPath);
        file_put_contents($configPath, $this->configTemplate($pair['private'], basename($agentPath)));
        $io->success(($configExisted ? 'Configuration file overwritten' : 'Configuration file generated')
            . " with the private key: $configPath");
        $io->writeln('Fill in <comment>url</comment> and <comment>mapping.local</comment> in it.');
        $io->warning('Keep the private key secret and store it outside of git.');

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
        return array_values(array_map(strval(...), $raw['protect']));
    }

    private function configTemplate(string $privateKey, string $agentFile): string
    {
        $key = var_export($privateKey, true);
        return <<<PHP
        <?php

        // psync configuration. Keep the private key secret (outside of public git).
        return [
            'url'        => 'https://example.com/$agentFile',
            //                       ^^^^^^^^^^^ - put here domain of your website
            'privateKey' => $key,
            'mapping'    => [
                'local'  => __DIR__, // <--- complete path to the local website root
                'remote' => '/',
            ],
            'ignore'     => ['/.git', '*.log', '/temp', '/uploads'],
            'protect'    => ['/uploads', '/temp'],
            'checksum'   => false,
            'compress'   => true,
        ];

        PHP;
    }
}
