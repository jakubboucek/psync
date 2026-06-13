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
 * `compare` – 2-phase local↔remote comparison, prints the differences (transfers nothing).
 *
 * Legend:
 *   >  local only (missing on the server)
 *   <  server only (missing locally)
 *   M  differs in content
 *   =  identical (only with -v)
 */
final class CompareCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('compare')
            ->setDescription('Compares local and remote files and prints the differences.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);

        $caps = $http->capabilities();
        if (($caps['protocolVersion'] ?? null) !== Protocol::VERSION) {
            $io->warning(sprintf(
                'Protocol version mismatch (server %s, client %d). Regenerate the agent with the install command.',
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
            $io->success('In sync – no differences.');
            return;
        }
        $io->writeln(sprintf(
            '<info>Differences:</info> M %d, > %d (local only), < %d (server only), = %d identical, hashed %d.',
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
