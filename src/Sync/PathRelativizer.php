<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * Computes the relative path between two forward (sub)paths that share a common
 * anchor (the project-root). Used to derive the agent's baked scope: the path
 * from where the agent file physically lives (agent-dir) to the top of the
 * synchronized tree (sync-root). The result may climb up ('..'), descend
 * ('system/logs'), mix ('../www/system/logs') or be empty ('' = the same dir,
 * i.e. the agent's own __DIR__).
 *
 * Inputs are paths relative to the project-root, without '..' (the installer
 * validates that). The output is the literal that gets baked into the agent and
 * is mirrored by the client for the capabilities cross-check, so both sides MUST
 * compute it identically.
 */
final class PathRelativizer
{
    /**
     * Relative path from $from to $to (both relative to the same anchor).
     */
    public static function relativize(string $from, string $to): string
    {
        $fromSegs = self::segments($from);
        $toSegs = self::segments($to);

        $common = 0;
        $max = min(count($fromSegs), count($toSegs));
        while ($common < $max && $fromSegs[$common] === $toSegs[$common]) {
            $common++;
        }

        $up = array_fill(0, count($fromSegs) - $common, '..');
        $down = array_slice($toSegs, $common);

        return implode('/', array_merge($up, $down));
    }

    /**
     * Normalized form used for comparison: drops empty and '.' segments so that
     * '', '.', './' all collapse to the same canonical string.
     */
    public static function normalize(string $path): string
    {
        return implode('/', self::segments($path));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $path = str_replace('\\', '/', $path);
        return array_values(array_filter(
            explode('/', $path),
            static fn(string $seg): bool => $seg !== '' && $seg !== '.',
        ));
    }
}
