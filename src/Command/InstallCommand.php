<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Install\AgentBuilder;
use PhpSync\Protocol\Signer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `install` – vygeneruje pár klíčů (Ed25519), vyrenderuje agenta s veřejným
 * klíčem (k nahrání přes FTP) a vypíše privátní klíč pro klientský config.
 */
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Vygeneruje serverového agenta a pár klíčů.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Kam zapsat vyrenderovaného agenta.', 'agent.php')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Cesta ke konfiguračnímu souboru.', 'php-sync.php')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Přepiš existující soubory bez ptaní.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agentPath = (string) $input->getOption('output');
        $configPath = (string) $input->getOption('config');
        $force = (bool) $input->getOption('force');

        if (is_file($agentPath) && !$force) {
            $io->error("Soubor '$agentPath' už existuje. Použij --force pro přepsání.");
            return Command::FAILURE;
        }

        // Protect-list převezmi z existujícího configu (pokud je), ať se zazdí do agenta.
        $protect = $this->readProtect($configPath);

        $pair = Signer::generateKeyPair();
        $agent = (new AgentBuilder())->build($pair['public'], $protect);

        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Nelze zapsat agenta do '$agentPath'.");
            return Command::FAILURE;
        }

        $io->success("Agent vygenerován: $agentPath");
        $io->writeln('Nahraj ho přes FTP na server (do adresáře, který má být remote rootem).');

        // Config: pokud chybí, nabídni vygenerování šablony s privátním klíčem.
        if (!is_file($configPath)) {
            file_put_contents($configPath, $this->configTemplate($pair['private']));
            $io->success("Vygenerován konfigurační soubor s privátním klíčem: $configPath");
            $io->writeln('Doplň v něm <comment>url</comment> a <comment>mapping.local</comment>.');
        } else {
            $io->section('Privátní klíč – vlož do configu jako "privateKey"');
            $io->writeln("<info>{$pair['private']}</info>");
            $io->warning('Privátní klíč drž v tajnosti a verzuj ho mimo git.');
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

    private function configTemplate(string $privateKey): string
    {
        $key = var_export($privateKey, true);
        return <<<PHP
        <?php

        // Konfigurace php-sync. Privátní klíč drž v tajnosti (mimo veřejný git).
        return [
            'url'        => 'https://example.com/agent.php',
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
