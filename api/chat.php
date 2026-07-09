<?php
// pages/chat.php — Chat Dokter (sisi pasien)
$chatAlert = '';

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
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">' . $display . '</a>';
            },
            $escaped
        );
        return nl2br($escaped);
    }
}

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

// Ambil nama & spesialisasi dokter yang sedang dipilih (buat ditampilkan di header chat)
$selectedDokterNama = '';
$selectedDokterSpesialisasi = '';
if ($selected_dokter_id !== null) {
    foreach ($listDokter as $dok) {
        if ((int)$dok['id'] === $selected_dokter_id) {
            $selectedDokterNama = $dok['nama'];
            $selectedDokterSpesialisasi = $dok['spesialisasi'];
            break;
        }
    }
}

// Cek Status Pembayaran + masa berlaku 1x24 jam sejak dibayar
$isPaid = false;
$chatExpiresAt = null; // unix timestamp
if ($selected_dokter_id !== null) {
    $stmtCekBayar = $db->prepare("SELECT status, paid_at FROM pembayaran_chat WHERE user_id = ? AND dokter_id = ? AND status = 'lunas' LIMIT 1");
    $stmtCekBayar->execute([$userId, $selected_dokter_id]);
    $bayarRow = $stmtCekBayar->fetch();
    if ($bayarRow) {
        $paidAt = $bayarRow['paid_at'] ?? null;
        if ($paidAt) {
            $chatExpiresAt = strtotime($paidAt) + 24 * 3600;
            $isPaid = time() < $chatExpiresAt;
        }
    }
}

// ── Handle Simulasi Bayar (mengaktifkan/reset sesi 24 jam) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar_chat'])) {
    $stmtBayar = $db->prepare("INSERT INTO pembayaran_chat (user_id, dokter_id, nominal, status, paid_at) 
                               VALUES (?, ?, 45000, 'lunas', NOW()) 
                               ON DUPLICATE KEY UPDATE status = 'lunas', paid_at = NOW()");
    $stmtBayar->execute([$userId, $selected_dokter_id]);
    echo "<script>window.location.href='/api/dashboarduser.php?page=chat&dokter_id=".$selected_dokter_id."';</script>";
    exit;
}

// ── Handle kirim pesan (redirect setelah insert biar refresh nggak double) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_pesan']) && $isPaid) {
    $pesan = trim($_POST['pesan'] ?? '');
    if ($pesan !== '' && $selected_dokter_id !== null) {
        $stmtInsert = $db->prepare(
            "INSERT INTO konsultasi_chat (user_id, dokter_id, pengirim, pesan, dibaca, created_at)
             VALUES (?, ?, 'user', ?, 0, NOW())"
        );
        $stmtInsert->execute([$userId, $selected_dokter_id, $pesan]);
    }
    // Redirect (bukan render ulang) supaya kalau halaman di-refresh, browser
    // nge-GET halaman ini lagi, bukan resend POST kirim_pesan yang bikin pesan dobel.
    echo "<script>window.location.href='/api/dashboarduser.php?page=chat&dokter_id=".$selected_dokter_id."';</script>";
    exit;
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
    :root {
        --wa-header: #0ea5e9;
        --wa-header-dark: #0284c7;
        --wa-accent: #0ea5e9;
        --wa-accent-dark: #0284c7;
        --wa-bubble-out: #dbeafe;
        --wa-bubble-in: #ffffff;
        --wa-wallpaper: #eef6fc;
        --wa-text: #0f172a;
        --wa-text-muted: #64748b;
    }

    .chat-outer { max-width: 640px; margin: 0 auto; padding: 0 4px; }
    .chat-picker-card { background: white; border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 16px 20px; margin-bottom: 16px; }
    .chat-picker-card label { font-weight: 700; color: var(--wa-text); font-size: .88rem; margin-bottom: 8px; display: block; }
    .chat-picker-card select { border-radius: 10px; border: 1.5px solid #d1d7db; padding: 10px 14px; font-size: .9rem; }
    .chat-picker-card select:focus { border-color: var(--wa-header); box-shadow: 0 0 0 .2rem rgba(7,94,84,.15); }

    .chat-lock-card { background: white; border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,.1); padding: 44px 32px; text-align: center; }
    .chat-lock-icon { font-size: 3.2rem; margin-bottom: 8px; }
    .chat-lock-price { color: var(--wa-header); font-weight: 800; font-size: 2rem; margin: 6px 0 14px; }
    .chat-lock-notice { background: #fff8e1; border: 1px solid #ffe69c; color: #7a5c00; border-radius: 12px; padding: 10px 16px; font-size: .82rem; margin: 0 auto 22px; max-width: 420px; display: flex; align-items: center; gap: 8px; text-align: left; }
    .chat-lock-btn { background: linear-gradient(135deg, var(--wa-header), var(--wa-header-dark)); border: none; color: white; font-weight: 700; padding: 13px 40px; border-radius: 50px; font-size: 1rem; box-shadow: 0 6px 18px rgba(14,165,233,.35); transition: .2s; }
    .chat-lock-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(14,165,233,.45); color: white; background: var(--wa-header-dark); }

    .chat-card { background: var(--wa-wallpaper); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,.15); border: 1px solid rgba(0,0,0,.05); }

    .chat-header { background: linear-gradient(135deg, var(--wa-header), var(--wa-header-dark)); padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
    .chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; color: white; flex-shrink: 0; }
    .chat-header-name { color: white; font-weight: 600; font-size: .98rem; line-height: 1.25; }
    .chat-header-sub { color: rgba(255,255,255,.8); font-size: .74rem; }

    .chat-body {
        height: 440px; overflow-y: auto; padding: 14px 6%; display: flex; flex-direction: column;
        background-color: var(--wa-wallpaper);
        background-image:
            radial-gradient(circle at 15% 20%, rgba(0,0,0,.025) 0, transparent 45%),
            radial-gradient(circle at 85% 60%, rgba(0,0,0,.025) 0, transparent 45%),
            radial-gradient(circle at 40% 85%, rgba(0,0,0,.02) 0, transparent 40%);
    }
    .chat-empty { margin: auto; text-align: center; color: var(--wa-text-muted); }
    .chat-empty-icon { font-size: 2.4rem; margin-bottom: 10px; }

    .chat-session-banner { align-self: center; background: #fff3cd; color: #7a5c00; font-size: .74rem; padding: 7px 14px; border-radius: 8px; margin-bottom: 14px; text-align: center; max-width: 90%; box-shadow: 0 1px 3px rgba(0,0,0,.08); }

    .bubble-row { display: flex; margin-bottom: 6px; }
    .bubble-row.from-user { justify-content: flex-end; }
    .bubble-row.from-dokter { justify-content: flex-start; }
    .bubble { position: relative; max-width: 75%; padding: 7px 9px 6px 10px; border-radius: 8px; font-size: .9rem; line-height: 1.4; box-shadow: 0 1px 1px rgba(0,0,0,.1); word-wrap: break-word; }
    .bubble-row.from-user .bubble { background: var(--wa-bubble-out); color: var(--wa-text); border-top-right-radius: 0; }
    .bubble-row.from-dokter .bubble { background: var(--wa-bubble-in); color: var(--wa-text); border-top-left-radius: 0; }
    .bubble-label { font-size: .68rem; font-weight: 700; letter-spacing: .02em; color: var(--wa-header); margin-bottom: 2px; }
    .bubble-text { white-space: pre-wrap; }
    .bubble-time { float: right; margin: 4px 0 -2px 8px; font-size: .68rem; color: var(--wa-text-muted); }
    .bubble-row.from-user .bubble-time { display: flex; align-items: center; gap: 3px; }
    .bubble-tick { color: #53bdeb; }

    .chat-footer { background: #f0f2f5; padding: 8px 12px; border-top: 1px solid rgba(0,0,0,.05); }
    .chat-footer input.form-control { border-radius: 24px; border: none; padding: 10px 18px; font-size: .9rem; background: white; }
    .chat-footer input.form-control:focus { box-shadow: 0 0 0 1.5px var(--wa-header); }
    .chat-footer button { border-radius: 50%; width: 42px; height: 42px; padding: 0; display: flex; align-items: center; justify-content: center; background: var(--wa-header); border: none; flex-shrink: 0; }
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
                <?php if ($chatExpiresAt !== null): ?>
                    <p class="text-muted mb-1" style="max-width:420px;margin-inline:auto;">Sesi konsultasi sebelumnya sudah berakhir (masa berlaku 24 jam terlampaui). Silakan bayar lagi untuk membuka sesi chat baru:</p>
                <?php else: ?>
                    <p class="text-muted mb-1" style="max-width:420px;margin-inline:auto;">Untuk memulai chat dengan dokter pilihan Anda, Anda wajib menyelesaikan pembayaran sesi konsultasi daring sebesar:</p>
                <?php endif; ?>
                <div class="chat-lock-price">Rp 45.000</div>
                <div class="chat-lock-notice">
                    <span style="font-size:1.1rem;">⏳</span>
                    <span>Setelah pembayaran berhasil, sesi chat dengan dokter <strong>hanya berlaku 1×24 jam</strong>. Lewat dari itu, chat akan terkunci lagi dan Anda perlu membayar ulang.</span>
                </div>
                <form method="POST">
                    <button type="submit" name="bayar_chat" class="chat-lock-btn">
                        Bayar Sekarang & Buka Chat
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="chat-card">
                <div class="chat-header">
                    <div class="chat-header-avatar"><?= $selectedDokterNama !== '' ? strtoupper(substr($selectedDokterNama, 0, 1)) : '🩺' ?></div>
                    <div style="min-width:0;">
                        <div class="chat-header-name"><?= $selectedDokterNama !== '' ? 'dr. ' . htmlspecialchars($selectedDokterNama) : 'Konsultasi Dokter' ?></div>
                        <div class="chat-header-sub"><?= $selectedDokterSpesialisasi !== '' ? htmlspecialchars($selectedDokterSpesialisasi) : 'Sesi aktif' ?></div>
                    </div>
                    <div class="chat-timer" id="chatTimer" data-expires="<?= $chatExpiresAt * 1000 ?>" style="margin-left:auto;background:rgba(255,255,255,.18);color:white;border-radius:20px;padding:6px 12px;font-size:.72rem;font-weight:700;white-space:nowrap;">⏳ --</div>
                </div>

                <div class="chat-body" id="chatBody">
                    <div class="chat-session-banner">🔒 Sesi konsultasi ini berlaku 1×24 jam sejak pembayaran. Sisa waktu ditampilkan di pojok kanan atas.</div>
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
                                        <div class="bubble-label">dr. <?= htmlspecialchars($selectedDokterNama) ?></div>
                                    <?php endif; ?>
                                    <span class="bubble-text"><?= linkify($m['pesan']) ?></span>
                                    <span class="bubble-time">
                                        <?= date('H:i', strtotime($m['created_at'])) ?>
                                        <?php if ($m['pengirim'] === 'user'): ?>
                                            <svg class="bubble-tick" width="15" height="11" viewBox="0 0 16 11" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.071.653a.457.457 0 0 0-.304-.102.493.493 0 0 0-.381.178l-6.19 7.636-2.405-2.272a.463.463 0 0 0-.336-.146.47.47 0 0 0-.343.146l-.311.325a.454.454 0 0 0-.14.336c0 .131.047.242.14.336l2.996 2.996c.131.131.25.187.406.187a.475.475 0 0 0 .381-.187l6.826-8.436a.472.472 0 0 0 .105-.35.47.47 0 0 0-.161-.325L11.4.882" fill="currentColor" transform="translate(3.5 -0.1)"/></svg>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-footer">
                    <form method="POST" class="d-flex gap-2">
                        <input type="text" name="pesan" placeholder="Ketik pesan" class="form-control" required autocomplete="off">
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

    const timerEl = document.getElementById('chatTimer');
    if (timerEl) {
        const expiresAt = parseInt(timerEl.dataset.expires, 10);
        function tick() {
            const diff = expiresAt - Date.now();
            if (diff <= 0) {
                timerEl.textContent = '⏳ Sesi berakhir';
                clearInterval(iv);
                setTimeout(() => location.reload(), 1200);
                return;
            }
            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            timerEl.textContent = `⏳ Sisa ${h}j ${m}m ${s}d`;
        }
        tick();
        const iv = setInterval(tick, 1000);
    }
</script>