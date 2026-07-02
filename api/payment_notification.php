<?php
/**
 * payment_notification.php
 * URL ini didaftarkan di Midtrans Dashboard -> Settings -> Configuration
 * -> Payment Notification URL, contoh:
 *   https://nama-project-kamu.vercel.app/api/payment_notification.php
 *
 * Midtrans akan POST JSON ke sini setiap kali status transaksi berubah
 * (pending, settlement/capture, deny, cancel, expire).
 * Endpoint ini TIDAK memakai session — harus bisa diakses server Midtrans langsung.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/midtrans.php';

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || empty($body['order_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload tidak valid.']);
    exit;
}

$orderId      = (string) $body['order_id'];
$statusCode   = (string) ($body['status_code'] ?? '');
$grossAmount  = (string) ($body['gross_amount'] ?? '');
$signatureKey = (string) ($body['signature_key'] ?? '');
$transStatus  = (string) ($body['transaction_status'] ?? '');
$fraudStatus  = (string) ($body['fraud_status'] ?? '');

// 1. Verifikasi signature — WAJIB, supaya notifikasi tidak bisa dipalsukan orang lain
if (!midtransVerifySignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Signature tidak valid.']);
    exit;
}

// 2. Tentukan status internal berdasarkan transaction_status dari Midtrans
$statusBaru = null;
if ($transStatus === 'capture') {
    $statusBaru = ($fraudStatus === 'accept') ? 'lunas' : 'gagal';
} elseif ($transStatus === 'settlement') {
    $statusBaru = 'lunas';
} elseif (in_array($transStatus, ['deny', 'cancel'], true)) {
    $statusBaru = 'gagal';
} elseif ($transStatus === 'expire') {
    $statusBaru = 'expired';
} elseif ($transStatus === 'pending') {
    $statusBaru = 'pending';
}

if ($statusBaru === null) {
    // Status tidak dikenali, cukup diabaikan tapi tetap balas 200 agar Midtrans tidak retry terus
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$db = getDB();
$stmt = $db->prepare("UPDATE pembayaran_chat SET status = ?, updated_at = NOW() WHERE order_id = ?");
$stmt->execute([$statusBaru, $orderId]);

echo json_encode(['ok' => true, 'order_id' => $orderId, 'status' => $statusBaru]);
