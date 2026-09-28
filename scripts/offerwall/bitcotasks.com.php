#!/usr/bin/env php
<?php
// v1.4.3 loader — join part1+part2 (CRLF-safe), write real script, run
$p1 = @file_get_contents(__DIR__ . "/bitcotasks.part1.b64");
$p2 = @file_get_contents(__DIR__ . "/bitcotasks.part2.b64");
$full = @file_get_contents(__DIR__ . "/bitcotasks.full.b64");
$raw = "";
if ($full && strlen(trim($full)) > 100) {
    $raw = preg_replace('/\s+/', '', $full);
} elseif ($p1 && $p2) {
    $raw = preg_replace('/\s+/', '', $p1 . $p2);
} else {
    fwrite(STDERR, "missing part1/part2 or full.b64\n");
    exit(1);
}
$bin = base64_decode($raw, true);
if ($bin === false) { fwrite(STDERR, "b64 fail\n"); exit(1); }
$real = @gzdecode($bin);
if ($real === false || strpos($real, "<?php") === false) {
    fwrite(STDERR, "gzdecode fail\n");
    exit(1);
}
$path = __DIR__ . "/bitcotasks.com.php";
file_put_contents($path, $real);
echo "[v1.4.3] wrote " . strlen($real) . " bytes\n";
passthru(PHP_BINARY . " " . escapeshellarg($path) . " " . implode(" ", array_map("escapeshellarg", array_slice($argv, 1))));
