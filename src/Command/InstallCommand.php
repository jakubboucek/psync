<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Command;

use JakubBoucek\Psync\Install\AgentBuilder;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\Sync\PathRelativizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `install` – bootstrap: generates an Ed25519 key pair, renders the agent (public
 * key + baked scope + protect-list) and writes the client config.
 *
 * Two directories drive the layout, both relative to the project-root (the config
 * file's directory): the **sync-root** (the synchronized tree) and the **agent-dir**
 * (where the agent file is deployed). The agent's scope is the path agent-dir →
 * sync-root, so the synced tree may lie above the agent, below it, or aside.
 *
 * The HTTP endpoint is given either as `--host` (convenience: the URL is composed)
 * or `--agent-url` (explicit: stored verbatim) – never both.
 */
final class InstallCommand extends Command
{
    /** Baked into the agent and written into the config – kept identical on both. */
    private const array DEFAULT_PROTECT = ['/uploads', '/temp'];

    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Generates the server agent + key pair and writes the client config.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Public host/base-URL serving the agent-dir (e.g. example.com or example.com/tools); the agent URL is composed from it.')
            ->addOption('agent-url', null, InputOption::VALUE_REQUIRED, 'Explicit full agent URL, stored verbatim (mutually exclusive with --host).')
            ->addOption('sync-root', null, InputOption::VALUE_REQUIRED, 'Directory to synchronize, relative to the project-root (default: the project-root).')
            ->addOption('agent-dir', null, InputOption::VALUE_REQUIRED, 'Directory where the agent file is deployed, relative to the project-root (default: the sync-root).')
            ->addOption('agent-file', null, InputOption::VALUE_REQUIRED, 'Agent filename; may carry a directory used as --agent-dir. Default: a randomized psync-agent-<nonce>.php.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file.', '.psync.php')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing config without asking.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = (string) $input->getOption('config');
        $force = (bool) $input->getOption('force');

        // An existing config almost always means the user wants `re-install` (keep the URL
        // and layout, just re-render the agent), not a fresh install that overwrites it.
        // The delegated re-install rotates the key by default, like a direct `re-install`.
        if (is_file($configPath) && !$force) {
            if ($io->confirm("An existing config '$configPath' was found. `install` creates a brand-new key and agent and overwrites the config. Did you mean `re-install` instead (regenerate the agent, rotate the key, keep the existing URL and layout)?", true)) {
                return new ReinstallCommand()->generate($io, $configPath);
            }
            $io->warning("Proceeding with a fresh install – '$configPath' will be overwritten.");
        }

        // --- HTTP axis: exactly one of --host / --agent-url ------------------------------
        $host = $input->getOption('host');
        $agentUrl = $input->getOption('agent-url');
        if ($host !== null && $agentUrl !== null) {
            $io->error('Use either --host or --agent-url, not both.');
            return Command::FAILURE;
        }

        // --- agent-dir + agent-file (agent-file may carry a directory) -------------------
        $agentDir = $input->getOption('agent-dir');
        $agentDir = $agentDir !== null ? (string) $agentDir : null;
        $agentFile = $input->getOption('agent-file');
        $agentFile = $agentFile !== null ? str_replace('\\', '/', (string) $agentFile) : null;
        if ($agentFile !== null && str_contains($agentFile, '/')) {
            if ($agentDir !== null) {
                $io->error('The directory is given twice: --agent-file carries a path and --agent-dir is also set. Use only one.');
                return Command::FAILURE;
            }
            $agentDir = dirname($agentFile);
            $agentFile = basename($agentFile);
        }

        $syncRoot = (string) ($input->getOption('sync-root') ?? '');
        $agentDir ??= $syncRoot; // default: the agent lives at the top of what it syncs

        foreach (['sync-root' => $syncRoot, 'agent-dir' => $agentDir] as $label => $p) {
            if ($p !== '' && ($error = $this->validateRel($p)) !== null) {
                $io->error("--$label $error");
                return Command::FAILURE;
            }
        }
        $syncRoot = trim($syncRoot, '/');
        $agentDir = trim($agentDir, '/');

        $projectRoot = realpath(dirname($configPath));
        $projectRoot = $projectRoot !== false ? $projectRoot : dirname($configPath);

        $syncAbs = $syncRoot === '' ? $projectRoot : $projectRoot . '/' . $syncRoot;
        if (!is_dir($syncAbs)) {
            $io->error("sync-root does not exist locally (it is the directory being synchronized): $syncAbs");
            return Command::FAILURE;
        }

        // --- agent filename --------------------------------------------------------------
        if ($agentFile === null || $agentFile === '') {
            $agentFile = $agentUrl !== null
                ? $this->filenameFromUrl((string) $agentUrl)
                : 'psync-agent-' . bin2hex(random_bytes(3)) . '.php';
            if ($agentFile === null) {
                $io->error('Cannot determine the agent filename from --agent-url; pass --agent-file explicitly.');
                return Command::FAILURE;
            }
        }
        if (str_contains($agentFile, '/')) {
            $io->error('--agent-file must be a filename without a directory.');
            return Command::FAILURE;
        }

        // --- URL (composed for --host, verbatim for --agent-url, placeholder otherwise) --
        $placeholder = false;
        if ($agentUrl !== null) {
            $url = (string) $agentUrl;
        } elseif ($host !== null) {
            $url = $this->composeUrl((string) $host, $agentFile);
        } else {
            $url = 'https://example.com/' . $agentFile;
            $placeholder = true;
        }

        $scopeRelPath = PathRelativizer::relativize($agentDir, $syncRoot);

        // --- render + write the agent ----------------------------------------------------
        $pair = Signer::generateKeyPair();
        $agent = new AgentBuilder()->build($pair['public'], $scopeRelPath, self::DEFAULT_PROTECT);

        $agentLocalDir = ($agentDir !== '' && is_dir($projectRoot . '/' . $agentDir))
            ? $projectRoot . '/' . $agentDir
            : $projectRoot;
        $agentPath = $agentLocalDir . '/' . $agentFile;
        if (is_file($agentPath) && !$force) {
            $io->error("File '$agentPath' already exists. Use --force to overwrite.");
            return Command::FAILURE;
        }
        if (file_put_contents($agentPath, $agent) === false) {
            $io->error("Unable to write the agent to '$agentPath'.");
            return Command::FAILURE;
        }

        $io->success("Agent generated: $agentPath");
        $io->writeln(sprintf(
            'Upload it via FTP into <comment>%s</comment> so it is reachable at <comment>%s</comment>.',
            $agentDir === '' ? 'the sync-root' : $agentDir,
            $url,
        ));

        // --- write the config ------------------------------------------------------------
        $configExisted = is_file($configPath);
        if (file_put_contents($configPath, $this->configTemplate($pair['private'], $url, $syncRoot, $agentDir, $agentFile)) === false) {
            $io->error("Unable to write the configuration to '$configPath'. The agent was written but the config was NOT.");
            return Command::FAILURE;
        }
        $io->success(($configExisted ? 'Configuration file overwritten' : 'Configuration file generated')
            . " with the private key: $configPath");
        if ($placeholder) {
            $io->warning("No --host/--agent-url given: fill in the placeholder 'agentUrl' in the config.");
        }
        $io->warning('Keep the private key secret and store it outside of git.');

        return Command::SUCCESS;
    }

    /** Returns an error fragment if the relative path is unsafe, otherwise null. */
    private function validateRel(string $path): ?string
    {
        $norm = str_replace('\\', '/', $path);
        if (str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:#', $norm) === 1) {
            return 'must be relative to the project-root (no leading "/", not absolute).';
        }
        if (in_array('..', explode('/', $norm), true)) {
            return 'must not contain "..".';
        }
        return null;
    }

    /** Basename of the URL path, or null when it cannot be determined. */
    private function filenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $base = basename($path);
        return $base !== '' ? $base : null;
    }

    /** Composes the agent URL from a host/base-URL and the filename (prepends https:// if missing). */
    private function composeUrl(string $host, string $file): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $host) !== 1) {
            $host = 'https://' . $host;
        }
        return rtrim($host, '/') . '/' . $file;
    }

    private function configTemplate(string $privateKey, string $url, string $syncRoot, string $agentDir, string $agentFile): string
    {
        $key = var_export($privateKey, true);
        $url = var_export($url, true);
        $syncRoot = var_export($syncRoot, true);
        $agentDir = var_export($agentDir, true);
        $agentFile = var_export($agentFile, true);
        return <<<PHP
        <?php

        // psync configuration. Keep the private key secret (outside of public git).
        // Paths are relative to THIS file's directory (the project-root).
        return [
            'agentUrl'   => $url,
            'privateKey' => $key,
            'syncRoot'   => $syncRoot,  // top of the synchronized tree ('' = this directory)
            'agentDir'   => $agentDir,  // where the agent file is deployed ('' = sync-root)
            'agentFile'  => $agentFile, // basename, used by `re-install`
            'ignore'     => ['/.git', '*.log', '/temp', '/uploads'],
            'protect'    => ['/uploads', '/temp'],
            'checksum'   => false,
            'compress'   => true,
        ];

        PHP;
    }
}
