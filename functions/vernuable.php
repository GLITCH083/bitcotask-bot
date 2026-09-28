<?php
/**
 * Vernuable API client — method=bitcotask (motion GIF → x,y)
 * Docs: https://vernuable.my.id/docs#bitcotask
 *
 * Official request (JSON body):
 *   {"key":"...","method":"bitcotask","image":"BASE64_GIF","json":"1"}
 * Success:
 *   {"status":1,"request":"{\"x\":242,\"y\":45,\"index\":1}","x":242,"y":45,"index":1}
 *
 * @version 1.1.0
 */

if (!function_exists('vernuable_balance')) {

function vernuable_key() {
    static $key = null;
    if ($key === null) {
        $key = saveData("vernuable-Bot", "vernuable-apikey");
    }
    return trim((string)$key);
}

function vernuable_base() {
    return "https://vernuable.my.id";
}

/**
 * Low-level HTTP helper matching official docs.
 * @param string $url
 * @param array  $fields
 * @param bool   $jsonBody  true = Content-Type application/json
 * @return array|null
 */
function vernuable_request($url, $fields, $jsonBody = false) {
    $ch = curl_init($url);
    $headers = [
        "Accept: application/json",
        "User-Agent: Mozilla/5.0 VernuableOfficialScripts/1.1",
    ];
    if ($jsonBody) {
        $headers[] = "Content-Type: application/json";
        $body = json_encode($fields);
    } else {
        $body = http_build_query($fields);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    if ($raw === false || $raw === '') {
        return ["status" => 0, "request" => "HTTP_FAIL", "error" => $err ?: "empty response"];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ["status" => 0, "request" => "BAD_JSON", "error" => substr($raw, 0, 200)];
    }
    return $json;
}

function vernuable_balance() {
    $key = vernuable_key();
    if ($key === '') {
        return null;
    }
    $res = vernuable_request(vernuable_base() . "/res.php", [
        "key"    => $key,
        "action" => "getbalance",
        "json"   => "1",
    ], false);
    if (isset($res["status"]) && (string)$res["status"] === "1") {
        return (float)($res["balance"] ?? $res["request"] ?? 0);
    }
    return null;
}

/**
 * Parse solve result into x,y.
 * Handles:
 *   - top-level x/y
 *   - request as JSON string {"x":..,"y":..}
 *   - request as array
 *   - request as "x|y"
 */
function vernuable_parse_xy($out) {
    if (!is_array($out)) {
        return null;
    }
    if (isset($out["x"], $out["y"])) {
        return [
            "x"     => (float)$out["x"],
            "y"     => (float)$out["y"],
            "index" => isset($out["index"]) ? (int)$out["index"] : null,
            "raw"   => $out,
        ];
    }
    $req = $out["request"] ?? null;
    if (is_array($req) && isset($req["x"], $req["y"])) {
        return [
            "x"     => (float)$req["x"],
            "y"     => (float)$req["y"],
            "index" => isset($req["index"]) ? (int)$req["index"] : null,
            "raw"   => $req,
        ];
    }
    if (is_string($req) && $req !== "" && $req !== "CAPCHA_NOT_READY") {
        $decoded = json_decode($req, true);
        if (is_array($decoded) && isset($decoded["x"], $decoded["y"])) {
            return [
                "x"     => (float)$decoded["x"],
                "y"     => (float)$decoded["y"],
                "index" => isset($decoded["index"]) ? (int)$decoded["index"] : null,
                "raw"   => $decoded,
            ];
        }
        if (strpos($req, "|") !== false) {
            $parts = explode("|", $req, 2);
            return [
                "x"   => (float)trim($parts[0]),
                "y"   => (float)trim($parts[1]),
                "raw" => $req,
            ];
        }
    }
    return null;
}

/**
 * Solve BitcoTasks motion captcha (GIF → x,y click).
 *
 * @param string $gifBytes raw GIF binary
 * @param int    $timeout  max seconds to poll
 * @param int    $poll     seconds between polls
 * @return array|null  ['x'=>float,'y'=>float,'index'=>?, 'error'=>?] or null
 */
function vernuable_solve_bitcotask($gifBytes, $timeout = 180, $poll = 2) {
    $key = vernuable_key();
    if ($key === '') {
        return ["error" => "NO_API_KEY"];
    }
    if ($gifBytes === '' || $gifBytes === false || $gifBytes === null) {
        return ["error" => "EMPTY_GIF"];
    }

    // GIF magic check
    if (substr($gifBytes, 0, 3) !== "GIF") {
        // still try — sometimes already base64 or partial
    }

    $b64  = base64_encode($gifBytes);
    $base = vernuable_base();

    // Official docs: JSON body with method=bitcotask + image=BASE64
    $task = vernuable_request($base . "/in.php", [
        "key"    => $key,
        "method" => "bitcotask",
        "image"  => $b64,
        "json"   => "1",
    ], true);

    // Fallback: form-urlencoded if JSON submit rejected
    if (!isset($task["status"]) || (string)$task["status"] !== "1") {
        $task2 = vernuable_request($base . "/in.php", [
            "key"    => $key,
            "method" => "bitcotask",
            "image"  => $b64,
            "json"   => "1",
        ], false);
        if (isset($task2["status"]) && (string)$task2["status"] === "1") {
            $task = $task2;
        }
    }

    if (!isset($task["status"]) || (string)$task["status"] !== "1") {
        $err = $task["request"] ?? $task["error"] ?? json_encode($task);
        return ["error" => "SUBMIT_FAIL: " . (is_string($err) ? $err : json_encode($err))];
    }

    $taskId = $task["request"];
    if ($taskId === '' || $taskId === null) {
        return ["error" => "NO_TASK_ID"];
    }

    $t0 = time();
    while ((time() - $t0) < $timeout) {
        sleep($poll);
        $res = vernuable_request($base . "/res.php", [
            "key"    => $key,
            "action" => "get",
            "id"     => $taskId,
            "json"   => "1",
        ], false);

        $st  = isset($res["status"]) ? (string)$res["status"] : "";
        $req = (string)($res["request"] ?? "");

        if ($st === "1") {
            $xy = vernuable_parse_xy($res);
            if ($xy && isset($xy["x"])) {
                return $xy;
            }
            return ["error" => "BAD_RESULT: " . substr($req, 0, 120)];
        }

        // still solving
        if ($req === "CAPCHA_NOT_READY" || stripos($req, "NOT_READY") !== false) {
            continue;
        }

        // hard fail
        return ["error" => $req !== "" ? $req : ("STATUS_0: " . json_encode($res))];
    }

    return ["error" => "TIMEOUT"];
}

} // end function_exists guard
