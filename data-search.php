<?php
// Server-side proxy. The API token stays on the server and is never sent to the browser.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail_response(int $status, string $error, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $error], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_response(405, 'Method not allowed');
}

$configFile = dirname(__DIR__) . '/api-config.php';
if (!is_file($configFile)) {
    fail_response(503, 'API belum dikonfigurasi. Buat /home/gwpe7134/api-config.php di cPanel.');
}

$config = require $configFile;
$token = trim((string)($config['token'] ?? ''));
$endpoint = trim((string)($config['endpoint'] ?? 'https://leakosintapi.com/'));
$defaultLimit = (int)($config['limit'] ?? 100);
$lang = (string)($config['lang'] ?? 'en');

if ($token === '' || $token === 'PASTE_YOUR_API_TOKEN_HERE') {
    fail_response(503, 'API token belum diisi di /home/gwpe7134/api-config.php.');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fail_response(400, 'Request browser bukan JSON yang valid.');
}

$query = trim((string)($input['request'] ?? ''));
$limit = (int)($input['limit'] ?? $defaultLimit);

if ($query === '') {
    fail_response(400, 'Query kosong.');
}

$limit = max(100, min(10000, $limit));
if (mb_strlen($query) > 500) {
    fail_response(400, 'Query terlalu panjang.');
}

// Matches the documented API request: token + request, with optional limit/lang.
$payload = [
    'token' => $token,
    'request' => $query,
    'limit' => $limit,
    'lang' => $lang,
];

$jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false) {
    fail_response(500, 'Gagal membuat JSON request API.');
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 45,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($response === false || $curlError !== '') {
    fail_response(502, 'Gagal menghubungi API upstream.', ['detail' => $curlError]);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    fail_response(502, 'Respons API bukan JSON yang valid.', [
        'http_status' => $status,
        'content_type' => $contentType,
    ]);
}

// The documentation uses the exact key "Error code". Also accept common
// variants so the browser gets a useful diagnostic if the upstream format changes.
$apiError = null;
$errorKey = null;
foreach (['Error code', 'error_code', 'error', 'message', 'Error', 'Message'] as $key) {
    if (array_key_exists($key, $data) && is_scalar($data[$key])) {
        $apiError = trim((string)$data[$key]);
        $errorKey = $key;
        break;
    }
}

if ($status >= 400 || $apiError !== null) {
    fail_response($status >= 400 ? $status : 502, $apiError !== '' ? $apiError : 'API mengembalikan error.', [
        'http_status' => $status,
        'upstream_error_key' => $errorKey,
    ]);
}

echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
