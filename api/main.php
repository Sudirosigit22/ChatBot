<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(is_logged_in() ? 405 : 401); exit;
}
if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403); exit;
}

$historyRaw = (string)($_POST['history'] ?? '[]');
$decoded = json_decode($historyRaw, true);
$rawMessages = is_array($decoded) ? $decoded : [];

$messages = [];
foreach ($rawMessages as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    $content = (string)($m['content'] ?? '');
    if (!in_array($role, ['user', 'assistant'], true)) continue;

    $hasImages = !empty($m['images']) && is_array($m['images']);
    if ($content === '' && !($role === 'user' && $hasImages)) continue;
    $entry = ['role' => $role, 'content' => $content !== '' ? $content : '(gambar terlampir)'];
    if ($role === 'user' && $hasImages) {
        $imgs = [];
        foreach ($m['images'] as $img) {
            if (!is_string($img) || $img === '') continue;

            if (strlen($img) > 12 * 1024 * 1024) continue;
            $imgs[] = $img;
        }
        if ($imgs) $entry['images'] = array_slice($imgs, 0, 4);
    }
    $messages[] = $entry;
}

if (empty($messages) || $messages[count($messages) - 1]['role'] !== 'user') {
    http_response_code(400); exit;
}

$conversationId = trim((string)($_POST['conversation_id'] ?? ''));
if ($conversationId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conversationId)) {
    $conversationId = bin2hex(random_bytes(8));
}

$needTitle = ($_POST['need_title'] ?? '') === '1';

$thinkLevelRaw = strtolower(trim((string)($_POST['think_level'] ?? '')));
$thinkOverride = in_array($thinkLevelRaw, ['low', 'medium', 'high', 'on', 'off'], true) ? $thinkLevelRaw : null;

$modelRaw = trim((string)($_POST['model'] ?? ''));
$model = ollama_resolve_model($modelRaw !== '' ? $modelRaw : null);

$responseDepthRaw = strtolower(trim((string)($_POST['response_depth'] ?? 'adaptive')));
$responseDepth = in_array($responseDepthRaw, ['hemat', 'adaptive', 'mendalam'], true)
    ? $responseDepthRaw
    : 'adaptive';

$lastUserMessage = $messages[count($messages) - 1]['content'];

// Preview klasifikasi intent (Naive Bayes) untuk pesan terakhir user, dikirim
// segera lewat event 'meta' agar UI bisa menampilkan badge "jenis pertanyaan
// terdeteksi" SEBELUM jawaban mulai muncul. Ini murni pratinjau read-only
// (tidak mengubah $GLOBALS['AGENT_RUN']); agent_start_run() di dalam
// ollama_chat_stream() akan menghitung ulang klasifikasi yang sesungguhnya
// dipakai untuk audit trail -- keduanya konsisten karena logikanya sama
// (agent_classify()), hanya dipanggil dua kali dengan input yang sama.
$intentPreview = function_exists('agent_classify') ? agent_classify($lastUserMessage) : null;
$intentMl = $intentPreview['ml'] ?? ['label' => 'umum', 'probs' => [], 'available' => false];
$intentPayload = [
    'label' => (string) ($intentMl['label'] ?? 'umum'),
    'confidence' => isset($intentMl['probs'][$intentMl['label']]) ? (float) $intentMl['probs'][$intentMl['label']] : null,
    'available' => (bool) ($intentMl['available'] ?? false),
];

session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
echo "event: meta\ndata: " . json_encode(['conversation_id' => $conversationId, 'model' => $model, 'response_depth' => $responseDepth, 'intent' => $intentPayload]) . "\n\n";
@ob_flush(); @flush();

$emit = static function (string $token): void {
    echo "event: token\ndata: " . json_encode(['text' => $token], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
};
$heartbeat = static function (): void {

    echo ": ping\n\n";
    @ob_flush(); @flush();
};
$result = ollama_chat_stream($messages, $emit, $thinkOverride, $model, $heartbeat, $responseDepth);

if (!$result['ok']) {
    $prefix = !empty($result['friendly']) ? '' : 'API Error: ';
    $trace = agent_finish_run('');
    echo "event: agent_trace\ndata: " . json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "event: error\ndata: " . json_encode(['message' => $prefix . $result['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
} else {
    $answer = ollama_sanitize_answer_text($result['answer']);
    $trace = agent_finish_run($answer);
    $title = null;
    if ($needTitle && $answer !== '') {
        $title = ollama_generate_title($lastUserMessage, $answer, $model);
    }
    echo "event: agent_trace\ndata: " . json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "event: done\ndata: " . json_encode([
        'stopped' => !empty($result['stopped']),
        'title' => $title,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
}
@ob_flush(); @flush();
