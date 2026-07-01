<?php

session_start();

// --- Guard: hanya dokter yang boleh akses ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'dokter') {
    header('Location: login.php');
    exit;
}

require_once 'db.php'; // koneksi PDO / mysqli dari project

$dokter_id = $_SESSION['user_id'];
$hari_ini  = date('Y-m-d');
$hari_en   = strtolower(date('l')); // monday, tuesday, …
// Mapping hari Inggris → Indonesia untuk perbandingan kolom hari
$hari_map = [
    'monday'    => 'Senin',
    'tuesday'   => 'Selasa',
    'wednesday' => 'Rabu',
    'thursday'  => 'Kamis',
    'friday'    => 'Jumat',
    'saturday'  => 'Sabtu',
    'sunday'    => 'Minggu',
];
$hari_id = $hari_map[$hari_en] ?? ucfirst($hari_en);

// ============================================================
//  QUERY: Data profil dokter
// ============================================================
$stmt = $conn->prepare("
    SELECT d.*, u.email, u.foto
    FROM dokter d
    JOIN users u ON u.id = d.user_id
    WHERE d.user_id = ?
    LIMIT 1
");
$stmt->execute([$dokter_id]);
$dokter = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback jika tabel/kolom sedikit berbeda – pakai query users saja
if (!$dokter) {
    $stmt2 = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt2->execute([$dokter_id]);
    $dokter = $stmt2->fetch(PDO::FETCH_ASSOC);
}

$nama_dokter   = $dokter['nama']          ?? $dokter['name']           ?? 'Dokter';
$spesialis     = $dokter['spesialis']     ?? $dokter['specialization']  ?? '-';
$nomor_sip     = $dokter['nomor_sip']     ?? $dokter['sip']             ?? '-';
$pengalaman    = $dokter['pengalaman']    ?? $dokter['experience']       ?? '-';
$biaya         = $dokter['biaya_konsultasi'] ?? $dokter['biaya']        ?? 0;
$durasi        = $dokter['durasi_konsultasi'] ?? $dokter['durasi']      ?? '-';
$foto_profil   = $dokter['foto']          ?? null;

// ============================================================
//  QUERY: Statistik – Jumlah Pasien Hari Ini
// ============================================================
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT pasien_id) AS total
    FROM antrean
    WHERE dokter_id = ? AND DATE(tanggal) = ?
");
$stmt->execute([$dokter_id, $hari_ini]);
$jml_pasien = $stmt->fetchColumn() ?: 0;

// ============================================================
//  QUERY: Statistik – Antrean Hari Ini
// ============================================================
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM antrean
    WHERE dokter_id = ? AND DATE(tanggal) = ?
");
$stmt->execute([$dokter_id, $hari_ini]);
$jml_antrean = $stmt->fetchColumn() ?: 0;

// ============================================================
//  QUERY: Statistik – Chat Belum Dibaca
// ============================================================
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM chat
    WHERE penerima_id = ? AND is_read = 0
");
$stmt->execute([$dokter_id]);
$jml_chat_unread = $stmt->fetchColumn() ?: 0;

// ============================================================
//  QUERY: Statistik – Rekam Medis Belum Diisi
// ============================================================
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM antrean a
    LEFT JOIN rekam_medis r ON r.antrean_id = a.id
    WHERE a.dokter_id = ? AND r.id IS NULL
      AND a.status IN ('selesai', 'done', 'completed')
");
$stmt->execute([$dokter_id]);
$jml_rm_pending = $stmt->fetchColumn() ?: 0;

// ============================================================
//  QUERY: Jadwal Praktik Hari Ini
// ============================================================
$stmt = $conn->prepare("
    SELECT *
    FROM jadwal_dokter
    WHERE dokter_id = ? AND (hari = ? OR tanggal = ?)
    ORDER BY jam_mulai ASC
");
$stmt->execute([$dokter_id, $hari_id, $hari_ini]);
$jadwal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
//  QUERY: Antrean Pasien Hari Ini
// ============================================================
$stmt = $conn->prepare("
    SELECT a.*, u.nama AS nama_pasien, u.name AS name_pasien
    FROM antrean a
    JOIN users u ON u.id = a.pasien_id
    WHERE a.dokter_id = ? AND DATE(a.tanggal) = ?
    ORDER BY a.nomor_antrean ASC
");
$stmt->execute([$dokter_id, $hari_ini]);
$antrean_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
//  QUERY: Chat Terbaru (5 percakapan terakhir)
// ============================================================
$stmt = $conn->prepare("
    SELECT c.*, u.nama AS nama_pengirim, u.name AS name_pengirim
    FROM chat c
    JOIN users u ON u.id = c.pengirim_id
    WHERE c.penerima_id = ?
    ORDER BY c.created_at DESC
    LIMIT 5
");
$stmt->execute([$dokter_id]);
$chat_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
//  QUERY: Rekam Medis Pending (konsultasi selesai belum diisi)
// ============================================================
$stmt = $conn->prepare("
    SELECT a.id AS antrean_id,
           a.tanggal,
           a.status,
           u.nama  AS nama_pasien,
           u.name  AS name_pasien
    FROM antrean a
    JOIN users u ON u.id = a.pasien_id
    LEFT JOIN rekam_medis r ON r.antrean_id = a.id
    WHERE a.dokter_id = ? AND r.id IS NULL
      AND a.status IN ('selesai', 'done', 'completed')
    ORDER BY a.tanggal DESC
    LIMIT 10
");
$stmt->execute([$dokter_id]);
$rm_pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: format rupiah
function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

// Helper: potong teks
function potong($str, $max = 60) {
    return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
}

// Helper: foto profil
function foto_src($foto) {
    if ($foto && file_exists($foto))  return htmlspecialchars($foto);
    if ($foto && str_starts_with($foto, 'http')) return htmlspecialchars($foto);
    return 'https://ui-avatars.com/api/?name=Dokter&background=4E9DB8&color=fff&size=128';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Dokter – Telemedicine</title>

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
/* ── Root Variables (Disinkronkan Sempurna dengan Referensi Admin) ── */
:root {
  --primary:      #0ea5e9; /* Sky Blue Utama */
  --primary-dark: #0369a1; /* Ocean Blue Tua */
  --primary-light:#f0f9ff; /* Background header kartu / aktif ringan */
  --secondary:    #64748b; /* Slate Muted Text */
  --bg-gradient:  linear-gradient(135deg, #e0f2fe, #bae6fd); /* Gradient halaman */
  --white:        #ffffff;
  --text-main:    #0f172a; /* Slate Dark 900 */
  --text-muted:   #64748b; /* Slate Light 500 */
  --sidebar-bg:   linear-gradient(180deg, #0369a1 0%, #0284c7 60%, #38bdf8 100%);
  --sidebar-w:    260px;
  --radius:       20px;    /* Rounded 20px sesuai referensi card-custom */
  --shadow:       0 8px 20px rgba(14,165,233,.12); /* Shadow khas referensi */
  --shadow-hover: 0 12px 28px rgba(14,165,233,.2);
  --transition:   .2s ease;
}

/* ── Base ───────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
body {
  font-family: 'Segoe UI', Arial, sans-serif;
  background: var(--bg-gradient);
  color: var(--text-main);
  margin: 0;
  overflow-x: hidden;
  min-height: 100vh;
}

/* ── Sidebar (Identik dengan Referensi User/Admin) ── */
#sidebar {
  position: fixed;
  top: 0; left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--sidebar-bg);
  z-index: 1040;
  display: flex;
  flex-direction: column;
  transition: transform var(--transition);
  overflow-y: auto;
  box-shadow: 4px 0 20px rgba(0,0,0,.12);
}
#sidebar .sidebar-brand {
  padding: 24px 20px 4px;
}
#sidebar .sidebar-brand .brand-icon {
  width: 40px; height: 40px;
  background: var(--white);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: var(--primary-dark);
  font-size: 1.3rem;
  font-weight: 700;
  margin-bottom: .5rem;
}
#sidebar .sidebar-brand h5 {
  color: #fff;
  font-weight: 800;
  font-size: 1.6rem;
  margin: 0;
  letter-spacing: -.5px;
}
#sidebar .sidebar-brand h5 span {
  color: #bae6fd;
}
#sidebar .sidebar-brand p {
  color: #bae6fd;
  font-size: .72rem;
  margin: 0;
  padding: 4px 0 18px;
  border-bottom: 1px solid rgba(255,255,255,.15);
  display: flex;
  align-items: center;
  gap: 6px;
}
#sidebar .nav-section {
  font-size: .68rem;
  font-weight: 700;
  color: rgba(255,255,255,.45);
  text-transform: uppercase;
  letter-spacing: .08em;
  padding: 16px 20px 4px;
}
#sidebar .nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #e0f2fe;
  padding: 10px 14px;
  border-radius: 12px;
  margin: 3px 10px;
  font-size: .88rem;
  font-weight: 500;
  transition: var(--transition);
  text-decoration: none;
}
#sidebar .nav-link i { font-size: 1.05rem; min-width: 20px; }
#sidebar .nav-link:hover {
  background: rgba(255,255,255,.18);
  color: #fff;
}
#sidebar .nav-link.active {
  background: rgba(255,255,255,.22);
  color: #fff;
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(0,0,0,.1);
}
#sidebar .sidebar-footer {
  margin-top: auto;
  padding: 16px 20px;
  border-top: 1px solid rgba(255,255,255,.15);
}
#sidebar .sidebar-footer .nav-link {
  color: #fca5a5;
  margin: 0;
  padding: 0;
}
#sidebar .sidebar-footer .nav-link:hover { 
  background: transparent; 
  color: #f87171; 
}

/* ── Navbar ─────────────────────────────────────────────── */
#topbar {
  position: fixed;
  top: 0; left: var(--sidebar-w); right: 0;
  height: 64px;
  background: transparent; /* Transparan agar menyatu dengan gradient body */
  display: flex;
  align-items: center;
  padding: 0 1.75rem;
  z-index: 1030;
  gap: 1rem;
  margin-top: 10px;
}
#topbar .topbar-toggle {
  display: none;
  background: var(--white);
  border: none;
  font-size: 1.35rem;
  color: var(--text-main);
  cursor: pointer;
  padding: 5px 10px;
  border-radius: 10px;
  box-shadow: var(--shadow);
}
#topbar .topbar-title {
  font-size: 1.6rem;
  font-weight: 800;
  color: var(--text-main);
  flex: 1;
}
#topbar .topbar-title span {
  color: var(--primary);
}
#topbar .topbar-date {
  background: var(--white);
  border-radius: 50px;
  padding: 8px 18px;
  font-size: .85rem;
  font-weight: 600;
  color: var(--primary-dark);
  box-shadow: 0 2px 10px rgba(14,165,233,.15);
}
.topbar-avatar {
  width: 38px; height: 38px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--white);
  box-shadow: var(--shadow);
  cursor: pointer;
}
#topbar .dropdown-menu {
  border: none;
  box-shadow: var(--shadow-hover);
  border-radius: 14px;
  min-width: 200px;
  padding: .5rem;
}
#topbar .dropdown-item {
  border-radius: 10px;
  padding: .5rem .85rem;
  font-size: .875rem;
  display: flex; align-items: center; gap: .6rem;
}
#topbar .dropdown-item:hover { background: var(--primary-light); color: var(--primary-dark); }

/* ── Main Content ───────────────────────────────────────── */
#main-content {
  margin-left: var(--sidebar-w);
  padding-top: 64px;
  min-height: 100vh;
  display: flex; flex-direction: column;
}
.content-area {
  padding: 2rem 1.75rem;
  flex: 1;
}

/* ── Page Header ────────────────────────────────────────── */
.page-header { margin-bottom: 1.75rem; }
.page-header h2 { font-size: 1.6rem; font-weight: 800; margin: 0; color: var(--text-main); }
.page-header p { color: var(--text-muted); font-size: .88rem; margin: .2rem 0 0; }

/* ── Cards (Menyesuaikan .card-custom) ── */
.card {
  border: none;
  border-radius: var(--radius) !important;
  box-shadow: var(--shadow);
  background: var(--white);
  transition: transform var(--transition), box-shadow var(--transition);
}
.card:hover { box-shadow: var(--shadow-hover); }
.card-header {
  background: #f0f9ff !important; /* Identik dengan .card-header-custom */
  border-bottom: 1px solid #dbeafe !important;
  border-radius: var(--radius) var(--radius) 0 0 !important;
  padding: 16px 24px;
  font-weight: 700;
  color: var(--text-main);
  font-size: .95rem;
  display: flex; align-items: center; justify-content: space-between;
}
.card-header i { color: var(--primary); margin-right: .5rem; }

/* ── Profil Card (Kombinasi Gradasi Indah Sky Blue) ── */
.profil-card {
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  color: #fff;
  border-radius: var(--radius) !important;
  padding: 1.75rem;
  box-shadow: var(--shadow);
}
.profil-card .profil-avatar {
  width: 80px; height: 80px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid rgba(255,255,255,.3);
  margin-bottom: .75rem;
}
.profil-card .profil-name { font-size: 1.25rem; font-weight: 800; margin: 0; }
.profil-card .profil-spec {
  font-size: .82rem;
  background: rgba(255,255,255,.2);
  color: #fff;
  border-radius: 10px;
  display: inline-block;
  padding: .3rem .8rem;
  margin-top: .4rem;
  font-weight: 600;
}
.profil-info-row { display: flex; flex-direction: column; gap: .5rem; margin-top: 1.1rem; }
.profil-info-item { display: flex; align-items: center; gap: .6rem; font-size: .85rem; color: #e0f2fe; }
.profil-info-item i { font-size: .9rem; color: #bae6fd; min-width: 16px; }
.profil-info-item strong { color: #fff; }

/* ── Statistik Cards (Gaya Border Left Khas Referensi) ── */
.stat-card {
  border-radius: var(--radius) !important;
  padding: 1.4rem 1.25rem;
  display: flex; align-items: center; gap: 1.1rem;
  box-shadow: var(--shadow);
  border: none;
  background: var(--white);
  border-left: 4px solid #38bdf8; /* Aksen warna cerah */
  transition: transform var(--transition), box-shadow var(--transition);
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
.stat-icon {
  width: 54px; height: 54px;
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem;
  flex-shrink: 0;
}
.stat-icon.blue   { background: #f0f9ff; color: #0ea5e9; }
.stat-icon.teal   { background: #e0f7f4; color: #19a49a; }
.stat-icon.amber  { background: #fff8e1; color: #f59e0b; }
.stat-icon.rose   { background: #fff1f2; color: #ef4444; }
.stat-label { font-size: .82rem; color: var(--text-muted); font-weight: 600; margin: 0; }
.stat-value { font-size: 1.8rem; font-weight: 800; color: var(--text-main); line-height: 1.1; }

/* ── Table (Gaya .table thead di Referensi) ── */
.table { font-size: .88rem; }
.table thead { background: #38bdf8 !important; }
.table thead th {
  background: #38bdf8 !important;
  color: var(--white) !important;
  font-weight: 600;
  font-size: .85rem;
  border: none;
  padding: .75rem 1rem;
}
.table tbody td { padding: .85rem 1rem; vertical-align: middle; border-color: #e2e8f0; color: var(--text-main); }
.table tbody tr:hover { background: #f0f9ff; }

/* ── Badge Status (Lebih Segar & Konsisten) ── */
.badge-status { font-size: .75rem; padding: .35rem .75rem; border-radius: 10px; font-weight: 600; }
.badge-aktif    { background: #d1e7dd; color: #0f5132; }
.badge-menunggu { background: #fff3cd; color: #856404; }
.badge-selesai  { background: #e0f2fe; color: #0369a1; }
.badge-batal    { background: #f8d7da; color: #721c24; }
.badge-proses   { background: #f1f5f9; color: #334155; }

/* ── Buttons (.btn-save di Referensi) ── */
.btn-primary-custom { 
  background: #38bdf8; 
  border: none; 
  color: white; 
  font-weight: 600; 
  border-radius: 11px; 
  padding: .45rem 1.2rem;
  font-size: .82rem;
  transition: var(--transition);
}
.btn-primary-custom:hover { background: #0ea5e9; color: white; }

.btn-outline-custom { 
  border: 1px solid #38bdf8; 
  color: #0ea5e9; 
  background: transparent; 
  border-radius: 11px; 
  font-weight: 600;
  padding: .45rem 1.2rem;
  font-size: .82rem;
  transition: var(--transition);
}
.btn-outline-custom:hover { background: #38bdf8; color: white; }

/* ── Chat Item ── */
.chat-item { display: flex; align-items: center; gap: .85rem; padding: .75rem 0; border-bottom: 1px solid #f1f5f9; }
.chat-item:last-child { border-bottom: none; }
.chat-avatar { 
  width: 42px; height: 42px; 
  border-radius: 50%; 
  background: linear-gradient(135deg, #38bdf8, #0284c7); 
  color: white; 
  display: flex; align-items: center; justify-content: center; 
  font-weight: 700; flex-shrink: 0; font-size: 1rem; 
}
.chat-meta { flex: 1; min-width: 0; }
.chat-name { font-weight: 700; font-size: .88rem; margin: 0; color: var(--text-main); }
.chat-preview { font-size: .8rem; color: var(--text-muted); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-time { font-size: .75rem; color: var(--text-muted); }

/* ── Empty State & Footer ───────────────────────────────── */
.empty-state { text-align: center; padding: 2rem 1rem; color: var(--text-muted); }
.empty-state i { font-size: 2.5rem; color: #cbd5e1; margin-bottom: .5rem; }
.footer { background: var(--white); border-top: 1px solid #e2e8f0; padding: 1rem 1.75rem; font-size: .82rem; color: var(--text-muted); text-align: center; border-radius: var(--radius) var(--radius) 0 0; margin-top: auto; }

/* ── Overlay & Responsive ───────────────────────────────── */
#sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.3); z-index: 1035; backdrop-filter: blur(2px); }
@media (max-width: 991.98px) {
  #sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); }
  #sidebar.show { transform: translateX(0); }
  #sidebar-overlay.show { display: block; }
  #topbar { left: 0; background: var(--white); margin-top: 0; box-shadow: var(--shadow); }
  #topbar .topbar-toggle { display: block; }
  #main-content { margin-left: 0; }
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR OVERLAY (mobile)
══════════════════════════════════════════════════════════ -->
<div id="sidebar-overlay"></div>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════════════ -->
<nav id="sidebar">
  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></div>
    <h5>MediTrack</h5>
    <p>Portal Dokter</p>
  </div>

  <div class="nav-section">Menu Utama</div>

  <a href="dashboard_dokter.php" class="nav-link active">
    <i class="bi bi-grid-1x2-fill"></i> Dashboard
  </a>
  <a href="jadwal_dokter.php" class="nav-link">
    <i class="bi bi-calendar3"></i> Jadwal Praktik
  </a>
  <a href="antrean.php" class="nav-link">
    <i class="bi bi-people-fill"></i> Antrean Pasien
  </a>
  <a href="chat.php" class="nav-link">
    <i class="bi bi-chat-dots-fill"></i> Chat
    <?php if ($jml_chat_unread > 0): ?>
      <span class="badge rounded-pill ms-auto"
            style="background:rgba(255,255,255,.3);font-size:.65rem;">
        <?= $jml_chat_unread ?>
      </span>
    <?php endif; ?>
  </a>
  <a href="rekam_medis.php" class="nav-link">
    <i class="bi bi-file-earmark-medical-fill"></i> Rekam Medis
  </a>

  <div class="nav-section">Akun</div>

  <a href="profil.php" class="nav-link">
    <i class="bi bi-person-circle"></i> Profil
  </a>

  <div class="sidebar-footer">
    <a href="logout.php" class="nav-link">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
  </div>
</nav>

<!-- ══════════════════════════════════════════════════════════
     TOP NAVBAR
══════════════════════════════════════════════════════════ -->
<header id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="bi bi-list"></i>
  </button>

  <span class="topbar-title">Dashboard</span>

  <span class="topbar-date">
    <i class="bi bi-calendar2-check me-1"></i>
    <?= date('d M Y') ?>
  </span>

  <!-- Dropdown Profil -->
  <div class="dropdown ms-2">
    <button class="btn p-0 border-0 d-flex align-items-center gap-2"
            type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <img src="<?= foto_src($foto_profil) ?>"
           class="topbar-avatar"
           alt="Foto Profil"
           onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($nama_dokter) ?>&background=4E9DB8&color=fff&size=128'">
      <span class="d-none d-md-block fw-500" style="font-size:.85rem;font-weight:600;">
        Dr. <?= htmlspecialchars($nama_dokter) ?>
      </span>
      <i class="bi bi-chevron-down d-none d-md-block" style="font-size:.7rem;color:var(--text-muted);"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li>
        <div class="dropdown-item-text px-3 py-2">
          <div class="fw-600" style="font-size:.85rem;">Dr. <?= htmlspecialchars($nama_dokter) ?></div>
          <div style="font-size:.75rem;color:var(--text-muted);"><?= htmlspecialchars($spesialis) ?></div>
        </div>
      </li>
      <li><hr class="dropdown-divider my-1"></li>
      <li>
        <a class="dropdown-item" href="profil.php">
          <i class="bi bi-person"></i> Profil Saya
        </a>
      </li>
      <li>
        <a class="dropdown-item text-danger" href="logout.php">
          <i class="bi bi-box-arrow-left"></i> Logout
        </a>
      </li>
    </ul>
  </div>
</header>

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════════ -->
<div id="main-content">
  <div class="content-area">

    <!-- Page Header -->
    <div class="page-header">
      <h2>Selamat Datang, Dr. <?= htmlspecialchars($nama_dokter) ?> 👋</h2>
      <p>Ini ringkasan aktivitas Anda hari ini, <?= date('l, d F Y') ?>.</p>
    </div>

    <!-- ── ROW 1: Profil + Statistik ── -->
    <div class="row g-4 mb-4">

      <!-- Profil Card -->
      <div class="col-12 col-lg-4">
        <div class="profil-card h-100">
          <img src="<?= foto_src($foto_profil) ?>"
               class="profil-avatar"
               alt="Foto Profil"
               onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($nama_dokter) ?>&background=3a7d93&color=fff&size=128'">
          <p class="profil-name">Dr. <?= htmlspecialchars($nama_dokter) ?></p>
          <span class="profil-spec"><?= htmlspecialchars($spesialis) ?></span>
          <div class="profil-info-row">
            <div class="profil-info-item">
              <i class="bi bi-card-text"></i>
              <span>SIP: <strong><?= htmlspecialchars($nomor_sip) ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-award"></i>
              <span>Pengalaman: <strong><?= htmlspecialchars($pengalaman) ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-cash-coin"></i>
              <span>Biaya: <strong><?= rupiah($biaya) ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-clock"></i>
              <span>Durasi: <strong><?= htmlspecialchars($durasi) ?> menit</strong></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Statistik -->
      <div class="col-12 col-lg-8">
        <div class="row g-3 h-100 align-content-start">

          <!-- Pasien Hari Ini -->
          <div class="col-6 col-sm-6">
            <div class="stat-card">
              <div class="stat-icon blue"><i class="bi bi-person-heart"></i></div>
              <div>
                <p class="stat-label">Pasien Hari Ini</p>
                <div class="stat-value"><?= $jml_pasien ?></div>
              </div>
            </div>
          </div>

          <!-- Antrean Hari Ini -->
          <div class="col-6 col-sm-6">
            <div class="stat-card">
              <div class="stat-icon teal"><i class="bi bi-list-ol"></i></div>
              <div>
                <p class="stat-label">Antrean Hari Ini</p>
                <div class="stat-value"><?= $jml_antrean ?></div>
              </div>
            </div>
          </div>

          <!-- Chat Belum Dibaca -->
          <div class="col-6 col-sm-6">
            <div class="stat-card">
              <div class="stat-icon amber"><i class="bi bi-chat-dots"></i></div>
              <div>
                <p class="stat-label">Chat Belum Dibaca</p>
                <div class="stat-value"><?= $jml_chat_unread ?></div>
              </div>
            </div>
          </div>

          <!-- Rekam Medis Pending -->
          <div class="col-6 col-sm-6">
            <div class="stat-card">
              <div class="stat-icon rose"><i class="bi bi-file-earmark-x"></i></div>
              <div>
                <p class="stat-label">Rekam Medis Pending</p>
                <div class="stat-value"><?= $jml_rm_pending ?></div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div><!-- /ROW 1 -->

    <!-- ── ROW 2: Jadwal + Antrean ── -->
    <div class="row g-4 mb-4">

      <!-- Jadwal Praktik Hari Ini -->
      <div class="col-12 col-lg-5">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-calendar-check"></i>Jadwal Praktik Hari Ini</span>
            <a href="jadwal_dokter.php" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (count($jadwal_list) > 0): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead>
                    <tr>
                      <th>Hari</th>
                      <th>Jam Mulai</th>
                      <th>Jam Selesai</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($jadwal_list as $j): ?>
                      <?php
                        $status_j = strtolower($j['status'] ?? 'aktif');
                        $badge_j  = match(true) {
                            str_contains($status_j, 'aktif')   => 'badge-aktif',
                            str_contains($status_j, 'selesai') => 'badge-selesai',
                            str_contains($status_j, 'batal')   => 'badge-batal',
                            default                             => 'badge-aktif',
                        };
                      ?>
                      <tr>
                        <td><?= htmlspecialchars($j['hari'] ?? $hari_id) ?></td>
                        <td><?= htmlspecialchars(substr($j['jam_mulai'] ?? '-', 0, 5)) ?></td>
                        <td><?= htmlspecialchars(substr($j['jam_selesai'] ?? '-', 0, 5)) ?></td>
                        <td>
                          <span class="badge-status <?= $badge_j ?>">
                            <?= htmlspecialchars(ucfirst($j['status'] ?? 'Aktif')) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state">
                <i class="bi bi-calendar-x d-block"></i>
                <p>Tidak ada jadwal hari ini.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Antrean Pasien -->
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-people"></i>Antrean Pasien Hari Ini</span>
            <a href="antrean.php" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (count($antrean_list) > 0): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Nama Pasien</th>
                      <th>Jam</th>
                      <th>Status</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($antrean_list as $a):
                      $nm_pasien = $a['nama_pasien'] ?? $a['name_pasien'] ?? 'Pasien';
                      $status_a  = strtolower($a['status'] ?? 'menunggu');
                      $badge_a   = match(true) {
                          str_contains($status_a, 'menunggu') => 'badge-menunggu',
                          str_contains($status_a, 'proses')   => 'badge-proses',
                          str_contains($status_a, 'selesai')  => 'badge-selesai',
                          str_contains($status_a, 'batal')    => 'badge-batal',
                          default                              => 'badge-menunggu',
                      };
                      $jam = $a['jam_konsultasi'] ?? $a['jam'] ?? $a['created_at'] ?? '-';
                      if (strlen($jam) > 5) $jam = substr($jam, 11, 5);
                    ?>
                      <tr>
                        <td>
                          <span class="fw-700" style="color:var(--primary);">
                            <?= htmlspecialchars($a['nomor_antrean'] ?? '-') ?>
                          </span>
                        </td>
                        <td><?= htmlspecialchars($nm_pasien) ?></td>
                        <td><?= htmlspecialchars($jam) ?></td>
                        <td>
                          <span class="badge-status <?= $badge_a ?>">
                            <?= htmlspecialchars(ucfirst($a['status'] ?? 'Menunggu')) ?>
                          </span>
                        </td>
                        <td>
                          <div class="d-flex gap-1 flex-wrap">
                            <a href="detail_antrean.php?id=<?= $a['id'] ?>"
                               class="btn-outline-custom btn">
                              <i class="bi bi-eye"></i>
                            </a>
                            <a href="konsultasi.php?id=<?= $a['id'] ?>"
                               class="btn-primary-custom btn">
                              Mulai
                            </a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state">
                <i class="bi bi-people d-block"></i>
                <p>Belum ada antrean pasien hari ini.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /ROW 2 -->

    <!-- ── ROW 3: Chat Terbaru + Rekam Medis Pending ── -->
    <div class="row g-4">

      <!-- Chat Terbaru -->
      <div class="col-12 col-lg-5">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-chat-dots"></i>Chat Terbaru</span>
            <a href="chat.php" class="btn-outline-custom btn">Buka Semua</a>
          </div>
          <div class="card-body py-1 px-3">
            <?php if (count($chat_list) > 0): ?>
              <?php foreach ($chat_list as $c):
                $nm_pengirim = $c['nama_pengirim'] ?? $c['name_pengirim'] ?? 'Pasien';
                $pesan       = $c['pesan'] ?? $c['message'] ?? $c['content'] ?? '';
                $waktu_chat  = $c['created_at'] ?? '';
                if ($waktu_chat) {
                    $diff = time() - strtotime($waktu_chat);
                    if ($diff < 3600)       $waktu_chat = floor($diff/60) . ' mnt lalu';
                    elseif ($diff < 86400)  $waktu_chat = floor($diff/3600) . ' jam lalu';
                    else                    $waktu_chat = date('d M', strtotime($waktu_chat));
                }
              ?>
                <div class="chat-item">
                  <div class="chat-avatar"><i class="bi bi-person-fill"></i></div>
                  <div class="chat-meta">
                    <p class="chat-name"><?= htmlspecialchars($nm_pengirim) ?></p>
                    <p class="chat-preview"><?= htmlspecialchars(potong($pesan, 55)) ?></p>
                  </div>
                  <div class="d-flex flex-column align-items-end gap-1">
                    <span class="chat-time"><?= htmlspecialchars($waktu_chat) ?></span>
                    <a href="chat.php?user=<?= $c['pengirim_id'] ?>"
                       class="btn-primary-custom btn" style="font-size:.72rem;padding:.25rem .6rem;">
                      Buka
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <i class="bi bi-chat-square d-block"></i>
                <p>Belum ada chat masuk.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Rekam Medis Pending -->
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-file-earmark-medical"></i>Rekam Medis Pending</span>
            <a href="rekam_medis.php" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (count($rm_pending_list) > 0): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead>
                    <tr>
                      <th>Nama Pasien</th>
                      <th>Tanggal</th>
                      <th>Status</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rm_pending_list as $rm):
                      $nm_rm  = $rm['nama_pasien'] ?? $rm['name_pasien'] ?? 'Pasien';
                      $tgl_rm = $rm['tanggal'] ?? '-';
                      if (strlen($tgl_rm) >= 10)
                          $tgl_rm = date('d M Y', strtotime($tgl_rm));
                    ?>
                      <tr>
                        <td><?= htmlspecialchars($nm_rm) ?></td>
                        <td><?= htmlspecialchars($tgl_rm) ?></td>
                        <td>
                          <span class="badge-status badge-menunggu">Belum Diisi</span>
                        </td>
                        <td>
                          <a href="isi_rekam_medis.php?antrean_id=<?= $rm['antrean_id'] ?>"
                             class="btn-primary-custom btn">
                            <i class="bi bi-pencil-square me-1"></i>Isi Rekam Medis
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state">
                <i class="bi bi-check-circle d-block" style="color:#19a49a;"></i>
                <p>Semua rekam medis sudah diisi. ✅</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /ROW 3 -->

  </div><!-- /.content-area -->

  <!-- Footer -->
  <footer class="footer">
    &copy; <?= date('Y') ?> MediTrack– Sistem Telemedicine. Hak cipta dilindungi.
  </footer>

</div><!-- /#main-content -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Sidebar Toggle (mobile) ──────────────────────────────
const sidebar       = document.getElementById('sidebar');
const overlay       = document.getElementById('sidebar-overlay');
const toggleBtn     = document.getElementById('sidebarToggle');

function openSidebar() {
  sidebar.classList.add('show');
  overlay.classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  sidebar.classList.remove('show');
  overlay.classList.remove('show');
  document.body.style.overflow = '';
}

toggleBtn.addEventListener('click', () => {
  sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
});
overlay.addEventListener('click', closeSidebar);

// Close sidebar on window resize to desktop
window.addEventListener('resize', () => {
  if (window.innerWidth >= 992) closeSidebar();
});

// ── Active nav link ──────────────────────────────────────
(function() {
  const current = window.location.pathname.split('/').pop();
  document.querySelectorAll('#sidebar .nav-link').forEach(link => {
    const href = (link.getAttribute('href') || '').split('/').pop();
    if (href === current && href !== '') {
      link.classList.add('active');
    } else if (href !== current) {
      link.classList.remove('active');
    }
  });
})();
</script>
</body>
</html>