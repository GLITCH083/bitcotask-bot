#!/usr/bin/env php
<?php
/**
 * BitcoTasks.com — offerwall PTC via Vernuable bitcotask
 *
 * Accounts: configs/bitcotasks.com-config/accounts
 * Format: name|publisher_key|sub_id|proxy
 *
 * Claims: number, or 0 = unlimited
 *
 * @version 1.2.0
 */

error_reporting(0);
require_once dirname(__DIR__, 2) . "/functions/function.php";
require_once dirname(__DIR__, 2) . "/functions/vernuable.php";

define("HOST", "bitcotasks.com");
define("BASE", "https://bitcotasks.com");
define("MIN_GIF_BYTES", 800); // real motion GIF is almost never under 1KB

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
        return 0;
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

/**
 * Decode preload into raw GIF bytes. Handles data-URI, raw b64, URL.
 */
function extractGifFromPreload($preload, $proxy = null, $jar = null) {
    if (!is_string($preload) || $preload === "") {
        return null;
    }
    $preload = trim($preload);

    // URL → download
    if (preg_match('#^https?://#i', $preload) || preg_match('#^//#', $preload)) {
        $url = (strpos($preload, "//") === 0) ? "https:" . $preload : $preload;
        list($code, $bin) = httpRequest($url, "GET", null, [], $proxy, $jar);
        if ($code < 400 && is_string($bin) && strlen($bin) > 50) {
            return $bin;
        }
        return null;
    }

    // data:image/gif;base64,XXXX
    if (stripos($preload, "base64,") !== false) {
        $parts = explode("base64,", $preload, 2);
        $b64 = preg_replace('/\s+/', '', $parts[1]);
        $bin = base64_decode($b64, false);
        if ($bin !== false && strlen($bin) > 50) {
            return $bin;
        }
    }

    // pure base64 (GIF magic in b64 = R0lGOD)
    $clean = preg_replace('/\s+/', '', $preload);
    // strip accidental quotes
    $clean = trim($clean, "\"'");
    $bin = base64_decode($clean, false);
    if ($bin !== false && strlen($bin) > 50) {
        return $bin;
    }

    // already binary?
    if (substr($preload, 0, 3) === "GIF") {
        return $preload;
    }

    return null;
}

function isValidGif($bin) {
    if (!is_string($bin) || strlen($bin) < MIN_GIF_BYTES) {
        return false;
    }
    // GIF87a / GIF89a
    return substr($bin, 0, 3) === "GIF";
}

/**
 * POST captcha endpoint → JSON + GIF bytes
 */
function fetchCaptchaChallenge($capUrl, $proxy, $jar) {
    list($code, $jraw) = httpRequest(
        $capUrl,
        "POST",
        json_encode([
            "t" => (int)(microtime(true) * 1000),
            "r" => mt_rand() / mt_getrandmax(),
        ]),
        [
            "Content-Type: application/json",
            "X-Requested-With: XMLHttpRequest",
        ],
        $proxy,
        $jar
    );

    $j = json_decode($jraw, true);
    if (!is_array($j)) {
        return [null, null, "bad JSON HTTP $code: " . substr((string)$jraw, 0, 80)];
    }

    // try multiple possible fields
    $candidates = [];
    foreach (["preload", "image", "gif", "img", "data", "captcha", "body"] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) {
            $candidates[] = $j[$k];
        }
    }
    // nested
    if (isset($j["result"]) && is_array($j["result"])) {
        foreach (["preload", "image", "gif"] as $k) {
            if (!empty($j["result"][$k]) && is_string($j["result"][$k])) {
                $candidates[] = $j["result"][$k];
            }
        }
    }

    $gif = null;
    foreach ($candidates as $c) {
        $try = extractGifFromPreload($c, $proxy, $jar);
        if (isValidGif($try)) {
            $gif = $try;
            break;
        }
        // keep largest attempt even if under threshold (for error msg)
        if ($try && (!$gif || strlen($try) > strlen($gif))) {
            $gif = $try;
        }
    }

    return [$gif, $j, null];
}

/**
 * Submit solved x,y click to BitcoTasks captcha endpoint, then validate firewall.
 * Returns [offerPath, offerToken] or [null, null] on fail.
 */
function submitCaptchaAndValidate($capUrl, $meta, $x, $y, $key, $sub, $proxy, $jar, $html) {
    $cdata = $meta["cdata"] ?? $meta["h"] ?? null;
    $coords = json_encode([(int)$x, (int)$y]);
    $body = [
        "action" => "data",
        "x"      => (int)$x,
        "y"      => (int)$y,
        "coords" => $coords,
        "point"  => $coords,
    ];
    // some builds use obfuscated field names mirrored from response keys
    if (!empty($meta["fields"]) && is_array($meta["fields"])) {
        foreach ($meta["fields"] as $fk => $fv) {
            if (is_string($fk) && $fk !== "") {
                $body[$fk] = $fv;
            }
        }
    }

    $params = "action=data";
    if ($cdata) {
        $params .= "&cdata=" . urlencode($cdata);
    }
    $capBase = explode("?", $capUrl)[0];

    list($sc, $sraw) = httpRequest($capBase . "?" . $params, "POST", $body, [
        "Content-Type: application/x-www-form-urlencoded",
        "X-Requested-With: XMLHttpRequest",
    ], $proxy, $jar);

    $sj = json_decode($sraw, true) ?: [];
    themeBox("📤  SUBMIT CLICK", [
        "HTTP"   => (string)$sc,
        "Coords" => GREEN . "$x, $y" . RESET,
        "Resp"   => substr(is_string($sraw) ? $sraw : json_encode($sj), 0, 40),
    ]);

    // token for firewall validate
    $token = $meta["token"] ?? $sj["token"] ?? null;
    if (!$token) {
        foreach (array_merge($meta, $sj) as $v) {
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

    if (!$offerPath && preg_match('#(/offerwall/[^"\'\s]+)#i', (string)$vraw . (string)$html, $om)) {
        $offerPath = $om[1];
    }

    themeBox("🔓  VALIDATE", [
        "HTTP"     => (string)$cv,
        "Redirect" => $offerPath ? GREEN . substr($offerPath, 0, 40) . RESET : RED . "none" . RESET,
        "Resp"     => substr((string)$vraw, 0, 40),
    ]);

    if (!$offerPath) {
        return [null, null];
    }
    $parts = explode("/", rtrim($offerPath, "/"));
    $offerToken = end($parts);
    return [$offerPath, $offerToken];
}

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

    $vkey = vernuable_key();
    if ($vkey === "") {
        logFail("Vernuable", "API key empty — set it from main menu");
        return;
    }
    $bal = vernuable_balance();
    themeBox("💰  VERNUABLE", [
        "Balance" => $bal !== null ? GREEN . "$" . number_format($bal, 5) . RESET : RED . "n/a" . RESET,
        "Key"     => substr($vkey, 0, 6) . "…" . substr($vkey, -4),
    ]);
    if ($bal !== null && $bal <= 0) {
        logFail("Vernuable", "ZERO_BALANCE — deposit on vernuable.my.id");
        return;
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
        themeBox("🛡️  FIREWALL", [
            "Status"  => YELLOW . "Captcha required" . RESET,
            "Cap URL" => GREY . substr($capUrl, 0, 45) . RESET,
        ]);

        $solved = null;
        $meta   = [];
        $maxTries = 4;

        for ($try = 1; $try <= $maxTries; $try++) {
            list($gif, $j, $err) = fetchCaptchaChallenge($capUrl, $proxy, $jar);
            if ($err) {
                themeBox("📥  CAPTCHA FETCH", [
                    "Try"    => "$try/$maxTries",
                    "Status" => RED . "Failed" . RESET,
                    "Detail" => substr($err, 0, 40),
                ]);
                sleep(2);
                continue;
            }

            $keys = is_array($j) ? implode(",", array_slice(array_keys($j), 0, 8)) : "-";
            $gsize = $gif ? strlen($gif) : 0;
            $magic = ($gif && strlen($gif) >= 6) ? substr($gif, 0, 6) : "-";

            themeBox("📥  CAPTCHA FETCH", [
                "Try"    => "$try/$maxTries",
                "Keys"   => substr($keys, 0, 42),
                "GIF"    => $gsize . " bytes",
                "Magic"  => $magic,
            ]);

            if (!isValidGif($gif)) {
                themeBox("⚠️  GIF INVALID", [
                    "Size"   => (string)$gsize,
                    "Need"   => ">= " . MIN_GIF_BYTES . " + GIF header",
                    "Action" => "retry fetch",
                ]);
                sleep(2);
                continue;
            }

            themeBox("🤖  VERNUABLE", [
                "Action" => YELLOW . "Solving bitcotask…" . RESET,
                "GIF"    => $gsize . " bytes",
                "Try"    => "$try/$maxTries",
            ]);

            $solved = vernuable_solve_bitcotask($gif, 180, 2);

            if ($solved && isset($solved["x"])) {
                break;
            }

            $detail = is_array($solved) && isset($solved["error"])
                ? $solved["error"]
                : "solve failed";

            themeBox("❌  VERNUABLE", [
                "Status" => RED . "Failed" . RESET,
                "Detail" => substr($detail, 0, 42),
                "Try"    => "$try/$maxTries",
            ]);

            // fresh challenge on unsolvable
            sleep(2);
            $solved = null;
            $meta = $j ?: [];
        }

        if (!$solved || !isset($solved["x"])) {
            logFail("Vernuable", "all $maxTries tries failed");
            return;
        }

        $meta = $j ?: $meta;
        $x = (int)round($solved["x"]);
        $y = (int)round($solved["y"]);

        themeBox("🔐  CAPTCHA SOLVED", [
            "Click"  => GREEN . "$x, $y" . RESET,
            "Index"  => isset($solved["index"]) ? (string)$solved["index"] : "-",
            "Action" => YELLOW . "Submitting click…" . RESET,
        ]);

        list($offerPath, $offerToken) = submitCaptchaAndValidate(
            $capUrl, $meta, $x, $y, $key, $sub, $proxy, $jar, $html
        );

        if (!$offerPath) {
            logFail("Validate", "no offerwall redirect after click");
            return;
        }
    } else {
        if (preg_match('#(/offerwall/[^"\'\s]+)#i', $html, $om)) {
            $offerPath = $om[1];
            $parts = explode("/", rtrim($offerPath, "/"));
            $offerToken = end($parts);
            themeBox("🛡️  FIREWALL", [
                "Status" => GREEN . "Already clear" . RESET,
                "Path"   => substr($offerPath, 0, 45),
            ]);
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

    themeBox("📋  OFFERWALL", [
        "URL"   => GREY . substr($offerUrl, 0, 45) . RESET,
        "Token" => $offerToken ? substr($offerToken, 0, 16) . "…" : "-",
    ]);

    $ok = 0;
    $fail = 0;
    $i = 0;
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
        $sj = json_decode($sraw, true) ?: [];
        $items = $sj["items"] ?? $sj["data"] ?? [];

        if (empty($items) || !is_array($items)) {
            $emptyStreak++;
            themeBox("📭  PTC", [
                "Status" => YELLOW . "No items" . RESET,
                "Streak" => (string)$emptyStreak,
            ]);
            if ($emptyStreak >= 3) {
                themeBox("⏹  STOP", ["Reason" => "no more ads"]);
                break;
            }
            sleep(5);
            continue;
        }
        $emptyStreak = 0;

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
        themeBox("🎯  CLAIM $label", [
            "Reward" => YELLOW . ($item["reward"] ?? "?") . RESET,
            "Dur"    => ($item["duration"] ?? "?") . "s",
            "Hash"   => substr((string)($item["hash"] ?? ""), 0, 16),
        ]);

        list($ci, $iraw) = httpRequest($offerUrl, "POST", [
            "token"  => $offerToken,
            "action" => "init_transaction",
            "hash"   => $item["hash"] ?? "",
            "sid"    => $item["sid"] ?? $sub,
            "key"    => $item["key"] ?? $key,
            "type"   => $item["type"] ?? "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $ij = json_decode($iraw, true) ?: [];
        $lead = $ij["offer"] ?? $ij["url"] ?? $ij["link"] ?? "";
        if (!$lead) {
            $fail++;
            logFail("init_transaction", substr((string)$iraw, 0, 100));
            if ($fail >= 8) {
                themeBox("⏹  STOP", ["Reason" => "too many fails"]);
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
            $fail = 0;
            themeBox("✅  CLAIM OK", [
                "Reward" => GREEN . ($item["reward"] ?? "") . RESET,
                "Msg"    => substr((string)($pj["message"] ?? $pj["msg"] ?? "OK"), 0, 40),
                "Total"  => GREEN . (string)$ok . RESET,
            ]);
        } else {
            $fail++;
            logFail("Lead", substr((string)$praw, 0, 120));
            if ($fail >= 8) {
                themeBox("⏹  STOP", ["Reason" => "too many fails"]);
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
