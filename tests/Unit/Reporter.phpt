<?php

declare(strict_types=1);

use JakubBoucek\Psync\Console\Reporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('warn is visible at any verbosity, log levels are gated', function () {
    $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
    $reporter = new Reporter($out);

    $reporter->warn('watch out');
    $reporter->log('hidden');
    $reporter->debug('hidden');
    $reporter->trace('hidden');

    $text = $out->fetch();
    Assert::contains('watch out', $text);
    Assert::notContains('hidden', $text);

    $out = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
    $reporter = new Reporter($out);
    $reporter->log('now visible');
    Assert::contains('now visible', $out->fetch());
});


test('messages are plain text: console markup in data is printed literally', function () {
    // decorated=true so the formatter actually interprets tags – an unescaped
    // message would have its markup swallowed/styled instead of printed.
    $out = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, true);
    $reporter = new Reporter($out);

    $reporter->warn('file <info>a.txt</info> is <fg=red>odd</>');
    $reporter->log('remote name: </>x<error>!</error>');

    $text = $out->fetch();
    Assert::contains('<info>a.txt</info>', $text);
    Assert::contains('<fg=red>odd</>', $text);
    Assert::contains('</>x<error>!</error>', $text);
});
