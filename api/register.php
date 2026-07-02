<?php
session_start();
require_once __DIR__ . '/db.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$name || !$email || !$password) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = 'Email sudah terdaftar. Silakan login.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            $ins->execute([$name, $email, $hash, 'user']);

            // Bersihin session/cookie lama biar gak auto-login ke akun sebelumnya
            $_SESSION = [];
            session_unset();
            session_destroy();
            setcookie('user_id', '', time() - 3600, '/');
            setcookie('user_name', '', time() - 3600, '/');
            setcookie('user_role', '', time() - 3600, '/');
            unset($_COOKIE['user_id'], $_COOKIE['user_name'], $_COOKIE['user_role']);

            header('Location: /api/login.php?loggedout=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register — MediTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #e0f2fe; font-family: 'Segoe UI', Arial, sans-serif; }
        .card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(14,165,233,.2); }
        .card-header { background: #38bdf8; color: white; border-radius: 20px 20px 0 0 !important; }
        .btn-primary { background: #38bdf8; border: none; border-radius: 10px; font-weight: 600; }
        .btn-primary:hover { background: #0ea5e9; }
        .form-control { border-radius: 10px; padding: 10px 14px; }
        .form-control:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.25); }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header text-center py-4">
                    <h3 class="mb-0">🏥 Registrasi Akun</h3>
                </div>
                <div class="card-body p-4">

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <?= htmlspecialchars($success) ?>
                            <a href="/api/login.php">Login sekarang</a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/api/register.php">
                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                                   placeholder="Nama lengkap" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   placeholder="email@contoh.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control"
                                   placeholder="Minimal 6 karakter" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">
                            Daftar Sekarang
                        </button>
                        <p class="text-center mt-3 mb-0">
                            Sudah punya akun? <a href="/api/login.php">Login</a>
                        </p>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>