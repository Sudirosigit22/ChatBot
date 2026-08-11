<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = flash_error();
$title = app_config('login_title');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="card">
        <div class="logo">☁️</div>
        <h2>Selamat Datang</h2>
        <p class="subtitle">Akses Dashboard Sigit AI Anda</p>

        <?php if (!empty($error)): ?>
            <div class="error-box">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="api/login.php">
            <div class="input-group">
                <label>Username</label>
                <input name="Username" type="text" required autofocus />
            </div>
            <div class="input-group">
                <label>Password</label>
                <input name="Password" type="password" required />
            </div>
            <button type="submit">Masuk ke Sistem</button>
        </form>
    </div>
</body>
</html>
