<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (!is_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$username = (string)$_SESSION['username'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = trim((string)($_GET['q'] ?? ''));
    json_response(['conversations' => list_conversations($username, $search)]);
}

if ($method !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf_token($csrfHeader)) {
    json_response(['error' => 'Invalid CSRF token'], 403);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    $id = create_conversation($username);
    json_response(['id' => $id, 'title' => 'Percakapan Baru']);
}

if ($action === 'rename') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($id <= 0 || $title === '') {
        json_response(['error' => 'Data tidak lengkap'], 400);
    }
    if (!conversation_belongs_to($id, $username)) {
        json_response(['error' => 'Percakapan tidak ditemukan'], 404);
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 80) : substr($title, 0, 80);
    rename_conversation($id, $title);
    json_response(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Data tidak lengkap'], 400);
    }
    if (!conversation_belongs_to($id, $username)) {
        json_response(['error' => 'Percakapan tidak ditemukan'], 404);
    }
    delete_conversation($id);
    json_response(['ok' => true]);
}

json_response(['error' => 'Aksi tidak dikenal'], 400);
