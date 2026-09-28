#!/usr/bin/env php
<?php
$path = __DIR__ . "/bitcotasks.com.php";
if (!file_exists($path)) { fwrite(STDERR, "missing bitcotasks.com.php\n"); exit(1); }
$b = file_get_contents($path);

// Remove false accept
$b = preg_replace('/if\s*\(\s*!\$ok\s*&&\s*\$token\s*\)\s*\$ok\s*=\s*true\s*;/', '// strict: no false accept', $b);

// Inject validate_field from getElementById("FIELD").value = response.TOKEN
if (strpos($b, 'validate_field') === false) {
    $inject = '    $out["validate_field"] = "UEjS";\n'
        . '    if (preg_match(\'#getElementById\\(\\s*["\'\\']([A-Za-z0-9_]+)["\'\\']\\s*\\)\\s*\\.\\s*value\\s*=\\s*response\\.([A-Za-z0-9_]+)#\', $js, $m)) {\n'
        . '        $out["validate_field"] = $m[1];\n'
        . '        $out["token_key"] = $m[2];\n'
        . '    }\n';
    $b = preg_replace('/(\n\s*)(return \$out;\s*\n\})/', "\n$inject$1$2", $b, 1);
}

// Replace hardcoded UEjS in validate POST
$b = str_replace(
    '"action=validate&UEjS=" . rawurlencode($tryTok)',
    '"action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode($tryTok)',
    $b
);
$b = str_replace(
    '"action=validate&UEjS=" . rawurlencode($token',
    '"action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode($token',
    $b
);
$b = preg_replace(
    '/action=validate&UEjS="\s*\.\s*rawurlencode\(/',
    'action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode(',
    $b
);

// Log ValField + exact POST
if (strpos($b, 'ValField') === false) {
    $b = str_replace(
        'logLine("Click", GREEN . "accepted (strict)" . RESET);',
        'logLine("Click", GREEN . "accepted (strict)" . RESET);
        logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));',
        $b
    );
    if (strpos($b, 'ValField') === false) {
        $b = str_replace(
            'logLine("Click", GREEN . "accepted" . RESET);',
            'logLine("Click", GREEN . "accepted" . RESET);
        logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));',
            $b
        );
    }
}
if (strpos($b, 'ValPOST') === false) {
    $b = str_replace(
        'list($cv, $vraw) = httpRequest($fwUrl, "POST",',
        'logLine("ValPOST", "action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . substr((string)$token,0,18) . "...");
        list($cv, $vraw) = httpRequest($fwUrl, "POST",',
        $b
    );
}

file_put_contents($path, $b);
echo "Patched OK (" . strlen($b) . " bytes)\n";
echo "Look for ValField + ValPOST in next run.\n";
