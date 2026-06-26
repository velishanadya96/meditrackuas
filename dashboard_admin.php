<?php
session_start();

// Guard: harus login + harus admin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: dashboarduser.php");
    exit;
}

require_once 'db.php';

$pdo      = getDB();
$message  = '';
$userName = $_SESSION['user_name'];

// Deteksi halaman aktif
$page   = $_GET['page']   ?? 'dashboard';
$action = $_GET['action'] ?? 'list';
$id     = $_GET['id']     ?? null;

// ── CRUD DOKTER ──────────────────────────────────────────────
if ($page === 'dokter') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nama         = trim($_POST['nama']);
        $spesialisasi = trim($_POST['spesialisasi']);
        $jadwal       = trim($_POST['jadwal']);
        $kuota        = (int) $_POST['kuota'];

        if ($_POST['form_action'] === 'tambah') {
            $stmt = $pdo->prepare("INSERT INTO dokter (nama, spesialisasi, jadwal, kuota) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nama, $spesialisasi, $jadwal, $kuota]);
            $message = 'success|Dokter berhasil ditambahkan.';
        } elseif ($_POST['form_action'] === 'edit') {
            $stmt = $pdo->prepare("UPDATE dokter SET nama=?, spesialisasi=?, jadwal=?, kuota=? WHERE id=?");
            $stmt->execute([$nama, $spesialisasi, $jadwal, $kuota, $_POST['edit_id']]);
            $message = 'success|Data dokter berhasil diupdate.';
        }
        $action = 'list';
    }

    if ($action === 'hapus' && $id) {
        $stmt = $pdo->prepare("DELETE FROM dokter WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'success|Dokter berhasil dihapus.';
        $action = 'list';
    }

    $dokters = $pdo->query("SELECT * FROM dokter ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// ── REKAM MEDIS ───────────────────────────────────────────────
$successRekam = '';
$errorRekam   = '';

if ($page === 'rekam') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nama_pasien     = trim($_POST['nama_pasien']     ?? '');
        $tanggal_periksa = trim($_POST['tanggal_periksa'] ?? '');
        $diagnosa        = trim($_POST['diagnosa']        ?? '');
        $nama_dokter     = trim($_POST['nama_dokter']     ?? '');
        $poliklinik      = trim($_POST['poliklinik']      ?? '');
        $catatan         = trim($_POST['catatan']         ?? '');
        $user_id_rekam   = trim($_POST['user_id_rekam']   ?? '');

        if (!$nama_pasien || !$tanggal_periksa || !$diagnosa || !$nama_dokter || !$poliklinik) {
            $errorRekam = 'Semua field wajib diisi (kecuali catatan).';
        } else {
            $uid = $user_id_rekam ?: $_SESSION['user_id'];
            $ins = $pdo->prepare(
                'INSERT INTO rekam_medis
                 (user_id, nama_pasien, tanggal_periksa, diagnosa, nama_dokter, poliklinik, catatan)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$uid, $nama_pasien, $tanggal_periksa, $diagnosa, $nama_dokter, $poliklinik, $catatan]);
            $successRekam = 'Data rekam medis berhasil disimpan!';
        }
    }

    // Admin lihat semua rekam medis
    $stmtRekam = $pdo->query('SELECT rm.*, u.name AS nama_user FROM rekam_medis rm LEFT JOIN users u ON rm.user_id = u.id ORDER BY rm.tanggal_periksa DESC');
    $dataRekam = $stmtRekam->fetchAll();
}

// ── STAT DASHBOARD ────────────────────────────────────────────
if ($page === 'dashboard') {
    $totalDokter  = $pdo->query("SELECT COUNT(*) FROM dokter")->fetchColumn();
    $totalRekam   = $pdo->query("SELECT COUNT(*) FROM rekam_medis")->fetchColumn();
    $totalUser    = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediTrack Admin</title>
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
            padding: 0 20px 10px;
        }
        .badge-admin-tag {
            display: inline-block;
            background: rgba(239,68,68,.85);
            color: white; font-size: .65rem; font-weight: 700;
            padding: 2px 8px; border-radius: 20px;
            margin: 0 20px 14px;
            letter-spacing: .04em;
            border-bottom: 1px solid rgba(255,255,255,.15);
            width: fit-content;
        }
        .sidebar-nav { padding: 6px 10px; flex: 1; }
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

        /* ── ACTION BUTTONS ── */
        .btn-edit-custom {
            background: #fbbf24; border: none; color: white;
            border-radius: 8px; padding: 5px 12px; font-size: .8rem; font-weight: 600;
        }
        .btn-edit-custom:hover { background: #f59e0b; color: white; }
        .btn-del-custom {
            background: #f87171; border: none; color: white;
            border-radius: 8px; padding: 5px 12px; font-size: .8rem; font-weight: 600;
        }
        .btn-del-custom:hover { background: #ef4444; color: white; }
        .btn-primary-custom {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            border: none; color: white;
            font-weight: 600; border-radius: 11px; padding: 10px 22px;
            box-shadow: 0 4px 12px rgba(14,165,233,.3); transition: .2s;
        }
        .btn-primary-custom:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(14,165,233,.4); color: white; }

        /* ── QUICK CARD ── */
        .quick-card {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            border-radius: 20px; padding: 28px;
            color: white; box-shadow: 0 10px 30px rgba(14,165,233,.3);
        }
        .quick-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.2); color: white;
            border: 1.5px solid rgba(255,255,255,.4);
            border-radius: 12px; padding: 10px 22px;
            font-weight: 600; font-size: .9rem; text-decoration: none; transition: .2s;
        }
        .quick-btn:hover { background: rgba(255,255,255,.32); color: white; }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- ════════════ SIDEBAR ════════════ -->
    <aside class="sidebar">
        <div class="sidebar-brand">Medi<span>Track.</span></div>
        <div class="sidebar-tagline">Rekam Medis Digital</div>
        <div class="badge-admin-tag">⚙️ ADMIN</div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Menu Utama</div>

            <a href="dashboard_admin.php?page=dashboard"
               class="nav-link-item <?= ($page === 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <div class="nav-section-label" style="margin-top:8px">Manajemen</div>

            <a href="dashboard_admin.php?page=dokter"
               class="nav-link-item <?= ($page === 'dokter') ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i> Kelola Dokter
            </a>

            <a href="dashboard_admin.php?page=rekam"
               class="nav-link-item <?= ($page === 'rekam') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-medical"></i> Rekam Medis
            </a>

            <div class="nav-section-label" style="margin-top:8px">Akses</div>

            <a href="dashboarduser.php"
               class="nav-link-item">
                <i class="bi bi-person-circle"></i> Dashboard User
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-role">Login sebagai Admin</div>
            <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
            <a href="logout.php" class="btn-logout-sidebar mt-2">
                <i class="bi bi-box-arrow-right"></i> Keluar
            </a>
        </div>
    </aside>

    <!-- ════════════ MAIN CONTENT ════════════ -->
    <main class="main-content">

        <?php if ($page === 'dashboard'): ?>
        <!-- ── PAGE: DASHBOARD ── -->
        <div class="topbar">
            <div>
                <div class="topbar-title">⚙️ Dashboard <span>Admin</span></div>
                <div class="topbar-sub">Selamat datang kembali, <?= htmlspecialchars($userName) ?> 👋</div>
            </div>
            <div class="topbar-badge">📅 <?= date('d F Y') ?></div>
        </div>

        <div class="alert alert-info-custom mb-4">
            ✨ Panel admin aktif — kelola dokter dan rekam medis dari sini.
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Total Dokter</h6>
                    <div class="stat-number"><?= $totalDokter ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Total Rekam Medis</h6>
                    <div class="stat-number"><?= $totalRekam ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <h6 class="text-muted mb-1">Total User</h6>
                    <div class="stat-number"><?= $totalUser ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-0">
            <div class="col-md-6">
                <div class="quick-card">
                    <h5 class="fw-bold mb-2"><i class="bi bi-person-badge me-2"></i>Kelola Dokter</h5>
                    <p style="opacity:.85; font-size:.9rem; margin-bottom:18px">
                        Tambah, edit, atau hapus data dokter dan jadwal praktik.
                    </p>
                    <a href="dashboard_admin.php?page=dokter" class="quick-btn">
                        <i class="bi bi-arrow-right-circle"></i> Buka Kelola Dokter
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="quick-card">
                    <h5 class="fw-bold mb-2"><i class="bi bi-file-earmark-medical me-2"></i>Rekam Medis</h5>
                    <p style="opacity:.85; font-size:.9rem; margin-bottom:18px">
                        Lihat dan input seluruh data rekam medis pasien.
                    </p>
                    <a href="dashboard_admin.php?page=rekam" class="quick-btn">
                        <i class="bi bi-arrow-right-circle"></i> Buka Rekam Medis
                    </a>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'dokter'): ?>
        <!-- ── PAGE: KELOLA DOKTER ── -->
        <div class="topbar">
            <div>
                <div class="topbar-title"><i class="bi bi-person-badge me-2"></i><span>Kelola Dokter</span></div>
                <div class="topbar-sub">Manajemen data dokter dan jadwal praktik</div>
            </div>
            <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle me-1"></i> Tambah Dokter
            </button>
        </div>

        <?php if ($message):
            [$type, $text] = explode('|', $message);
            $cls = $type === 'success' ? 'alert-success' : 'alert-danger';
        ?>
        <div class="alert <?= $cls ?> alert-dismissible fade show rounded-3 mb-3">
            <?= htmlspecialchars($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">📋 Daftar Dokter</h5>
                <span class="badge-custom"><?= count($dokters) ?> Dokter</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nama Dokter</th>
                                <th>Spesialisasi</th>
                                <th>Jadwal</th>
                                <th>Kuota/Hari</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($dokters)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data dokter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dokters as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($d['nama']) ?></strong></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($d['spesialisasi']) ?></span></td>
                                <td><?= htmlspecialchars($d['jadwal']) ?></td>
                                <td><?= $d['kuota'] ?> pasien</td>
                                <td class="text-center">
                                    <button class="btn-edit-custom me-1"
                                        onclick="openEdit(<?= htmlspecialchars(json_encode($d)) ?>)">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <a href="?page=dokter&action=hapus&id=<?= $d['id'] ?>"
                                       class="btn-del-custom"
                                       onclick="return confirm('Hapus dokter <?= htmlspecialchars($d['nama']) ?>?')">
                                        <i class="bi bi-trash"></i> Hapus
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Tambah -->
        <div class="modal fade" id="modalTambah" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="border-radius:16px">
                    <form method="POST" action="dashboard_admin.php?page=dokter">
                        <input type="hidden" name="form_action" value="tambah">
                        <div class="modal-header" style="border-bottom:1px solid #dbeafe">
                            <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-info"></i>Tambah Dokter</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Dokter</label>
                                <input type="text" name="nama" class="form-control" placeholder="dr. Nama Dokter" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Spesialisasi</label>
                                <input type="text" name="spesialisasi" class="form-control" placeholder="Contoh: Umum, Anak, Gigi" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jadwal</label>
                                <input type="text" name="jadwal" class="form-control" placeholder="Contoh: Senin–Jumat, 08:00–12:00" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kuota / Hari</label>
                                <input type="number" name="kuota" class="form-control" placeholder="20" min="1" required>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top:1px solid #dbeafe">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn-save"><i class="bi bi-save me-1"></i>Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Edit -->
        <div class="modal fade" id="modalEdit" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="border-radius:16px">
                    <form method="POST" action="dashboard_admin.php?page=dokter">
                        <input type="hidden" name="form_action" value="edit">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <div class="modal-header" style="border-bottom:1px solid #dbeafe">
                            <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-warning"></i>Edit Dokter</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Dokter</label>
                                <input type="text" name="nama" id="f_nama" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Spesialisasi</label>
                                <input type="text" name="spesialisasi" id="f_spesialisasi" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jadwal</label>
                                <input type="text" name="jadwal" id="f_jadwal" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kuota / Hari</label>
                                <input type="number" name="kuota" id="f_kuota" class="form-control" min="1" required>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top:1px solid #dbeafe">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn-save" style="background:#fbbf24"><i class="bi bi-save me-1"></i>Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'rekam'): ?>
        <!-- ── PAGE: REKAM MEDIS ── -->
        <div class="topbar">
            <div>
                <div class="topbar-title">📋 <span>Rekam Medis</span></div>
                <div class="topbar-sub">Input dan lihat seluruh data rekam medis pasien</div>
            </div>
        </div>

        <?php if ($successRekam): ?>
            <div class="alert alert-success rounded-3 mb-3"><?= htmlspecialchars($successRekam) ?></div>
        <?php endif; ?>
        <?php if ($errorRekam): ?>
            <div class="alert alert-danger rounded-3 mb-3"><?= htmlspecialchars($errorRekam) ?></div>
        <?php endif; ?>

        <div class="card-custom p-4 mb-4">
            <h5 class="fw-bold mb-4">Input Data Baru</h5>
            <form method="POST" action="dashboard_admin.php?page=rekam">
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
                <div class="mb-3">
                    <label class="form-label">User ID Pasien <small class="text-muted">(opsional — kosongkan untuk pakai ID admin)</small></label>
                    <input type="number" name="user_id_rekam" class="form-control" placeholder="ID user pemilik rekam medis">
                </div>
                <button type="submit" class="btn btn-save">💾 Simpan Data</button>
            </form>
        </div>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">📑 Semua Data Rekam Medis</h5>
                <span class="badge-custom"><?= count($dataRekam) ?> Data</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>No</th><th>User</th><th>Nama Pasien</th><th>Tanggal</th>
                                <th>Diagnosa</th><th>Dokter</th><th>Poliklinik</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dataRekam)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data rekam medis</td></tr>
                            <?php else: ?>
                                <?php foreach ($dataRekam as $i => $item): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($item['nama_user'] ?? 'N/A') ?></small></td>
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

        <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(data) {
    document.getElementById('edit_id').value       = data.id;
    document.getElementById('f_nama').value        = data.nama;
    document.getElementById('f_spesialisasi').value = data.spesialisasi;
    document.getElementById('f_jadwal').value      = data.jadwal;
    document.getElementById('f_kuota').value       = data.kuota;
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}
</script>
</body>
</html>
