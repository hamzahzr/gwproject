<?php
// Server-side proxy. The API token stays on the server and is never sent to the browser.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

$configFile = dirname(__DIR__) . '/api-config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'API belum dikonfigurasi. Buat /home/gwpe7134/api-config.php di cPanel.']);
    exit;
}

$config = require $configFile;
$token = trim((string)($config['token'] ?? ''));
$endpoint = trim((string)($config['endpoint'] ?? 'https://leakosintapi.com/'));
$defaultLimit = (int)($config['limit'] ?? 100);
$lang = (string)($config['lang'] ?? 'en');

if ($token === '' || $token === 'PASTE_YOUR_API_TOKEN_HERE') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'API token belum diisi di /home/gwpe7134/api-config.php.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = trim((string)($input['request'] ?? ''));
$limit = (int)($input['limit'] ?? $defaultLimit);

if ($query === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Query kosong.']);
    exit;
}

$limit = max(100, min(10000, $limit));
if (mb_strlen($query) > 500) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Query terlalu panjang.']);
    exit;
}

// Matches the documented API request: token + request, with optional limit/lang.
$payload = [
    'token' => $token,
    'request' => $query,
    'limit' => $limit,
    'lang' => $lang,
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 45,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '') {
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'Gagal menghubungi API upstream.','detail'=>$curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'Respons API tidak valid.','http_status'=>$status], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($status >= 400 || isset($data['Error code'])) {
    $apiError = (string)($data['Error code'] ?? $data['error'] ?? $data['message'] ?? 'API mengembalikan error.');
    http_response_code($status >= 400 ? $status : 502);
    echo json_encode(['ok'=>false,'error'=>$apiError,'http_status'=>$status], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
