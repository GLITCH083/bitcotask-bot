#!/usr/bin/env php
<?php
/**
 * BitcoTasks.com — offerwall PTC via Vernuable bitcotask
 *
 * @version 1.3.0
 */

error_reporting(0);
require_once dirname(__DIR__, 2) . "/functions/function.php";
require_once dirname(__DIR__, 2) . "/functions/vernuable.php";

define("HOST", "bitcotasks.com");
define("BASE", "https://bitcotasks.com");
define("MIN_GIF_BYTES", 800);

enableCtrlC();

$UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36";

/* ── continuous single status box ─────────────────────────── */
$_LOG_OPEN = false;

function logStart($title) {
    global $_LOG_OPEN;
    if ($_LOG_OPEN) {
        themeClose();
    }
    themeOpen($title);
    $_LOG_OPEN = true;
}

function logLine($label, $value) {
    global $_LOG_OPEN;
    if (!$_LOG_OPEN) {
        themeOpen("STATUS");
        $_LOG_OPEN = true;
    }
    themeRow($label, $value);
}

function logEnd() {
    global $_LOG_OPEN;
    if ($_LOG_OPEN) {
        themeClose();
        $_LOG_OPEN = false;
    }
}

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

function extractGifFromPreload($preload, $proxy = null, $jar = null) {
    if (!is_string($preload) || $preload === "") {
        return null;
    }
    $preload = trim($preload);
    if (preg_match('#^https?://#i', $preload) || preg_match('#^//#', $preload)) {
        $url = (strpos($preload, "//") === 0) ? "https:" . $preload : $preload;
        list($code, $bin) = httpRequest($url, "GET", null, [], $proxy, $jar);
        if ($code < 400 && is_string($bin) && strlen($bin) > 50) {
            return $bin;
        }
        return null;
    }
    if (stripos($preload, "base64,") !== false) {
        $parts = explode("base64,", $preload, 2);
        $bin = base64_decode(preg_replace('/\s+/', '', $parts[1]), false);
        if ($bin !== false && strlen($bin) > 50) {
            return $bin;
        }
    }
    $clean = trim(preg_replace('/\s+/', '', $preload), "\"'");
    $bin = base64_decode($clean, false);
    if ($bin !== false && strlen($bin) > 50) {
        return $bin;
    }
    if (substr($preload, 0, 3) === "GIF") {
        return $preload;
    }
    return null;
}

function isValidGif($bin) {
    return is_string($bin) && strlen($bin) >= MIN_GIF_BYTES && substr($bin, 0, 3) === "GIF";
}

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
        return [null, null, "bad JSON HTTP $code"];
    }
    $candidates = [];
    foreach (["preload", "image", "gif", "img", "data", "captcha", "body"] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) {
            $candidates[] = $j[$k];
        }
    }
    $gif = null;
    foreach ($candidates as $c) {
        $try = extractGifFromPreload($c, $proxy, $jar);
        if (isValidGif($try)) {
            $gif = $try;
            break;
        }
        if ($try && (!$gif || strlen($try) > strlen($gif))) {
            $gif = $try;
        }
    }
    return [$gif, $j, null];
}

/**
 * Try multiple submit formats until captcha accepts click.
 * Returns [ok(bool), responseJson, raw, usedStrategy]
 */
function submitCaptchaClick($capUrl, $meta, $x, $y, $index, $proxy, $jar) {
    $capBase = explode("?", $capUrl)[0];
    $h = $meta["h"] ?? $meta["cdata"] ?? $meta["token"] ?? "";
    $refZone = $meta["refZone"] ?? null;
    $w = $meta["w"] ?? null;

    $xi = (int)$x;
    $yi = (int)$y;

    // Strategies: [queryAction, bodyType json|form, body payload]
    $strategies = [];

    // JSON bodies (same style as challenge fetch which uses JSON)
    foreach (["check", "verify", "click", "solve", "answer", "data"] as $act) {
        $payload = ["x" => $xi, "y" => $yi];
        if ($h !== "") {
            $payload["h"] = $h;
        }
        if ($index !== null) {
            $payload["index"] = (int)$index;
            $payload["answer"] = (int)$index;
        }
        if ($refZone !== null) {
            $payload["refZone"] = $refZone;
        }
        if ($w !== null) {
            $payload["w"] = $w;
        }
        $strategies[] = [$act, "json", $payload];
    }

    // Form-urlencoded variants
    foreach (["check", "verify", "click", "data"] as $act) {
        $payload = [
            "x" => $xi,
            "y" => $yi,
            "coords" => json_encode([$xi, $yi]),
            "point"  => json_encode([$xi, $yi]),
        ];
        if ($h !== "") {
            $payload["h"] = $h;
            $payload["cdata"] = $h;
        }
        if ($index !== null) {
            $payload["index"] = (int)$index;
        }
        $strategies[] = [$act, "form", $payload];
    }

    // JSON with only h + index (icon pick style)
    if ($index !== null && $h !== "") {
        $strategies[] = ["check", "json", ["h" => $h, "index" => (int)$index]];
        $strategies[] = ["verify", "json", ["h" => $h, "index" => (int)$index]];
        $strategies[] = ["check", "form", ["h" => $h, "index" => (int)$index]];
    }

    foreach ($strategies as $i => $st) {
        list($act, $type, $payload) = $st;
        $url = $capBase . "?action=" . urlencode($act);
        if ($type === "json") {
            list($code, $raw) = httpRequest($url, "POST", json_encode($payload), [
                "Content-Type: application/json",
                "X-Requested-With: XMLHttpRequest",
            ], $proxy, $jar);
        } else {
            list($code, $raw) = httpRequest($url, "POST", $payload, [
                "Content-Type: application/x-www-form-urlencoded",
                "X-Requested-With: XMLHttpRequest",
            ], $proxy, $jar);
        }

        $j = json_decode($raw, true) ?: [];
        $msg = (string)($j["message"] ?? "");

        // hard reject → try next
        if (stripos($msg, "Invalid action") !== false) {
            continue;
        }

        // success signals
        $ok = false;
        if (isset($j["status"]) && (string)$j["status"] === "success") {
            $ok = true;
        }
        if (isset($j["success"]) && $j["success"]) {
            $ok = true;
        }
        if (!empty($j["token"]) && is_string($j["token"])) {
            $ok = true;
        }
        if (!empty($j["redirect"])) {
            $ok = true;
        }
        // any non-false boolean-looking random key with true
        foreach ($j as $k => $v) {
            if (is_bool($v) && $v === true && $k !== "success") {
                $ok = true;
            }
            if (is_string($v) && strlen($v) >= 32 && preg_match('/^[0-9a-f]+$/i', $v) && !in_array($k, ["h", "preload", "image"], true)) {
                $ok = true;
            }
        }
        // not an explicit failure message
        if ($ok || ($code === 200 && $msg === "" && !empty($j) && stripos($msg, "invalid") === false && stripos($msg, "fail") === false && stripos($msg, "error") === false)) {
            // still reject pure error objects
            if (isset($j["status"]) && (string)$j["status"] === "error") {
                continue;
            }
            if (stripos($msg, "Invalid") !== false || stripos($msg, "wrong") !== false || stripos($msg, "fail") !== false) {
                // keep trying unless we got a token
                if (empty($j["token"])) {
                    continue;
                }
            }
            return [true, $j, $raw, "$act/$type"];
        }
    }

    // return last attempt info
    return [false, $j ?? [], $raw ?? "", "all-failed"];
}

function validateFirewall($key, $sub, $token, $proxy, $jar, $html) {
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
    return [$offerPath, $vj, $vraw, $cv];
}

function runAccount($acc, $claims = 5) {
    $name  = $acc["name"];
    $key   = $acc["key"];
    $sub   = $acc["sub_id"];
    $proxy = $acc["proxy"] ?? null;
    $jar   = configPath(HOST, "cookie_" . preg_replace("/[^a-z0-9]/i", "_", $name) . ".txt");
    $claimsLabel = ($claims === 0) ? "UNLIMITED" : (string)$claims;

    logStart("▶  RUN · $name");
    logLine("Account", GREEN . $name . RESET);
    logLine("sub_id", $sub);
    logLine("Proxy", $proxy ? CYAN . substr($proxy, 0, 40) . RESET : GREY . "DIRECT" . RESET);
    logLine("Claims", YELLOW . $claimsLabel . RESET);

    $vkey = vernuable_key();
    if ($vkey === "") {
        logLine("Error", RED . "Vernuable API key empty" . RESET);
        logEnd();
        return;
    }
    $bal = vernuable_balance();
    logLine("Vernuable", $bal !== null ? GREEN . "$" . number_format($bal, 5) . RESET : RED . "n/a" . RESET);
    if ($bal !== null && $bal <= 0) {
        logLine("Error", RED . "ZERO_BALANCE" . RESET);
        logEnd();
        return;
    }

    list($code, $html) = httpRequest(
        BASE . "/firewall.php?key=" . urlencode($key) . "&sub_id=" . urlencode($sub),
        "GET", null, [], $proxy, $jar
    );
    if ($code >= 400 || $html === false || $html === "") {
        logLine("Firewall", RED . "HTTP $code" . RESET);
        logEnd();
        return;
    }

    $capUrl = null;
    $offerPath = null;
    $offerToken = null;

    if (preg_match('#(/captcha2/[a-f0-9]{32,}\.js)\?action=captcha#i', $html, $m)) {
        $capUrl = BASE . $m[1] . "?action=captcha";
    } elseif (preg_match('#captcha2/([a-f0-9]{32,})\.js#i', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    }

    if ($capUrl) {
        logLine("Firewall", YELLOW . "captcha required" . RESET);
        logLine("CapURL", GREY . substr($capUrl, 0, 42) . RESET);

        $solved = null;
        $meta = [];
        $maxTries = 4;

        for ($try = 1; $try <= $maxTries; $try++) {
            list($gif, $j, $err) = fetchCaptchaChallenge($capUrl, $proxy, $jar);
            if ($err || !isValidGif($gif)) {
                logLine("Fetch#$try", RED . ($err ?: ("bad GIF " . ($gif ? strlen($gif) : 0) . "B")) . RESET);
                sleep(2);
                continue;
            }
            $meta = $j;
            logLine("Fetch#$try", GREEN . strlen($gif) . "B GIF89a OK" . RESET);
            logLine("Keys", substr(implode(",", array_keys($j)), 0, 42));

            logLine("Solve", YELLOW . "Vernuable bitcotask…" . RESET);
            $solved = vernuable_solve_bitcotask($gif, 180, 2);
            if ($solved && isset($solved["x"])) {
                logLine("Solved", GREEN . ((int)$solved["x"] . "," . (int)$solved["y"])
                    . (isset($solved["index"]) ? " idx=" . $solved["index"] : "") . RESET);
                break;
            }
            $detail = is_array($solved) && isset($solved["error"]) ? $solved["error"] : "fail";
            logLine("Solve#$try", RED . substr($detail, 0, 40) . RESET);
            sleep(2);
            $solved = null;
        }

        if (!$solved || !isset($solved["x"])) {
            logLine("Error", RED . "all solve tries failed" . RESET);
            logEnd();
            return;
        }

        $x = (int)round($solved["x"]);
        $y = (int)round($solved["y"]);
        $index = isset($solved["index"]) ? (int)$solved["index"] : null;

        logLine("Submit", YELLOW . "trying check/verify/click…" . RESET);
        list($ok, $sj, $sraw, $strategy) = submitCaptchaClick($capUrl, $meta, $x, $y, $index, $proxy, $jar);
        logLine("Strategy", $strategy);
        logLine("ClickResp", substr(is_string($sraw) ? $sraw : json_encode($sj), 0, 42));

        if (!$ok) {
            logLine("Error", RED . "click rejected by captcha" . RESET);
            logEnd();
            return;
        }
        logLine("Click", GREEN . "accepted" . RESET);

        // token for firewall
        $token = $sj["token"] ?? $meta["token"] ?? null;
        if (!$token) {
            foreach (array_merge($meta, $sj) as $v) {
                if (is_string($v) && strlen($v) >= 32 && preg_match('/^[0-9a-f]+$/i', $v)) {
                    $token = $v;
                    break;
                }
            }
        }
        // sometimes success response embeds redirect already
        if (!empty($sj["redirect"])) {
            $offerPath = $sj["redirect"];
            $parts = explode("/", rtrim($offerPath, "/"));
            $offerToken = end($parts);
            logLine("Redirect", GREEN . substr($offerPath, 0, 40) . RESET);
        } else {
            list($offerPath, $vj, $vraw, $cv) = validateFirewall($key, $sub, $token ?: "", $proxy, $jar, $html);
            logLine("Validate", $offerPath ? GREEN . "OK" . RESET : RED . substr((string)$vraw, 0, 36) . RESET);
            if ($offerPath) {
                $parts = explode("/", rtrim($offerPath, "/"));
                $offerToken = end($parts);
            }
        }

        if (!$offerPath) {
            logLine("Error", RED . "no offerwall redirect" . RESET);
            logEnd();
            return;
        }
    } else {
        if (preg_match('#(/offerwall/[^"\'\s]+)#i', $html, $om)) {
            $offerPath = $om[1];
            $parts = explode("/", rtrim($offerPath, "/"));
            $offerToken = end($parts);
            logLine("Firewall", GREEN . "already clear" . RESET);
        } else {
            logLine("Error", RED . "no captcha / no offerwall" . RESET);
            logEnd();
            return;
        }
    }

    $offerUrl = (strpos($offerPath, "http") === 0)
        ? $offerPath
        : rtrim(BASE, "/") . "/" . ltrim($offerPath, "/");

    list($co, $ohtml) = httpRequest($offerUrl, "GET", null, [], $proxy, $jar);
    if (preg_match('/token["\']?\s*[:=]\s*["\']([a-f0-9]{32,})["\']/i', $ohtml, $tm)) {
        $offerToken = $tm[1];
    }
    logLine("Offerwall", GREY . substr($offerUrl, 0, 42) . RESET);
    logEnd();

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
            "token" => $offerToken,
            "action" => "switch_cat",
            "type" => "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $sj = json_decode($sraw, true) ?: [];
        $items = $sj["items"] ?? $sj["data"] ?? [];

        if (empty($items) || !is_array($items)) {
            $emptyStreak++;
            logStart("PTC");
            logLine("Items", YELLOW . "none (streak $emptyStreak)" . RESET);
            logEnd();
            if ($emptyStreak >= 3) {
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

        $label = ($claims === 0) ? "#$i ∞" : "#$i/$claims";
        logStart("CLAIM $label");
        logLine("Reward", YELLOW . ($item["reward"] ?? "?") . RESET);
        logLine("Duration", ($item["duration"] ?? "?") . "s");

        list($ci, $iraw) = httpRequest($offerUrl, "POST", [
            "token" => $offerToken,
            "action" => "init_transaction",
            "hash" => $item["hash"] ?? "",
            "sid" => $item["sid"] ?? $sub,
            "key" => $item["key"] ?? $key,
            "type" => $item["type"] ?? "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $ij = json_decode($iraw, true) ?: [];
        $lead = $ij["offer"] ?? $ij["url"] ?? $ij["link"] ?? "";
        if (!$lead) {
            $fail++;
            logLine("Init", RED . substr((string)$iraw, 0, 40) . RESET);
            logEnd();
            if ($fail >= 8) {
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
        logLine("View", YELLOW . (int)$wait . "s" . RESET);
        logEnd();
        sleep((int)$wait);

        $tokenLead = basename(parse_url($lead, PHP_URL_PATH) ?: rtrim($lead, "/"));
        list($cp, $praw) = httpRequest(BASE . "/system/ajax.php", "POST", [
            "hash" => $item["hash"] ?? "",
            "sub_id" => $item["sid"] ?? $sub,
            "key" => $item["key"] ?? $key,
            "token" => $tokenLead,
            "action" => "proccessLead",
            "atxrN" => "",
        ], [
            "X-Requested-With: XMLHttpRequest",
            "Cookie: " . $cookieHdr,
        ], $proxy, $jar);

        $pj = json_decode($praw, true) ?: [];
        $success = (isset($pj["status"]) && (int)$pj["status"] === 200)
            || stripos((string)($pj["message"] ?? ""), "SUCCESS") !== false
            || stripos((string)($pj["msg"] ?? ""), "SUCCESS") !== false;

        logStart("RESULT $label");
        if ($success) {
            $ok++;
            $fail = 0;
            logLine("Status", GREEN . "OK" . RESET);
            logLine("Reward", GREEN . ($item["reward"] ?? "") . RESET);
            logLine("Total", GREEN . (string)$ok . RESET);
        } else {
            $fail++;
            logLine("Status", RED . "FAIL" . RESET);
            logLine("Detail", substr((string)$praw, 0, 40));
            if ($fail >= 8) {
                logLine("Stop", RED . "too many fails" . RESET);
                logEnd();
                break;
            }
        }
        logEnd();
        sleep(mt_rand(2, 5));
    }

    logStart("SUMMARY");
    logLine("Account", $name);
    logLine("OK", GREEN . $ok . RESET);
    logLine("Fail", RED . $fail . RESET);
    logLine("Mode", $claims === 0 ? YELLOW . "unlimited" . RESET : (string)$claims);
    logEnd();
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
