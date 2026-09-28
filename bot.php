#!/usr/bin/env php
<?php
/**
 * Vernuable Official Scripts — Main Launcher (Buxads-style)
 *
 * @author GLITCH083
 * @version 1.0.0
 *
 * php bot.php
 */

error_reporting(0);
ini_set("display_errors", 0);

define("BASE_DIR", __DIR__);
define("SCRIPTS_DIR", BASE_DIR . "/scripts");

require_once BASE_DIR . "/functions/function.php";
require_once BASE_DIR . "/functions/vernuable.php";

// GitHub (same pattern as Buxads-scripts)
$tokenFile = BASE_DIR . "/github_token.txt";
$githubToken = file_exists($tokenFile) ? trim(file_get_contents($tokenFile)) : "";

define("GITHUB_TOKEN", $githubToken);
define("GITHUB_USERNAME", "GLITCH083");
define("GITHUB_REPO", "bitcotask-bot");
define("GITHUB_BRANCH", "vernuable-officialscripts");
define("GITHUB_API_URL", "https://api.github.com/repos/" . GITHUB_USERNAME . "/" . GITHUB_REPO);
define("VERSION_FILE", "version.json");
define("TEMP_DIR", BASE_DIR . "/temp_update");
define("APP_HOST", "vernuable-Bot");

date_default_timezone_set("Asia/Karachi");
enableCtrlC();

function githubApiRequest($endpoint) {
    $url = GITHUB_API_URL . $endpoint;
    $ch = curl_init();
    $headers = [
        "Accept: application/vnd.github.v3+json",
        "User-Agent: VernuableOfficialScripts/1.0",
    ];
    if (GITHUB_TOKEN !== "") {
        $headers[] = "Authorization: token " . GITHUB_TOKEN;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    if ($httpCode == 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

function githubDownloadFile($path) {
    $data = githubApiRequest("/contents/" . $path . "?ref=" . GITHUB_BRANCH);
    if ($data && isset($data["content"])) {
        return base64_decode($data["content"]);
    }
    $rawUrl = "https://raw.githubusercontent.com/" . GITHUB_USERNAME . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/" . $path;
    $ch = curl_init();
    $headers = ["User-Agent: VernuableOfficialScripts/1.0"];
    if (GITHUB_TOKEN !== "") {
        $headers[] = "Authorization: token " . GITHUB_TOKEN;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $rawUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    return ($httpCode == 200 && $response) ? $response : false;
}

function githubGetTree() {
    $data = githubApiRequest("/git/trees/" . GITHUB_BRANCH . "?recursive=1");
    if ($data && isset($data["tree"]) && is_array($data["tree"])) {
        $files = [];
        foreach ($data["tree"] as $item) {
            if (isset($item["type"], $item["path"]) && $item["type"] === "blob") {
                $files[] = $item["path"];
            }
        }
        return $files;
    }
    return [];
}

function getCurrentVersion() {
    $localFile = BASE_DIR . "/" . VERSION_FILE;
    if (file_exists($localFile)) {
        $data = json_decode(file_get_contents($localFile), true);
        if ($data && isset($data["version"])) {
            return $data;
        }
    }
    return ["version" => "0.0.0", "whats_new" => []];
}

function fetchLatestVersion() {
    $content = githubDownloadFile(VERSION_FILE);
    if ($content) {
        $data = json_decode($content, true);
        if ($data && isset($data["version"])) {
            return $data;
        }
    }
    return null;
}

function isNewerVersion($current, $latest) {
    if (!$latest || !isset($latest["version"]) || !isset($current["version"])) {
        return false;
    }
    return version_compare($latest["version"], $current["version"], ">");
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function applyUpdate($latest) {
    echo "\n" . YELLOW . "📥 Fetching repository file list..." . RESET . "\n";
    $remoteFiles = githubGetTree();
    if (empty($remoteFiles)) {
        echo RED . "✗ Could not fetch tree. Check token / branch." . RESET . "\n";
        return false;
    }
    $managedExt = ["php", "json", "md", "sh", "txt"];
    $remoteFiles = array_values(array_filter($remoteFiles, function ($path) use ($managedExt) {
        if (strpos($path, ".") === 0 || strpos($path, ".github/") === 0) {
            return false;
        }
        if (preg_match('#\.(py|pyc)$#i', $path)) {
            return false;
        }
        if (strpos($path, "lib/") === 0 || strpos($path, "core/") === 0 || strpos($path, "bots/") === 0) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $managedExt) || $path === "version.json";
    }));

    if (is_dir(TEMP_DIR)) {
        deleteDirectory(TEMP_DIR);
    }
    mkdir(TEMP_DIR, 0777, true);

    $downloaded = 0;
    foreach ($remoteFiles as $file) {
        echo "  Downloading: $file ... ";
        $content = githubDownloadFile($file);
        if ($content !== false) {
            $tempFile = TEMP_DIR . "/" . $file;
            $dir = dirname($tempFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($tempFile, $content);
            $downloaded++;
            echo GREEN . "✓" . RESET . "\n";
        } else {
            echo RED . "✗" . RESET . "\n";
        }
    }

    if ($downloaded == 0) {
        echo RED . "✗ No files downloaded." . RESET . "\n";
        deleteDirectory(TEMP_DIR);
        return false;
    }

    if (file_exists(BASE_DIR . "/bot.php")) {
        copy(BASE_DIR . "/bot.php", BASE_DIR . "/bot.php.bak");
    }

    echo "\n" . YELLOW . "🔄 Applying update..." . RESET . "\n";
    $applied = 0;
    foreach ($remoteFiles as $file) {
        $tempFile = TEMP_DIR . "/" . $file;
        if (!file_exists($tempFile)) {
            continue;
        }
        $dest = BASE_DIR . "/" . $file;
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (rename($tempFile, $dest)) {
            echo "  ✓ Updated: $file\n";
            $applied++;
        }
    }
    deleteDirectory(TEMP_DIR);
    echo "\n" . GREEN . "✅ Update applied ($applied files). Restart the bot." . RESET . "\n";
    return true;
}

function checkForUpdates() {
    echo "\n" . CYAN . "🔍 Checking for updates..." . RESET . "\n";
    $current = getCurrentVersion();
    echo GREY . "  Current: " . $current["version"] . RESET . "\n";
    $latest = fetchLatestVersion();
    if (!$latest) {
        echo RED . "❌ Could not fetch version (need github_token.txt for private repo)." . RESET . "\n";
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        return;
    }
    echo GREY . "  Latest:  " . $latest["version"] . RESET . "\n";
    if (isNewerVersion($current, $latest)) {
        echo GREEN . "📦 Update available!\n" . RESET;
        if (!empty($latest["whats_new"])) {
            foreach ($latest["whats_new"] as $n) {
                echo "  • $n\n";
            }
        }
        echo WHITE . "Install update? (y/n): " . RESET;
        $c = strtolower(trim(fgets(STDIN)));
        if ($c === "y" || $c === "yes") {
            applyUpdate($latest);
        }
    } else {
        echo GREEN . "✅ Already on latest (" . $current["version"] . ")\n" . RESET;
    }
    echo WHITE . "Press Enter..." . RESET;
    fgets(STDIN);
}

function getScripts() {
    $scripts = [];
    if (!is_dir(SCRIPTS_DIR)) {
        mkdir(SCRIPTS_DIR, 0777, true);
        return $scripts;
    }
    $dirs = glob(SCRIPTS_DIR . "/*", GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $category = basename($dir);
        foreach (glob($dir . "/*.php") as $file) {
            $basename = basename($file);
            $scripts[] = [
                "file" => $file,
                "basename" => $basename,
                "description" => getScriptDescription($basename),
                "category" => $category,
            ];
        }
    }
    usort($scripts, function ($a, $b) {
        return strcasecmp($a["basename"], $b["basename"]);
    });
    return $scripts;
}

function getScriptDescription($filename) {
    $map = [
        "bitcotasks.com.php" => "BitcoTasks offerwall (motion captcha)",
        "bitcotask.php" => "BitcoTasks multi-account",
    ];
    foreach ($map as $k => $v) {
        if (stripos($filename, str_replace(".php", "", $k)) !== false) {
            return $v;
        }
    }
    return ucwords(str_replace([".php", "-", "_"], " ", $filename));
}

function getCategories($scripts) {
    $cats = [];
    foreach ($scripts as $s) {
        $cats[$s["category"]] = true;
    }
    return array_keys($cats);
}

function showBanner() {
    $v = getCurrentVersion();
    $bal = "?";
    try {
        $b = vernuable_balance();
        if ($b !== null) {
            $bal = "$" . number_format($b, 5);
        }
    } catch (Exception $e) {
        $bal = "n/a";
    }
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
        $cats = getCategories($scripts);

        echo "\n" . WHITE . BOLD . "  MAIN MENU\n" . RESET;
        echo GREY . "  ────────────────────────────────────────\n" . RESET;
        $i = 1;
        $map = [];
        foreach ($cats as $cat) {
            $count = count(array_filter($scripts, function ($s) use ($cat) {
                return $s["category"] === $cat;
            }));
            echo "  " . GREEN . "[$i]" . WHITE . "  " . ucwords(str_replace(["-", "_"], " ", $cat)) . GREY . " ($count scripts)\n" . RESET;
            $map[$i] = $cat;
            $i++;
        }
        echo "  " . GREEN . "[$i]" . WHITE . "  Check for Updates\n" . RESET;
        $upd = $i;
        $i++;
        echo "  " . GREEN . "[$i]" . WHITE . "  Vernuable balance / set API key\n" . RESET;
        $bal = $i;
        $i++;
        echo "  " . GREEN . "[0]" . WHITE . "  Exit\n" . RESET;
        echo "\n" . YELLOW . "  › " . RESET;
        $choice = trim(fgets(STDIN));

        if ($choice === "0" || strtolower($choice) === "q") {
            echo CYAN . "\n  Bye.\n" . RESET;
            exit(0);
        }
        if ((int)$choice === $upd) {
            checkForUpdates();
            continue;
        }
        if ((int)$choice === $bal) {
            clearScreen();
            showBanner();
            $key = vernuable_key();
            $masked = strlen($key) > 10 ? substr($key, 0, 6) . "…" . substr($key, -4) : $key;
            echo "\n  API key: $masked\n";
            $b = vernuable_balance();
            echo $b !== null ? GREEN . "  Balance: $" . number_format($b, 5) . "\n" . RESET : RED . "  Balance check failed\n" . RESET;
            echo WHITE . "\nPress Enter..." . RESET;
            fgets(STDIN);
            continue;
        }
        $n = (int)$choice;
        if (!isset($map[$n])) {
            continue;
        }
        categoryMenu($map[$n], $scripts);
    }
}

function categoryMenu($category, $allScripts) {
    $list = array_values(array_filter($allScripts, function ($s) use ($category) {
        return $s["category"] === $category;
    }));
    while (true) {
        clearScreen();
        showBanner();
        echo "\n" . WHITE . BOLD . "  " . strtoupper($category) . "\n" . RESET;
        echo GREY . "  ────────────────────────────────────────\n" . RESET;
        $i = 1;
        foreach ($list as $s) {
            echo "  " . GREEN . "[$i]" . WHITE . "  " . $s["basename"] . GREY . " — " . $s["description"] . "\n" . RESET;
            $i++;
        }
        echo "  " . GREEN . "[0]" . WHITE . "  Back\n" . RESET;
        echo "\n" . YELLOW . "  › " . RESET;
        $choice = trim(fgets(STDIN));
        if ($choice === "0") {
            return;
        }
        $idx = (int)$choice - 1;
        if ($idx < 0 || $idx >= count($list)) {
            continue;
        }
        $script = $list[$idx]["file"];
        echo CYAN . "\n  Running " . basename($script) . " …\n" . RESET;
        passthru("php " . escapeshellarg($script));
        echo WHITE . "\nPress Enter..." . RESET;
        fgets(STDIN);
    }
}

mainMenu();
