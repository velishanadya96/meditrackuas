<?php
// pages/chat.php — Chat Dokter (sisi pasien)
$chatAlert = '';

// Ambil list semua dokter dan spesialisasinya untuk ditaruh di dropdown/list
$stmtAllDokter = $db->query("SELECT id, nama, spesialisasi FROM dokter WHERE email IS NOT NULL AND email <> '' ORDER BY nama ASC");
$listDokter = $stmtAllDokter->fetchAll();
$validIds = array_map('intval', array_column($listDokter, 'id'));

// PENTING: jangan pakai "> 0" untuk mengecek dokter terpilih, karena id dokter
// bisa saja 0 (mis. baris pertama di tabel dokter). Gunakan null sebagai penanda
// "belum ada dokter dipilih", dan validasi terhadap daftar id yang benar-benar ada.
$rawDokterId = $_GET['dokter_id'] ?? null;
$selected_dokter_id = ($rawDokterId !== null && $rawDokterId !== '' && is_numeric($rawDokterId))
    ? (int) $rawDokterId
    : null;

if ($selected_dokter_id !== null && !in_array($selected_dokter_id, $validIds, true)) {
    $selected_dokter_id = null;
}

// Cek Status Pembayaran jika dokter sudah dipilih
$isPaid = false;
if ($selected_dokter_id !== null) {
    $stmtCekBayar = $db->prepare("SELECT status FROM pembayaran_chat WHERE user_id = ? AND dokter_id = ? AND status = 'lunas' LIMIT 1");
    $stmtCekBayar->execute([$userId, $selected_dokter_id]);
    if ($stmtCekBayar->fetch()) {
        $isPaid = true;
    }
}

// ── Handle Simulasi Bayar ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar_chat'])) {
    // Insert/Update status ke lunas
    $stmtBayar = $db->prepare("INSERT INTO pembayaran_chat (user_id, dokter_id, nominal, status) 
                               VALUES (?, ?, 45000, 'lunas') 
                               ON DUPLICATE KEY UPDATE status = 'lunas'");
    $stmtBayar->execute([$userId, $selected_dokter_id]);
    echo "<script>window.location.href='/api/dashboarduser.php?page=chat&dokter_id=".$selected_dokter_id."';</script>";
    exit;
}

// ── Handle kirim pesan ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_pesan']) && $isPaid) {
    $pesan = trim($_POST['pesan'] ?? '');
    if ($pesan !== '' && $selected_dokter_id !== null) {
        $stmtInsert = $db->prepare(
            "INSERT INTO konsultasi_chat (user_id, dokter_id, pengirim, pesan, dibaca, created_at)
             VALUES (?, ?, 'user', ?, 0, NOW())"
        );
        $stmtInsert->execute([$userId, $selected_dokter_id, $pesan]);
        $chatAlert = 'ok';
    }
}

// ── Tandai pesan dokter sebagai sudah dibaca ──────────────────────────────────
if ($selected_dokter_id !== null) {
    $db->prepare(
        "UPDATE konsultasi_chat SET dibaca = 1
         WHERE user_id = ? AND dokter_id = ? AND pengirim = 'dokter' AND dibaca = 0"
    )->execute([$userId, $selected_dokter_id]);
}

// ── Ambil semua pesan history berdasarkan dokter yang dipilih ─────────────────
$messages = [];
if ($selected_dokter_id !== null && $isPaid) {
    $stmtMsg = $db->prepare(
        "SELECT * FROM konsultasi_chat WHERE user_id = ? AND dokter_id = ? ORDER BY created_at ASC"
    );
    $stmtMsg->execute([$userId, $selected_dokter_id]);
    $messages = $stmtMsg->fetchAll();
}
?>

<style>
    .chat-outer { max-width: 640px; margin: 0 auto; padding: 0 4px; }
    .chat-picker-card { background: white; border: none; border-radius: 18px; box-shadow: 0 8px 20px rgba(14,165,233,.12); padding: 20px 24px; margin-bottom: 18px; }
    .chat-picker-card label { font-weight: 700; color: #0f172a; font-size: .92rem; margin-bottom: 8px; display: block; }
    .chat-picker-card select { border-radius: 12px; border: 1.5px solid #dbeafe; padding: 10px 14px; font-size: .92rem; }
    .chat-picker-card select:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.18); }

    .chat-lock-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(14,165,233,.15); padding: 48px 32px; text-align: center; }
    .chat-lock-icon { font-size: 3.2rem; margin-bottom: 8px; }
    .chat-lock-price { color: #0ea5e9; font-weight: 800; font-size: 2rem; margin: 6px 0 22px; }
    .chat-lock-btn { background: linear-gradient(135deg, #22c55e, #16a34a); border: none; color: white; font-weight: 700; padding: 13px 40px; border-radius: 14px; font-size: 1rem; box-shadow: 0 6px 18px rgba(34,197,94,.35); transition: .2s; }
    .chat-lock-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(34,197,94,.45); color: white; }

    .chat-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(14,165,233,.18); }
    .chat-header { background: linear-gradient(135deg, #0ea5e9, #0369a1); padding: 18px 22px; display: flex; align-items: center; gap: 12px; }
    .chat-header-avatar { width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.25); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
    .chat-header-name { color: white; font-weight: 700; font-size: 1.02rem; }
    .chat-header-sub { color: #e0f2fe; font-size: .78rem; }

    .chat-body { height: 420px; overflow-y: auto; padding: 22px; background: #f0f9ff; display: flex; flex-direction: column; }
    .chat-empty { margin: auto; text-align: center; color: #64748b; }
    .chat-empty-icon { font-size: 2.4rem; margin-bottom: 10px; }

    .bubble-row { display: flex; margin-bottom: 14px; }
    .bubble-row.from-user { justify-content: flex-end; }
    .bubble-row.from-dokter { justify-content: flex-start; }
    .bubble { max-width: 72%; padding: 11px 16px; border-radius: 16px; font-size: .92rem; line-height: 1.45; box-shadow: 0 3px 10px rgba(15,23,42,.08); word-wrap: break-word; }
    .bubble-row.from-user .bubble { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; border-bottom-right-radius: 4px; }
    .bubble-row.from-dokter .bubble { background: white; color: #0f172a; border-bottom-left-radius: 4px; }
    .bubble-label { font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; opacity: .65; margin-bottom: 3px; }

    .chat-footer { background: white; padding: 14px 18px; border-top: 1px solid #e2e8f0; }
    .chat-footer input.form-control { border-radius: 50px; border: 1.5px solid #dbeafe; padding: 11px 20px; font-size: .92rem; }
    .chat-footer input.form-control:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(56,189,248,.18); }
    .chat-footer button { border-radius: 50px; width: 46px; height: 46px; padding: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0ea5e9, #0284c7); border: none; flex-shrink: 0; }
</style>

<div class="chat-outer">
    <div class="chat-picker-card">
        <label>Pilih Dokter & Spesialisasi Konsultasi</label>
        <select class="form-select" onchange="location = this.value;">
            <option value="/api/dashboarduser.php?page=chat">-- Pilih Dokter --</option>
            <?php foreach ($listDokter as $dok): ?>
                <option value="/api/dashboarduser.php?page=chat&dokter_id=<?= (int)$dok['id'] ?>" <?= ($selected_dokter_id !== null && $selected_dokter_id === (int)$dok['id']) ? 'selected' : '' ?>>
                    dr. <?= htmlspecialchars($dok['nama']) ?> (Spesialis <?= htmlspecialchars($dok['spesialisasi']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($selected_dokter_id !== null): ?>
        <?php if (!$isPaid): ?>
            <div class="chat-lock-card">
                <div class="chat-lock-icon">💳</div>
                <h4 class="fw-bold mb-2">Sesi Chat Dokter Terkunci</h4>
                <p class="text-muted mb-1" style="max-width:420px;margin-inline:auto;">Untuk memulai chat dengan dokter pilihan Anda, Anda wajib menyelesaikan pembayaran sesi konsultasi daring sebesar:</p>
                <div class="chat-lock-price">Rp 45.000</div>
                <p class="text-muted mb-3" style="max-width:420px;margin-inline:auto;font-size:.8rem;">
                    <i class="bi bi-clock-history me-1"></i>Sesi chat berlaku <strong>1x24 jam</strong> sejak pembayaran berhasil.
                </p>
                <form method="POST">
                    <button type="submit" name="bayar_chat" class="chat-lock-btn">
                        Bayar Sekarang & Buka Chat
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="chat-card">
                <div class="chat-header">
                    <div class="chat-header-avatar">🩺</div>
                    <div>
                        <div class="chat-header-name">Konsultasi Dokter</div>
                        <div class="chat-header-sub">Sesi aktif &middot; terhubung</div>
                    </div>
                </div>

                <div class="chat-body" id="chatBody">
                    <?php if (empty($messages)): ?>
                        <div class="chat-empty">
                            <div class="chat-empty-icon">💬</div>
                            <div>Sesi chat telah dibuka.<br>Silakan kirim keluhan Anda pada dokter.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="bubble-row <?= $m['pengirim'] === 'user' ? 'from-user' : 'from-dokter' ?>">
                                <div class="bubble">
                                    <?php if ($m['pengirim'] !== 'user'): ?>
                                        <div class="bubble-label">Dokter</div>
                                    <?php endif; ?>
                                    <?= nl2br(htmlspecialchars($m['pesan'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-footer">
                    <form method="POST" class="d-flex gap-2">
                        <input type="text" name="pesan" placeholder="Tulis pesan..." class="form-control" required autocomplete="off">
                        <button type="submit" name="kirim_pesan" title="Kirim">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="white"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9-7-9-7v14zm0-7H3"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-info text-center" style="border-radius:14px;">Silakan pilih spesialisasi dokter di atas untuk memulai konsultasi chat.</div>
    <?php endif; ?>
</div>

<script>
    const body = document.getElementById('chatBody');
    if (body) body.scrollTop = body.scrollHeight;
</script>