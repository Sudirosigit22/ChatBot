<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        json_response(['answer' => '⚠️ Ekstensi PHP pdo_sqlite belum aktif di server. Aktifkan dulu di php.ini.'], 500);
    }

    $dbPath = (string)app_config('db_path');
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    db_migrate($pdo);

    return $pdo;
}

function db_migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT 'Percakapan Baru',
            model TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");
    
    
    
    
    $hasModelColumn = false;
    foreach ($pdo->query('PRAGMA table_info(conversations)') as $col) {
        if (($col['name'] ?? '') === 'model') { $hasModelColumn = true; break; }
    }
    if (!$hasModelColumn) {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN model TEXT NOT NULL DEFAULT ''");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('user','assistant')),
            content TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conversations_username ON conversations(username)');
}

function list_conversations(string $username, string $search = ''): array {
    $sql = 'SELECT c.id, c.title, c.model, c.created_at, c.updated_at FROM conversations c WHERE c.username = :u';
    $params = ['u' => $username];
    if ($search !== '') {
        $sql .= ' AND (c.title LIKE :q OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.content LIKE :q))';
        $params['q'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY c.updated_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function conversation_belongs_to(int $conversationId, string $username): bool {
    $stmt = db()->prepare('SELECT 1 FROM conversations WHERE id = :id AND username = :u');
    $stmt->execute(['id' => $conversationId, 'u' => $username]);
    return (bool)$stmt->fetchColumn();
}

function create_conversation(string $username, string $title = 'Percakapan Baru', string $model = ''): int {
    $now = date('c');
    $stmt = db()->prepare('INSERT INTO conversations (username, title, model, created_at, updated_at) VALUES (:u, :t, :m, :c, :up)');
    $stmt->execute(['u' => $username, 't' => $title, 'm' => $model, 'c' => $now, 'up' => $now]);
    return (int)db()->lastInsertId();
}

function rename_conversation(int $conversationId, string $title): void {
    $stmt = db()->prepare('UPDATE conversations SET title = :t, updated_at = :up WHERE id = :id');
    $stmt->execute(['t' => $title, 'up' => date('c'), 'id' => $conversationId]);
}

function get_conversation_model(int $conversationId): string {
    $stmt = db()->prepare('SELECT model FROM conversations WHERE id = :id');
    $stmt->execute(['id' => $conversationId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function set_conversation_model(int $conversationId, string $model): void {
    $stmt = db()->prepare('UPDATE conversations SET model = :m WHERE id = :id');
    $stmt->execute(['m' => $model, 'id' => $conversationId]);
}

function touch_conversation(int $conversationId): void {
    $stmt = db()->prepare('UPDATE conversations SET updated_at = :up WHERE id = :id');
    $stmt->execute(['up' => date('c'), 'id' => $conversationId]);
}

function delete_conversation(int $conversationId): void {
    $stmt = db()->prepare('DELETE FROM conversations WHERE id = :id');
    $stmt->execute(['id' => $conversationId]);
}

function list_messages(int $conversationId): array {
    $stmt = db()->prepare('SELECT id, role, content, created_at FROM messages WHERE conversation_id = :id ORDER BY id ASC');
    $stmt->execute(['id' => $conversationId]);
    return $stmt->fetchAll();
}

function add_message(int $conversationId, string $role, string $content): int {
    $stmt = db()->prepare('INSERT INTO messages (conversation_id, role, content, created_at) VALUES (:c, :r, :ct, :cr)');
    $stmt->execute(['c' => $conversationId, 'r' => $role, 'ct' => $content, 'cr' => date('c')]);
    return (int)db()->lastInsertId();
}

function delete_last_assistant_message(int $conversationId): void {
    $stmt = db()->prepare('DELETE FROM messages WHERE id = (SELECT id FROM messages WHERE conversation_id = :id AND role = \'assistant\' ORDER BY id DESC LIMIT 1)');
    $stmt->execute(['id' => $conversationId]);
}

function delete_messages_from(int $conversationId, int $messageId): bool {
    $check = db()->prepare('SELECT 1 FROM messages WHERE id = :mid AND conversation_id = :cid AND role = \'user\'');
    $check->execute(['mid' => $messageId, 'cid' => $conversationId]);
    if (!$check->fetchColumn()) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM messages WHERE conversation_id = :cid AND id >= :mid');
    $stmt->execute(['cid' => $conversationId, 'mid' => $messageId]);
    return true;
}

function make_title_from_message(string $message): string {
    $title = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    if ($title === '') {
        return 'Percakapan Baru';
    }
    if (function_exists('mb_substr')) {
        return mb_strlen($title) > 45 ? mb_substr($title, 0, 45) . '…' : $title;
    }
    return strlen($title) > 45 ? substr($title, 0, 45) . '…' : $title;
}
