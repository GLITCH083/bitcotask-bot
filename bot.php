#!/usr/bin/env php
<?php
error_reporting(0);
define("BASE_DIR", __DIR__);
define("SCRIPTS_DIR", BASE_DIR . "/scripts");
require_once BASE_DIR . "/functions/function.php";
require_once BASE_DIR . "/functions/vernuable.php";

define("GH_USER", "GLITCH083");
define("GH_REPO", "bitcotask-bot");
define("GH_BRANCH", "vernuable-officialscripts");
define("VERSION_FILE", "version.json");
define("TEMP_DIR", BASE_DIR . "/temp_update");
define("UPDATE_FILES", [
    "bot.php", "version.json",
    "functions/function.php", "functions/vernuable.php",
    "scripts/offerwall/bitcotasks.com.php",
]);
date_default_timezone_set("Asia/Karachi");
enableCtrlC();

function httpGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ["User-Agent: VernuableOfficialScripts/1.4"],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) @curl_close($ch);
    return [$code, $body];
}

function rawDownload($path) {
    $url = "https://raw.githubusercontent.com/" . GH_USER . "/" . GH_REPO . "/" . GH_BRANCH . "/" . $path;
    list($code, $body) = httpGet($url);
    return ($code == 200 && $body !== false && $body !== "") ? $body : false;
}

function getCurrentVersion() {
    $f = BASE_DIR . "/" . VERSION_FILE;
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true);
        if ($d && isset($d["version"])) return $d;
    }
    return ["version" => "0.0.0", "whats_new" => []];
}

function fetchLatestVersion() {
    $c = rawDownload(VERSION_FILE);
    if ($c) {
        $d = json_decode($c, true);
        if ($d && isset($d["version"])) return $d;
    }
    return null;
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function cleanupJunk() {
    $n = 0;
    $patterns = [
        SCRIPTS_DIR . "/*/patch_*.php",
        SCRIPTS_DIR . "/*/*.part*.b64",
        SCRIPTS_DIR . "/*/*.full.b64",
        BASE_DIR . "/bot.b64.*",
        BASE_DIR . "/bot.php.bak",
    ];
    foreach ($patterns as $pat) {
        foreach (glob($pat) as $f) {
            if (is_file($f) && @unlink($f)) {
                echo "  removed " . str_replace(BASE_DIR . DIRECTORY_SEPARATOR, "", $f) . "\n";
                $n++;
            }
        }
    }
    if (is_dir(TEMP_DIR)) {
        deleteDirectory(TEMP_DIR);
        echo "  removed temp_update/\n";
        $n++;
    }
    return $n;
}

function applyUpdate($latest) {
    echo "\n" . YELLOW . "Downloading update files...\n" . RESET;
    if (is_dir(TEMP_DIR)) deleteDirectory(TEMP_DIR);
    mkdir(TEMP_DIR, 0777, true);
    $n = 0;
    foreach (UPDATE_FILES as $file) {
        echo "  $file ... ";
        $content = rawDownload($file);
        if ($content === false) { echo RED . "x\n" . RESET; continue; }
        $tmp = TEMP_DIR . "/" . $file;
        $dir = dirname($tmp);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($tmp, $content);
        echo GREEN . "ok (" . strlen($content) . ")\n" . RESET;
        $n++;
    }
    if ($n == 0) {
        echo RED . "No files downloaded.\n" . RESET;
        deleteDirectory(TEMP_DIR);
        return false;
    }
    $applied = 0;
    echo YELLOW . "Applying...\n" . RESET;
    foreach (UPDATE_FILES as $file) {
        $tmp = TEMP_DIR . "/" . $file;
        if (!file_exists($tmp)) continue;
        $dest = BASE_DIR . "/" . $file;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (@rename($tmp, $dest) || (@copy($tmp, $dest) && @unlink($tmp))) {
            echo "  updated $file\n";
            $applied++;
        }
    }
    deleteDirectory(TEMP_DIR);
    echo YELLOW . "Cleaning junk...\n" . RESET;
    $cn = cleanupJunk();
    echo GREEN . "Done ($applied files, $cn junk removed). Restart bot.\n" . RESET;
    return true;
}

function checkForUpdates() {
    echo "\n" . CYAN . "Checking for updates...\n" . RESET;
    $cur = getCurrentVersion();
    echo "  Current: " . $cur["version"] . "\n";
    $lat = fetchLatestVersion();
    if (!$lat) {
        echo RED . "Could not fetch version.json\n" . RESET;
        echo WHITE . "Press Enter..." . RESET; fgets(STDIN); return;
    }
    echo "  Latest:  " . $lat["version"] . "\n";
    if (version_compare($lat["version"], $cur["version"], ">")) {
        echo GREEN . "Update available!\n" . RESET;
        foreach ($lat["whats_new"] ?? [] as $n) echo "  - $n\n";
        echo WHITE . "Install? (y/n): " . RESET;
        $c = strtolower(trim(fgets(STDIN)));
        if ($c === "y" || $c === "yes") applyUpdate($lat);
    } else {
        echo GREEN . "Already latest (" . $cur["version"] . ")\n" . RESET;
    }
    echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
}

function getScripts() {
    $out = [];
    if (!is_dir(SCRIPTS_DIR)) return $out;
    $skip = ["patch_", "boot", "loader", "restore"];
    foreach (glob(SCRIPTS_DIR . "/*", GLOB_ONLYDIR) as $dir) {
        $cat = basename($dir);
        foreach (glob($dir . "/*.php") as $file) {
            $base = basename($file);
            $low = strtolower($base);
            $bad = false;
            foreach ($skip as $s) if (strpos($low, $s) !== false) { $bad = true; break; }
            if ($bad) continue;
            $out[] = ["file" => $file, "basename" => $base, "category" => $cat, "description" => "offerwall"];
        }
    }
    return $out;
}

function healBitcotasks($script) {
    if (!is_file($script)) return;
    $b = file_get_contents($script);
    $changed = false;
    $nb = preg_replace('/if\s*\(\s*!\$ok\s*&&\s*\$token\s*\)\s*\$ok\s*=\s*true\s*;/', '// strict: no false accept', $b);
    if ($nb !== null && $nb !== $b) { $b = $nb; $changed = true; }
    if (strpos($b, 'validate_field') === false && strpos($b, 'function parseCaptchaJs') !== false) {
        $inj = "    \$out[\"validate_field\"] = \"UEjS\";\n"
             . "    if (preg_match('#getElementById\\\\(\\\\s*[\"\\']([A-Za-z0-9_]+)[\"\\']\\\\s*\\\\)\\\\s*\\\\.\\\\s*value\\\\s*=\\\\s*response\\\\.([A-Za-z0-9_]+)#', \$js, \$m)) {\n"
             . "        \$out[\"validate_field\"] = \$m[1];\n"
             . "        \$out[\"token_key\"] = \$m[2];\n"
             . "    }\n";
        $b2 = preg_replace('/(\n\s*)(return \$out;\s*\n\})/', "\n" . $inj . "$1$2", $b, 1);
        if ($b2 !== null && $b2 !== $b) { $b = $b2; $changed = true; }
    }
    if (strpos($b, 'validate_field') !== false) {
        $nb = str_replace(
            '"action=validate&UEjS=" . rawurlencode($tryTok)',
            '"action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode($tryTok)',
            $b
        );
        $nb = str_replace(
            '"action=validate&UEjS=" . rawurlencode($token)',
            '"action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode($token)',
            $nb
        );
        $nb = preg_replace(
            '/action=validate&UEjS="\s*\.\s*rawurlencode\(/',
            'action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode(',
            $nb
        );
        if ($nb !== null && $nb !== $b) { $b = $nb; $changed = true; }
    }
    if (strpos($b, 'ValField') === false) {
        $nb = str_replace(
            'logLine("Click", GREEN . "accepted (strict)" . RESET);',
            'logLine("Click", GREEN . "accepted (strict)" . RESET);\n        logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));',
            $b
        );
        if ($nb === $b) {
            $nb = str_replace(
                'logLine("Click", GREEN . "accepted" . RESET);',
                'logLine("Click", GREEN . "accepted" . RESET);\n        logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));',
                $b
            );
        }
        if ($nb !== $b) { $b = $nb; $changed = true; }
    }
    if ($changed) {
        file_put_contents($script, $b);
        echo YELLOW . "  [auto-heal] bitcotasks captcha/validate fixed\n" . RESET;
    }
}

function showBanner() {
    $v = getCurrentVersion();
    $bal = "?";
    try {
        $bb = vernuable_balance();
        if ($bb !== null) $bal = "$" . number_format($bb, 5);
    } catch (Exception $e) {}
    echo "\n";
    echo CYAN . "╔══════════════════════════════════════════════════════════╗\n" . RESET;
    echo CYAN . "║" . WHITE . BOLD . "     VERNUABLE  ·  OFFICIAL SCRIPTS                      " . CYAN . "║\n" . RESET;
    echo CYAN . "║" . DIM . "     Buxads-style launcher · auto-update                  " . CYAN . "║\n" . RESET;
    echo CYAN . "╠══════════════════════════════════════════════════════════╣\n" . RESET;
    echo CYAN . "║" . WHITE . "  Version  : " . GREEN . $v["version"] . WHITE . str_repeat(" ", max(0, 40 - strlen($v["version"]))) . CYAN . "║\n" . RESET;
    echo CYAN . "║" . WHITE . "  Balance  : " . GREEN . $bal . WHITE . str_repeat(" ", max(0, 40 - strlen($bal))) . CYAN . "║\n" . RESET;
    echo CYAN . "╚══════════════════════════════════════════════════════════╝\n" . RESET;
}

function mainMenu() {
    while (true) {
        clearScreen();
        showBanner();
        $scripts = getScripts();
        $cats = [];
        foreach ($scripts as $s) $cats[$s["category"]] = true;
        $cats = array_keys($cats);
        $i = 1;
        echo "\n  MAIN MENU\n  " . str_repeat("─", 40) . "\n";
        $map = [];
        foreach ($cats as $c) {
            $cnt = count(array_filter($scripts, function ($s) use ($c) { return $s["category"] === $c; }));
            echo "  " . GREEN . "[$i]" . WHITE . "  " . ucfirst($c) . " ($cnt scripts)\n" . RESET;
            $map[$i] = $c;
            $i++;
        }
        echo "  " . GREEN . "[$i]" . WHITE . "  Check for Updates\n" . RESET; $upd = $i; $i++;
        echo "  " . GREEN . "[$i]" . WHITE . "  Vernuable balance / set API key\n" . RESET; $bal = $i; $i++;
        echo "  " . GREEN . "[$i]" . WHITE . "  Clean junk files\n" . RESET; $cln = $i;
        echo "  " . GREEN . "[0]" . WHITE . "  Exit\n" . RESET;
        echo "\n" . YELLOW . "  › " . RESET;
        $choice = trim(fgets(STDIN));
        if ($choice === "0" || strtolower($choice) === "q") { echo "Bye.\n"; exit(0); }
        if ((int)$choice === $upd) { checkForUpdates(); continue; }
        if ((int)$choice === $cln) {
            echo "\n";
            $n = cleanupJunk();
            echo $n ? GREEN . "Removed $n item(s)\n" . RESET : "Nothing to clean.\n";
            echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
            continue;
        }
        if ((int)$choice === $bal) {
            echo "\n";
            $k = function_exists("vernuable_key") ? vernuable_key() : "";
            echo "  Key: " . ($k ? substr($k, 0, 8) . "..." : "(not set)") . "\n";
            $bb = vernuable_balance();
            echo "  Balance: " . ($bb !== null ? "$" . number_format($bb, 5) : "error") . "\n";
            echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
            continue;
        }
        $n = (int)$choice;
        if (!isset($map[$n])) continue;
        $cat = $map[$n];
        $list = array_values(array_filter($scripts, function ($s) use ($cat) { return $s["category"] === $cat; }));
        while (true) {
            clearScreen(); showBanner();
            echo "\n  " . strtoupper($cat) . "\n";
            foreach ($list as $j => $s) {
                echo "  " . GREEN . "[" . ($j + 1) . "]" . WHITE . "  " . $s["basename"] . "\n" . RESET;
            }
            echo "  " . GREEN . "[0]" . WHITE . "  Back\n" . RESET;
            echo "\n" . YELLOW . "  › " . RESET;
            $c2 = trim(fgets(STDIN));
            if ($c2 === "0") break;
            $idx = (int)$c2 - 1;
            if ($idx < 0 || $idx >= count($list)) continue;
            $script = $list[$idx]["file"];
            if (stripos(basename($script), "bitcotasks") !== false) {
                healBitcotasks($script);
            }
            passthru("php " . escapeshellarg($script));
            echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
        }
    }
}
mainMenu();
