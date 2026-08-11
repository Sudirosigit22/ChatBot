<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$username = (string)$_SESSION['username'];
$conversationId = (int)($_GET['conversation_id'] ?? 0);

if ($conversationId <= 0 || !conversation_belongs_to($conversationId, $username)) {
    json_response(['error' => 'Percakapan tidak ditemukan'], 404);
}

json_response([
    'messages' => list_messages($conversationId),
    'model' => get_conversation_model($conversationId),
]);
