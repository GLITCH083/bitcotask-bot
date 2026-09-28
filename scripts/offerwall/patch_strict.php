#!/usr/bin/env php
<?php
// Apply strict captcha accept fix to bitcotasks.com.php
$path = __DIR__ . "/bitcotasks.com.php";
if (!file_exists($path)) { fwrite(STDERR, "bitcotasks.com.php not found\n"); exit(1); }
$src = file_get_contents($path);
if (strpos($src, 'FALSE accept') !== false || strpos($src, 'accepted (strict)') !== false) {
    echo "Already patched.\n"; exit(0);
}

// Remove: if (!$ok && $token) $ok = true;
$lines = explode("\n", $src);
$out = [];
foreach ($lines as $line) {
    if (preg_match('/if\s*\(\s*!\$ok\s*&&\s*\$token\s*\)\s*\$ok\s*=\s*true/', $line)) {
        $out[] = '        // STRICT FIX: do not accept on random hex alone';
        continue;
    }
    $out[] = $line;
}
$src2 = implode("\n", $out);

// Before log accepted, require success_key truthy
$src2 = str_replace(
    'logLine("Click", GREEN . "accepted" . RESET);',
    'logLine("SuccKey", (string)($parsed["success_key"] ?? "?") . "=" . json_encode($sj[$parsed["success_key"] ?? ""] ?? null));
        logLine("TokKey", (string)($parsed["token_key"] ?? "?"));
        $skv = $sj[$parsed["success_key"] ?? ""] ?? null;
        if (!($skv === true || $skv === 1 || $skv === "1")) {
            logLine("Click", RED . "FALSE accept - success_key not true" . RESET);
            logLine("ClickRaw", substr((string)$sraw, 0, 65));
            logEnd();
            return;
        }
        logLine("Click", GREEN . "accepted (strict)" . RESET);',
    $src2
);

file_put_contents($path, $src2);
echo "Patched OK\n";
echo "Re-run. If you see FALSE accept then coords were wrong.\n";
