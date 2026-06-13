<?php

declare(strict_types=1);

use PhpSync\Config\Config;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Writes a temporary config (PHP returning an array) and returns the path. */
function writeConfig(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpsync_cfg_') . '.php';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return $path;
}


test('a valid config is loaded and normalized', function () {
    $local = sys_get_temp_dir();
    $path = writeConfig([
        'url' => 'https://example.com/agent.php',
        'privateKey' => 'key',
        'mapping' => ['local' => $local, 'remote' => '/web/'],
        'ignore' => ['/.git', '*.log'],
        'protect' => ['/uploads'],
        'compressSkipExt' => ['.JPG', 'png'],
    ]);

    $config = Config::load($path);
    Assert::same('https://example.com/agent.php', $config->url);
    Assert::same('key', $config->requirePrivateKey());
    Assert::same(realpath($local), $config->localRoot);
    Assert::same('/web', $config->remoteRoot);              // trailing slash removed
    Assert::same(['/.git', '*.log'], $config->ignore);
    Assert::same(['jpg', 'png'], $config->compressSkipExt); // lowercase, without the dot

    @unlink($path);
});


test('missing privateKey – requirePrivateKey throws an exception', function () {
    $path = writeConfig([
        'url' => 'https://example.com/agent.php',
        'mapping' => ['local' => sys_get_temp_dir()],
    ]);
    $config = Config::load($path);
    Assert::null($config->privateKey);
    Assert::exception(fn() => $config->requirePrivateKey(), RuntimeException::class, '%a%privateKey%a%');
    @unlink($path);
});


test('missing url throws an exception', function () {
    $path = writeConfig(['mapping' => ['local' => sys_get_temp_dir()]]);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, "%a%'url'%a%");
    @unlink($path);
});


test('missing mapping.local throws an exception', function () {
    $path = writeConfig(['url' => 'https://example.com/agent.php']);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%mapping.local%a%');
    @unlink($path);
});


test('a config that does not return an array throws an exception', function () {
    $path = tempnam(sys_get_temp_dir(), 'phpsync_cfg_') . '.php';
    file_put_contents($path, '<?php return "not-an-array";');
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%must return an array%a%');
    @unlink($path);
});
