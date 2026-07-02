<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$db       = getDB();

// Ambil rekam medis user ini (read-only)
$stmt = $db->prepare('SELECT * FROM rekam_medis WHERE user_id = ? ORDER BY tanggal_periksa DESC');
$stmt->execute([$userId]);
$data = $stmt->fetchAll();

$dokterUnik = count(array_unique(array_column($data, 'nama_dokter')));

// Hitung pesan admin yang belum dibaca
$stmtUnread = $db->prepare("SELECT COUNT(*) FROM konsultasi_chat WHERE user_id = ? AND pengirim = 'admin' AND dibaca = 0");
$stmtUnread->execute([$userId]);
$unreadCount = $stmtUnread->fetchColumn();

$page = $_GET['page'] ?? 'dashboard';

// Cek apakah user punya antrean aktif (mode Konsultasi Offline).
// Kalau aktif, akses ke Chat Dokter (mode Online) dikunci total.
$isAntreanAktif = false;
try {
    $stmtCekAntrean = $db->prepare("SELECT COUNT(*) FROM antrean WHERE user_id = ? AND status IN ('menunggu','dikonfirmasi')");
    $stmtCekAntrean->execute([$userId]);
    $isAntreanAktif = $stmtCekAntrean->fetchColumn() > 0;
} catch (Exception $e) {
    // Tabel antrean belum ada / belum pernah dibuat — anggap tidak ada antrean aktif
    $isAntreanAktif = false;
}

if ($page === 'chat' && $isAntreanAktif) {
    header('Location: dashboarduser.php?page=antrean&chat_blocked=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediTrack Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #e0f2fe, #bae6fd);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #0f172a;
            margin: 0;
        }
        .wrapper { display: flex; min-height: 100vh; }
        .sidebar {
            width: 240px; flex-shrink: 0;
            background: linear-gradient(180deg, #0369a1 0%, #0284c7 60%, #38bdf8 100%);
            display: flex; flex-direction: column;
            position: sticky; top: 0; height: 100vh;
            box-shadow: 4px 0 20px rgba(0,0,0,.12);
        }
        .sidebar-brand { font-size: 1.6rem; font-weight: 800; color: white; padding: 24px 20px 4px; letter-spacing: -.5px; }
        .sidebar-brand span { color: #bae6fd; }
        .sidebar-tagline { color: #bae6fd; font-size: .72rem; padding: 0 20px 18px; border-bottom: 1px solid rgba(255,255,255,.15); }
        .sidebar-nav { padding: 14px 10px; flex: 1; }
        .nav-link-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 12px;
            color: #e0f2fe; font-weight: 500; font-size: .88rem;
            text-decoration: none; transition: .2s; margin-bottom: 3px;
            position: relative;
        }
        .nav-link-item:hover { background: rgba(255,255,255,.18); color: white; }
        .nav-link-item.active { background: rgba(255,255,255,.22); color: white; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .nav-section-label { font-size: .68rem; font-weight: 700; letter-spacing: .08em; color: rgba(255,255,255,.45); padding: 12px 14px 4px; text-transform: uppercase; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.15); }
        .sidebar-user-name { color: white; font-weight: 700; font-size: .88rem; }
        .sidebar-user-role { color: #bae6fd; font-size: .72rem; margin-bottom: 10px; }
        .btn-logout-sidebar { display: flex; align-items: center; gap: 7px; color: #fca5a5; font-size: .82rem; text-decoration: none; transition: .2s; }
        .btn-logout-sidebar:hover { color: #f87171; }
        .main-content { flex: 1; padding: 32px; overflow: auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar-title { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .topbar-title span { color: #0ea5e9; }
        .topbar-sub { color: #64748b; font-size: .88rem; margin-top: 2px; }
        .topbar-badge { background: white; border-radius: 50px; padding: 8px 18px; font-size: .85rem; font-weight: 600; color: #0369a1; box-shadow: 0 2px 10px rgba(14,165,233,.15); }
        .card-custom { background: white; border: none; border-radius: 20px; box-shadow: 0 8px 20px rgba(14,165,233,.12); }
        .stat-card { border-radius: 18px; padding: 20px; background: white; box-shadow: 0 8px 20px rgba(14,165,233,.12); }
        .stat-number { font-size: 2rem; font-weight: 800; color: #0ea5e9; }
        .card-header-custom { background: #f0f9ff; border-bottom: 1px solid #dbeafe; border-radius: 20px 20px 0 0 !important; padding: 16px 24px; }
        .badge-custom { background: #38bdf8; color: white; padding: 7px 14px; border-radius: 10px; font-weight: 600; font-size: .82rem; }
        .table thead { background: #38bdf8; color: white; }
        .table thead th { border: none; }
        .antrean-btn { display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; border: none; border-radius: 14px; padding: 12px 28px; font-weight: 700; font-size: .95rem; text-decoration: none; box-shadow: 0 4px 15px rgba(14,165,233,.3); transition: .2s; }
        .antrean-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(14,165,233,.4); color: white; }
        .antrean-card { background: linear-gradient(135deg, #0ea5e9, #0369a1); border-radius: 20px; padding: 32px; color: white; box-shadow: 0 10px 30px rgba(14,165,233,.3); }
        .badge-notif { background: #ef4444; color: white; border-radius: 50px; font-size: .65rem; padding: 2px 7px; position: absolute; top: 6px; right: 10px; font-weight: 700; }
    </style>
</head>
<body>

<div class="wrapper">
    <aside class="sidebar">
        <div class="sidebar-brand">Medi<span>Track.</span></div>
        <div class="sidebar-tagline">Rekam Medis Digital</div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Menu Utama</div>

            <a href="/api/dashboarduser.php?page=dashboard"
               class="nav-link-item <?= ($page === 'dashboard') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>

            <a href="/api/dashboarduser.php?page=rekam"
               class="nav-link-item <?= ($page === 'rekam') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Rekam Medis
            </a>

            <div class="nav-section-label" style="margin-top:8px">Layanan</div>

            <a href="/api/dashboarduser.php?page=antrean"
               class="nav-link-item <?= ($page === 'antrean') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Jadwal & Antrean
            </a>

            <a href="/api/dashboarduser.php?page=chat"
               class="nav-link-item <?= ($page === 'chat') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                Chat Dokter
                <?php if ($unreadCount > 0): ?>
                    <span class="badge-notif"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-role">Login sebagai</div>
            <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
            <a href="/api/logout.php" class="btn-logout-sidebar mt-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Keluar
            </a>
        </div>
    </aside>

    <main class="main-content">

        <?php if ($page === 'dashboard'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">🏥 Dashboard <span>MediTrack</span></div>
                <div class="topbar-sub">Selamat datang kembali, <?= htmlspecialchars($userName) ?> 👋</div>
            </div>
            <div class="topbar-badge">📅 <?= date('d F Y') ?></div>
        </div>

        <div class="alert" style="background:#dbeafe;border:1px solid #93c5fd;color:#0369a1;border-radius:14px;" class="mb-4">
            ✨ Sistem rekam medis digital siap digunakan.
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Total Rekam Medis</h6>
                    <div class="stat-number"><?= count($data) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Dokter Berbeda</h6>
                    <div class="stat-number"><?= $dokterUnik ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Pesan Belum Dibaca</h6>
                    <div class="stat-number" style="color:<?= $unreadCount > 0 ? '#ef4444' : '#0ea5e9' ?>"><?= $unreadCount ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-md-6">
                <div class="antrean-card">
                    <h5 class="fw-bold mb-2">📋 Konsultasi Offline</h5>
                    <p style="opacity:.85;font-size:.9rem;margin-bottom:18px">Cek jadwal dokter dan ambil nomor antrean secara online.</p>
                    <a href="/api/dashboarduser.php?page=antrean" class="antrean-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Lihat Jadwal & Antrean
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background:linear-gradient(135deg,#7c3aed,#4f46e5);border-radius:20px;padding:32px;color:white;box-shadow:0 10px 30px rgba(109,40,217,.3);height:100%;">
                    <h5 class="fw-bold mb-2">💬 Konsultasi Online</h5>
                    <p style="opacity:.85;font-size:.9rem;margin-bottom:18px">Chat langsung dengan dokter tanpa perlu datang ke klinik.</p>
                    <a href="/api/dashboarduser.php?page=chat" style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.2);color:white;border-radius:14px;padding:12px 24px;font-weight:700;font-size:.95rem;text-decoration:none;transition:.2s;" onmouseover="this.style.background='rgba(255,255,255,.3)'" onmouseout="this.style.background='rgba(255,255,255,.2)'">
                        💬 Chat Dokter
                        <?php if ($unreadCount > 0): ?>
                            <span style="background:#ef4444;border-radius:50px;padding:2px 8px;font-size:.75rem;"><?= $unreadCount ?> baru</span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'rekam'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">📋 <span>Rekam Medis</span></div>
                <div class="topbar-sub">Riwayat rekam medis kamu</div>
            </div>
        </div>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">📑 Data Rekam Medis Saya</h5>
                <span class="badge-custom"><?= count($data) ?> Data</span>
            </div>
            <div class="p-3">
                <?php if (empty($data)): ?>
                <div class="text-center text-muted py-5">
                    <div style="font-size:3rem;margin-bottom:12px;">📂</div>
                    <div>Belum ada data rekam medis.</div>
                    <small>Data rekam medis akan muncul setelah dokter mencatatkannya.</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>No</th><th>Nama Pasien</th><th>Tanggal</th>
                                <th>Diagnosa</th><th>Dokter</th><th>Poliklinik</th><th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $i => $item): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($item['nama_pasien']) ?></td>
                                <td><?= htmlspecialchars($item['tanggal_periksa']) ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($item['diagnosa']) ?></span></td>
                                <td><?= htmlspecialchars($item['nama_dokter']) ?></td>
                                <td><?= htmlspecialchars($item['poliklinik']) ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($item['catatan'] ?? '-') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($page === 'antrean'): ?>
        <?php include __DIR__ . '/api/pages/antrean.php'; ?>

        <?php elseif ($page === 'chat'): ?>
        <?php include __DIR__ . '/api/pages/chat.php'; ?>

        <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>