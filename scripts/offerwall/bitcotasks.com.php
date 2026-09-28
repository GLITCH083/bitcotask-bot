#!/usr/bin/env php
<?php
/**
 * BitcoTasks.com — offerwall PTC via Vernuable bitcotask
 *
 * Accounts stored like Buxads (no accounts.example.json):
 *   configs/bitcotasks.com-config/accounts
 * Format per line: name|publisher_key|sub_id|proxy
 *   proxy optional: host:port:user:pass
 *
 * @version 1.0.0
 */

error_reporting(0);
require_once dirname(__DIR__, 2) . "/functions/function.php";
require_once dirname(__DIR__, 2) . "/functions/vernuable.php";

define("HOST", "bitcotasks.com");
define("BASE", "https://bitcotasks.com");

enableCtrlC();

$UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36";

function accountsFile() {
    return configPath(HOST, "accounts");
}

function loadAccounts() {
    $f = accountsFile();
    if (!file_exists($f)) {
        return [];
    }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") {
            continue;
        }
        $p = explode("|", $line);
        $out[] = [
            "name" => $p[0] ?? "acc",
            "key" => $p[1] ?? "",
            "sub_id" => $p[2] ?? "",
            "proxy" => $p[3] ?? null,
        ];
    }
    return $out;
}

function saveAccounts($list) {
    $lines = ["# name|publisher_key|sub_id|proxy"];
    foreach ($list as $a) {
        $lines[] = implode("|", [
            $a["name"],
            $a["key"],
            $a["sub_id"],
            $a["proxy"] ?? "",
        ]);
    }
    file_put_contents(accountsFile(), implode("\n", $lines) . "\n");
}

function addAccountInteractive() {
    echo WHITE . "  Name: " . RESET;
    $name = trim(fgets(STDIN));
    echo WHITE . "  Publisher key: " . RESET;
    $key = trim(fgets(STDIN));
    echo WHITE . "  sub_id: " . RESET;
    $sub = trim(fgets(STDIN));
    echo WHITE . "  Proxy (host:port:user:pass or empty): " . RESET;
    $proxy = trim(fgets(STDIN));
    if ($key === "" || $sub === "") {
        echo RED . "  key + sub_id required\n" . RESET;
        return;
    }
    $list = loadAccounts();
    $list[] = ["name" => $name ?: "acc", "key" => $key, "sub_id" => $sub, "proxy" => $proxy ?: null];
    saveAccounts($list);
    echo GREEN . "  Saved.\n" . RESET;
}

function httpRequest($url, $method = "GET", $data = null, $headers = [], $proxy = null, $cookieJar = null) {
    global $UA;
    $ch = curl_init($url);
    $h = array_merge([
        "User-Agent: $UA",
        "Accept: application/json, text/javascript, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.9",
        "Origin: " . BASE,
        "Referer: " . BASE . "/",
    ], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($cookieJar) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($proxy) {
        $px = parseProxyString($proxy);
        if ($px) {
            $opts[CURLOPT_PROXY] = $px;
        }
    }
    if (strtoupper($method) === "POST") {
        $opts[CURLOPT_POST] = true;
        if (is_array($data)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        } elseif (is_string($data)) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    return [$code, $body];
}

function makeNotifCookies() {
    $dt = new DateTime("now", new DateTimeZone("GMT"));
    $dt->modify("+30 minutes");
    $exp = $dt->format("D, d M Y H:i:s") . " GMT";
    $randstr = substr(md5($exp), 2, 9);
    return [
        "_bitco_notifad" => "ad_value_" . $randstr,
        "_bitco_notifad_expire" => "expires=" . $exp,
    ];
}

function runAccount($acc, $claims = 5) {
    $name = $acc["name"];
    $key = $acc["key"];
    $sub = $acc["sub_id"];
    $proxy = $acc["proxy"] ?? null;
    $jar = configPath(HOST, "cookie_" . preg_replace("/[^a-z0-9]/i", "_", $name) . ".txt");

    themeBox("👤  ACCOUNT", [
        "Name" => GREEN . $name . RESET,
        "sub_id" => $sub,
        "Proxy" => $proxy ? CYAN . substr($proxy, 0, 40) . RESET : GREY . "DIRECT" . RESET,
    ]);

    list($code, $html) = httpRequest(
        BASE . "/firewall.php?key=" . urlencode($key) . "&sub_id=" . urlencode($sub),
        "GET",
        null,
        [],
        $proxy,
        $jar
    );
    if ($code >= 400) {
        logFail("Firewall", "HTTP $code");
        return;
    }

    $capUrl = null;
    if (preg_match('#/captcha2/([a-f0-9]{32,})\.js\?action=captcha#', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    }

    $offerPath = null;
    $offerToken = null;

    if ($capUrl) {
        list($c2, $jraw) = httpRequest($capUrl, "POST", json_encode(["t" => (int)(microtime(true) * 1000), "r" => mt_rand() / mt_getrandmax()]), [
            "Content-Type: application/json",
            "X-Requested-With: XMLHttpRequest",
        ], $proxy, $jar);
        $j = json_decode($jraw, true) ?: [];
        $preload = $j["preload"] ?? "";
        $gif = null;
        if (strpos($preload, "R0lGOD") === 0) {
            $gif = base64_decode($preload);
        } elseif (strpos($preload, "base64") !== false && strpos($preload, ",") !== false) {
            $gif = base64_decode(explode(",", $preload, 2)[1]);
        } else {
            $gif = @base64_decode($preload);
        }
        if (!$gif) {
            logFail("Captcha", "no GIF");
            return;
        }
        $solved = vernuable_solve_bitcotask($gif);
        if (!$solved || !isset($solved["x"])) {
            logFail("Vernuable", "solve failed");
            return;
        }
        $x = (int)$solved["x"];
        $y = (int)$solved["y"];
        themeBox("🔐  CAPTCHA", ["Click" => GREEN . "$x,$y" . RESET]);

        $cdata = $j["cdata"] ?? $j["h"] ?? null;
        $body = ["action" => "data", "x" => $x, "y" => $y, "coords" => json_encode([$x, $y]), "point" => json_encode([$x, $y])];
        $params = "action=data";
        if ($cdata) {
            $params .= "&cdata=" . urlencode($cdata);
        }
        httpRequest(explode("?", $capUrl)[0] . "?" . $params, "POST", $body, [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Requested-With: XMLHttpRequest",
        ], $proxy, $jar);

        $token = null;
        foreach ($j as $v) {
            if (is_string($v) && strlen($v) >= 40 && preg_match('/^[0-9a-f]+$/i', substr($v, 0, 40))) {
                $token = $v;
                break;
            }
        }
        if (!$token) {
            $token = $j["token"] ?? "";
        }

        list($cv, $vraw) = httpRequest(
            BASE . "/firewall.php?key=" . urlencode($key) . "&sub_id=" . urlencode($sub),
            "POST",
            ["action" => "validate", "token" => $token, "UEjS" => $token],
            ["X-Requested-With: XMLHttpRequest"],
            $proxy,
            $jar
        );
        $vj = json_decode($vraw, true) ?: [];
        $offerPath = $vj["redirect"] ?? null;
        if (!$offerPath) {
            logFail("Validate", substr($vraw, 0, 120));
            return;
        }
        $parts = explode("/", rtrim($offerPath, "/"));
        $offerToken = end($parts);
    } else {
        if (stripos($html, "offerwall") === false) {
            logFail("Firewall", "no captcha / no offerwall");
            return;
        }
        echo YELLOW . "  Firewall already clear\n" . RESET;
    }

    if (!$offerPath) {
        logFail("Offerwall", "no path");
        return;
    }
    $offerUrl = (strpos($offerPath, "http") === 0) ? $offerPath : rtrim(BASE, "/") . "/" . ltrim($offerPath, "/");
    list($co, $ohtml) = httpRequest($offerUrl, "GET", null, [], $proxy, $jar);
    if (preg_match('/token["\']?\s*[:=]\s*["\']([a-f0-9]{32,})["\']/i', $ohtml, $tm)) {
        $offerToken = $tm[1];
    }

    $ok = 0;
    $fail = 0;
    for ($i = 1; $i <= $claims; $i++) {
        list($cs, $sraw) = httpRequest($offerUrl, "POST", [
            "token" => $offerToken,
            "action" => "switch_cat",
            "type" => "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $sj = json_decode($sraw, true) ?: [];
        $items = $sj["items"] ?? [];
        if (empty($items)) {
            echo YELLOW . "  No PTC items\n" . RESET;
            break;
        }
        usort($items, function ($a, $b) {
            return (float)($b["reward"] ?? 0) <=> (float)($a["reward"] ?? 0);
        });
        $item = null;
        foreach ($items as $it) {
            if ((float)($it["duration"] ?? 99) <= 25) {
                $item = $it;
                break;
            }
        }
        if (!$item) {
            $item = $items[0];
        }

        echo CYAN . "  Claim #$i reward=" . ($item["reward"] ?? "?") . " dur=" . ($item["duration"] ?? "?") . "\n" . RESET;

        list($ci, $iraw) = httpRequest($offerUrl, "POST", [
            "token" => $offerToken,
            "action" => "init_transaction",
            "hash" => $item["hash"],
            "sid" => $item["sid"] ?? $sub,
            "key" => $item["key"] ?? $key,
            "type" => $item["type"] ?? "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $ij = json_decode($iraw, true) ?: [];
        $lead = $ij["offer"] ?? "";
        if (!$lead) {
            $fail++;
            logFail("init_transaction", substr($iraw, 0, 100));
            continue;
        }
        if (strpos($lead, "http") !== 0) {
            $lead = rtrim(BASE, "/") . "/" . ltrim($lead, "/");
        }

        $notif = makeNotifCookies();
        httpRequest($lead, "POST", ["action" => "start_view"], [
            "X-Requested-With: XMLHttpRequest",
            "Cookie: _bitco_notifad=" . $notif["_bitco_notifad"] . "; _bitco_notifad_expire=" . $notif["_bitco_notifad_expire"],
        ], $proxy, $jar);

        $wait = max((float)($item["duration"] ?? 2), 2) + (mt_rand(3, 12) / 10);
        logWait("viewing", (int)$wait);
        sleep((int)$wait);

        $tokenLead = basename(rtrim($lead, "/"));
        list($cp, $praw) = httpRequest(BASE . "/system/ajax.php", "POST", [
            "hash" => $item["hash"],
            "sub_id" => $item["sid"] ?? $sub,
            "key" => $item["key"] ?? $key,
            "token" => $tokenLead,
            "action" => "proccessLead",
            "atxrN" => "",
        ], [
            "X-Requested-With: XMLHttpRequest",
            "Cookie: _bitco_notifad=" . $notif["_bitco_notifad"] . "; _bitco_notifad_expire=" . $notif["_bitco_notifad_expire"],
        ], $proxy, $jar);
        $pj = json_decode($praw, true) ?: [];
        $success = (isset($pj["status"]) && $pj["status"] == 200) || stripos((string)($pj["message"] ?? ""), "SUCCESS") !== false;
        if ($success) {
            $ok++;
            themeBox("✅  CLAIM", [
                "Reward" => GREEN . ($item["reward"] ?? "") . RESET,
                "Msg" => substr((string)($pj["message"] ?? "OK"), 0, 40),
            ]);
        } else {
            $fail++;
            logFail("Lead", substr($praw, 0, 120));
        }
        sleep(mt_rand(2, 5));
    }

    themeBox("📊  SUMMARY", [
        "Account" => $name,
        "OK" => GREEN . $ok . RESET,
        "Fail" => RED . $fail . RESET,
    ]);
}

while (true) {
    clearScreen();
    echo "\n";
    echo CYAN . "╔══════════════════════════════════════════════════════════╗\n" . RESET;
    echo CYAN . "║" . WHITE . BOLD . "  BitcoTasks.com · Vernuable bitcotask                   " . CYAN . "║\n" . RESET;
    echo CYAN . "╚══════════════════════════════════════════════════════════╝\n" . RESET;

    $accs = loadAccounts();
    echo "\n  Accounts: " . count($accs) . "\n";
    echo GREEN . "  [1]" . WHITE . "  Run all accounts\n" . RESET;
    echo GREEN . "  [2]" . WHITE . "  Run single account\n" . RESET;
    echo GREEN . "  [3]" . WHITE . "  Add account\n" . RESET;
    echo GREEN . "  [4]" . WHITE . "  List accounts\n" . RESET;
    echo GREEN . "  [0]" . WHITE . "  Back\n" . RESET;
    echo "\n" . YELLOW . "  › " . RESET;
    $c = trim(fgets(STDIN));

    if ($c === "0") {
        exit(0);
    }
    if ($c === "3") {
        addAccountInteractive();
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "4") {
        foreach ($accs as $i => $a) {
            echo "  " . ($i + 1) . ") " . $a["name"] . " sub=" . $a["sub_id"] . " proxy=" . ($a["proxy"] ?: "DIRECT") . "\n";
        }
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "1") {
        echo WHITE . "  Claims per account [5]: " . RESET;
        $n = trim(fgets(STDIN));
        $claims = $n !== "" ? max(1, (int)$n) : 5;
        foreach ($accs as $a) {
            runAccount($a, $claims);
        }
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "2") {
        if (empty($accs)) {
            echo RED . "  No accounts — use [3] Add\n" . RESET;
            fgets(STDIN);
            continue;
        }
        foreach ($accs as $i => $a) {
            echo "  " . ($i + 1) . ") " . $a["name"] . "\n";
        }
        echo WHITE . "  #: " . RESET;
        $ix = (int)trim(fgets(STDIN)) - 1;
        if (!isset($accs[$ix])) {
            continue;
        }
        echo WHITE . "  Claims [5]: " . RESET;
        $n = trim(fgets(STDIN));
        $claims = $n !== "" ? max(1, (int)$n) : 5;
        runAccount($accs[$ix], $claims);
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
}
