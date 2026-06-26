<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId    = $_SESSION['user_id'];
$userName  = $_SESSION['user_name'];
$db        = getDB();
$success   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_pasien     = trim($_POST['nama_pasien']     ?? '');
    $tanggal_periksa = trim($_POST['tanggal_periksa'] ?? '');
    $diagnosa        = trim($_POST['diagnosa']        ?? '');
    $nama_dokter     = trim($_POST['nama_dokter']     ?? '');
    $poliklinik      = trim($_POST['poliklinik']      ?? '');
    $catatan         = trim($_POST['catatan']         ?? '');

    if (!$nama_pasien || !$tanggal_periksa || !$diagnosa || !$nama_dokter || !$poliklinik) {
        $error = 'Semua field wajib diisi (kecuali catatan).';
    } else {
        $ins = $db->prepare(
            'INSERT INTO rekam_medis
             (user_id, nama_pasien, tanggal_periksa, diagnosa, nama_dokter, poliklinik, catatan)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$userId, $nama_pasien, $tanggal_periksa, $diagnosa, $nama_dokter, $poliklinik, $catatan]);
        $success = 'Data rekam medis berhasil disimpan!';
    }
}

$stmt = $db->prepare('SELECT * FROM rekam_medis WHERE user_id = ? ORDER BY tanggal_periksa DESC');
$stmt->execute([$userId]);
$data = $stmt->fetchAll();

$dokterUnik = count(array_unique(array_column($data, 'nama_dokter')));

// Deteksi halaman aktif
$page = $_GET['page'] ?? 'dashboard';
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

        /* ── LAYOUT ── */
        .wrapper { display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 240px; flex-shrink: 0;
            background: linear-gradient(180deg, #0369a1 0%, #0284c7 60%, #38bdf8 100%);
            display: flex; flex-direction: column;
            position: sticky; top: 0; height: 100vh;
            box-shadow: 4px 0 20px rgba(0,0,0,.12);
        }
        .sidebar-brand {
            font-size: 1.6rem; font-weight: 800; color: white;
            padding: 24px 20px 4px; letter-spacing: -.5px;
        }
        .sidebar-brand span { color: #bae6fd; }
        .sidebar-tagline {
            color: #bae6fd; font-size: .72rem;
            padding: 0 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,.15);
        }
        .sidebar-nav { padding: 14px 10px; flex: 1; }
        .nav-link-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 12px;
            color: #e0f2fe; font-weight: 500; font-size: .88rem;
            text-decoration: none; transition: .2s; margin-bottom: 3px;
        }
        .nav-link-item:hover { background: rgba(255,255,255,.18); color: white; }
        .nav-link-item.active { background: rgba(255,255,255,.22); color: white; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .nav-section-label {
            font-size: .68rem; font-weight: 700; letter-spacing: .08em;
            color: rgba(255,255,255,.45); padding: 12px 14px 4px; text-transform: uppercase;
        }
        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,.15);
        }
        .sidebar-user-name { color: white; font-weight: 700; font-size: .88rem; }
        .sidebar-user-role { color: #bae6fd; font-size: .72rem; margin-bottom: 10px; }
        .btn-logout-sidebar {
            display: flex; align-items: center; gap: 7px;
            color: #fca5a5; font-size: .82rem;
            text-decoration: none; transition: .2s;
        }
        .btn-logout-sidebar:hover { color: #f87171; }

        /* ── MAIN ── */
        .main-content { flex: 1; padding: 32px; overflow: auto; }

        /* ── TOPBAR ── */
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px;
        }
        .topbar-title { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .topbar-title span { color: #0ea5e9; }
        .topbar-sub { color: #64748b; font-size: .88rem; margin-top: 2px; }
        .topbar-badge {
            background: white; border-radius: 50px; padding: 8px 18px;
            font-size: .85rem; font-weight: 600; color: #0369a1;
            box-shadow: 0 2px 10px rgba(14,165,233,.15);
        }

        /* ── CARDS ── */
        .card-custom {
            background: white; border: none;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(14,165,233,.12);
        }
        .stat-card {
            border-radius: 18px; padding: 20px; background: white;
            box-shadow: 0 8px 20px rgba(14,165,233,.12);
        }
        .stat-number { font-size: 2rem; font-weight: 800; color: #0ea5e9; }
        .alert-info-custom {
            background: #dbeafe; border: 1px solid #93c5fd;
            color: #0369a1; border-radius: 14px;
        }
        .card-header-custom {
            background: #f0f9ff; border-bottom: 1px solid #dbeafe;
            border-radius: 20px 20px 0 0 !important; padding: 16px 24px;
        }
        .badge-custom {
            background: #38bdf8; color: white;
            padding: 7px 14px; border-radius: 10px; font-weight: 600; font-size: .82rem;
        }

        /* ── FORM ── */
        .form-control, .form-select {
            border-radius: 11px; border: 1px solid #cbd5e1; padding: 10px 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.2);
        }
        .btn-save {
            background: #38bdf8; border: none; color: white;
            font-weight: 600; border-radius: 11px; padding: 10px 25px;
        }
        .btn-save:hover { background: #0ea5e9; color: white; }

        /* ── TABLE ── */
        .table thead { background: #38bdf8; color: white; }
        .table thead th { border: none; }

        /* ── ANTREAN SECTION ── */
        .antrean-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white; border: none; border-radius: 14px;
            padding: 12px 28px; font-weight: 700; font-size: .95rem;
            text-decoration: none; box-shadow: 0 4px 15px rgba(14,165,233,.3);
            transition: .2s;
        }
        .antrean-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(14,165,233,.4); color: white; }
        .antrean-card {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            border-radius: 20px; padding: 32px;
            color: white; box-shadow: 0 10px 30px rgba(14,165,233,.3);
        }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- ════════════ SIDEBAR ════════════ -->
    <aside class="sidebar">
        <div class="sidebar-brand">Medi<span>Track.</span></div>
        <div class="sidebar-tagline">Rekam Medis Digital</div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Menu Utama</div>

            <a href="dashboarduser.php?page=dashboard"
               class="nav-link-item <?= ($page === 'dashboard') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>

            <a href="dashboarduser.php?page=rekam"
               class="nav-link-item <?= ($page === 'rekam') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Rekam Medis
            </a>

            <div class="nav-section-label" style="margin-top:8px">Layanan</div>

            <a href="dashboarduser.php?page=antrean"
               class="nav-link-item <?= ($page === 'antrean') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Jadwal & Antrean
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-role">Login sebagai</div>
            <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
            <a href="logout.php" class="btn-logout-sidebar mt-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Keluar
            </a>
        </div>
    </aside>

    <!-- ════════════ MAIN CONTENT ════════════ -->
    <main class="main-content">

        <?php if ($page === 'dashboard'): ?>
        <!-- ── PAGE: DASHBOARD ── -->
        <div class="topbar">
            <div>
                <div class="topbar-title">🏥 Dashboard <span>MediTrack</span></div>
                <div class="topbar-sub">Selamat datang kembali, <?= htmlspecialchars($userName) ?> 👋</div>
            </div>
            <div class="topbar-badge">📅 <?= date('d F Y') ?></div>
        </div>

        <div class="alert alert-info-custom mb-4">
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
                    <h6 class="text-muted mb-1">Pasien Tercatat</h6>
                    <div class="stat-number"><?= count($data) ?></div>
                </div>
            </div>
        </div>

        <!-- Shortcut ke antrean -->
        <div class="antrean-card mt-4">
            <h5 class="fw-bold mb-2">📋 Butuh Jadwal Dokter?</h5>
            <p style="opacity:.85; font-size:.9rem; margin-bottom:18px">
                Cek jadwal dokter hari ini dan ambil nomor antrean secara online.
            </p>
            <a href="dashboarduser.php?page=antrean" class="antrean-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Lihat Jadwal & Antrean
            </a>
        </div>

        <?php elseif ($page === 'rekam'): ?>
        <!-- ── PAGE: REKAM MEDIS ── -->
        <div class="topbar">
            <div>
                <div class="topbar-title">📋 <span>Rekam Medis</span></div>
                <div class="topbar-sub">Input dan lihat data rekam medis pasien</div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-3 mb-3"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 mb-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card-custom p-4 mb-4">
            <h5 class="fw-bold mb-4">Input Data Baru</h5>
            <form method="POST" action="dashboarduser.php?page=rekam">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Pasien</label>
                        <input type="text" name="nama_pasien" class="form-control" placeholder="Nama lengkap" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Periksa</label>
                        <input type="date" name="tanggal_periksa" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Diagnosa</label>
                    <input type="text" name="diagnosa" class="form-control" placeholder="Contoh: Hipertensi, ISPA, dll" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Dokter</label>
                        <input type="text" name="nama_dokter" class="form-control" placeholder="dr. Nama Dokter" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Poliklinik</label>
                        <select name="poliklinik" class="form-select" required>
                            <option value="">Pilih Poliklinik</option>
                            <option>Umum</option>
                            <option>Anak</option>
                            <option>Gigi</option>
                            <option>Penyakit Dalam</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Catatan Tambahan</label>
                    <textarea name="catatan" class="form-control" rows="3" placeholder="Keluhan, tindakan, resep..."></textarea>
                </div>
                <button type="submit" class="btn btn-save">💾 Simpan Data</button>
            </form>
        </div>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">📑 Data Rekam Medis</h5>
                <span class="badge-custom"><?= count($data) ?> Data</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>No</th><th>Nama Pasien</th><th>Tanggal</th>
                                <th>Diagnosa</th><th>Dokter</th><th>Poliklinik</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data rekam medis</td></tr>
                            <?php else: ?>
                                <?php foreach ($data as $i => $item): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($item['nama_pasien']) ?></td>
                                    <td><?= htmlspecialchars($item['tanggal_periksa']) ?></td>
                                    <td><?= htmlspecialchars($item['diagnosa']) ?></td>
                                    <td><?= htmlspecialchars($item['nama_dokter']) ?></td>
                                    <td><?= htmlspecialchars($item['poliklinik']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'antrean'): ?>
        <!-- ── PAGE: JADWAL & ANTREAN ── -->
        <?php include __DIR__ . '/pages/antrean.php'; ?>

        <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>