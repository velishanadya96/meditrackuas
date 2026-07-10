<?php
session_start();
require_once __DIR__ . '/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) { header("Location: /api/login.php"); exit; }
if ($_SESSION['user_role'] !== 'dokter') { header("Location: /api/login.php"); exit; }

$db      = getDB();
$userId  = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];

// Ambil data dokter
$stmtDokter = $db->prepare("SELECT d.*, u.email FROM dokter d JOIN users u ON u.id = d.user_id WHERE d.user_id = ? LIMIT 1");
$stmtDokter->execute([$userId]);
$dokterData = $stmtDokter->fetch(PDO::FETCH_ASSOC);

if (!$dokterData) {
    die('<div style="font-family:sans-serif;text-align:center;padding:80px;color:#ef4444;">
        <h2>⚠️ Akun belum terhubung ke data dokter</h2>
        <p>Hubungi admin untuk menghubungkan akun Anda ke profil dokter.</p>
        <a href="/api/logout.php">Keluar</a>
    </div>');
}

$dokterId   = $dokterData['id'];
$namaDokter = $dokterData['nama'];
$spesialis  = $dokterData['spesialisasi'];
$emailDokter = $dokterData['email'] ?? '';

$page = $_GET['page'] ?? 'dashboard';

// ── Helper ────────────────────────────────────────────────
function hariId($tgl) {
    return ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][date('w', strtotime($tgl))];
}
function bulanId($tgl) {
    return ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][(int)date('n', strtotime($tgl))];
}
function potong($str, $max = 60) {
    return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
}

// ── STATISTIK (untuk dashboard & sidebar badge) ───────────
// Total antrean hari ini
$stmtTotalAntrean = $db->prepare("
    SELECT COUNT(*) FROM antrean a
    JOIN jadwal_dokter j ON j.id = a.jadwal_id
    WHERE j.dokter_id = ? AND j.tanggal = CURDATE()
");
$stmtTotalAntrean->execute([$dokterId]);
$jml_antrean = (int)$stmtTotalAntrean->fetchColumn();

// Antrean menunggu hari ini
$stmtMenunggu = $db->prepare("
    SELECT COUNT(*) FROM antrean a
    JOIN jadwal_dokter j ON j.id = a.jadwal_id
    WHERE j.dokter_id = ? AND j.tanggal = CURDATE() AND a.status IN ('menunggu','dikonfirmasi')
");
$stmtMenunggu->execute([$dokterId]);
$jml_menunggu = (int)$stmtMenunggu->fetchColumn();

// Antrean selesai hari ini
$stmtSelesaiStat = $db->prepare("
    SELECT COUNT(*) FROM antrean a
    JOIN jadwal_dokter j ON j.id = a.jadwal_id
    WHERE j.dokter_id = ? AND j.tanggal = CURDATE() AND a.status = 'selesai'
");
$stmtSelesaiStat->execute([$dokterId]);
$jml_selesai = (int)$stmtSelesaiStat->fetchColumn();

// Unread chat
try {
    $stmtUnread = $db->prepare("
        SELECT COUNT(*) FROM konsultasi_chat c
        JOIN antrean a ON a.user_id = c.user_id
        JOIN jadwal_dokter j ON j.id = a.jadwal_id
        WHERE j.dokter_id = ? AND c.pengirim='user' AND (c.dibaca_dokter IS NULL OR c.dibaca_dokter=0)
    ");
    $stmtUnread->execute([$dokterId]);
    $jml_chat_unread = (int)$stmtUnread->fetchColumn();
} catch (Exception $e) { $jml_chat_unread = 0; }

// ════════════════════════════════════════════
// PAGE: DASHBOARD (ringkasan)
// ════════════════════════════════════════════
if ($page === 'dashboard') {
    // Antrean hari ini (untuk tabel)
    $stmtAntrean = $db->prepare("
        SELECT a.id, a.nomor_antrean, a.status, a.created_at,
               u.name AS nama_pasien,
               j.jam_mulai, j.jam_selesai
        FROM antrean a
        JOIN jadwal_dokter j ON j.id = a.jadwal_id
        JOIN users u ON u.id = a.user_id
        WHERE j.dokter_id = ? AND j.tanggal = CURDATE()
        ORDER BY a.nomor_antrean ASC
    ");
    $stmtAntrean->execute([$dokterId]);
    $antrean_list = $stmtAntrean->fetchAll(PDO::FETCH_ASSOC);

    // Jadwal hari ini
    $stmtJadwalHariIni = $db->prepare("
        SELECT * FROM jadwal_dokter
        WHERE dokter_id = ? AND tanggal = CURDATE()
        ORDER BY jam_mulai ASC
    ");
    $stmtJadwalHariIni->execute([$dokterId]);
    $jadwal_list = $stmtJadwalHariIni->fetchAll(PDO::FETCH_ASSOC);

    // Chat terbaru (satu chat terakhir per pasien)
    $stmtChat = $db->prepare("
        SELECT c.*, u.name AS nama_pengirim
        FROM konsultasi_chat c
        JOIN users u ON u.id = c.user_id
        JOIN (
            SELECT c2.user_id, MAX(c2.created_at) AS max_created
            FROM konsultasi_chat c2
            JOIN antrean a2 ON a2.user_id = c2.user_id
            JOIN jadwal_dokter j2 ON j2.id = a2.jadwal_id
            WHERE j2.dokter_id = ?
            GROUP BY c2.user_id
        ) latest ON latest.user_id = c.user_id AND latest.max_created = c.created_at
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmtChat->execute([$dokterId]);
    $chat_list = $stmtChat->fetchAll(PDO::FETCH_ASSOC);

    // Rekam medis terbaru yang dicatat dokter ini
    $stmtRMPending = $db->prepare("
        SELECT r.*, u.name AS nama_pasien
        FROM rekam_medis r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.nama_dokter = ?
        ORDER BY r.tanggal_periksa DESC
        LIMIT 10
    ");
    $stmtRMPending->execute([$namaDokter]);
    $rm_pending_list = $stmtRMPending->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════
// PAGE: ANTREAN HARI INI
// ════════════════════════════════════════════
if ($page === 'antrean') {
    $message = '';
    if (($_GET['action'] ?? '') === 'selesai' && !empty($_GET['antrean_id'])) {
        $db->prepare("UPDATE antrean SET status='selesai' WHERE id=? AND status IN ('menunggu','dikonfirmasi')")->execute([$_GET['antrean_id']]);
        $message = 'success|Pasien ditandai selesai.';
    }
    $stmtAntrean = $db->prepare("
        SELECT a.id, a.nomor_antrean, a.status, a.created_at,
               u.name AS nama_pasien, u.email AS email_pasien,
               j.tanggal, j.jam_mulai, j.jam_selesai
        FROM antrean a
        JOIN jadwal_dokter j ON j.id = a.jadwal_id
        JOIN users u ON u.id = a.user_id
        WHERE j.dokter_id = ? AND j.tanggal = CURDATE()
        ORDER BY a.nomor_antrean ASC
    ");
    $stmtAntrean->execute([$dokterId]);
    $antreanHariIni = $stmtAntrean->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════
// PAGE: JADWAL PRAKTIK
// ════════════════════════════════════════════
if ($page === 'jadwal') {
    $stmtJadwal = $db->prepare("
        SELECT j.*, (j.kuota - j.terisi) AS sisa_kuota
        FROM jadwal_dokter j
        WHERE j.dokter_id = ? AND j.tanggal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY)
        ORDER BY j.tanggal ASC, j.jam_mulai ASC
    ");
    $stmtJadwal->execute([$dokterId]);
    $jadwalList = $stmtJadwal->fetchAll(PDO::FETCH_ASSOC);
    $jadwalPerTanggal = [];
    for ($i = 0; $i < 14; $i++) {
        $tgl = date('Y-m-d', strtotime("+$i days"));
        $jadwalPerTanggal[$tgl] = [];
    }
    foreach ($jadwalList as $j) { $jadwalPerTanggal[$j['tanggal']][] = $j; }
}

// ════════════════════════════════════════════
// PAGE: REKAM MEDIS
// ════════════════════════════════════════════
if ($page === 'rekam') {
    $rekamMsg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_rekam'])) {
        $pasienId        = (int)($_POST['pasien_id'] ?? 0);
        $nama_pasien     = trim($_POST['nama_pasien'] ?? '');
        $tanggal_periksa = trim($_POST['tanggal_periksa'] ?? '');
        $diagnosa        = trim($_POST['diagnosa'] ?? '');
        $poliklinik      = trim($_POST['poliklinik'] ?? $spesialis);
        $catatan         = trim($_POST['catatan'] ?? '');
        if (!$pasienId || !$tanggal_periksa || !$diagnosa) {
            $rekamMsg = 'danger|Pasien, tanggal, dan diagnosa wajib diisi.';
        } else {
            $db->prepare("INSERT INTO rekam_medis (user_id, nama_pasien, tanggal_periksa, diagnosa, nama_dokter, poliklinik, catatan, created_by_admin) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$pasienId, $nama_pasien, $tanggal_periksa, $diagnosa, $namaDokter, $poliklinik, $catatan, $userId]);
            $rekamMsg = 'success|Rekam medis berhasil dicatat.';
        }
    }
    
    // Perbaikan line query pasienList agar memanggil seluruh akun pasien
    $pasienList = $db->query("SELECT id, name, email FROM users WHERE role = 'user' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtRekam = $db->prepare("SELECT r.*, u.email AS email_pasien FROM rekam_medis r LEFT JOIN users u ON u.id=r.user_id WHERE r.nama_dokter=? ORDER BY r.tanggal_periksa DESC LIMIT 50");
    $stmtRekam->execute([$namaDokter]);
    $rekamList = $stmtRekam->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════
// PAGE: CHAT

if (!function_exists('linkify')) {
    // Escape dulu, baru ubah URL jadi link yang bisa diklik. Aman dari XSS
    // karena linkify jalan SETELAH htmlspecialchars, bukan sebelum.
    function linkify(string $text): string {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace_callback(
            '/((https?:\/\/|www\.)[^\s<]+)/i',
            function ($m) {
                $display = rtrim($m[1], '.,)');
                $href = (stripos($display, 'http') === 0) ? $display : 'https://' . $display;
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;">' . $display . '</a>';
            },
            $escaped
        );
        return nl2br($escaped);
    }
}

if ($page === 'chat') {
    $chatUserId = (int)($_GET['chat_user'] ?? 0);
    $chatUserName = '';
    $chatExpiresAt = null; // unix timestamp masa berlaku sesi (1x24 jam sejak bayar)
    $chatExpired = true;

    // Cek masa berlaku sesi 1x24 jam berdasarkan waktu pembayaran pasien
    if ($chatUserId) {
        $stmtBayar = $db->prepare("SELECT paid_at FROM pembayaran_chat WHERE user_id = ? AND dokter_id = ? AND status = 'lunas' ORDER BY paid_at DESC LIMIT 1");
        $stmtBayar->execute([$chatUserId, $dokterId]);
        $paidAt = $stmtBayar->fetchColumn();
        if ($paidAt) {
            $chatExpiresAt = strtotime($paidAt) + 24 * 3600;
            $chatExpired = time() >= $chatExpiresAt;
        }
    }

    // Balas Pesan: Terikat ke dokterId yang sedang login dan user yang bersangkutan
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply']) && $chatUserId && !$chatExpired) {
        $reply = trim($_POST['reply'] ?? '');
        if ($reply !== '') {
            // Pengirim di-set sebagai 'dokter' dan menyertakan dokter_id
            $db->prepare("INSERT INTO konsultasi_chat (user_id, dokter_id, pengirim, pesan, dibaca, created_at) 
                          VALUES (?, ?, 'dokter', ?, 1, NOW())")
               ->execute([$chatUserId, $dokterId, $reply]);
        }
        header("Location: dashboard_dokter.php?page=chat&chat_user=$chatUserId");
        exit;
    }

    // Ambil daftar Pasien yang pernah mengirim pesan atau membayar KHUSUS ke dokter ini saja (Restriksi)
    $stmtChatUsers = $db->prepare("
        SELECT DISTINCT u.id, u.name, 
               MAX(c.created_at) AS last_msg, 
               SUM(CASE WHEN c.pengirim='user' AND (c.dibaca_dokter IS NULL OR c.dibaca_dokter=0) THEN 1 ELSE 0 END) AS unread
        FROM konsultasi_chat c
        JOIN users u ON u.id = c.user_id
        WHERE c.dokter_id = ?
        GROUP BY u.id, u.name
        ORDER BY last_msg DESC
    ");
    $stmtChatUsers->execute([$dokterId]);
    $chatUsers = $stmtChatUsers->fetchAll(PDO::FETCH_ASSOC);

    // Ambil histori pesan antara Dokter ini dengan Pasien yang dipilih
    if ($chatUserId) {
        $su = $db->prepare("SELECT name FROM users WHERE id=?");
        $su->execute([$chatUserId]);
        $chatUserName = $su->fetchColumn();

        // Pastikan hanya menarik chat antara user ini dan dokter ini saja
        $stmtMsg = $db->prepare("SELECT * FROM konsultasi_chat WHERE user_id = ? AND dokter_id = ? ORDER BY created_at ASC");
        $stmtMsg->execute([$chatUserId, $dokterId]);
        $chatMessages = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

        // Tandai pesan pasien tersebut telah dibaca oleh dokter ini
        try {
            $db->prepare("UPDATE konsultasi_chat SET dibaca_dokter=1 WHERE user_id=? AND dokter_id=? AND pengirim='user'")
               ->execute([$chatUserId, $dokterId]);
        } catch (Exception $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Dokter – MediTrack</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
  --primary:      #0ea5e9;
  --primary-dark: #0369a1;
  --primary-light:#f0f9ff;
  --bg-gradient:  linear-gradient(135deg, #e0f2fe, #bae6fd);
  --white:        #ffffff;
  --text-main:    #0f172a;
  --text-muted:   #64748b;
  --sidebar-bg:   linear-gradient(180deg, #0369a1 0%, #0284c7 60%, #38bdf8 100%);
  --sidebar-w:    260px;
  --radius:       20px;
  --shadow:       0 8px 20px rgba(14,165,233,.12);
  --shadow-hover: 0 12px 28px rgba(14,165,233,.2);
  --transition:   .2s ease;
}
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: var(--bg-gradient); color: var(--text-main); margin: 0; overflow-x: hidden; min-height: 100vh; }

/* SIDEBAR */
#sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh; background: var(--sidebar-bg); z-index: 1040; display: flex; flex-direction: column; transition: transform var(--transition); overflow-y: auto; box-shadow: 4px 0 20px rgba(0,0,0,.12); }
#sidebar .sidebar-brand { padding: 24px 20px 4px; }
#sidebar .sidebar-brand .brand-icon { width: 40px; height: 40px; background: var(--white); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--primary-dark); font-size: 1.3rem; font-weight: 700; margin-bottom: .5rem; }
#sidebar .sidebar-brand h5 { color: #fff; font-weight: 800; font-size: 1.6rem; margin: 0; letter-spacing: -.5px; }
#sidebar .sidebar-brand h5 span { color: #bae6fd; }
#sidebar .sidebar-brand p { color: #bae6fd; font-size: .72rem; margin: 0; padding: 4px 0 18px; border-bottom: 1px solid rgba(255,255,255,.15); display: flex; align-items: center; gap: 6px; }
#sidebar .nav-section { font-size: .68rem; font-weight: 700; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: .08em; padding: 16px 20px 4px; }
#sidebar .nav-link { display: flex; align-items: center; gap: 10px; color: #e0f2fe; padding: 10px 14px; border-radius: 12px; margin: 3px 10px; font-size: .88rem; font-weight: 500; transition: var(--transition); text-decoration: none; }
#sidebar .nav-link i { font-size: 1.05rem; min-width: 20px; }
#sidebar .nav-link:hover { background: rgba(255,255,255,.18); color: #fff; }
#sidebar .nav-link.active { background: rgba(255,255,255,.22); color: #fff; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
#sidebar .sidebar-footer { margin-top: auto; padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.15); }
#sidebar .sidebar-footer .user-name { color: white; font-weight: 700; font-size: .88rem; }
#sidebar .sidebar-footer .user-role { color: #bae6fd; font-size: .72rem; margin-bottom: 8px; }
#sidebar .sidebar-footer .nav-link { color: #fca5a5; margin: 0; padding: 0; }
#sidebar .sidebar-footer .nav-link:hover { background: transparent; color: #f87171; }
.badge-notif { background: #ef4444; color: white; border-radius: 50px; font-size: .65rem; padding: 2px 7px; font-weight: 700; margin-left: auto; }

/* TOPBAR */
#topbar { position: fixed; top: 0; left: var(--sidebar-w); right: 0; height: 64px; background: transparent; display: flex; align-items: center; padding: 0 1.75rem; z-index: 1030; gap: 1rem; margin-top: 10px; }
#topbar .topbar-toggle { display: none; background: var(--white); border: none; font-size: 1.35rem; color: var(--text-main); cursor: pointer; padding: 5px 10px; border-radius: 10px; box-shadow: var(--shadow); }
#topbar .topbar-title { font-size: 1.6rem; font-weight: 800; color: var(--text-main); flex: 1; }
#topbar .topbar-title span { color: var(--primary); }
#topbar .topbar-date { background: var(--white); border-radius: 50px; padding: 8px 18px; font-size: .85rem; font-weight: 600; color: var(--primary-dark); box-shadow: 0 2px 10px rgba(14,165,233,.15); }

/* MAIN */
#main-content { margin-left: var(--sidebar-w); padding-top: 64px; min-height: 100vh; display: flex; flex-direction: column; }
.content-area { padding: 2rem 1.75rem; flex: 1; }
.page-header { margin-bottom: 1.75rem; }
.page-header h2 { font-size: 1.6rem; font-weight: 800; margin: 0; color: var(--text-main); }
.page-header p { color: var(--text-muted); font-size: .88rem; margin: .2rem 0 0; }

/* CARDS */
.card { border: none; border-radius: var(--radius) !important; box-shadow: var(--shadow); background: var(--white); transition: transform var(--transition), box-shadow var(--transition); }
.card:hover { box-shadow: var(--shadow-hover); }
.card-header { background: #f0f9ff !important; border-bottom: 1px solid #dbeafe !important; border-radius: var(--radius) var(--radius) 0 0 !important; padding: 16px 24px; font-weight: 700; color: var(--text-main); font-size: .95rem; display: flex; align-items: center; justify-content: space-between; }
.card-header i { color: var(--primary); margin-right: .5rem; }

/* PROFIL CARD */
.profil-card { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #fff; border-radius: var(--radius) !important; padding: 1.75rem; box-shadow: var(--shadow); }
.profil-card .profil-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,.3); margin-bottom: .75rem; background: rgba(255,255,255,.2); display:flex;align-items:center;justify-content:center;font-size:2rem; }
.profil-card .profil-name { font-size: 1.25rem; font-weight: 800; margin: 0; }
.profil-card .profil-spec { font-size: .82rem; background: rgba(255,255,255,.2); color: #fff; border-radius: 10px; display: inline-block; padding: .3rem .8rem; margin-top: .4rem; font-weight: 600; }
.profil-info-row { display: flex; flex-direction: column; gap: .5rem; margin-top: 1.1rem; }
.profil-info-item { display: flex; align-items: center; gap: .6rem; font-size: .85rem; color: #e0f2fe; }
.profil-info-item i { font-size: .9rem; color: #bae6fd; min-width: 16px; }
.profil-info-item strong { color: #fff; }

/* STAT CARDS */
.stat-card { border-radius: var(--radius) !important; padding: 1.4rem 1.25rem; display: flex; align-items: center; gap: 1.1rem; box-shadow: var(--shadow); border: none; background: var(--white); border-left: 4px solid #38bdf8; transition: transform var(--transition), box-shadow var(--transition); }
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
.stat-icon { width: 54px; height: 54px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
.stat-icon.blue  { background: #f0f9ff; color: #0ea5e9; }
.stat-icon.teal  { background: #e0f7f4; color: #19a49a; }
.stat-icon.amber { background: #fff8e1; color: #f59e0b; }
.stat-icon.rose  { background: #fff1f2; color: #ef4444; }
.stat-label { font-size: .82rem; color: var(--text-muted); font-weight: 600; margin: 0; }
.stat-value { font-size: 1.8rem; font-weight: 800; color: var(--text-main); line-height: 1.1; }

/* TABLE */
.table { font-size: .88rem; }
.table thead { background: #38bdf8 !important; }
.table thead th { background: #38bdf8 !important; color: var(--white) !important; font-weight: 600; font-size: .85rem; border: none; padding: .75rem 1rem; }
.table tbody td { padding: .85rem 1rem; vertical-align: middle; border-color: #e2e8f0; }
.table tbody tr:hover { background: #f0f9ff; }

/* BADGE STATUS */
.badge-status { font-size: .75rem; padding: .35rem .75rem; border-radius: 10px; font-weight: 600; }
.badge-aktif    { background: #d1e7dd; color: #0f5132; }
.badge-menunggu { background: #fff3cd; color: #856404; }
.badge-dikonfirmasi { background: #dbeafe; color: #1d4ed8; }
.badge-selesai  { background: #e0f2fe; color: #0369a1; }
.badge-batal    { background: #f8d7da; color: #721c24; }

/* BUTTONS */
.btn-primary-custom { background: #38bdf8; border: none; color: white; font-weight: 600; border-radius: 11px; padding: .45rem 1.2rem; font-size: .82rem; transition: var(--transition); }
.btn-primary-custom:hover { background: #0ea5e9; color: white; }
.btn-outline-custom { border: 1px solid #38bdf8; color: #0ea5e9; background: transparent; border-radius: 11px; font-weight: 600; padding: .45rem 1.2rem; font-size: .82rem; transition: var(--transition); }
.btn-outline-custom:hover { background: #38bdf8; color: white; }
.btn-save { background: #38bdf8; border: none; color: white; font-weight: 600; border-radius: 11px; padding: 10px 25px; }
.btn-save:hover { background: #0ea5e9; color: white; }
.form-control, .form-select { border-radius: 11px; border: 1px solid #cbd5e1; padding: 10px 14px; }
.form-control:focus, .form-select:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.2); }

/* CHAT */
.chat-item { display: flex; align-items: center; gap: .85rem; padding: .75rem 0; border-bottom: 1px solid #f1f5f9; }
.chat-item:last-child { border-bottom: none; }
.chat-avatar-sm { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #38bdf8, #0284c7); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; font-size: 1rem; }
.chat-meta { flex: 1; min-width: 0; }
.chat-name { font-weight: 700; font-size: .88rem; margin: 0; color: var(--text-main); }
.chat-preview { font-size: .8rem; color: var(--text-muted); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-time { font-size: .75rem; color: var(--text-muted); }
.chat-list-item { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: .15s; display: flex; align-items: center; gap: 12px; text-decoration: none; }
.chat-list-item:hover { background: #f0f9ff; }
.chat-list-item.active { background: #dbeafe; }
.chat-box { height: 420px; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; background: #f8fafc; max-width: 620px; margin: 0 auto; width: 100%; }

/* ===== WhatsApp-style redesign khusus halaman Chat Pasien (dokter) ===== */
:root {
    --wa-header: #0ea5e9;
    --wa-header-dark: #0284c7;
    --wa-accent: #0ea5e9;
    --wa-bubble-out: #dbeafe;
    --wa-bubble-in: #ffffff;
    --wa-wallpaper: #eef6fc;
    --wa-text: #0f172a;
    --wa-text-muted: #64748b;
}
.wa-shell { display: flex; align-items: stretch; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,.12); border: 1px solid rgba(0,0,0,.05); min-height: 560px; background: white; }
.wa-sidebar { background: white; border-right: 1px solid #e9edef; flex: 0 0 320px; max-width: 320px; overflow-y: auto; }
.wa-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; }
@media (max-width: 767px) {
    .wa-shell { flex-direction: column; min-height: 0; }
    .wa-sidebar { flex: 0 0 auto; max-width: none; max-height: 260px; }
}
.wa-sidebar-head { padding: 16px 18px; font-weight: 700; font-size: .8rem; letter-spacing: .04em; color: white; background: linear-gradient(135deg, var(--wa-header), var(--wa-header-dark)); }
.wa-list-item { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: .15s; display: flex; align-items: center; gap: 12px; text-decoration: none; }
.wa-list-item:hover { background: #f0f9ff; }
.wa-list-item.active { background: #dbeafe; }
.wa-avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #38bdf8, #0284c7); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; font-size: 1rem; }
.wa-list-name { font-weight: 600; font-size: .9rem; color: var(--wa-text); }
.wa-list-time { font-size: .72rem; color: var(--wa-text-muted); }
.wa-list-unread { background: var(--wa-accent); color: white; border-radius: 50px; font-size: .68rem; padding: 2px 7px; font-weight: 700; margin-left: auto; }

.wa-chat-header { padding: 12px 18px; display: flex; align-items: center; gap: 12px; background: linear-gradient(135deg, var(--wa-header), var(--wa-header-dark)); }
.wa-chat-header .wa-avatar { width: 38px; height: 38px; font-size: .95rem; background: rgba(255,255,255,.2); }
.wa-chat-header-name { font-weight: 600; color: white; font-size: .95rem; }
.wa-chat-header-sub { font-size: .72rem; color: rgba(255,255,255,.8); }
.wa-timer-pill { margin-left: auto; background: rgba(255,255,255,.2); color: white; border-radius: 20px; padding: 6px 12px; font-size: .7rem; font-weight: 700; white-space: nowrap; }

.wa-body {
    height: 440px; overflow-y: auto; padding: 14px 6%; display: flex; flex-direction: column; gap: 2px;
    background-color: var(--wa-wallpaper);
    background-image:
        radial-gradient(circle at 15% 20%, rgba(14,165,233,.04) 0, transparent 45%),
        radial-gradient(circle at 85% 60%, rgba(14,165,233,.04) 0, transparent 45%),
        radial-gradient(circle at 40% 85%, rgba(14,165,233,.03) 0, transparent 40%);
}
.wa-row { display: flex; }
.wa-row.out { justify-content: flex-end; }
.wa-row.in { justify-content: flex-start; align-items: flex-end; gap: 8px; }
.wa-bubble { position: relative; max-width: 72%; padding: 7px 9px 6px 10px; border-radius: 8px; font-size: .875rem; line-height: 1.4; box-shadow: 0 1px 1px rgba(0,0,0,.1); }
.wa-row.out .wa-bubble { background: var(--wa-bubble-out); color: var(--wa-text); border-top-right-radius: 0; }
.wa-row.in .wa-bubble { background: var(--wa-bubble-in); color: var(--wa-text); border-top-left-radius: 0; }
.wa-bubble-time { float: right; margin: 4px 0 -2px 8px; font-size: .66rem; color: var(--wa-text-muted); }

.wa-footer { padding: 8px 12px; border-top: 1px solid rgba(0,0,0,.05); background: #f0f2f5; }
.wa-footer textarea { border-radius: 20px; border: none; padding: 10px 18px; background: white; font-size: .875rem; }
.wa-footer textarea:focus { outline: none; box-shadow: 0 0 0 1.5px var(--wa-header); }
.wa-send-btn { background: var(--wa-header); color: white; }
.wa-expired-banner { padding: 14px 16px; background: #fff3cd; color: #7a5c00; text-align: center; font-size: .82rem; border-top: 1px solid rgba(0,0,0,.05); }

/* JADWAL */
.jadwal-day-block { margin-bottom: 22px; }
.jadwal-day-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #e0f2fe; }
.jadwal-day-header .dot { width: 10px; height: 10px; border-radius: 50%; background: #38bdf8; }

/* ANTREAN CARDS */
.antrean-card { background: white; border-radius: 16px; padding: 18px; border: 2px solid #bae6fd; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 10px rgba(14,165,233,.07); }
.antrean-card.selesai { border-color: #e2e8f0; opacity: .65; }
.nomor-badge { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #38bdf8, #0369a1); color: white; font-size: 1.4rem; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.nomor-badge.selesai { background: #94a3b8; }

/* EMPTY STATE */
.empty-state { text-align: center; padding: 2rem 1rem; color: var(--text-muted); }
.empty-state i { font-size: 2.5rem; color: #cbd5e1; margin-bottom: .5rem; }
.footer { background: var(--white); border-top: 1px solid #e2e8f0; padding: 1rem 1.75rem; font-size: .82rem; color: var(--text-muted); text-align: center; margin-top: auto; }

/* RESPONSIVE */
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

<div id="sidebar-overlay"></div>

<!-- SIDEBAR -->
<nav id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></div>
    <h5>Medi<span>Track</span></h5>
    <p>Portal Dokter</p>
  </div>

  <div class="nav-section">Menu Utama</div>
  <a href="/api/dashboard_dokter.php?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>">
    <i class="bi bi-grid-1x2-fill"></i> Dashboard
  </a>
  <a href="/api/dashboard_dokter.php?page=jadwal" class="nav-link <?= $page==='jadwal'?'active':'' ?>">
    <i class="bi bi-calendar3"></i> Jadwal Praktik
  </a>
  <a href="/api/dashboard_dokter.php?page=antrean" class="nav-link <?= $page==='antrean'?'active':'' ?>">
    <i class="bi bi-people-fill"></i> Antrean Pasien
  </a>
  <a href="/api/dashboard_dokter.php?page=chat" class="nav-link <?= $page==='chat'?'active':'' ?>">
    <i class="bi bi-chat-dots-fill"></i> Chat
    <?php if ($jml_chat_unread > 0): ?>
      <span class="badge-notif"><?= $jml_chat_unread ?></span>
    <?php endif; ?>
  </a>
  <a href="/api/dashboard_dokter.php?page=rekam" class="nav-link <?= $page==='rekam'?'active':'' ?>">
    <i class="bi bi-file-earmark-medical-fill"></i> Rekam Medis
  </a>

  <div class="sidebar-footer">
    <div class="user-role">Login sebagai</div>
    <div class="user-name">dr. <?= htmlspecialchars($namaDokter) ?></div>
    <div class="user-role" style="margin-top:2px;"><?= htmlspecialchars($spesialis) ?></div>
    <a href="/api/logout.php" class="nav-link mt-2">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
  </div>
</nav>

<!-- TOPBAR -->
<header id="topbar">
  <button class="topbar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
  <span class="topbar-title">
    <?php
    $titles = ['dashboard'=>'Dashboard','jadwal'=>'Jadwal Praktik','antrean'=>'Antrean Pasien','chat'=>'Chat Pasien','rekam'=>'Rekam Medis'];
    echo '<span>' . ($titles[$page] ?? 'Dashboard') . '</span>';
    ?>
  </span>
  <span class="topbar-date">
    <i class="bi bi-calendar2-check me-1"></i><?= date('d M Y') ?>
  </span>
</header>

<!-- MAIN CONTENT -->
<div id="main-content">
  <div class="content-area">

<?php if ($page === 'dashboard'): ?>

    <div class="page-header">
      <h2>Selamat Datang, Dr. <?= htmlspecialchars($namaDokter) ?> 👋</h2>
      <p>Ini ringkasan aktivitas Anda hari ini, <?= hariId(date('Y-m-d')) ?>, <?= date('d') ?> <?= bulanId(date('Y-m-d')) ?> <?= date('Y') ?>.</p>
    </div>

    <!-- ROW 1: Profil + Statistik -->
    <div class="row g-4 mb-4">
      <div class="col-12 col-lg-4">
        <div class="profil-card h-100">
          <div class="profil-avatar">👨‍⚕️</div>
          <p class="profil-name">Dr. <?= htmlspecialchars($namaDokter) ?></p>
          <span class="profil-spec"><?= htmlspecialchars($spesialis) ?></span>
          <div class="profil-info-row">
            <div class="profil-info-item">
              <i class="bi bi-envelope"></i>
              <span>Email: <strong><?= htmlspecialchars($emailDokter) ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-ticket-perforated"></i>
              <span>Antrean hari ini: <strong><?= $jml_antrean ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-check-circle"></i>
              <span>Sudah selesai: <strong><?= $jml_selesai ?></strong></span>
            </div>
            <div class="profil-info-item">
              <i class="bi bi-hourglass-split"></i>
              <span>Masih menunggu: <strong><?= $jml_menunggu ?></strong></span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-8">
        <div class="row g-3 h-100 align-content-start">
          <div class="col-6">
            <div class="stat-card">
              <div class="stat-icon blue"><i class="bi bi-list-ol"></i></div>
              <div><p class="stat-label">Antrean Hari Ini</p><div class="stat-value"><?= $jml_antrean ?></div></div>
            </div>
          </div>
          <div class="col-6">
            <div class="stat-card" style="border-left-color:#f59e0b;">
              <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
              <div><p class="stat-label">Menunggu</p><div class="stat-value"><?= $jml_menunggu ?></div></div>
            </div>
          </div>
          <div class="col-6">
            <div class="stat-card" style="border-left-color:#19a49a;">
              <div class="stat-icon teal"><i class="bi bi-check2-circle"></i></div>
              <div><p class="stat-label">Selesai Diperiksa</p><div class="stat-value"><?= $jml_selesai ?></div></div>
            </div>
          </div>
          <div class="col-6">
            <div class="stat-card" style="border-left-color:#ef4444;">
              <div class="stat-icon rose"><i class="bi bi-chat-dots"></i></div>
              <div><p class="stat-label">Chat Belum Dibaca</p><div class="stat-value"><?= $jml_chat_unread ?></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ROW 2: Jadwal + Antrean -->
    <div class="row g-4 mb-4">
      <div class="col-12 col-lg-5">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-calendar-check"></i>Jadwal Praktik Hari Ini</span>
            <a href="/api/dashboard_dokter.php?page=jadwal" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (!empty($jadwal_list)): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead><tr><th>Jam Mulai</th><th>Jam Selesai</th><th>Kuota</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php foreach ($jadwal_list as $j): ?>
                      <tr>
                        <td><?= substr($j['jam_mulai'],0,5) ?></td>
                        <td><?= substr($j['jam_selesai'],0,5) ?></td>
                        <td><?= $j['terisi'] ?>/<?= $j['kuota'] ?></td>
                        <td><span class="badge-status <?= $j['status']==='penuh'?'badge-batal':'badge-aktif' ?>"><?= ucfirst($j['status']) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state"><i class="bi bi-calendar-x d-block"></i><p>Tidak ada jadwal hari ini.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-people"></i>Antrean Pasien Hari Ini</span>
            <a href="/api/dashboard_dokter.php?page=antrean" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (!empty($antrean_list)): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead><tr><th>#</th><th>Nama Pasien</th><th>Jam</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                    <?php foreach ($antrean_list as $a):
                      $badge_a = ['menunggu'=>'badge-menunggu','dikonfirmasi'=>'badge-dikonfirmasi','selesai'=>'badge-selesai','batal'=>'badge-batal'][$a['status']] ?? 'badge-menunggu';
                    ?>
                      <tr>
                        <td><span class="fw-bold" style="color:var(--primary);"><?= $a['nomor_antrean'] ?></span></td>
                        <td><?= htmlspecialchars($a['nama_pasien']) ?></td>
                        <td><?= substr($a['jam_mulai'],0,5) ?>–<?= substr($a['jam_selesai'],0,5) ?></td>
                        <td><span class="badge-status <?= $badge_a ?>"><?= ucfirst($a['status']) ?></span></td>
                        <td>
                          <?php if (!in_array($a['status'],['selesai','batal'])): ?>
                            <a href="/api/dashboard_dokter.php?page=antrean&action=selesai&antrean_id=<?= $a['id'] ?>"
                               class="btn-primary-custom btn" onclick="return confirm('Tandai selesai?')">Selesai</a>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state"><i class="bi bi-people d-block"></i><p>Belum ada antrean hari ini.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ROW 3: Chat + Rekam Medis -->
    <div class="row g-4">
      <div class="col-12 col-lg-5">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-chat-dots"></i>Chat Terbaru</span>
            <a href="/api/dashboard_dokter.php?page=chat" class="btn-outline-custom btn">Buka Semua</a>
          </div>
          <div class="card-body py-1 px-3">
            <?php if (!empty($chat_list)): ?>
              <?php foreach ($chat_list as $c):
                $diff = time() - strtotime($c['created_at']);
                $waktu = $diff < 3600 ? floor($diff/60).' mnt lalu' : ($diff < 86400 ? floor($diff/3600).' jam lalu' : date('d M', strtotime($c['created_at'])));
              ?>
                <div class="chat-item">
                  <div class="chat-avatar-sm"><i class="bi bi-person-fill"></i></div>
                  <div class="chat-meta">
                    <p class="chat-name"><?= htmlspecialchars($c['nama_pengirim']) ?></p>
                    <p class="chat-preview"><?= htmlspecialchars(potong($c['pesan'] ?? '', 55)) ?></p>
                  </div>
                  <div class="d-flex flex-column align-items-end gap-1">
                    <span class="chat-time"><?= $waktu ?></span>
                    <a href="/api/dashboard_dokter.php?page=chat&chat_user=<?= $c['user_id'] ?>" class="btn-primary-custom btn" style="font-size:.72rem;padding:.25rem .6rem;">Buka</a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state"><i class="bi bi-chat-square d-block"></i><p>Belum ada chat masuk.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <span><i class="bi bi-file-earmark-medical"></i>Rekam Medis Terbaru</span>
            <a href="/api/dashboard_dokter.php?page=rekam" class="btn-outline-custom btn">Lihat Semua</a>
          </div>
          <div class="card-body p-0">
            <?php if (!empty($rm_pending_list)): ?>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead><tr><th>Nama Pasien</th><th>Tanggal</th><th>Diagnosa</th></tr></thead>
                  <tbody>
                    <?php foreach ($rm_pending_list as $rm): ?>
                      <tr>
                        <td><?= htmlspecialchars($rm['nama_pasien'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($rm['tanggal_periksa']) ?></td>
                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($rm['diagnosa']) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="empty-state"><i class="bi bi-check-circle d-block" style="color:#19a49a;"></i><p>Belum ada rekam medis dicatat.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

<?php elseif ($page === 'antrean'): ?>
    <div class="page-header">
      <h2>🎟️ <span style="color:var(--primary);">Antrean Pasien</span></h2>
      <p>Daftar antrean hari ini — <?= hariId(date('Y-m-d')) ?>, <?= date('d') ?> <?= bulanId(date('Y-m-d')) ?> <?= date('Y') ?></p>
    </div>
    <?php if (!empty($message)): [$mt,$mx] = explode('|',$message); ?>
    <div class="alert alert-<?= $mt==='success'?'success':'danger' ?> alert-dismissible fade show rounded-3 mb-3">
      <?= htmlspecialchars($mx) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div class="row g-3 mb-4">
      <div class="col-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-list-ol"></i></div><div><p class="stat-label">Total</p><div class="stat-value"><?= count($antreanHariIni) ?></div></div></div></div>
      <div class="col-4"><div class="stat-card" style="border-left-color:#f59e0b;"><div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div><div><p class="stat-label">Menunggu</p><div class="stat-value"><?= $jml_menunggu ?></div></div></div></div>
      <div class="col-4"><div class="stat-card" style="border-left-color:#19a49a;"><div class="stat-icon teal"><i class="bi bi-check2-circle"></i></div><div><p class="stat-label">Selesai</p><div class="stat-value"><?= $jml_selesai ?></div></div></div></div>
    </div>
    <?php if (empty($antreanHariIni)): ?>
      <div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-calendar-x d-block"></i><p>Tidak ada antrean hari ini.</p></div></div></div>
    <?php else: ?>
      <div class="d-flex flex-column gap-3">
      <?php foreach ($antreanHariIni as $a):
        $isSelesai = $a['status']==='selesai'; $isBatal = $a['status']==='batal';
        $badge_a = ['menunggu'=>'badge-menunggu','dikonfirmasi'=>'badge-dikonfirmasi','selesai'=>'badge-selesai','batal'=>'badge-batal'][$a['status']] ?? 'badge-menunggu';
      ?>
        <div class="antrean-card <?= ($isSelesai||$isBatal)?'selesai':'' ?>">
          <div class="nomor-badge <?= $isSelesai?'selesai':'' ?>">#<?= $a['nomor_antrean'] ?></div>
          <div style="flex:1;">
            <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($a['nama_pasien']) ?></div>
            <div style="font-size:.78rem;color:#64748b;"><?= htmlspecialchars($a['email_pasien']) ?></div>
            <div style="font-size:.78rem;color:#475569;margin-top:4px;">🕐 <?= substr($a['jam_mulai'],0,5) ?>–<?= substr($a['jam_selesai'],0,5) ?> &nbsp;·&nbsp; Ambil: <?= date('H:i',strtotime($a['created_at'])) ?></div>
          </div>
          <div class="text-end">
            <span class="badge-status <?= $badge_a ?>"><?= ucfirst($a['status']) ?></span>
            <?php if (!$isSelesai && !$isBatal): ?>
            <div class="mt-2">
              <a href="/api/dashboard_dokter.php?page=antrean&action=selesai&antrean_id=<?= $a['id'] ?>" class="btn-primary-custom btn" style="font-size:.78rem;" onclick="return confirm('Tandai pasien ini selesai?')">🏁 Selesai</a>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

<?php elseif ($page === 'jadwal'): ?>
    <div class="page-header">
      <h2>📅 <span style="color:var(--primary);">Jadwal Praktik</span></h2>
      <p>Jadwal praktik 14 hari ke depan</p>
    </div>
    <div class="card">
      <div class="card-header"><span><i class="bi bi-calendar-week"></i>14 Hari ke Depan</span></div>
      <div class="card-body">
        <?php $adaJadwal = false; foreach ($jadwalPerTanggal as $tgl => $jadwals):
          if (empty($jadwals)) continue; $adaJadwal = true; $isToday = ($tgl===date('Y-m-d')); ?>
          <div class="jadwal-day-block">
            <div class="jadwal-day-header">
              <span class="dot"></span>
              <strong style="font-size:.88rem;color:<?= $isToday?'#0369a1':'#0f172a' ?>;">
                <?= $isToday?'📌 Hari ini, ':'' ?><?= hariId($tgl) ?>, <?= date('d',strtotime($tgl)) ?> <?= bulanId($tgl) ?> <?= date('Y',strtotime($tgl)) ?>
              </strong>
            </div>
            <div class="row g-2">
              <?php foreach ($jadwals as $j): $penuh = $j['sisa_kuota']<=0; ?>
                <div class="col-md-6">
                  <div style="background:<?= $isToday?'#f0f9ff':'white' ?>;border-radius:14px;padding:16px;border:1.5px solid <?= $penuh?'#fecaca':'#bae6fd' ?>;">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <div style="font-weight:700;">🕐 <?= substr($j['jam_mulai'],0,5) ?> – <?= substr($j['jam_selesai'],0,5) ?></div>
                        <div style="font-size:.78rem;color:#64748b;margin-top:4px;">Kuota: <?= $j['terisi'] ?>/<?= $j['kuota'] ?> terisi</div>
                      </div>
                      <span class="badge <?= $penuh?'bg-danger':'bg-success' ?>"><?= $penuh?'Penuh':$j['sisa_kuota'].' sisa' ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; if (!$adaJadwal): ?>
          <div class="empty-state"><i class="bi bi-calendar-x d-block"></i><p>Belum ada jadwal praktik untuk 2 minggu ke depan.</p></div>
        <?php endif; ?>
      </div>
    </div>

<?php elseif ($page === 'rekam'): ?>
    <div class="page-header">
      <h2>📋 <span style="color:var(--primary);">Rekam Medis</span></h2>
      <p>Catat diagnosa dan rekam medis pasien</p>
    </div>
    <?php if (!empty($rekamMsg)): [$rt,$rx] = explode('|',$rekamMsg); ?>
    <div class="alert alert-<?= $rt==='success'?'success':'danger' ?> alert-dismissible fade show rounded-3 mb-3">
      <?= htmlspecialchars($rx) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div class="card mb-4">
      <div class="card-header"><span><i class="bi bi-pencil-square"></i>Catat Rekam Medis Baru</span></div>
      <div class="card-body">
        <form method="POST" action="/api/dashboard_dokter.php?page=rekam">
          <input type="hidden" name="form_rekam" value="1">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">Pasien</label>
              <select name="pasien_id" id="pasienSelect" class="form-select" required onchange="isiNamaPasien()">
                <option value="">— Pilih Pasien —</option>
                <?php foreach ($pasienList as $p): ?>
                <option value="<?= $p['id'] ?>" data-nama="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['email']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="nama_pasien" id="namaPasienHidden">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Tanggal Periksa</label>
              <input type="date" name="tanggal_periksa" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Diagnosa</label>
              <input type="text" name="diagnosa" class="form-control" placeholder="Contoh: ISPA, Hipertensi" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Poliklinik</label>
              <input type="text" name="poliklinik" class="form-control" value="<?= htmlspecialchars($spesialis) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">Catatan</label>
              <textarea name="catatan" class="form-control" rows="3" placeholder="Keluhan, tindakan, resep..."></textarea>
            </div>
          </div>
          <button type="submit" class="btn btn-save mt-3">💾 Simpan Rekam Medis</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header">
        <span><i class="bi bi-file-earmark-medical"></i>Rekam Medis yang Sudah Dicatat</span>
        <span class="badge" style="background:#38bdf8;color:white;padding:6px 14px;border-radius:10px;"><?= count($rekamList) ?> Data</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead><tr><th>No</th><th>Pasien</th><th>Tanggal</th><th>Diagnosa</th><th>Poliklinik</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php if (empty($rekamList)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada rekam medis dicatat.</td></tr>
            <?php else: foreach ($rekamList as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><strong><?= htmlspecialchars($r['nama_pasien']) ?></strong></td>
                <td><?= htmlspecialchars($r['tanggal_periksa']) ?></td>
                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($r['diagnosa']) ?></span></td>
                <td><?= htmlspecialchars($r['poliklinik']) ?></td>
                <td style="font-size:.82rem;color:#64748b;"><?= htmlspecialchars(potong($r['catatan']??'',80)) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

<?php elseif ($page === 'chat'): ?>
    <div class="page-header">
      <h2>💬 <span style="color:var(--primary);">Chat Pasien</span></h2>
      <p>Balas pesan dari pasien yang pernah antrean ke Anda</p>
    </div>
    <div class="wa-shell">
      <div class="wa-sidebar">
        <div class="wa-sidebar-head">💬 PASIEN</div>
        <?php if (empty($chatUsers)): ?>
          <div class="empty-state"><i class="bi bi-chat-square d-block"></i><p>Belum ada pasien yang chat.</p></div>
        <?php else: foreach ($chatUsers as $cu): ?>
          <a href="/api/dashboard_dokter.php?page=chat&chat_user=<?= $cu['id'] ?>" class="wa-list-item <?= ($chatUserId==$cu['id'])?'active':'' ?>">
            <div class="wa-avatar"><?= strtoupper(substr($cu['name'],0,1)) ?></div>
            <div style="flex:1;min-width:0;">
              <div class="wa-list-name"><?= htmlspecialchars($cu['name']) ?></div>
              <div class="wa-list-time"><?= date('d M H:i',strtotime($cu['last_msg'])) ?></div>
            </div>
            <?php if ($cu['unread']>0): ?><span class="wa-list-unread"><?= $cu['unread'] ?></span><?php endif; ?>
          </a>
        <?php endforeach; endif; ?>
      </div>
      <div class="wa-panel">
        <?php if (!empty($chatUserId) && $chatUserName): ?>
          <div class="wa-chat-header">
            <div class="wa-avatar"><?= strtoupper(substr($chatUserName,0,1)) ?></div>
            <div style="flex:1;min-width:0;">
              <div class="wa-chat-header-name"><?= htmlspecialchars($chatUserName) ?></div>
              <div class="wa-chat-header-sub">Pasien</div>
            </div>
            <?php if ($chatExpiresAt !== null): ?>
              <div class="wa-timer-pill" id="chatTimer" data-expires="<?= $chatExpiresAt * 1000 ?>">⏳ --</div>
            <?php endif; ?>
          </div>
          <div class="wa-body flex-grow-1" id="chatBox">
            <?php if ($chatExpiresAt !== null): ?>
              <div class="chat-session-banner" style="align-self:center;background:#fff3cd;color:#7a5c00;font-size:.72rem;padding:6px 14px;border-radius:8px;margin-bottom:10px;text-align:center;max-width:90%;">🔒 Sesi konsultasi berlaku 1×24 jam sejak pasien membayar.</div>
            <?php endif; ?>
            <?php if (empty($chatMessages)): ?>
              <div style="text-align:center;margin:auto;color:#94a3b8;font-size:.88rem;">Belum ada pesan</div>
            <?php else: foreach ($chatMessages as $cm): ?>
              <?php if ($cm['pengirim']==='user'): ?>
                <div class="wa-row in">
                  <div class="wa-avatar" style="width:28px;height:28px;font-size:.7rem;"><?= strtoupper(substr($chatUserName,0,1)) ?></div>
                  <div class="wa-bubble">
                    <?= linkify($cm['pesan']) ?>
                    <span class="wa-bubble-time"><?= date('H:i',strtotime($cm['created_at'])) ?></span>
                  </div>
                </div>
              <?php else: ?>
                <div class="wa-row out">
                  <div class="wa-bubble">
                    <?= linkify($cm['pesan']) ?>
                    <span class="wa-bubble-time"><?= date('H:i',strtotime($cm['created_at'])) ?> ✓✓</span>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; endif; ?>
          </div>
          <?php if ($chatExpired): ?>
            <div class="wa-expired-banner">
              ⏳ Sesi konsultasi pasien ini sudah lewat 24 jam. Pasien perlu membayar ulang untuk melanjutkan chat.
            </div>
          <?php else: ?>
          <div class="wa-footer">
            <form method="POST" action="/api/dashboard_dokter.php?page=chat&chat_user=<?= $chatUserId ?>" style="display:flex;gap:10px;align-items:flex-end;">
              <textarea name="reply" rows="1" style="flex:1;font-family:inherit;resize:none;" class="form-control" placeholder="Tulis balasan..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"></textarea>
              <button type="submit" class="wa-send-btn" style="width:42px;height:42px;border-radius:50%;border:none;font-size:1rem;cursor:pointer;flex-shrink:0;">➤</button>
            </form>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div style="display:flex;align-items:center;justify-content:center;flex:1;color:#94a3b8;flex-direction:column;gap:10px;">
            <div style="font-size:3rem;">💬</div>
            <div style="font-size:.9rem;">Pilih pasien untuk membalas pesan</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

<?php endif; ?>

  </div>
  <footer class="footer">&copy; <?= date('Y') ?> MediTrack – Sistem Rekam Medis Digital.</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebar-overlay');
const toggleBtn = document.getElementById('sidebarToggle');
function openSidebar() { sidebar.classList.add('show'); overlay.classList.add('show'); document.body.style.overflow='hidden'; }
function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); document.body.style.overflow=''; }
if (toggleBtn) toggleBtn.addEventListener('click', () => sidebar.classList.contains('show') ? closeSidebar() : openSidebar());
if (overlay) overlay.addEventListener('click', closeSidebar);
window.addEventListener('resize', () => { if (window.innerWidth >= 992) closeSidebar(); });
function isiNamaPasien() {
    const sel = document.getElementById('pasienSelect');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('namaPasienHidden').value = opt.dataset.nama || '';
}
const chatBox = document.getElementById('chatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

const dokterChatTimer = document.getElementById('chatTimer');
if (dokterChatTimer) {
    const expiresAt = parseInt(dokterChatTimer.dataset.expires, 10);
    function tickDokterTimer() {
        const diff = expiresAt - Date.now();
        if (diff <= 0) {
            dokterChatTimer.textContent = '⏳ Sesi berakhir';
            return;
        }
        const h = Math.floor(diff / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        dokterChatTimer.textContent = `⏳ Sisa ${h}j ${m}m`;
    }
    tickDokterTimer();
    setInterval(tickDokterTimer, 30000);
}
<?php if ($page==='chat' && !empty($chatUserId)): ?>
setTimeout(() => {
    const replyBox = document.querySelector('.wa-footer textarea[name="reply"]');
    if (!replyBox || replyBox.value.trim() === '') location.reload();
}, 8000);
<?php endif; ?>
</script>
</body>
</html>