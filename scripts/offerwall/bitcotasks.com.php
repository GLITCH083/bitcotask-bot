#!/usr/bin/env php
<?php
// v1.4.2 loader — single full.b64 preferred (avoids Windows split/CRLF issues)
function loadPayload() {
    $full = __DIR__ . "/bitcotasks.full.b64";
    if (file_exists($full)) {
        $raw = preg_replace('/\s+/', '', file_get_contents($full));
        $bin = base64_decode($raw, true);
        if ($bin !== false) {
            $php = @gzdecode($bin);
            if ($php !== false && strpos($php, "<?php") !== false) return $php;
        }
    }
    $p1 = @file_get_contents(__DIR__ . "/bitcotasks.part1.b64");
    $p2 = @file_get_contents(__DIR__ . "/bitcotasks.part2.b64");
    if ($p1 && $p2) {
        $raw = preg_replace('/\s+/', '', $p1 . $p2);
        $bin = base64_decode($raw, true);
        if ($bin !== false) {
            $php = @gzdecode($bin);
            if ($php !== false && strpos($php, "<?php") !== false) return $php;
        }
    }
    return false;
}
$real = loadPayload();
if ($real === false || strlen($real) < 1000) {
    fwrite(STDERR, "decode fail — re-download bitcotasks.full.b64\n");
    exit(1);
}
$path = __DIR__ . "/bitcotasks.com.php";
file_put_contents($path, $real);
echo "[v1.4.2] wrote " . strlen($real) . " bytes\n";
passthru(PHP_BINARY . " " . escapeshellarg($path) . " " . implode(" ", array_map("escapeshellarg", array_slice($argv, 1))));
