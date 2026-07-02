<?php
/**
 * midtrans.php
 * Helper untuk integrasi Midtrans Snap (Sandbox/Production).
 * File ini TIDAK diakses langsung dari browser — hanya di-include (require_once)
 * oleh payment.php dan payment_notification.php.
 *
 * Env vars yang dibutuhkan (set di Vercel Project Settings -> Environment Variables):
 *   MIDTRANS_SERVER_KEY   = SB-Mid-server-xxxxxxxxxxxxxxxxxxxxxxxx
 *   MIDTRANS_CLIENT_KEY   = SB-Mid-client-xxxxxxxxxxxxxxxxxxxxxxxx
 *   MIDTRANS_IS_PRODUCTION = false   (isi "true" kalau nanti sudah live)
 */

function midtransIsProduction(): bool
{
    return strtolower((string) getenv('MIDTRANS_IS_PRODUCTION')) === 'true';
}

function midtransServerKey(): string
{
    $key = getenv('MIDTRANS_SERVER_KEY');
    if (!$key) {
        throw new RuntimeException('MIDTRANS_SERVER_KEY belum di-set di environment variables.');
    }
    return $key;
}

function midtransClientKey(): string
{
    return getenv('MIDTRANS_CLIENT_KEY') ?: '';
}

function midtransSnapBaseUrl(): string
{
    return midtransIsProduction()
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
}

function midtransSnapJsUrl(): string
{
    return midtransIsProduction()
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

/**
 * Membuat transaksi Snap ke Midtrans dan mengembalikan array response
 * (berisi 'token' dan 'redirect_url').
 *
 * @param string $orderId   ID unik pesanan, misal CHAT-<userId>-<dokterId>-<time>
 * @param int    $amount    Nominal dalam Rupiah (integer, tanpa desimal)
 * @param array  $customer  ['first_name' => ..., 'email' => ..., 'phone' => ...]
 * @param string $itemName  Nama item yang tampil di halaman pembayaran
 */
function midtransCreateSnapTransaction(string $orderId, int $amount, array $customer, string $itemName): array
{
    $payload = [
        'transaction_details' => [
            'order_id'     => $orderId,
            'gross_amount' => $amount,
        ],
        'item_details' => [[
            'id'       => 'KONSULTASI-ONLINE',
            'price'    => $amount,
            'quantity' => 1,
            'name'     => $itemName,
        ]],
        'customer_details' => $customer,
        // Notifikasi status akan dikirim Midtrans ke URL ini (set juga di dashboard Midtrans)
        'callbacks' => [
            'finish' => (getenv('APP_BASE_URL') ?: '') . '/api/dashboarduser.php?page=chat',
        ],
    ];

    $ch = curl_init(midtransSnapBaseUrl());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(midtransServerKey() . ':'),
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Gagal menghubungi Midtrans: ' . $curlErr);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 400 || !isset($data['token'])) {
        $msg = $data['error_messages'][0] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Midtrans menolak transaksi: ' . $msg);
    }

    return $data; // ['token' => ..., 'redirect_url' => ...]
}

/**
 * Verifikasi signature_key yang dikirim Midtrans pada notifikasi webhook.
 * Rumus resmi: SHA512(order_id + status_code + gross_amount + ServerKey)
 */
function midtransVerifySignature(string $orderId, string $statusCode, string $grossAmount, string $signatureKey): bool
{
    $expected = hash('sha512', $orderId . $statusCode . $grossAmount . midtransServerKey());
    return hash_equals($expected, $signatureKey);
}
