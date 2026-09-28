#!/usr/bin/env php
<?php
// v1.4.1 loader — joins part1+part2, writes real script, runs it
$p1 = @file_get_contents(__DIR__ . "/bitcotasks.part1.b64");
$p2 = @file_get_contents(__DIR__ . "/bitcotasks.part2.b64");
if (!$p1 || !$p2) {
    fwrite(STDERR, "missing bitcotasks.part1.b64 or part2.b64 — git pull again\n");
    exit(1);
}
$real = gzdecode(base64_decode($p1 . $p2));
if ($real === false || strlen($real) < 1000) {
    fwrite(STDERR, "decode fail\n");
    exit(1);
}
$path = __DIR__ . "/bitcotasks.com.php";
file_put_contents($path, $real);
echo "[v1.4.1] wrote " . strlen($real) . " bytes\n";
passthru(PHP_BINARY . " " . escapeshellarg($path) . " " . implode(" ", array_map("escapeshellarg", array_slice($argv, 1))));
