<?php
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS pembayaran_chat (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            dokter_id  INT NOT NULL,
            nominal    INT NOT NULL DEFAULT 0,
            status     ENUM('pending','lunas') DEFAULT 'pending',
            created_at DATETIME DEFAULT NOW(),
            UNIQUE KEY uniq_user_dokter (user_id, dokter_id)
        )
    ");
} catch (Exception $e) { /* tabel sudah ada, lanjut */ }

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS konsultasi_chat (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            dokter_id     INT NOT NULL,
            pengirim      ENUM('user','dokter','admin') NOT NULL,
            pesan         TEXT NOT NULL,
            dibaca        TINYINT(1) DEFAULT 0,
            dibaca_dokter TINYINT(1) DEFAULT 0,
            created_at    DATETIME DEFAULT NOW()
        )
    ");
} catch (Exception $e) { /* tabel sudah ada, lanjut */ }

$chatAlert = '';
$selected_dokter_id = isset($_GET['dokter_id']) ? (int)$_GET['dokter_id'] : 0;

// Ambil list semua dokter dan spesialisasinya untuk ditaruh di dropdown/list
$stmtAllDokter = $db->query("SELECT id, nama, spesialisasi FROM dokter WHERE email IS NOT NULL AND email <> '' ORDER BY nama ASC");
$listDokter = $stmtAllDokter->fetchAll();

// Data dokter yang sedang dipilih (dipakai untuk header chat biar jelas lagi ngobrol sama siapa)
$selectedDokter = null;
if ($selected_dokter_id > 0) {
    $validIds = array_column($listDokter, 'id');
    if (!in_array($selected_dokter_id, $validIds)) {
        $selected_dokter_id = 0;
    } else {
        foreach ($listDokter as $dok) {
            if ($dok['id'] == $selected_dokter_id) { $selectedDokter = $dok; break; }
        }
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
                <p class="text-muted mb-1">
                    Untuk memulai chat dengan
                    <strong>dr. <?= htmlspecialchars($selectedDokter['nama'] ?? '') ?> (Spesialis <?= htmlspecialchars($selectedDokter['spesialisasi'] ?? '') ?>)</strong>,
                    Anda wajib menyelesaikan pembayaran sesi konsultasi daring sebesar:
                </p>
                <h2 class="text-primary fw-extrabold mb-4">Rp 45.000</h2>
                <form method="POST">
                    <button type="submit" name="bayar_chat" class="btn btn-success btn-lg px-5 fw-bold" style="border-radius:12px;">
                        Bayar Sekarang & Buka Chat
                    </button>
                </form>
            </div>
        <?php else: ?>
            <?php
                $namaDokterAktif = $selectedDokter ? $selectedDokter['nama'] : 'Dokter';
                $spesialisAktif  = $selectedDokter ? $selectedDokter['spesialisasi'] : '-';
                $inisialDokter   = strtoupper(substr($namaDokterAktif, 0, 1));
            ?>
            <div class="chat-card" style="border-radius:18px; overflow:hidden; box-shadow:0 8px 24px rgba(14,165,233,.15); background:#fff;">
                <!-- Header: identitas dokter yang sedang diajak chat -->
                <div class="chat-header text-white p-3 d-flex align-items-center gap-3" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
                    <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;">
                        <?= htmlspecialchars($inisialDokter) ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold" style="font-size:1rem;">dr. <?= htmlspecialchars($namaDokterAktif) ?></div>
                        <div style="font-size:.78rem;opacity:.9;">Spesialis <?= htmlspecialchars($spesialisAktif) ?> · <span style="color:#bbf7d0;">● Online</span></div>
                    </div>
                </div>

                <div class="chat-body p-3" id="chatBody" style="height: 380px; overflow-y: auto; background: #eff6fb;">
                    <?php if (empty($messages)): ?>
                        <div class="text-center text-muted my-4">
                            <div style="font-size:2.2rem;margin-bottom:8px;">💬</div>
                            Sesi chat dengan dr. <?= htmlspecialchars($namaDokterAktif) ?> telah dibuka.<br>Silakan kirim keluhan Anda.
                        </div>
                    <?php else: ?>
                        <?php $lastPengirim = null; ?>
                        <?php foreach ($messages as $m):
                            $isUser = $m['pengirim'] === 'user';
                            $labelPengirim = $isUser ? htmlspecialchars($userName) : 'dr. ' . htmlspecialchars($namaDokterAktif);
                            $waktu = isset($m['created_at']) ? date('d M, H:i', strtotime($m['created_at'])) : '';
                        ?>
                            <div class="d-flex mb-1 <?= $isUser ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div style="max-width: 75%;">
                                    <?php if ($lastPengirim !== $m['pengirim']): ?>
                                        <div style="font-size:.7rem;font-weight:700;color:#64748b;margin-bottom:3px;<?= $isUser ? 'text-align:right;' : '' ?>">
                                            <?= $labelPengirim ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="p-3 shadow-sm <?= $isUser ? 'text-white' : '' ?>"
                                         style="border-radius:16px; <?= $isUser
                                            ? 'background:linear-gradient(135deg,#0ea5e9,#0284c7); border-bottom-right-radius:4px;'
                                            : 'background:#ffffff; color:#0f172a; border:1px solid #e2e8f0; border-bottom-left-radius:4px;' ?>">
                                        <?= nl2br(htmlspecialchars($m['pesan'])) ?>
                                    </div>
                                    <div style="font-size:.65rem;color:#94a3b8;margin-top:2px;<?= $isUser ? 'text-align:right;' : '' ?>">
                                        <?= $waktu ?>
                                    </div>
                                </div>
                            </div>
                            <?php $lastPengirim = $m['pengirim']; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-footer p-2 bg-light border-top">
                    <form method="POST" class="d-flex gap-2">
                        <input type="text" name="pesan" placeholder="Tulis pesan untuk dr. <?= htmlspecialchars($namaDokterAktif) ?>..." class="form-control" required autocomplete="off">
                        <button type="submit" name="kirim_pesan" class="btn btn-primary" style="border-radius:10px;">Kirim</button>
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