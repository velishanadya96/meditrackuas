<?php
session_start();
require_once __DIR__ . '/db.php';

// Kalau sudah login, langsung redirect sesuai role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: dashboard_admin.php');
    } else {
        header('Location: konsultasi.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            if ($user['role'] === 'admin') {
                header('Location: dashboard_admin.php');
            } else {
                header('Location: konsultasi.php');
            }
            exit;
        } else {
            $error = 'Email atau password salah.';
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
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 16px 48px rgba(14,165,233,.18);
            padding: 44px 40px;
            width: 100%;
            max-width: 420px;
        }
        .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 14px;
            box-shadow: 0 8px 20px rgba(14,165,233,.3);
        }
        .brand-name {
            font-size: 1.7rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -.5px;
        }
        .brand-name span { color: #0ea5e9; }
        .brand-sub {
            color: #64748b;
            font-size: .85rem;
            margin-top: 2px;
        }
        .form-label {
            font-weight: 600;
            font-size: .85rem;
            color: #374151;
        }
        .form-control {
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            padding: 11px 14px;
            font-size: .9rem;
            transition: .2s;
        }
        .form-control:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,.12);
        }
        .btn-login {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            color: white;
            border: none;
            border-radius: 14px;
            padding: 12px;
            font-weight: 700;
            font-size: .95rem;
            width: 100%;
            transition: .2s;
            box-shadow: 0 4px 14px rgba(14,165,233,.3);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(14,165,233,.4);
            color: white;
        }
        .btn-login:active { transform: translateY(0); }
        .divider {
            text-align: center;
            color: #94a3b8;
            font-size: .8rem;
            margin: 18px 0;
            position: relative;
        }
        .divider::before, .divider::after {
            content: '';
            position: absolute;
            top: 50%; width: 38%;
            height: 1px;
            background: #e2e8f0;
        }
        .divider::before { left: 0; }
        .divider::after  { right: 0; }
        .link-register {
            text-align: center;
            font-size: .85rem;
            color: #64748b;
        }
        .link-register a {
            color: #0ea5e9;
            font-weight: 600;
            text-decoration: none;
        }
        .link-register a:hover { text-decoration: underline; }
        .alert-err {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: .85rem;
            margin-bottom: 18px;
        }
        .input-wrap { position: relative; }
        .toggle-pw {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #94a3b8; cursor: pointer;
            padding: 0; font-size: 1rem;
            line-height: 1;
        }
        .toggle-pw:hover { color: #0ea5e9; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand">
        <div class="brand-icon">🏥</div>
        <div class="brand-name">Medi<span>Track</span></div>
        <div class="brand-sub">Sistem Rekam Medis Digital</div>
    </div>

    <?php if ($error): ?>
    <div class="alert-err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input
                type="email"
                name="email"
                class="form-control"
                placeholder="nama@email.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
                autofocus
            >
        </div>
        <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <input
                    type="password"
                    name="password"
                    id="pwInput"
                    class="form-control"
                    placeholder="••••••••"
                    required
                    style="padding-right:40px;"
                >
                <button type="button" class="toggle-pw" onclick="togglePw()" title="Tampilkan password">👁</button>
            </div>
        </div>
        <button type="submit" class="btn-login">Masuk →</button>
    </form>

    <div class="divider">atau</div>

    <div class="link-register">
        Belum punya akun? <a href="register.php">Daftar sekarang</a>
    </div>
</div>

<script>
function togglePw() {
    const inp = document.getElementById('pwInput');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>