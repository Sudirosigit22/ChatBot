<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$username = trim((string)($_POST['Username'] ?? ''));
$password = (string)($_POST['Password'] ?? '');

if ($username !== (string)app_config('username') || $password !== (string)app_config('password')) {
    flash_error('Username atau password salah');
    header('Location: ../login.php');
    exit;
}

login_user($username);
header('Location: ../index.php');
exit;
