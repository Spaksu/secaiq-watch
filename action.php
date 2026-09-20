<?php
/**
 * Receives a permission-removal request and drops it in the queue (var/queue). The collector applies it (src/Actions.php).
 * Protection: loopback only · POST · Host/Origin localhost · secret token · fixed action types.
 */
require __DIR__ . '/src/Guard.php';
require __DIR__ . '/src/Actions.php';
Guard::localOnly();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = function (int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!empty($_SERVER['HTTP_X_AIGW_DEMO'])) {
    $fail(403, 'Demo mode: actions are disabled');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail(405, 'POST only');
}
if (!Guard::sameOrigin()) {
    $fail(403, 'Invalid origin');
}
$token = Actions::token();
if ($token === null) {
    $fail(503, 'The collector is not ready yet (bin/start.sh start)');
}
if (!hash_equals($token, (string) ($_SERVER['HTTP_X_AIGW_TOKEN'] ?? ''))) {
    $fail(403, 'Invalid token — reload the page');
}

$req = json_decode((string) file_get_contents('php://input'), true);
$type = is_array($req) ? (string) ($req['type'] ?? '') : '';
if (!in_array($type, Actions::TYPES, true)) {
    $fail(400, 'Invalid action');
}
$params = [];
foreach ((array) ($req['params'] ?? []) as $k => $v) {
    if (is_string($k) && preg_match('/^[a-z_]{1,20}$/', $k) && is_string($v) && strlen($v) <= 600) {
        $params[$k] = $v;
    }
}
$dir = Actions::queueDir();
if (!is_dir($dir) || !is_writable($dir)) {
    $fail(503, 'Queue directory is not writable — restart the collector');
}
if (count(glob($dir . '/*.json') ?: []) >= 20) {
    $fail(429, 'Too many pending requests');
}

$id = bin2hex(random_bytes(6));
$payload = json_encode(['id' => $id, 'type' => $type, 'params' => $params, 'label' => mb_substr((string) ($req['label'] ?? $type), 0, 120)], JSON_UNESCAPED_UNICODE);
$tmp = "$dir/.$id.tmp";
if (file_put_contents($tmp, $payload) === false || !rename($tmp, "$dir/$id.json")) {
    $fail(500, 'Could not write to the queue');
}
echo json_encode(['ok' => true, 'id' => $id]);
