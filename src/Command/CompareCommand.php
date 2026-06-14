<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Console\Reporter;
use JakubBoucek\Psync\Sync\Comparison;
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
        $reporter = new Reporter($output);
        $http = $this->buildHttpClient($config, $reporter);

        // Handshake; capabilities() hard-fails on a protocol version mismatch.
        $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http, $reporter)->compare($this->scope($input));
        $this->render($io, $output, $comparison);

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, OutputInterface $output, Comparison $c): void
    {
        // Build one list keyed by path so entries from the same directory stay
        // together, then sort by path (regardless of status).
        $lines = [];
        if ($output->isVerbose()) {
            foreach ($c->equal as $rel) {
                $lines[$rel] = "<fg=gray>=  $rel</>";
            }
        }
        foreach ($c->modified as $rel => $pair) {
            $lines[$rel] = sprintf(
                "<fg=yellow>M  %s</> <fg=gray>(local %s / remote %s)</>",
                $rel,
                $this->bytes($pair['local']->size),
                $this->bytes($pair['remote']->size),
            );
        }
        foreach ($c->localOnly as $rel => $e) {
            $lines[$rel] = "<fg=green>>  $rel</> <fg=gray>({$this->bytes($e->size)})</>";
        }
        foreach ($c->remoteOnly as $rel => $e) {
            $lines[$rel] = "<fg=red><  $rel</> <fg=gray>({$this->bytes($e->size)})</>";
        }

        ksort($lines, SORT_STRING);
        foreach ($lines as $line) {
            $output->writeln($line);
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
