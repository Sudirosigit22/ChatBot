<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(is_logged_in() ? 405 : 401); exit;
}
if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403); exit;
}

$message = trim((string)($_POST['Message'] ?? ''));
$username = (string)$_SESSION['username'];
$conversationId = (int)($_POST['conversation_id'] ?? 0);
$regenerate = ($_POST['regenerate'] ?? '') === '1';
$editMessageId = (int)($_POST['edit_message_id'] ?? 0);

$thinkLevelRaw = strtolower(trim((string)($_POST['think_level'] ?? '')));
$thinkOverride = in_array($thinkLevelRaw, ['low', 'medium', 'high', 'on', 'off'], true) ? $thinkLevelRaw : null;

$modelRaw = trim((string)($_POST['model'] ?? ''));
$model = ollama_resolve_model($modelRaw !== '' ? $modelRaw : null);

if ($conversationId > 0 && !conversation_belongs_to($conversationId, $username)) {
    http_response_code(404); exit;
}
$isNewConversation = ($conversationId <= 0);
if ($isNewConversation) {
    $conversationId = create_conversation($username, make_title_from_message($message), $model);
} else {
    
    
    
    set_conversation_model($conversationId, $model);
}

if ($regenerate) {
    delete_last_assistant_message($conversationId);
    $userMessageId = 0;
} elseif ($editMessageId > 0) {
    if ($message === '' || !delete_messages_from($conversationId, $editMessageId)) { http_response_code(400); exit; }
    $userMessageId = add_message($conversationId, 'user', $message);
} else {
    if ($message === '') { http_response_code(400); exit; }
    $userMessageId = add_message($conversationId, 'user', $message);
}

$messages = list_messages($conversationId);
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
echo "event: meta\ndata: " . json_encode(['conversation_id' => $conversationId, 'message_id' => $userMessageId, 'model' => $model]) . "\n\n";
@ob_flush(); @flush();

$emit = static function (string $token): void {
    echo "event: token\ndata: " . json_encode(['text' => $token], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
};
$result = ollama_chat_stream($messages, $emit, $thinkOverride, $model);
if (!$result['ok']) {
    
    
    
    $prefix = !empty($result['friendly']) ? '' : 'API Error: ';
    echo "event: error\ndata: " . json_encode(['message' => $prefix . $result['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
} else {
    
    
    
    
    $result['answer'] = ollama_sanitize_answer_text($result['answer']);
    if ($result['answer'] !== '') add_message($conversationId, 'assistant', $result['answer']);
    
    
    
    
    if ($isNewConversation && $result['answer'] !== '') {
        
        
        $aiTitle = ollama_generate_title($message, $result['answer'], $model);
        if ($aiTitle !== null) rename_conversation($conversationId, $aiTitle);
    }
    touch_conversation($conversationId);
    echo "event: done\ndata: " . json_encode(['stopped' => !empty($result['stopped'])]) . "\n\n";
}
@ob_flush(); @flush();
