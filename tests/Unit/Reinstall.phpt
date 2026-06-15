<?php

declare(strict_types=1);

use JakubBoucek\Psync\Command\ReinstallCommand;
use JakubBoucek\Psync\Protocol\Signer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * Builds a project dir with a config (incl. a hand comment + extra key) and an
 * agent-dir, returns [configPath, initialPrivateKey].
 *
 * @return array{0: string, 1: string}
 */
function makeProject(): array
{
    $dir = sys_get_temp_dir() . '/psync_reinstall_' . bin2hex(random_bytes(4));
    mkdir($dir . '/www', 0777, true);

    $priv = Signer::generateKeyPair()['private'];
    $body = "<?php\n// MY HAND COMMENT — keep me\nreturn [\n"
        . "    'agentUrl'   => 'https://example.com/agent.php',\n"
        . "    'privateKey' => '$priv',\n"
        . "    'syncRoot'   => '',\n"
        . "    'agentDir'   => 'www',\n"
        . "    'agentFile'  => 'agent.php',\n"
        . "    'ignore'     => ['/.git', '*.log'],\n"
        . "];\n";
    $path = $dir . '/.psync.php';
    file_put_contents($path, $body);

    return [$path, $priv];
}

function runReinstall(string $configPath, bool $preserveKey): CommandTester
{
    $app = new Application();
    $app->add(new ReinstallCommand());
    $tester = new CommandTester($app->find('re-install'));
    $params = ['--config' => $configPath];
    if ($preserveKey) {
        $params['--preserve-key'] = true;
    }
    $tester->execute($params);
    return $tester;
}

/** @return mixed[] */
function loadConfig(string $path): array
{
    return require $path;
}


test('rotation replaces the key and bakes the matching public key, preserving the rest', function () {
    [$configPath, $oldPriv] = makeProject();
    $dir = dirname($configPath);

    $tester = runReinstall($configPath, preserveKey: false);
    Assert::same(0, $tester->getStatusCode());

    $config = loadConfig($configPath);
    $newPriv = $config['privateKey'];
    Assert::notSame($oldPriv, $newPriv);                       // key rotated

    // The agent carries the public key derived from the NEW private key.
    $expectedPub = base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode($newPriv)));
    $agent = (string) file_get_contents($dir . '/www/agent.php');
    Assert::contains("'" . $expectedPub . "'", $agent);

    // Surgical replace leaves everything else intact.
    $raw = (string) file_get_contents($configPath);
    Assert::contains('MY HAND COMMENT', $raw);
    Assert::same('https://example.com/agent.php', $config['agentUrl']);
    Assert::same(['/.git', '*.log'], $config['ignore']);

    // The output warns about the rotation / re-upload.
    Assert::contains('rotated', $tester->getDisplay());
});


test('--preserve-key keeps the key and does not touch the config', function () {
    [$configPath, $oldPriv] = makeProject();
    $before = (string) file_get_contents($configPath);

    $tester = runReinstall($configPath, preserveKey: true);
    Assert::same(0, $tester->getStatusCode());

    Assert::same($before, (string) file_get_contents($configPath));  // config untouched
    Assert::same($oldPriv, loadConfig($configPath)['privateKey']);
});


test('rotation refuses a config without a private key', function () {
    [$configPath] = makeProject();
    // Drop the privateKey line.
    $raw = (string) file_get_contents($configPath);
    $raw = preg_replace("/^.*'privateKey'.*\\n/m", '', $raw);
    file_put_contents($configPath, $raw);

    $tester = runReinstall($configPath, preserveKey: false);
    Assert::same(1, $tester->getStatusCode());
    Assert::contains('install --force', $tester->getDisplay());
});
