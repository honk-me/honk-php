<?php
// Router for `php -S`: answers each request with the next step of $HONK_MOCK_DIR/script.json
// (the last step repeats) and appends the request to requests.jsonl.
$dir = getenv('HONK_MOCK_DIR');
$lock = fopen("$dir/lock", 'c');
flock($lock, LOCK_EX);
$n = (int) @file_get_contents("$dir/count");
file_put_contents("$dir/count", (string) ($n + 1));
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
file_put_contents("$dir/requests.jsonl", json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
    'at' => microtime(true),
]) . "\n", FILE_APPEND);
$script = json_decode((string) file_get_contents("$dir/script.json"), true);
flock($lock, LOCK_UN);
$step = $script[min($n, count($script) - 1)];
if (!empty($step['delayMs'])) {
    usleep($step['delayMs'] * 1000);
}
http_response_code($step['status'] ?? 202);
header('Content-Type: application/json');
foreach ($step['headers'] ?? [] as $k => $v) {
    header("$k: $v");
}
echo is_string($step['body'] ?? null) ? $step['body'] : json_encode($step['body'] ?? new stdClass());
