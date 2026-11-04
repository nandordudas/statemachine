<?php

declare(strict_types=1);

/**
 * `php bench/compare.php base.txt head.txt`: puts two bench/run.php outputs side by side.
 */

/**
 * @return array<string, float> µs per operation by scenario
 */
function timings(string $file): array
{
    $timings = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(.{44})\s+[\d,]+ ops\/s\s+([\d.]+) µs\/op/u', $line, $m) === 1) {
            $timings[rtrim($m[1])] = (float) $m[2];
        }
    }

    return $timings;
}

$base = timings($argv[1] ?? 'base.txt');
$head = timings($argv[2] ?? 'head.txt');

printf("\n%-44s %12s %12s %9s\n", 'scenario', 'base µs/op', 'head µs/op', 'change');

foreach ($head as $name => $after) {
    $before = $base[$name] ?? null;
    printf(
        "%-44s %12s %12.2f %9s\n",
        $name,
        $before === null ? 'n/a' : sprintf('%.2f', $before),
        $after,
        $before === null || $before == 0.0 ? '' : sprintf('%+.0f%%', ($after - $before) / $before * 100),
    );
}
