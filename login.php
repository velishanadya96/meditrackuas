<?php
session_start();
require_once __DIR__ . '/db.php';

// Kalau sudah login, langsung ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboarduser.php'); // Perbaikan: hapus slash '/' di awal
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Email dan password wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, name, password, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Email atau password salah.';
        } else {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            if ($user['role'] === 'admin') {
                header('Location: dashboard_admin.php');
            } else {
                header('Location: dashboarduser.php');
            }
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
    <title>Login — MediTrack</title>
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
                    <h3 class="mb-0">🏥 Login MediTrack</h3>
                </div>
                <div class="card-body p-4">

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="login.php">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   placeholder="email@contoh.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control"
                                   placeholder="Password kamu" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">
                            Masuk
                        </button>
                        <p class="text-center mt-3 mb-0">
                            Belum punya akun? <a href="/rekam-medis-fixed/register.php">Daftar</a> </p>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>