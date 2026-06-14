<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live progress (and, later, verbose logging) for long-running operations.
 *
 * Progress is written to STDERR so it never pollutes the result on STDOUT
 * (important under `composer global exec` and when piping). A single line is
 * rewritten in place via carriage return; it is only shown on a decorated TTY
 * and not in verbose mode (there the log lines act as progress instead).
 */
final class Reporter
{
    private readonly OutputInterface $err;
    private readonly bool $live;
    private string $label = '';
    private bool $active = false;
    private int $lastLen = 0;

    public function __construct(private readonly OutputInterface $output)
    {
        $this->err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->live = $this->err->isDecorated() && !$output->isVerbose();
    }

    /** -v: high-level steps (phases, counts). */
    public function log(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->err->writeln("<fg=gray>· $message</>");
        }
    }

    /** -vv: per-call detail (HTTP calls, per-file verdicts). */
    public function debug(string $message): void
    {
        if ($this->output->isVeryVerbose()) {
            $this->err->writeln("<fg=gray>·· $message</>");
        }
    }

    /** -vvv: everything (URLs, sizes, raw paths). */
    public function trace(string $message): void
    {
        if ($this->output->isDebug()) {
            $this->err->writeln("<fg=gray>··· $message</>");
        }
    }

    public function progressStart(string $label): void
    {
        $this->label = $label;
        $this->active = true;
        $this->lastLen = 0;
        $this->draw($label);
    }

    public function progressUpdate(int $current, ?int $total = null): void
    {
        if (!$this->active) {
            return;
        }
        $this->draw($total !== null
            ? sprintf('%s %d/%d', $this->label, $current, $total)
            : sprintf('%s %d', $this->label, $current));
    }

    public function progressDone(): void
    {
        if ($this->active && $this->live && $this->lastLen > 0) {
            $this->err->write("\r" . str_repeat(' ', $this->lastLen) . "\r");
        }
        $this->active = false;
        $this->lastLen = 0;
    }

    private function draw(string $msg): void
    {
        if (!$this->live) {
            return;
        }
        $pad = max(0, $this->lastLen - mb_strlen($msg));
        $this->err->write("\r" . $msg . str_repeat(' ', $pad), false, OutputInterface::OUTPUT_PLAIN);
        $this->lastLen = mb_strlen($msg);
    }
}
