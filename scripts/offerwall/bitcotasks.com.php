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
 * Claims: enter number, or 0 / unlimited for endless loop until no ads.
 *
 * @version 1.1.0
 */

error_reporting(0);
require_once dirname(__DIR__, 2) . "/functions/function.php";
require_once dirname(__DIR__, 2) . "/functions/vernuable.php";

define("HOST", "bitcotasks.com");
define("BASE", "https://bitcotasks.com");

enableCtrlC();

$UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36";

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
            "name"   => $p[0] ?? "acc",
            "key"    => $p[1] ?? "",
            "sub_id" => $p[2] ?? "",
            "proxy"  => !empty($p[3]) ? $p[3] : null,
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

function askClaims() {
    echo WHITE . "  Claims per account (number, or 0 = unlimited): " . RESET;
    $n = trim(fgets(STDIN));
    if ($n === "" || strtolower($n) === "unlimited" || $n === "u") {
        return 0; // unlimited
    }
    $v = (int)$n;
    return $v < 0 ? 0 : $v;
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
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($cookieJar) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
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
    $randstr = substr(md5($exp . mt_rand()), 2, 9);
    return [
        "_bitco_notifad"        => "ad_value_" . $randstr,
        "_bitco_notifad_expire" => "expires=" . $exp,
    ];
}

function extractGifFromPreload($preload) {
    if (!is_string($preload) || $preload === "") {
        return null;
    }
    // data:image/gif;base64,XXXX
    if (stripos($preload, "base64,") !== false) {
        $parts = explode("base64,", $preload, 2);
        $bin = base64_decode(trim($parts[1]), true);
        if ($bin !== false && substr($bin, 0, 3) === "GIF") {
            return $bin;
        }
    }
    // raw base64 starting with GIF magic in b64 = R0lGOD
    if (strpos($preload, "R0lGOD") === 0 || strpos($preload, "R0lGOD") !== false) {
        // strip whitespace / data url leftovers
        $clean = preg_replace('/\s+/', '', $preload);
        if (stripos($clean, "base64,") !== false) {
            $clean = explode("base64,", $clean, 2)[1];
        }
        $bin = base64_decode($clean, true);
        if ($bin !== false && substr($bin, 0, 3) === "GIF") {
            return $bin;
        }
    }
    $bin = base64_decode($preload, true);
    if ($bin !== false && strlen($bin) > 50) {
        return $bin;
    }
    return null;
}

/**
 * @param array $acc
 * @param int   $claims  0 = unlimited
 */
function runAccount($acc, $claims = 5) {
    $name  = $acc["name"];
    $key   = $acc["key"];
    $sub   = $acc["sub_id"];
    $proxy = $acc["proxy"] ?? null;
    $jar   = configPath(HOST, "cookie_" . preg_replace("/[^a-z0-9]/i", "_", $name) . ".txt");

    $claimsLabel = ($claims === 0) ? "UNLIMITED" : (string)$claims;

    themeBox("👤  ACCOUNT", [
        "Name"   => GREEN . $name . RESET,
        "sub_id" => $sub,
        "Proxy"  => $proxy ? CYAN . substr($proxy, 0, 40) . RESET : GREY . "DIRECT" . RESET,
        "Claims" => YELLOW . $claimsLabel . RESET,
    ]);

    // Ensure Vernuable key exists before spending time on firewall
    $vkey = vernuable_key();
    if ($vkey === "") {
        logFail("Vernuable", "API key empty — set it from main menu");
        return;
    }
    $bal = vernuable_balance();
    if ($bal !== null) {
        echo GREY . "  Vernuable balance: $" . number_format($bal, 5) . "\n" . RESET;
        if ($bal <= 0) {
            logFail("Vernuable", "ZERO_BALANCE — deposit on vernuable.my.id");
            return;
        }
    }

    list($code, $html) = httpRequest(
        BASE . "/firewall.php?key=" . urlencode($key) . "&sub_id=" . urlencode($sub),
        "GET",
        null,
        [],
        $proxy,
        $jar
    );
    if ($code >= 400 || $html === false || $html === "") {
        logFail("Firewall", "HTTP $code");
        return;
    }

    $capUrl     = null;
    $offerPath  = null;
    $offerToken = null;

    if (preg_match('#(/captcha2/[a-f0-9]{32,}\.js)\?action=captcha#i', $html, $m)) {
        $capUrl = BASE . $m[1] . "?action=captcha";
    } elseif (preg_match('#captcha2/([a-f0-9]{32,})\.js#i', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    }

    if ($capUrl) {
        echo GREY . "  Captcha URL found\n" . RESET;
        list($c2, $jraw) = httpRequest(
            $capUrl,
            "POST",
            json_encode(["t" => (int)(microtime(true) * 1000), "r" => mt_rand() / mt_getrandmax()]),
            [
                "Content-Type: application/json",
                "X-Requested-With: XMLHttpRequest",
            ],
            $proxy,
            $jar
        );
        $j = json_decode($jraw, true) ?: [];
        $preload = $j["preload"] ?? $j["image"] ?? $j["gif"] ?? "";
        $gif = extractGifFromPreload($preload);

        if (!$gif) {
            // debug snippet
            $snip = is_string($preload) ? substr($preload, 0, 40) : gettype($preload);
            logFail("Captcha", "no GIF (preload starts: $snip)");
            return;
        }
        echo GREY . "  GIF size: " . strlen($gif) . " bytes — solving via Vernuable…\n" . RESET;

        $solved = vernuable_solve_bitcotask($gif, 180, 2);

        if (!$solved || !isset($solved["x"])) {
            $detail = is_array($solved) && isset($solved["error"])
                ? $solved["error"]
                : "solve failed";
            logFail("Vernuable", $detail);
            return;
        }

        $x = (int)round($solved["x"]);
        $y = (int)round($solved["y"]);
        themeBox("🔐  CAPTCHA", [
            "Click" => GREEN . "$x, $y" . RESET,
            "Index" => isset($solved["index"]) ? (string)$solved["index"] : "-",
        ]);

        $cdata  = $j["cdata"] ?? $j["h"] ?? null;
        $body   = [
            "action" => "data",
            "x"      => $x,
            "y"      => $y,
            "coords" => json_encode([$x, $y]),
            "point"  => json_encode([$x, $y]),
        ];
        $params = "action=data";
        if ($cdata) {
            $params .= "&cdata=" . urlencode($cdata);
        }
        $capBase = explode("?", $capUrl)[0];
        httpRequest($capBase . "?" . $params, "POST", $body, [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Requested-With: XMLHttpRequest",
        ], $proxy, $jar);

        $token = $j["token"] ?? null;
        if (!$token) {
            foreach ($j as $v) {
                if (is_string($v) && strlen($v) >= 32 && preg_match('/^[0-9a-f]+$/i', $v)) {
                    $token = $v;
                    break;
                }
            }
        }
        if (!$token) {
            $token = "";
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
        $offerPath = $vj["redirect"] ?? $vj["url"] ?? null;
        if (!$offerPath) {
            // maybe already redirected via cookie — try parse HTML for offerwall link
            if (preg_match('#(/offerwall/[^"\'\s]+)#i', $vraw . $html, $om)) {
                $offerPath = $om[1];
            }
        }
        if (!$offerPath) {
            logFail("Validate", substr((string)$vraw, 0, 120));
            return;
        }
        $parts = explode("/", rtrim($offerPath, "/"));
        $offerToken = end($parts);
    } else {
        // Already past captcha?
        if (preg_match('#(/offerwall/[^"\'\s]+)#i', $html, $om)) {
            $offerPath = $om[1];
            $parts = explode("/", rtrim($offerPath, "/"));
            $offerToken = end($parts);
            echo YELLOW . "  Firewall already clear\n" . RESET;
        } else {
            logFail("Firewall", "no captcha / no offerwall path");
            return;
        }
    }

    if (!$offerPath) {
        logFail("Offerwall", "no path");
        return;
    }

    $offerUrl = (strpos($offerPath, "http") === 0)
        ? $offerPath
        : rtrim(BASE, "/") . "/" . ltrim($offerPath, "/");

    list($co, $ohtml) = httpRequest($offerUrl, "GET", null, [], $proxy, $jar);
    if (preg_match('/token["\']?\s*[:=]\s*["\']([a-f0-9]{32,})["\']/i', $ohtml, $tm)) {
        $offerToken = $tm[1];
    }

    $ok   = 0;
    $fail = 0;
    $i    = 0;
    $emptyStreak = 0;

    while (true) {
        $i++;
        if ($claims > 0 && $i > $claims) {
            break;
        }

        list($cs, $sraw) = httpRequest($offerUrl, "POST", [
            "token"  => $offerToken,
            "action" => "switch_cat",
            "type"   => "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $sj    = json_decode($sraw, true) ?: [];
        $items = $sj["items"] ?? $sj["data"] ?? [];

        if (empty($items) || !is_array($items)) {
            $emptyStreak++;
            echo YELLOW . "  No PTC items (streak $emptyStreak)\n" . RESET;
            if ($emptyStreak >= 3) {
                echo YELLOW . "  Stopping — no more ads\n" . RESET;
                break;
            }
            sleep(5);
            continue;
        }
        $emptyStreak = 0;

        // smart pick: highest reward with duration <= 25s, else best reward
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

        $label = ($claims === 0) ? "#$i (∞)" : "#$i/$claims";
        echo CYAN . "  Claim $label reward=" . ($item["reward"] ?? "?")
            . " dur=" . ($item["duration"] ?? "?") . "\n" . RESET;

        list($ci, $iraw) = httpRequest($offerUrl, "POST", [
            "token"  => $offerToken,
            "action" => "init_transaction",
            "hash"   => $item["hash"] ?? "",
            "sid"    => $item["sid"] ?? $sub,
            "key"    => $item["key"] ?? $key,
            "type"   => $item["type"] ?? "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $ij   = json_decode($iraw, true) ?: [];
        $lead = $ij["offer"] ?? $ij["url"] ?? $ij["link"] ?? "";
        if (!$lead) {
            $fail++;
            logFail("init_transaction", substr((string)$iraw, 0, 100));
            if ($fail >= 8) {
                echo RED . "  Too many fails — stop\n" . RESET;
                break;
            }
            sleep(3);
            continue;
        }
        if (strpos($lead, "http") !== 0) {
            $lead = rtrim(BASE, "/") . "/" . ltrim($lead, "/");
        }

        $notif = makeNotifCookies();
        $cookieHdr = "_bitco_notifad=" . $notif["_bitco_notifad"]
            . "; _bitco_notifad_expire=" . $notif["_bitco_notifad_expire"];

        httpRequest($lead, "POST", ["action" => "start_view"], [
            "X-Requested-With: XMLHttpRequest",
            "Cookie: " . $cookieHdr,
        ], $proxy, $jar);

        $wait = max((float)($item["duration"] ?? 2), 2) + (mt_rand(3, 12) / 10);
        logWait("viewing", (int)$wait);
        sleep((int)$wait);

        $tokenLead = basename(parse_url($lead, PHP_URL_PATH) ?: rtrim($lead, "/"));
        list($cp, $praw) = httpRequest(BASE . "/system/ajax.php", "POST", [
            "hash"   => $item["hash"] ?? "",
            "sub_id" => $item["sid"] ?? $sub,
            "key"    => $item["key"] ?? $key,
            "token"  => $tokenLead,
            "action" => "proccessLead",
            "atxrN"  => "",
        ], [
            "X-Requested-With: XMLHttpRequest",
            "Cookie: " . $cookieHdr,
        ], $proxy, $jar);

        $pj = json_decode($praw, true) ?: [];
        $success = (isset($pj["status"]) && (int)$pj["status"] === 200)
            || stripos((string)($pj["message"] ?? ""), "SUCCESS") !== false
            || stripos((string)($pj["msg"] ?? ""), "SUCCESS") !== false;

        if ($success) {
            $ok++;
            $fail = 0; // reset fail streak on success
            themeBox("✅  CLAIM", [
                "Reward" => GREEN . ($item["reward"] ?? "") . RESET,
                "Msg"    => substr((string)($pj["message"] ?? $pj["msg"] ?? "OK"), 0, 40),
                "Total"  => GREEN . (string)$ok . RESET,
            ]);
        } else {
            $fail++;
            logFail("Lead", substr((string)$praw, 0, 120));
            if ($fail >= 8) {
                echo RED . "  Too many fails — stop\n" . RESET;
                break;
            }
        }
        sleep(mt_rand(2, 5));
    }

    themeBox("📊  SUMMARY", [
        "Account" => $name,
        "OK"      => GREEN . $ok . RESET,
        "Fail"    => RED . $fail . RESET,
        "Mode"    => $claims === 0 ? YELLOW . "unlimited" . RESET : (string)$claims,
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
            echo "  " . ($i + 1) . ") " . $a["name"] . " sub=" . $a["sub_id"]
                . " proxy=" . ($a["proxy"] ?: "DIRECT") . "\n";
        }
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "1") {
        if (empty($accs)) {
            echo RED . "  No accounts — use [3] Add\n" . RESET;
            fgets(STDIN);
            continue;
        }
        $claims = askClaims();
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
        $claims = askClaims();
        runAccount($accs[$ix], $claims);
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
}
