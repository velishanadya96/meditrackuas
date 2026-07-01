<?php
session_start();

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
$adminId  = $_SESSION['user_id'];
$message  = '';
$page     = $_GET['page'] ?? 'dokter'; // dokter | jadwal | rekam | chat

// ════════════════════════════════════════════
// PAGE: KELOLA DOKTER (data master)
// ════════════════════════════════════════════
if ($page === 'dokter') {
    $action = $_GET['action'] ?? 'list';
    $id     = $_GET['id'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
        $nama         = trim($_POST['nama']);
        $spesialisasi = trim($_POST['spesialisasi']);
        $emailDokter  = trim($_POST['email_dokter'] ?? '');
        $passwordBaru = trim($_POST['password_dokter'] ?? '');

        if ($_POST['form_action'] === 'tambah') {
            // Buat akun user dengan role dokter jika email diisi
            $userId = null;
            if ($emailDokter !== '') {
                // Cek email belum dipakai
                $cekEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $cekEmail->execute([$emailDokter]);
                $existingUser = $cekEmail->fetch();
                if ($existingUser) {
                    // Email sudah ada, link ke user tersebut dan update role jadi dokter
                    $pdo->prepare("UPDATE users SET role='dokter' WHERE id=?")->execute([$existingUser['id']]);
                    $userId = $existingUser['id'];
                } else {
                    $pw = $passwordBaru !== '' ? $passwordBaru : 'dokter123';
                    $hash = password_hash($pw, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'dokter')");
                    $ins->execute([$nama, $emailDokter, $hash]);
                    $userId = $pdo->lastInsertId();
                }
            }
            $stmt = $pdo->prepare("INSERT INTO dokter (nama, spesialisasi, user_id, email) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nama, $spesialisasi, $userId, $emailDokter ?: null]);
            $message = 'success|Dokter berhasil ditambahkan' . ($emailDokter ? ' beserta akun login.' : '.');

        } elseif ($_POST['form_action'] === 'edit') {
            $editId      = (int) $_POST['edit_id'];
            $emailDokter = trim($_POST['email_dokter'] ?? '');

            // Ambil data dokter lama untuk cek user_id sebelumnya
            $dokterLama = $pdo->prepare("SELECT * FROM dokter WHERE id=?");
            $dokterLama->execute([$editId]);
            $dokterLama = $dokterLama->fetch();

            $userId = $dokterLama['user_id'] ?? null;

            if ($emailDokter !== '') {
                if ($userId) {
                    // Update akun yang sudah ada
                    $upd = "UPDATE users SET name=?, email=?, role='dokter'";
                    $params = [$nama, $emailDokter];
                    if ($passwordBaru !== '') {
                        $upd .= ", password=?";
                        $params[] = password_hash($passwordBaru, PASSWORD_BCRYPT);
                    }
                    $upd .= " WHERE id=?";
                    $params[] = $userId;
                    $pdo->prepare($upd)->execute($params);
                } else {
                    // Buat akun baru
                    $cekEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $cekEmail->execute([$emailDokter]);
                    $existingUser = $cekEmail->fetch();
                    if ($existingUser) {
                        $pdo->prepare("UPDATE users SET role='dokter' WHERE id=?")->execute([$existingUser['id']]);
                        $userId = $existingUser['id'];
                    } else {
                        $pw = $passwordBaru !== '' ? $passwordBaru : 'dokter123';
                        $hash = password_hash($pw, PASSWORD_BCRYPT);
                        $ins = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'dokter')");
                        $ins->execute([$nama, $emailDokter, $hash]);
                        $userId = $pdo->lastInsertId();
                    }
                }
            }

            $stmt = $pdo->prepare("UPDATE dokter SET nama=?, spesialisasi=?, user_id=?, email=? WHERE id=?");
            $stmt->execute([$nama, $spesialisasi, $userId ?: null, $emailDokter ?: null, $editId]);
            $message = 'success|Data dokter berhasil diupdate.';
        }
    }

    if ($action === 'hapus' && $id) {
        // Kembalikan role user jika ada akun terhubung
        $dokterHapus = $pdo->prepare("SELECT user_id FROM dokter WHERE id=?");
        $dokterHapus->execute([$id]);
        $dokterHapus = $dokterHapus->fetch();
        if ($dokterHapus && $dokterHapus['user_id']) {
            $pdo->prepare("UPDATE users SET role='user' WHERE id=?")->execute([$dokterHapus['user_id']]);
        }
        $pdo->prepare("DELETE FROM dokter WHERE id = ?")->execute([$id]);
        $message = 'success|Dokter berhasil dihapus.';
    }

    $dokters = $pdo->query("SELECT d.*, u.email AS akun_email FROM dokter d LEFT JOIN users u ON u.id = d.user_id ORDER BY d.nama ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════
// PAGE: JADWAL DOKTER (per tanggal, 7 hari ke depan)
// ════════════════════════════════════════════
if ($page === 'jadwal') {
    $action = $_GET['action'] ?? 'list';
    $id     = $_GET['id'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
        $dokterId    = (int) $_POST['dokter_id'];
        $tanggal     = $_POST['tanggal'];
        $jamMulai    = $_POST['jam_mulai'];
        $jamSelesai  = $_POST['jam_selesai'];
        $kuota       = (int) $_POST['kuota'];
        $status      = $_POST['status'];

        if ($_POST['form_action'] === 'tambah') {
            $stmt = $pdo->prepare("INSERT INTO jadwal_dokter (dokter_id, tanggal, jam_mulai, jam_selesai, kuota, status) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$dokterId, $tanggal, $jamMulai, $jamSelesai, $kuota, $status]);
            $message = 'success|Jadwal berhasil ditambahkan.';
        } elseif ($_POST['form_action'] === 'edit') {
            $stmt = $pdo->prepare("UPDATE jadwal_dokter SET dokter_id=?, tanggal=?, jam_mulai=?, jam_selesai=?, kuota=?, status=? WHERE id=?");
            $stmt->execute([$dokterId, $tanggal, $jamMulai, $jamSelesai, $kuota, $status, $_POST['edit_id']]);
            $message = 'success|Jadwal berhasil diupdate.';
        }
    }

    if ($action === 'hapus' && $id) {
        $pdo->prepare("DELETE FROM jadwal_dokter WHERE id = ?")->execute([$id]);
        $message = 'success|Jadwal berhasil dihapus.';
    }

    // Dropdown daftar dokter untuk form
    $allDokter = $pdo->query("SELECT * FROM dokter ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Ambil jadwal 7 hari ke depan (termasuk hari ini)
    $tanggalMulai = date('Y-m-d');
    $tanggalAkhir = date('Y-m-d', strtotime('+6 days'));

    $stmtJadwal = $pdo->prepare("
        SELECT j.*, d.nama AS nama_dokter, d.spesialisasi
        FROM jadwal_dokter j
        JOIN dokter d ON d.id = j.dokter_id
        WHERE j.tanggal BETWEEN ? AND ?
        ORDER BY j.tanggal ASC, j.jam_mulai ASC
    ");
    $stmtJadwal->execute([$tanggalMulai, $tanggalAkhir]);
    $jadwalRaw = $stmtJadwal->fetchAll(PDO::FETCH_ASSOC);

    // Kelompokkan per tanggal (7 hari ke depan, walau kosong tetap muncul)
    $jadwalPerTanggal = [];
    for ($i = 0; $i < 7; $i++) {
        $tgl = date('Y-m-d', strtotime("+$i days"));
        $jadwalPerTanggal[$tgl] = [];
    }
    foreach ($jadwalRaw as $j) {
        $jadwalPerTanggal[$j['tanggal']][] = $j;
    }

    $editJadwal = null;
    if ($action === 'edit' && $id) {
        $stmtEdit = $pdo->prepare("SELECT * FROM jadwal_dokter WHERE id = ?");
        $stmtEdit->execute([$id]);
        $editJadwal = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    }
}

// ════════════════════════════════════════════
// PAGE: REKAM MEDIS (tertaut ke pasien/users)
// ════════════════════════════════════════════
if ($page === 'rekam') {
    $rekamSuccess = '';
    $rekamError   = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_rekam'])) {
        $pasienId        = (int) ($_POST['pasien_id'] ?? 0);
        $nama_pasien     = trim($_POST['nama_pasien']     ?? '');
        $tanggal_periksa = trim($_POST['tanggal_periksa'] ?? '');
        $diagnosa        = trim($_POST['diagnosa']        ?? '');
        $nama_dokter     = trim($_POST['nama_dokter']     ?? '');
        $poliklinik      = trim($_POST['poliklinik']      ?? '');
        $catatan         = trim($_POST['catatan']         ?? '');

        if (!$pasienId || !$tanggal_periksa || !$diagnosa || !$nama_dokter || !$poliklinik) {
            $rekamError = 'Pasien, tanggal, diagnosa, dokter, dan poliklinik wajib diisi.';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO rekam_medis
                 (user_id, nama_pasien, tanggal_periksa, diagnosa, nama_dokter, poliklinik, catatan, created_by_admin)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$pasienId, $nama_pasien, $tanggal_periksa, $diagnosa, $nama_dokter, $poliklinik, $catatan, $adminId]);
            $rekamSuccess = 'Rekam medis berhasil ditambahkan dan akan muncul di dashboard pasien.';
        }
    }

    if (($_GET['action'] ?? '') === 'hapus' && !empty($_GET['id'])) {
        $pdo->prepare("DELETE FROM rekam_medis WHERE id = ?")->execute([$_GET['id']]);
        $rekamSuccess = 'Rekam medis berhasil dihapus.';
    }

    // Daftar pasien (role user) untuk dropdown
    $pasienList = $pdo->query("SELECT id, name, email FROM users WHERE role = 'user' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Filter rekam medis (opsional by pasien)
    $filterPasien = $_GET['filter_pasien'] ?? '';
    if ($filterPasien) {
        $stmtRekam = $pdo->prepare("SELECT * FROM rekam_medis WHERE user_id = ? ORDER BY tanggal_periksa DESC");
        $stmtRekam->execute([$filterPasien]);
    } else {
        $stmtRekam = $pdo->query("SELECT * FROM rekam_medis ORDER BY tanggal_periksa DESC LIMIT 100");
    }
    $semuaRekam = $stmtRekam->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════
// PAGE: ANTREAN PASIEN (dikelompokkan per dokter & tanggal)
// ════════════════════════════════════════════
if ($page === 'antrean') {
    $filterDokter  = $_GET['filter_dokter']  ?? '';
    $filterTanggal = $_GET['filter_tanggal'] ?? '';
    $filterStatus  = $_GET['filter_status']  ?? '';

    $sqlAntrean = "
        SELECT a.id, a.nomor_antrean, a.status, a.created_at,
               u.name AS nama_pasien, u.email AS email_pasien,
               j.tanggal, j.jam_mulai, j.jam_selesai,
               d.id AS dokter_id, d.nama AS nama_dokter, d.spesialisasi
        FROM antrean a
        JOIN users u         ON u.id = a.user_id
        JOIN jadwal_dokter j ON j.id = a.jadwal_id
        JOIN dokter d        ON d.id = j.dokter_id
        WHERE 1=1
    ";
    $paramsAntrean = [];

    if ($filterDokter !== '') {
        $sqlAntrean      .= " AND d.id = ?";
        $paramsAntrean[]  = $filterDokter;
    }
    if ($filterTanggal !== '') {
        $sqlAntrean      .= " AND j.tanggal = ?";
        $paramsAntrean[]  = $filterTanggal;
    }
    if ($filterStatus !== '') {
        $sqlAntrean      .= " AND a.status = ?";
        $paramsAntrean[]  = $filterStatus;
    }

    $sqlAntrean .= " ORDER BY d.nama ASC, j.tanggal ASC, j.jam_mulai ASC, a.nomor_antrean ASC";

    $stmtAntrean = $pdo->prepare($sqlAntrean);
    $stmtAntrean->execute($paramsAntrean);
    $semuaAntrean = $stmtAntrean->fetchAll(PDO::FETCH_ASSOC);

    // Kelompokkan: nama dokter -> tanggal -> list antrean
    $antreanPerDokter = [];
    foreach ($semuaAntrean as $a) {
        $namaDokter = $a['nama_dokter'];
        $tgl        = $a['tanggal'];
        if (!isset($antreanPerDokter[$namaDokter])) {
            $antreanPerDokter[$namaDokter] = [
                'spesialisasi' => $a['spesialisasi'],
                'per_tanggal'  => [],
            ];
        }
        $antreanPerDokter[$namaDokter]['per_tanggal'][$tgl][] = $a;
    }

    // Dropdown daftar dokter untuk filter
    $allDokterAntrean = $pdo->query("SELECT * FROM dokter ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Catatan: Inbox Chat sudah tidak lagi ditangani dari sisi admin.
// Fitur chat sepenuhnya dipindah & ditangani dari dashboard dokter.
if ($page === 'chat') {
    header('Location: dashboard_admin.php?page=dokter');
    exit;
}

// Helper nama hari Indonesia
function namaHari($tanggal) {
    $hariList = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    return $hariList[date('w', strtotime($tanggal))];
}
function namaBulan($tanggal) {
    $bulanList = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    return $bulanList[(int) date('n', strtotime($tanggal))];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — MediTrack</title>
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

        /* ── SIDEBAR (sama dengan dashboard user) ── */
        .sidebar {
            width: 240px; flex-shrink: 0;
            background: linear-gradient(180deg, #0369a1 0%, #0284c7 60%, #38bdf8 100%);
            display: flex; flex-direction: column;
            position: sticky; top: 0; height: 100vh;
            box-shadow: 4px 0 20px rgba(0,0,0,.12);
        }
        .sidebar-brand { font-size: 1.6rem; font-weight: 800; color: white; padding: 24px 20px 4px; letter-spacing: -.5px; }
        .sidebar-brand span { color: #bae6fd; }
        .sidebar-tagline { color: #bae6fd; font-size: .72rem; padding: 0 20px 18px; border-bottom: 1px solid rgba(255,255,255,.15); display:flex; align-items:center; gap:6px; }
        .badge-admin-tag { background: #ef4444; color: white; font-size: .62rem; padding: 2px 8px; border-radius: 10px; font-weight: 700; }
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
        .badge-notif { background: #ef4444; color: white; border-radius: 50px; font-size: .65rem; padding: 2px 7px; font-weight: 700; margin-left: auto; }

        /* ── MAIN ── */
        .main-content { flex: 1; padding: 32px; overflow: auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
        .topbar-title { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .topbar-title span { color: #0ea5e9; }
        .topbar-sub { color: #64748b; font-size: .88rem; margin-top: 2px; }
        .topbar-badge { background: white; border-radius: 50px; padding: 8px 18px; font-size: .85rem; font-weight: 600; color: #0369a1; box-shadow: 0 2px 10px rgba(14,165,233,.15); }

        .card-custom { background: white; border: none; border-radius: 20px; box-shadow: 0 8px 20px rgba(14,165,233,.12); }
        .card-header-custom { background: #f0f9ff; border-bottom: 1px solid #dbeafe; border-radius: 20px 20px 0 0 !important; padding: 16px 24px; }
        .badge-custom { background: #38bdf8; color: white; padding: 7px 14px; border-radius: 10px; font-weight: 600; font-size: .82rem; }
        .table thead { background: #38bdf8; color: white; }
        .table thead th { border: none; font-weight: 600; font-size: .82rem; }
        .form-control, .form-select { border-radius: 11px; border: 1px solid #cbd5e1; padding: 10px 14px; }
        .form-control:focus, .form-select:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.2); }
        .btn-save { background: #38bdf8; border: none; color: white; font-weight: 600; border-radius: 11px; padding: 10px 25px; }
        .btn-save:hover { background: #0ea5e9; color: white; }

        /* ── JADWAL: kartu per hari ── */
        .day-strip { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 24px; }
        .day-card {
            min-width: 130px; background: white; border-radius: 16px; padding: 14px;
            box-shadow: 0 4px 14px rgba(14,165,233,.1); text-align: center; flex-shrink: 0;
            border: 2px solid transparent;
        }
        .day-card.today { border-color: #38bdf8; background: #f0f9ff; }
        .day-card .dname { font-size: .72rem; color: #64748b; font-weight: 700; text-transform: uppercase; }
        .day-card .dnum { font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 2px 0; }
        .day-card .dmonth { font-size: .72rem; color: #94a3b8; }
        .day-card .dcount { font-size: .68rem; margin-top: 6px; font-weight: 600; }

        .jadwal-day-block { margin-bottom: 22px; }
        .jadwal-day-header {
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
            padding-bottom: 8px; border-bottom: 2px solid #e0f2fe;
        }
        .jadwal-day-header .dot { width: 10px; height: 10px; border-radius: 50%; background: #38bdf8; }
        .jadwal-item {
            background: white; border-radius: 14px; padding: 14px 18px; margin-bottom: 8px;
            box-shadow: 0 2px 10px rgba(14,165,233,.08);
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
        }
        .jadwal-empty { color: #94a3b8; font-size: .82rem; padding: 10px 4px; }

        /* ── Chat (admin) ── */
        .chat-list-item { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: .15s; display: flex; align-items: center; gap: 12px; }
        .chat-list-item:hover { background: #f0f9ff; }
        .chat-list-item.active { background: #dbeafe; }
        .chat-avatar { width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#38bdf8,#0284c7);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;flex-shrink:0;font-size:1rem; }
        .chat-box { height:420px;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#f8fafc; }
    </style>
</head>
<body>

<div class="wrapper">
    <aside class="sidebar">
        <div class="sidebar-brand">Medi<span>Track.</span></div>
        <div class="sidebar-tagline">Panel Admin <span class="badge-admin-tag">ADMIN</span></div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Manajemen</div>

            <a href="dashboard_admin.php?page=dokter" class="nav-link-item <?= $page === 'dokter' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Kelola Dokter
            </a>

            <a href="dashboard_admin.php?page=jadwal" class="nav-link-item <?= $page === 'jadwal' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Jadwal Dokter
            </a>

            <a href="dashboard_admin.php?page=rekam" class="nav-link-item <?= $page === 'rekam' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Rekam Medis
            </a>

            <a href="dashboard_admin.php?page=antrean" class="nav-link-item <?= $page === 'antrean' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                Antrean Pasien
            </a>

        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-role">Login sebagai</div>
            <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
            <a href="logout.php" class="btn-logout-sidebar mt-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Keluar
            </a>
        </div>
    </aside>

    <main class="main-content">

    <?php if ($page === 'dokter'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">👨‍⚕️ <span>Kelola Dokter</span></div>
                <div class="topbar-sub">Data master dokter (nama & spesialisasi)</div>
            </div>
            <button class="btn btn-save" data-bs-toggle="modal" data-bs-target="#modalTambahDokter">
                <i class="bi bi-plus-circle me-1"></i> Tambah Dokter
            </button>
        </div>

        <?php if ($message): [$type, $text] = explode('|', $message); ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show rounded-3">
            <?= htmlspecialchars($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">Daftar Dokter</h5>
                <span class="badge-custom"><?= count($dokters) ?> Dokter</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>#</th><th>Nama Dokter</th><th>Spesialisasi</th><th>Akun Login</th><th class="text-center">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($dokters)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data dokter.</td></tr>
                        <?php else: foreach ($dokters as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($d['nama']) ?></strong></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($d['spesialisasi']) ?></span></td>
                                <td>
                                    <?php if (!empty($d['akun_email'])): ?>
                                        <span class="badge bg-success" style="font-size:.75rem;"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($d['akun_email']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" style="font-size:.75rem;">Belum ada akun</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-warning me-1" onclick="openEditDokter(<?= htmlspecialchars(json_encode($d)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="?page=dokter&action=hapus&id=<?= $d['id'] ?>" class="btn btn-sm btn-danger"
                                       onclick="return confirm('Hapus dokter <?= htmlspecialchars($d['nama']) ?>? Jadwal terkait juga akan terhapus.')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Tambah Dokter -->
        <div class="modal fade" id="modalTambahDokter" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="dashboard_admin.php?page=dokter">
                        <input type="hidden" name="form_action" value="tambah">
                        <div class="modal-header"><h5 class="modal-title">➕ Tambah Dokter</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3"><label class="form-label fw-bold">Nama Dokter</label><input name="nama" class="form-control" placeholder="dr. Nama Lengkap" required></div>
                            <div class="mb-3"><label class="form-label fw-bold">Spesialisasi</label><input name="spesialisasi" class="form-control" placeholder="Umum, Gigi, Anak, dll" required></div>
                            <hr>
                            <div class="mb-1 text-muted" style="font-size:.8rem;"><i class="bi bi-person-badge me-1"></i>Akun Login Dokter <span class="text-secondary">(opsional)</span></div>
                            <div class="mb-3"><label class="form-label fw-bold">Email Login</label><input type="email" name="email_dokter" class="form-control" placeholder="dokter@meditrack.com"></div>
                            <div class="mb-3"><label class="form-label fw-bold">Password</label><input type="password" name="password_dokter" class="form-control" placeholder="Kosongkan = default 'dokter123'"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-save">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Edit Dokter -->
        <div class="modal fade" id="modalEditDokter" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="dashboard_admin.php?page=dokter">
                        <input type="hidden" name="form_action" value="edit">
                        <input type="hidden" name="edit_id" id="ed_id">
                        <div class="modal-header"><h5 class="modal-title">✏️ Edit Dokter</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3"><label class="form-label fw-bold">Nama Dokter</label><input name="nama" id="ed_nama" class="form-control" required></div>
                            <div class="mb-3"><label class="form-label fw-bold">Spesialisasi</label><input name="spesialisasi" id="ed_spesialisasi" class="form-control" required></div>
                            <hr>
                            <div class="mb-1 text-muted" style="font-size:.8rem;"><i class="bi bi-person-badge me-1"></i>Akun Login Dokter</div>
                            <div class="mb-3"><label class="form-label fw-bold">Email Login</label><input type="email" name="email_dokter" id="ed_email_dokter" class="form-control"></div>
                            <div class="mb-3"><label class="form-label fw-bold">Password Baru</label><input type="password" name="password_dokter" class="form-control" placeholder="Kosongkan jika tidak diubah"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-warning">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($page === 'jadwal'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">📅 <span>Jadwal Dokter</span></div>
                <div class="topbar-sub">Jadwal praktik 7 hari ke depan — pasien melihat jadwal ini di halaman antrean</div>
            </div>
            <button class="btn btn-save" data-bs-toggle="modal" data-bs-target="#modalTambahJadwal">
                <i class="bi bi-plus-circle me-1"></i> Tambah Jadwal
            </button>
        </div>

        <?php if ($message): [$type, $text] = explode('|', $message); ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show rounded-3">
            <?= htmlspecialchars($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Strip 7 hari -->
        <div class="day-strip">
            <?php foreach ($jadwalPerTanggal as $tgl => $items): ?>
            <div class="day-card <?= $tgl === date('Y-m-d') ? 'today' : '' ?>">
                <div class="dname"><?= namaHari($tgl) ?></div>
                <div class="dnum"><?= date('d', strtotime($tgl)) ?></div>
                <div class="dmonth"><?= namaBulan($tgl) ?></div>
                <div class="dcount" style="color: <?= count($items) > 0 ? '#0ea5e9' : '#cbd5e1' ?>">
                    <?= count($items) ?> jadwal
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Detail per hari -->
        <?php foreach ($jadwalPerTanggal as $tgl => $items): ?>
        <div class="jadwal-day-block">
            <div class="jadwal-day-header">
                <span class="dot"></span>
                <strong><?= namaHari($tgl) ?>, <?= date('d', strtotime($tgl)) ?> <?= namaBulan($tgl) ?> <?= date('Y', strtotime($tgl)) ?></strong>
                <?php if ($tgl === date('Y-m-d')): ?><span class="badge bg-primary">Hari ini</span><?php endif; ?>
            </div>

            <?php if (empty($items)): ?>
                <div class="jadwal-empty">Tidak ada jadwal praktik pada tanggal ini.</div>
            <?php else: foreach ($items as $j): ?>
                <div class="jadwal-item">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:38px;height:38px;border-radius:50%;background:#e0f2fe;display:flex;align-items:center;justify-content:center;font-size:1rem;">🩺</div>
                        <div>
                            <strong><?= htmlspecialchars($j['nama_dokter']) ?></strong>
                            <span class="badge bg-info text-dark ms-1"><?= htmlspecialchars($j['spesialisasi']) ?></span><br>
                            <small class="text-muted"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($j['jam_mulai'])) ?> - <?= date('H:i', strtotime($j['jam_selesai'])) ?> · Kuota <?= $j['terisi'] ?>/<?= $j['kuota'] ?></small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($j['status'] === 'tersedia'): ?>
                            <span class="badge bg-success-subtle text-success border border-success">✅ Tersedia</span>
                        <?php elseif ($j['status'] === 'penuh'): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger">❌ Penuh</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary">🚫 Libur</span>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-warning" onclick='openEditJadwal(<?= json_encode($j) ?>)'><i class="bi bi-pencil"></i></button>
                        <a href="?page=jadwal&action=hapus&id=<?= $j['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus jadwal ini?')"><i class="bi bi-trash"></i></a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Modal Tambah Jadwal -->
        <div class="modal fade" id="modalTambahJadwal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="dashboard_admin.php?page=jadwal">
                        <input type="hidden" name="form_action" value="tambah">
                        <div class="modal-header"><h5 class="modal-title">Tambah Jadwal Dokter</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Dokter</label>
                                <select name="dokter_id" class="form-select" required>
                                    <option value="">Pilih dokter</option>
                                    <?php foreach ($allDokter as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama']) ?> — <?= htmlspecialchars($d['spesialisasi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal Praktik</label>
                                <input type="date" name="tanggal" class="form-control" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Jam Mulai</label><input type="time" name="jam_mulai" class="form-control" value="08:00" required></div>
                                <div class="col-md-6"><label class="form-label">Jam Selesai</label><input type="time" name="jam_selesai" class="form-control" value="12:00" required></div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-6"><label class="form-label">Kuota Pasien</label><input type="number" name="kuota" class="form-control" value="20" required></div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="tersedia">Tersedia</option>
                                        <option value="penuh">Penuh</option>
                                        <option value="libur">Libur</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-save">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Edit Jadwal -->
        <div class="modal fade" id="modalEditJadwal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="dashboard_admin.php?page=jadwal">
                        <input type="hidden" name="form_action" value="edit">
                        <input type="hidden" name="edit_id" id="ej_id">
                        <div class="modal-header"><h5 class="modal-title">Edit Jadwal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Dokter</label>
                                <select name="dokter_id" id="ej_dokter_id" class="form-select" required>
                                    <?php foreach ($allDokter as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama']) ?> — <?= htmlspecialchars($d['spesialisasi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3"><label class="form-label">Tanggal Praktik</label><input type="date" name="tanggal" id="ej_tanggal" class="form-control" required></div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Jam Mulai</label><input type="time" name="jam_mulai" id="ej_jam_mulai" class="form-control" required></div>
                                <div class="col-md-6"><label class="form-label">Jam Selesai</label><input type="time" name="jam_selesai" id="ej_jam_selesai" class="form-control" required></div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-6"><label class="form-label">Kuota Pasien</label><input type="number" name="kuota" id="ej_kuota" class="form-control" required></div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" id="ej_status" class="form-select">
                                        <option value="tersedia">Tersedia</option>
                                        <option value="penuh">Penuh</option>
                                        <option value="libur">Libur</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-warning">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($page === 'rekam'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">📋 <span>Rekam Medis Pasien</span></div>
                <div class="topbar-sub">Input rekam medis untuk pasien — otomatis muncul di dashboard pasien terkait</div>
            </div>
        </div>

        <?php if (!empty($rekamSuccess)): ?>
        <div class="alert alert-success rounded-3 mb-3"><?= htmlspecialchars($rekamSuccess) ?></div>
        <?php endif; ?>
        <?php if (!empty($rekamError)): ?>
        <div class="alert alert-danger rounded-3 mb-3"><?= htmlspecialchars($rekamError) ?></div>
        <?php endif; ?>

        <!-- Form input rekam medis dengan pilih pasien -->
        <div class="card-custom p-4 mb-4">
            <h5 class="fw-bold mb-4">➕ Input Rekam Medis Baru</h5>
            <form method="POST" action="dashboard_admin.php?page=rekam">
                <input type="hidden" name="form_rekam" value="1">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Pilih Pasien</label>
                        <select name="pasien_id" id="pasienSelect" class="form-select" required onchange="isiNamaPasien()">
                            <option value="">— Pilih pasien terdaftar —</option>
                            <?php foreach ($pasienList as $p): ?>
                            <option value="<?= $p['id'] ?>" data-nama="<?= htmlspecialchars($p['name']) ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="nama_pasien" id="namaPasienHidden">
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
                            <option>Umum</option><option>Anak</option><option>Gigi</option><option>Penyakit Dalam</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Catatan Tambahan</label>
                    <textarea name="catatan" class="form-control" rows="3" placeholder="Keluhan, tindakan, resep..."></textarea>
                </div>
                <button type="submit" class="btn btn-save">💾 Simpan Rekam Medis</button>
            </form>
        </div>

        <!-- Filter by pasien -->
        <form method="GET" class="d-flex gap-2 mb-3" style="max-width:420px;">
            <input type="hidden" name="page" value="rekam">
            <select name="filter_pasien" class="form-select" onchange="this.form.submit()">
                <option value="">— Semua Pasien —</option>
                <?php foreach ($pasienList as $p): ?>
                <option value="<?= $p['id'] ?>" <?= ($filterPasien == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">📑 Riwayat Rekam Medis</h5>
                <span class="badge-custom"><?= count($semuaRekam) ?> Data</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>No</th><th>Pasien</th><th>Tanggal</th><th>Diagnosa</th><th>Dokter</th><th>Poliklinik</th><th class="text-center">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($semuaRekam)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data rekam medis.</td></tr>
                        <?php else: foreach ($semuaRekam as $i => $item): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($item['nama_pasien']) ?></td>
                                <td><?= htmlspecialchars($item['tanggal_periksa']) ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($item['diagnosa']) ?></span></td>
                                <td><?= htmlspecialchars($item['nama_dokter']) ?></td>
                                <td><?= htmlspecialchars($item['poliklinik']) ?></td>
                                <td class="text-center">
                                    <a href="?page=rekam&action=hapus&id=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus rekam medis ini?')"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($page === 'antrean'): ?>
        <div class="topbar">
            <div>
                <div class="topbar-title">🎟️ <span>Antrean Pasien</span></div>
                <div class="topbar-sub">Daftar antrean yang sudah diambil pasien, dikelompokkan per dokter &amp; tanggal</div>
            </div>
            <span class="topbar-badge"><?= count($semuaAntrean) ?> Antrean</span>
        </div>

        <!-- Filter -->
        <form method="GET" class="card-custom mb-4 p-3 d-flex gap-2 flex-wrap align-items-end">
            <input type="hidden" name="page" value="antrean">
            <div>
                <label class="form-label fw-bold" style="font-size:.78rem;">Dokter</label>
                <select name="filter_dokter" class="form-select" style="min-width:200px;">
                    <option value="">— Semua Dokter —</option>
                    <?php foreach ($allDokterAntrean as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($filterDokter == $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label fw-bold" style="font-size:.78rem;">Tanggal</label>
                <input type="date" name="filter_tanggal" class="form-control" value="<?= htmlspecialchars($filterTanggal) ?>">
            </div>
            <div>
                <label class="form-label fw-bold" style="font-size:.78rem;">Status</label>
                <select name="filter_status" class="form-select">
                    <option value="">— Semua Status —</option>
                    <option value="menunggu" <?= $filterStatus === 'menunggu' ? 'selected' : '' ?>>Menunggu</option>
                    <option value="dikonfirmasi" <?= $filterStatus === 'dikonfirmasi' ? 'selected' : '' ?>>Dikonfirmasi</option>
                    <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                    <option value="batal" <?= $filterStatus === 'batal' ? 'selected' : '' ?>>Batal</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-save"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="dashboard_admin.php?page=antrean" class="btn btn-outline-secondary" style="border-radius:11px;">Reset</a>
            </div>
        </form>

        <?php if (empty($antreanPerDokter)): ?>
            <div class="card-custom p-5 text-center text-muted">
                Belum ada antrean yang diambil pasien<?= ($filterDokter || $filterTanggal || $filterStatus) ? ' untuk filter ini.' : '.' ?>
            </div>
        <?php else: foreach ($antreanPerDokter as $namaDokter => $dataDokter): ?>
            <div class="card-custom mb-4">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold">👨‍⚕️ dr. <?= htmlspecialchars($namaDokter) ?></h5>
                        <div class="topbar-sub mb-0"><?= htmlspecialchars($dataDokter['spesialisasi']) ?></div>
                    </div>
                    <span class="badge-custom">
                        <?= array_sum(array_map('count', $dataDokter['per_tanggal'])) ?> Antrean
                    </span>
                </div>
                <div class="p-3">
                    <?php
                    $tanggalList = array_keys($dataDokter['per_tanggal']);
                    sort($tanggalList);
                    foreach ($tanggalList as $tgl):
                        $listAntrean = $dataDokter['per_tanggal'][$tgl];
                        $isToday = ($tgl === date('Y-m-d'));
                    ?>
                    <div class="jadwal-day-block">
                        <div class="jadwal-day-header">
                            <span class="dot"></span>
                            <strong style="font-size:.88rem;color:<?= $isToday ? '#0369a1' : '#0f172a' ?>;">
                                <?= $isToday ? '📌 Hari ini, ' : '' ?><?= namaHari($tgl) ?>, <?= date('d', strtotime($tgl)) ?> <?= namaBulan($tgl) ?> <?= date('Y', strtotime($tgl)) ?>
                            </strong>
                            <span class="badge bg-light text-dark ms-auto" style="font-weight:600;"><?= count($listAntrean) ?> pasien</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>No. Antrean</th>
                                        <th>Nama Pasien</th>
                                        <th>Email</th>
                                        <th>Jam Praktik</th>
                                        <th>Status</th>
                                        <th>Diambil Pada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($listAntrean as $a):
                                    $statusBadge = [
                                        'menunggu'     => 'bg-warning text-dark',
                                        'dikonfirmasi' => 'bg-success',
                                        'selesai'      => 'bg-primary',
                                        'batal'        => 'bg-danger',
                                    ][$a['status']] ?? 'bg-secondary';
                                    $statusLabel = [
                                        'menunggu'     => '⏳ Menunggu',
                                        'dikonfirmasi' => '✅ Dikonfirmasi',
                                        'selesai'      => '🏁 Selesai',
                                        'batal'        => '✖ Batal',
                                    ][$a['status']] ?? ucfirst($a['status']);
                                ?>
                                    <tr>
                                        <td><span class="badge bg-info text-dark">#<?= $a['nomor_antrean'] ?></span></td>
                                        <td><strong><?= htmlspecialchars($a['nama_pasien']) ?></strong></td>
                                        <td class="text-muted" style="font-size:.85rem;"><?= htmlspecialchars($a['email_pasien']) ?></td>
                                        <td><?= substr($a['jam_mulai'],0,5) ?>–<?= substr($a['jam_selesai'],0,5) ?></td>
                                        <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
                                        <td style="font-size:.85rem;color:#64748b;"><?= date('d M Y, H:i', strtotime($a['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>

    <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEditDokter(data) {
    document.getElementById('ed_id').value = data.id;
    document.getElementById('ed_nama').value = data.nama;
    document.getElementById('ed_spesialisasi').value = data.spesialisasi;
    document.getElementById('ed_email_dokter').value = data.email || '';
    new bootstrap.Modal(document.getElementById('modalEditDokter')).show();
}
function openEditJadwal(data) {
    document.getElementById('ej_id').value = data.id;
    document.getElementById('ej_dokter_id').value = data.dokter_id;
    document.getElementById('ej_tanggal').value = data.tanggal;
    document.getElementById('ej_jam_mulai').value = data.jam_mulai;
    document.getElementById('ej_jam_selesai').value = data.jam_selesai;
    document.getElementById('ej_kuota').value = data.kuota;
    document.getElementById('ej_status').value = data.status;
    new bootstrap.Modal(document.getElementById('modalEditJadwal')).show();
}
function isiNamaPasien() {
    const sel = document.getElementById('pasienSelect');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('namaPasienHidden').value = opt.dataset.nama || '';
}
</script>
</body>
</html>