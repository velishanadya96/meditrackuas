<?php
// pages/chat.php — Chat Dokter (sisi pasien)
$chatAlert = '';
$selected_dokter_id = isset($_GET['dokter_id']) ? (int)$_GET['dokter_id'] : 0;

// Ambil list semua dokter dan spesialisasinya untuk ditaruh di dropdown/list
$stmtAllDokter = $db->query("SELECT id, nama, spesialisasi FROM dokter WHERE email IS NOT NULL AND email <> '' ORDER BY nama ASC");
$listDokter = $stmtAllDokter->fetchAll();

if ($selected_dokter_id > 0) {
    $validIds = array_column($listDokter, 'id');
    if (!in_array($selected_dokter_id, $validIds)) {
        $selected_dokter_id = 0;
    }
}

// Cek Status Pembayaran jika dokter sudah dipilih
$isPaid = false;
if ($selected_dokter_id > 0) {
    $stmtCekBayar = $db->prepare("SELECT status FROM pembayaran_chat WHERE user_id = ? AND dokter_id = ? AND status = 'lunas' LIMIT 1");
    $stmtCekBayar->execute([$userId, $selected_dokter_id]);
    if ($stmtCekBayar->fetch()) {
        $isPaid = true;
    }
}

// ── Pembayaran sekarang ditangani oleh Midtrans Snap via api/payment.php
// (lihat tombol "Bayar Sekarang" + script Snap.js di bawah)
require_once __DIR__ . '/midtrans.php';

// ── Handle kirim pesan ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_pesan']) && $isPaid) {
    $pesan = trim($_POST['pesan'] ?? '');
    if ($pesan !== '' && $selected_dokter_id > 0) {
        $stmtInsert = $db->prepare(
            "INSERT INTO konsultasi_chat (user_id, dokter_id, pengirim, pesan, dibaca, created_at)
             VALUES (?, ?, 'user', ?, 0, NOW())"
        );
        $stmtInsert->execute([$userId, $selected_dokter_id, $pesan]);
        $chatAlert = 'ok';
    }
}

// ── Tandai pesan dokter sebagai sudah dibaca ──────────────────────────────────
if ($selected_dokter_id > 0) {
    $db->prepare(
        "UPDATE konsultasi_chat SET dibaca = 1
         WHERE user_id = ? AND dokter_id = ? AND pengirim = 'dokter' AND dibaca = 0"
    )->execute([$userId, $selected_dokter_id]);
}

// ── Ambil semua pesan history berdasarkan dokter yang dipilih ─────────────────
$messages = [];
if ($selected_dokter_id > 0 && $isPaid) {
    $stmtMsg = $db->prepare(
        "SELECT * FROM konsultasi_chat WHERE user_id = ? AND dokter_id = ? ORDER BY created_at ASC"
    );
    $stmtMsg->execute([$userId, $selected_dokter_id]);
    $messages = $stmtMsg->fetchAll();
}
?>

<div class="chat-outer container mt-3">
    <div class="card p-3 mb-3 shadow-sm" style="border-radius:15px;">
        <label class="form-label fw-bold">Pilih Dokter & Spesialisasi Konsultasi:</label>
        <select class="form-select" onchange="location = this.value;">
            <option value="/api/dashboarduser.php?page=chat">-- Pilih Dokter --</option>
            <?php foreach ($listDokter as $dok): ?>
                <option value="/api/dashboarduser.php?page=chat&dokter_id=<?= $dok['id'] ?>" <?= $selected_dokter_id == $dok['id'] ? 'selected' : '' ?>>
                    dr. <?= htmlspecialchars($dok['nama']) ?> (Spesialis <?= htmlspecialchars($dok['spesialisasi']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($selected_dokter_id > 0): ?>
        <?php if (!$isPaid): ?>
            <div class="card p-5 text-center shadow-lg" style="border-radius:20px; background: #fff;">
                <div class="display-1 text-warning mb-3">💳</div>
                <h4 class="fw-bold">Sesi Chat Dokter Terkunci</h4>
                <p class="text-muted">Untuk memulai chat dengan dokter pilihan Anda, Anda wajib menyelesaikan pembayaran sesi konsultasi daring sebesar:</p>
                <h2 class="text-primary fw-extrabold mb-4">Rp 45.000</h2>
                <button type="button" id="btnBayarMidtrans" data-dokter-id="<?= $selected_dokter_id ?>"
                        class="btn btn-success btn-lg px-5 fw-bold" style="border-radius:12px;">
                    Bayar Sekarang & Buka Chat
                </button>
                <div id="bayarStatus" class="mt-3 text-muted small"></div>
            </div>
        <?php else: ?>
            <div class="chat-card">
                <div class="chat-header text-white p-3 bg-primary">
                    <div class="chat-header-name">💬 Menghubungi Dokter Pilihan Anda</div>
                </div>

                <div class="chat-body p-3" id="chatBody" style="height: 350px; overflow-y: auto; background: #f8fafc;">
                    <?php if (empty($messages)): ?>
                        <p class="text-center text-muted my-4">Sesi chat telah dibuka. Silakan kirim keluhan Anda pada dokter.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="d-flex mb-3 <?= $m['pengirim'] === 'user' ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div class="p-3 rounded shadow-sm text-white <?= $m['pengirim'] === 'user' ? 'bg-info' : 'bg-secondary' ?>" style="max-width: 75%;">
                                    <?= htmlspecialchars($m['pesan']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-footer p-2 bg-light">
                    <form method="POST" class="d-flex gap-2">
                        <input type="text" name="pesan" placeholder="Tulis pesan..." class="form-control" required autocomplete="off">
                        <button type="submit" name="kirim_pesan" class="btn btn-primary">Kirim</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-info text-center">Silakan pilih spesialisasi dokter di atas untuk memulai konsultasi chat.</div>
    <?php endif; ?>
</div>

<script>
    const body = document.getElementById('chatBody');
    if (body) body.scrollTop = body.scrollHeight;
</script>

<?php if ($selected_dokter_id > 0 && !$isPaid): ?>
<script src="<?= midtransSnapJsUrl() ?>" data-client-key="<?= htmlspecialchars(midtransClientKey()) ?>"></script>
<script>
document.getElementById('btnBayarMidtrans').addEventListener('click', function () {
    const btn = this;
    const statusEl = document.getElementById('bayarStatus');
    btn.disabled = true;
    statusEl.textContent = 'Menyiapkan pembayaran...';

    fetch('/api/payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'dokter_id=' + btn.dataset.dokterId
    })
    .then(res => res.json())
    .then(data => {
        if (!data.ok) {
            statusEl.textContent = 'Gagal: ' + (data.error || 'Terjadi kesalahan.');
            btn.disabled = false;
            return;
        }
        if (data.already_paid) {
            window.location.reload();
            return;
        }
        window.snap.pay(data.token, {
            onSuccess: function () {
                statusEl.textContent = 'Pembayaran berhasil! Membuka sesi chat...';
                setTimeout(() => window.location.reload(), 1500);
            },
            onPending: function () {
                statusEl.textContent = 'Menunggu pembayaran diselesaikan. Halaman akan otomatis diperbarui setelah pembayaran dikonfirmasi.';
                btn.disabled = false;
            },
            onError: function () {
                statusEl.textContent = 'Pembayaran gagal. Silakan coba lagi.';
                btn.disabled = false;
            },
            onClose: function () {
                statusEl.textContent = 'Kamu menutup popup sebelum menyelesaikan pembayaran.';
                btn.disabled = false;
            }
        });
    })
    .catch(() => {
        statusEl.textContent = 'Tidak bisa terhubung ke server pembayaran.';
        btn.disabled = false;
    });
});
</script>
<?php endif; ?>