<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['user_role'] === 'admin') {
    header('Location: dashboard_admin.php');
    exit;
}

$userName = $_SESSION['user_name'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pilih Konsultasi — MediTrack</title>
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
        .konsultasi-wrapper {
            width: 100%;
            max-width: 820px;
        }
        .greeting {
            text-align: center;
            margin-bottom: 36px;
        }
        .greeting h2 {
            font-size: 1.9rem;
            font-weight: 800;
            color: #0f172a;
        }
        .greeting p {
            color: #64748b;
            font-size: 1rem;
        }
        .card-konsul {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 12px 40px rgba(14,165,233,.15);
            padding: 40px 32px;
            text-align: center;
            transition: transform .2s, box-shadow .2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .card-konsul:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 50px rgba(14,165,233,.25);
            color: inherit;
        }
        .icon-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.4rem;
        }
        .icon-online { background: linear-gradient(135deg, #38bdf8, #0284c7); }
        .icon-offline { background: linear-gradient(135deg, #34d399, #059669); }
        .card-konsul h4 {
            font-size: 1.3rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: #0f172a;
        }
        .card-konsul p {
            font-size: .88rem;
            color: #64748b;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .badge-tag {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: .78rem;
            font-weight: 600;
        }
        .badge-online { background: #dbeafe; color: #1d4ed8; }
        .badge-offline { background: #d1fae5; color: #065f46; }
        .topbar-right {
            position: fixed;
            top: 16px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-chip {
            background: white;
            border-radius: 50px;
            padding: 6px 16px;
            font-size: .83rem;
            font-weight: 600;
            color: #0369a1;
            box-shadow: 0 2px 10px rgba(14,165,233,.15);
        }
        .btn-logout-top {
            background: white;
            border-radius: 50px;
            padding: 6px 14px;
            font-size: .8rem;
            color: #e11d48;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 2px 10px rgba(14,165,233,.1);
            transition: .2s;
        }
        .btn-logout-top:hover { background: #fee2e2; color: #e11d48; }
        .logo {
            text-align: center;
            font-size: 1.1rem;
            font-weight: 800;
            color: #0369a1;
            margin-bottom: 4px;
            letter-spacing: -.5px;
        }
    </style>
</head>
<body>

<div class="topbar-right">
    <div class="user-chip">👤 <?= htmlspecialchars($userName) ?></div>
    <a href="/api/logout.php" class="btn-logout-top">Keluar</a>
</div>

<div class="konsultasi-wrapper">
    <div class="greeting">
        <div class="logo">🏥 MediTrack</div>
        <h2>Halo, <?= htmlspecialchars($userName) ?>! 👋</h2>
        <p>Mau berkonsultasi dengan dokter bagaimana hari ini?</p>
    </div>

    <div class="row g-4">
        <!-- Online -->
        <div class="col-md-6">
            <a href="/api/dashboarduser.php?page=chat" class="card-konsul">
                <div class="icon-circle icon-online">
                    💬
                </div>
                <h4>Konsultasi Online</h4>
                <p>Chat langsung dengan dokter tanpa perlu datang ke klinik. Cocok untuk konsultasi ringan atau tanya-tanya.</p>
                <span class="badge-tag badge-online">💻 Chat dengan Dokter</span>
            </a>
        </div>

        <!-- Offline -->
        <div class="col-md-6">
            <a href="/api/dashboarduser.php?page=antrean" class="card-konsul">
                <div class="icon-circle icon-offline">
                    🏥
                </div>
                <h4>Konsultasi Offline</h4>
                <p>Kunjungi klinik langsung dan ambil nomor antrean. Lihat jadwal dokter yang tersedia hari ini.</p>
                <span class="badge-tag badge-offline">📅 Lihat Jadwal Antrean</span>
            </a>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="/api/dashboarduser.php" class="text-muted" style="font-size:.85rem; text-decoration:none;">
            Lewati → Langsung ke Dashboard
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
