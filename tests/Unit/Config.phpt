<?php

declare(strict_types=1);

use JakubBoucek\Psync\Config\Config;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Writes a temporary config (PHP returning an array) into a fresh dir and returns the path. */
function writeConfig(array $data): string
{
    $dir = sys_get_temp_dir() . '/psync_cfg_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir . '/.psync.php';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return $path;
}


test('a valid config is loaded and normalized', function () {
    $path = writeConfig([
        'agentUrl' => 'https://example.com/psync-agent-ab12cd.php',
        'privateKey' => 'key',
        'syncRoot' => '',
        'agentDir' => 'www',
        'agentFile' => 'psync-agent-ab12cd.php',
        'ignore' => ['/.git', '*.log'],
        'protect' => ['/uploads'],
        'compressSkipExt' => ['.JPG', 'png'],
    ]);

    $config = Config::load($path);
    Assert::same('https://example.com/psync-agent-ab12cd.php', $config->url);
    Assert::same('key', $config->requirePrivateKey());
    Assert::same(dirname(realpath($path)), $config->localRoot);  // syncRoot '' = project-root
    Assert::same('www', $config->agentDir);
    Assert::same('psync-agent-ab12cd.php', $config->agentFile);
    Assert::same('..', $config->scopeRelPath());                 // agent in www, sync the parent
    Assert::same(['/.git', '*.log'], $config->ignore);
    Assert::same(['jpg', 'png'], $config->compressSkipExt);      // lowercase, without the dot

    @unlink($path);
});


test('syncRoot resolves under the config directory', function () {
    $path = writeConfig([
        'agentUrl' => 'https://example.com/a.php',
        'syncRoot' => 'public',
        'agentFile' => 'a.php',
    ]);
    mkdir(dirname($path) . '/public');

    $config = Config::load($path);
    Assert::same(dirname(realpath($path)) . '/public', $config->localRoot);
    Assert::same('public', $config->scopeRelPath());             // agentDir '' (project-root) -> public

    @unlink($path);
});


test('missing privateKey – requirePrivateKey throws an exception', function () {
    $path = writeConfig([
        'agentUrl' => 'https://example.com/a.php',
        'agentFile' => 'a.php',
    ]);
    $config = Config::load($path);
    Assert::null($config->privateKey);
    Assert::exception(fn() => $config->requirePrivateKey(), RuntimeException::class, '%a%privateKey%a%');
    @unlink($path);
});


test('the pre-v1.1 mapping format is rejected with a migration hint', function () {
    $path = writeConfig([
        'url' => 'https://example.com/agent.php',
        'mapping' => ['local' => sys_get_temp_dir(), 'remote' => '/'],
    ]);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%install --force%a%');
    @unlink($path);
});


test('a config without agentUrl is rejected', function () {
    $path = writeConfig(['syncRoot' => '', 'agentFile' => 'a.php']);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%install --force%a%');
    @unlink($path);
});


test('a config that does not return an array throws an exception', function () {
    $dir = sys_get_temp_dir() . '/psync_cfg_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir . '/.psync.php';
    file_put_contents($path, '<?php return "not-an-array";');
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%must return an array%a%');
    @unlink($path);
});


test('the new behavior flags default to off (legacy behavior preserved)', function () {
    $path = writeConfig([
        'agentUrl' => 'https://example.com/a.php',
        'agentFile' => 'a.php',
    ]);
    $config = Config::load($path);
    Assert::false($config->checksum);
    Assert::false($config->allowDelete);
    Assert::false($config->testMode);
    @unlink($path);
});


test('the new behavior flags are read from the config', function () {
    $path = writeConfig([
        'agentUrl' => 'https://example.com/a.php',
        'agentFile' => 'a.php',
        'checksum' => true,
        'allowDelete' => true,
        'testMode' => true,
    ]);
    $config = Config::load($path);
    Assert::true($config->checksum);
    Assert::true($config->allowDelete);
    Assert::true($config->testMode);
    @unlink($path);
});
