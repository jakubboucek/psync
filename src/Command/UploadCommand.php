<?php

declare(strict_types=1);

namespace PhpSync\Command;

use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Wire;
use PhpSync\Sync\Uploader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `upload` – nahraje na server soubory, které tam chybí nebo se liší (local → remote).
 * S --delete navíc smaže soubory přebývající na serveru (mimo protect-list).
 */
final class UploadCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('upload')
            ->setDescription('Nahraje rozdílné soubory na server (local → remote).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš, co by se přeneslo/smazalo.')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Smaž na serveru soubory přebývající oproti lokálu.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->loadConfig($input);
        $http = $this->buildHttpClient($config);
        $caps = $http->capabilities();

        $comparison = $this->buildComparator($config, $input, $http)->compare($this->scope($input));

        // local → remote: chybějící na serveru + obsahově odlišné
        $files = $comparison->localOnly;
        foreach ($comparison->modified as $rel => $pair) {
            $files[$rel] = $pair['local'];
        }

        $delete = (bool) $input->getOption('delete');
        $protect = $this->buildProtect($config);
        $toDelete = [];
        $protectedCount = 0;
        if ($delete) {
            foreach ($comparison->remoteOnly as $rel => $e) {
                if ($protect->matches($rel)) {
                    $protectedCount++;
                } else {
                    $toDelete[] = $rel;
                }
            }
        }

        if ($files === [] && $toDelete === []) {
            $io->success('Není co nahrávat – server je v synchronizaci.');
            if ($protectedCount > 0) {
                $io->note("$protectedCount souborů přebývá, ale je chráněno protect-listem.");
            }
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            foreach ($files as $rel => $e) {
                $output->writeln("<fg=green>↑ $rel</>");
            }
            foreach ($toDelete as $rel) {
                $output->writeln("<fg=red>␡ $rel</>");
            }
            $io->note(sprintf('dry-run: %d nahrát, %d smazat.', count($files), count($toDelete)));
            return Command::SUCCESS;
        }

        $uploader = new Uploader($http, $config->localRoot, $caps, $config->compress, $config->compressSkipExt);
        $ok = 0;
        $fail = 0;
        $uploader->upload($files, static function (string $rel, bool $success, ?string $err) use ($output, &$ok, &$fail): void {
            if ($success) {
                $ok++;
                $output->writeln("<fg=green>↑ $rel</>");
            } else {
                $fail++;
                $output->writeln("<fg=red>✗ $rel</> <fg=gray>($err)</>");
            }
        });

        $deleted = 0;
        if ($toDelete !== []) {
            $paths = array_map(static fn(string $r): string => Wire::encPath($r), $toDelete);
            foreach ($http->postJson(Protocol::ACTION_DELETE, ['paths' => $paths]) as $r) {
                if (!isset($r['p'])) {
                    continue;
                }
                $rel = Wire::decPath((string) $r['p']);
                if (($r['ok'] ?? false) === true) {
                    $deleted++;
                    $output->writeln("<fg=red>␡ $rel</>");
                } else {
                    $fail++;
                    $output->writeln("<fg=red>✗ smazání $rel</> <fg=gray>(" . (string) ($r['err'] ?? '?') . ")</>");
                }
            }
        }

        $io->newLine();
        $io->writeln(sprintf('<info>Nahráno %d, smazáno %d, chyb %d.</info>', $ok, $deleted, $fail));
        if ($protectedCount > 0) {
            $io->note("$protectedCount přebývajících souborů ponecháno (protect-list).");
        }
        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
