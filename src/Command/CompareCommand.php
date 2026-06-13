<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Protocol\Protocol;
use PhpSync\Sync\Comparison;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `compare` – 2-fázové porovnání local↔remote, výpis rozdílů (nic nepřenáší).
 *
 * Legenda:
 *   >  jen lokálně (na serveru chybí)
 *   <  jen na serveru (lokálně chybí)
 *   M  liší se obsahem
 *   =  shodné (jen s -v)
 */
final class CompareCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('compare')
            ->setDescription('Porovná lokální a vzdálené soubory a vypíše rozdíly.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);

        $caps = $http->capabilities();
        if (($caps['protocolVersion'] ?? null) !== Protocol::VERSION) {
            $io->warning(sprintf(
                'Verze protokolu se liší (server %s, klient %d). Přegeneruj agenta příkazem install.',
                (string) ($caps['protocolVersion'] ?? '?'),
                Protocol::VERSION,
            ));
        }

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));
        $this->render($io, $output, $comparison);

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, OutputInterface $output, Comparison $c): void
    {
        $verbose = $output->isVerbose();

        if ($verbose) {
            foreach ($c->equal as $rel) {
                $output->writeln("<fg=gray>=  $rel</>");
            }
        }
        foreach ($c->modified as $rel => $pair) {
            $output->writeln(sprintf(
                "<fg=yellow>M  %s</> <fg=gray>(local %s / remote %s)</>",
                $rel,
                $this->bytes($pair['local']->size),
                $this->bytes($pair['remote']->size),
            ));
        }
        foreach ($c->localOnly as $rel => $e) {
            $output->writeln("<fg=green>>  $rel</> <fg=gray>({$this->bytes($e->size)})</>");
        }
        foreach ($c->remoteOnly as $rel => $e) {
            $output->writeln("<fg=red><  $rel</> <fg=gray>({$this->bytes($e->size)})</>");
        }

        $io->newLine();
        if ($c->isInSync()) {
            $io->success('V synchronizaci – žádné rozdíly.');
            return;
        }
        $io->writeln(sprintf(
            '<info>Rozdíly:</info> M %d, > %d (jen lokálně), < %d (jen na serveru), = %d shodných, hashováno %d.',
            count($c->modified),
            count($c->localOnly),
            count($c->remoteOnly),
            count($c->equal),
            $c->hashedCount,
        ));
    }

    private function bytes(int $n): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $n : sprintf('%.1f', $v)) . ' ' . $units[$i];
    }
}
