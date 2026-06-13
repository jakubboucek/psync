<?php

declare(strict_types=1);

use PhpSync\Config\Config;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Zapíše dočasný config (PHP vracející pole) a vrátí cestu. */
function writeConfig(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpsync_cfg_') . '.php';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return $path;
}


test('validní config se načte a znormalizuje', function () {
    $local = sys_get_temp_dir();
    $path = writeConfig([
        'url' => 'https://example.com/agent.php',
        'privateKey' => 'klic',
        'mapping' => ['local' => $local, 'remote' => '/web/'],
        'ignore' => ['/.git', '*.log'],
        'protect' => ['/uploads'],
        'compressSkipExt' => ['.JPG', 'png'],
    ]);

    $config = Config::load($path);
    Assert::same('https://example.com/agent.php', $config->url);
    Assert::same('klic', $config->requirePrivateKey());
    Assert::same(realpath($local), $config->localRoot);
    Assert::same('/web', $config->remoteRoot);              // koncové lomítko pryč
    Assert::same(['/.git', '*.log'], $config->ignore);
    Assert::same(['jpg', 'png'], $config->compressSkipExt); // lowercase, bez tečky

    @unlink($path);
});


test('chybějící privateKey – requirePrivateKey vyhodí výjimku', function () {
    $path = writeConfig([
        'url' => 'https://example.com/agent.php',
        'mapping' => ['local' => sys_get_temp_dir()],
    ]);
    $config = Config::load($path);
    Assert::null($config->privateKey);
    Assert::exception(fn() => $config->requirePrivateKey(), RuntimeException::class, '%a%privateKey%a%');
    @unlink($path);
});


test('chybějící url vyhodí výjimku', function () {
    $path = writeConfig(['mapping' => ['local' => sys_get_temp_dir()]]);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, "%a%'url'%a%");
    @unlink($path);
});


test('chybějící mapping.local vyhodí výjimku', function () {
    $path = writeConfig(['url' => 'https://example.com/agent.php']);
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%mapping.local%a%');
    @unlink($path);
});


test('config, který nevrací pole, vyhodí výjimku', function () {
    $path = tempnam(sys_get_temp_dir(), 'phpsync_cfg_') . '.php';
    file_put_contents($path, '<?php return "ne-pole";');
    Assert::exception(fn() => Config::load($path), RuntimeException::class, '%a%musí vracet pole%a%');
    @unlink($path);
});
