<?php
/**
 * payment.php
 * Dipanggil via fetch/AJAX dari chat.php saat user klik "Bayar Sekarang".
 * Membuat baris 'pending' di pembayaran_chat lalu minta Snap Token ke Midtrans.
 * Response: JSON { ok: true, token: "...", order_id: "..." }
 */

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/midtrans.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Belum login.']);
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$dokterId = isset($_POST['dokter_id']) ? (int) $_POST['dokter_id'] : 0;

if ($dokterId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'dokter_id tidak valid.']);
    exit;
}

$db = getDB();

// Pastikan kolom pendukung Midtrans tersedia (aman dijalankan berkali-kali)
try { $db->exec("ALTER TABLE pembayaran_chat ADD COLUMN order_id VARCHAR(100) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE pembayaran_chat ADD COLUMN snap_token VARCHAR(255) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE pembayaran_chat ADD COLUMN updated_at DATETIME NULL"); } catch (Exception $e) {}

// Kalau sudah lunas sebelumnya, tidak perlu bayar lagi
$stmtCek = $db->prepare("SELECT status FROM pembayaran_chat WHERE user_id = ? AND dokter_id = ? AND status = 'lunas' LIMIT 1");
$stmtCek->execute([$userId, $dokterId]);
if ($stmtCek->fetch()) {
    echo json_encode(['ok' => true, 'already_paid' => true]);
    exit;
}

// Ambil data dokter (untuk nama item) & data user (untuk customer_details)
$dokter = $db->prepare("SELECT nama FROM dokter WHERE id = ?");
$dokter->execute([$dokterId]);
$dokter = $dokter->fetch();
$namaDokter = $dokter['nama'] ?? 'Dokter';

$namaUser = $_SESSION['user_name'] ?? 'Pasien';
$emailUser = $_SESSION['user_email'] ?? 'pasien@example.com'; // sesuaikan jika kolom email tersedia di sesi

$amount  = 45000; // nominal sesi chat, samakan dengan yang tampil di chat.php
$orderId = 'CHAT-' . $userId . '-' . $dokterId . '-' . time();

try {
    $snap = midtransCreateSnapTransaction(
        $orderId,
        $amount,
        [
            'first_name' => $namaUser,
            'email'      => $emailUser,
        ],
        'Konsultasi Online dengan dr. ' . $namaDokter
    );
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Simpan/replace baris pending untuk pasangan user+dokter ini
$stmt = $db->prepare("
    INSERT INTO pembayaran_chat (user_id, dokter_id, nominal, status, order_id, snap_token, updated_at)
    VALUES (?, ?, ?, 'pending', ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        nominal = VALUES(nominal),
        status = 'pending',
        order_id = VALUES(order_id),
        snap_token = VALUES(snap_token),
        updated_at = NOW()
");
$stmt->execute([$userId, $dokterId, $amount, $orderId, $snap['token']]);

echo json_encode([
    'ok'       => true,
    'token'    => $snap['token'],
    'order_id' => $orderId,
]);
