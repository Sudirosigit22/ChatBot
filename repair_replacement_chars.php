<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

function repair_sanitize(string $text): string {
    if ($text === '' || strpos($text, "\u{FFFD}") === false) {
        return $text;
    }
    $text = preg_replace('/\x{202F}?\x{FFFD}+\x{202F}?/u', ' ≈ ', $text) ?? $text;
    $text = str_replace("\u{FFFD}", '', $text);
    return preg_replace('/[ \x{202F}]{2,}/u', ' ', $text) ?? $text;
}

$apply = in_array('--apply', $argv ?? [], true);

$pdo = db();
$stmt = $pdo->query("SELECT id, content FROM messages WHERE content LIKE '%' || X'EFBFBD' || '%'");
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "Tidak ada pesan dengan karakter rusak (U+FFFD) yang ditemukan.\n";
    exit(0);
}

echo count($rows) . " pesan mengandung karakter rusak ditemukan.\n\n";

$update = $pdo->prepare('UPDATE messages SET content = :c WHERE id = :id');
foreach ($rows as $row) {
    $before = (string) $row['content'];
    $after = repair_sanitize($before);
    if ($after === $before) continue;

    echo "--- Pesan #{$row['id']} ---\n";
    echo "SEBELUM: " . mb_substr($before, 0, 120) . "\n";
    echo "SESUDAH: " . mb_substr($after, 0, 120) . "\n\n";

    if ($apply) {
        $update->execute(['c' => $after, 'id' => $row['id']]);
    }
}

echo $apply
    ? "Selesai - perubahan telah DITULIS ke database.\n"
    : "Ini baru SIMULASI (dry-run) - tidak ada perubahan ditulis. Jalankan ulang dengan --apply untuk benar-benar menyimpan.\n";
