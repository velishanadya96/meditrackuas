<?php
// pages/chat.php — Chat Dokter (sisi pasien)
// Di-include dari dashboarduser.php SETELAH HTML head sudah dikirim,
// jadi JANGAN pakai header(). Gunakan JS redirect atau tampilkan pesan inline.

$chatAlert = '';

// ── Handle kirim pesan ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_pesan'])) {
    $pesan = trim($_POST['pesan'] ?? '');
    if ($pesan !== '') {
        $stmtInsert = $db->prepare(
            "INSERT INTO konsultasi_chat (user_id, pengirim, pesan, dibaca, created_at)
             VALUES (?, 'user', ?, 0, NOW())"
        );
        $stmtInsert->execute([$userId, $pesan]);
        $chatAlert = 'ok';
    }
}

// ── Tandai pesan admin sebagai sudah dibaca ──────────────────────────────────
$db->prepare(
    "UPDATE konsultasi_chat SET dibaca = 1
     WHERE user_id = ? AND pengirim = 'admin' AND dibaca = 0"
)->execute([$userId]);

// ── Ambil semua pesan history ────────────────────────────────────────────────
$stmtMsg = $db->prepare(
    "SELECT * FROM konsultasi_chat WHERE user_id = ? ORDER BY created_at ASC"
);
$stmtMsg->execute([$userId]);
$messages = $stmtMsg->fetchAll();
?>

<style>
/* ── Layout ── */
.chat-outer {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 80px);
    min-height: 500px;
    gap: 0;
}

/* ── Topbar custom ── */
.chat-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-shrink: 0;
}
.chat-topbar-left { display: flex; flex-direction: column; gap: 2px; }
.chat-topbar-title { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
.chat-topbar-title span { color: #0ea5e9; }
.chat-topbar-sub { color: #64748b; font-size: .88rem; }

/* ── Card pembungkus ── */
.chat-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 8px 24px rgba(14,165,233,.13);
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
}

/* ── Header chat ── */
.chat-header {
    background: linear-gradient(135deg, #0369a1 0%, #0ea5e9 100%);
    padding: 14px 22px;
    display: flex;
    align-items: center;
    gap: 13px;
    flex-shrink: 0;
}
.chat-header-avatar {
    width: 42px; height: 42px;
    background: rgba(255,255,255,.22);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
}
.chat-header-name  { color: white; font-weight: 700; font-size: .95rem; }
.chat-header-status {
    color: #bae6fd; font-size: .75rem;
    display: flex; align-items: center; gap: 5px;
}
.online-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #4ade80;
    display: inline-block;
    animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
    0%,100% { opacity:1; } 50% { opacity:.4; }
}
.chat-header-tag {
    margin-left: auto;
    background: rgba(255,255,255,.18);
    color: #e0f2fe;
    font-size: .72rem;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
}

/* ── Body scroll area ── */
.chat-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: #f8fafc;
    min-height: 0;
}
.chat-body::-webkit-scrollbar { width: 5px; }
.chat-body::-webkit-scrollbar-thumb { background: #bae6fd; border-radius: 10px; }

/* ── Empty state ── */
.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    gap: 8px;
    padding: 40px 0;
}
.chat-empty-icon { font-size: 3.2rem; }
.chat-empty-title { font-weight: 700; color: #475569; font-size: 1rem; }
.chat-empty-sub { font-size: .82rem; text-align: center; max-width: 260px; line-height: 1.5; }

/* ── Tanggal separator ── */
.date-sep {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #94a3b8;
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.date-sep::before, .date-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

/* ── Bubble row ── */
.bubble-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
.bubble-row.from-user  { flex-direction: row-reverse; }
.bubble-row.from-admin { flex-direction: row; }

.bubble-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    font-weight: 800;
    flex-shrink: 0;
}
.bubble-avatar.av-admin { background: #0ea5e9; color: white; }
.bubble-avatar.av-user  { background: #7c3aed; color: white; }

.bubble-wrap { display: flex; flex-direction: column; max-width: 65%; }
.from-user  .bubble-wrap { align-items: flex-end; }
.from-admin .bubble-wrap { align-items: flex-start; }

.bubble-sender-name {
    font-size: .7rem;
    font-weight: 700;
    color: #0369a1;
    margin-bottom: 3px;
    padding: 0 4px;
}

.bubble {
    padding: 10px 14px;
    border-radius: 18px;
    font-size: .88rem;
    line-height: 1.5;
    word-break: break-word;
}
.bubble.from-admin {
    background: white;
    color: #1e293b;
    border-bottom-left-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
.bubble.from-user {
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
    color: white;
    border-bottom-right-radius: 4px;
    box-shadow: 0 3px 10px rgba(14,165,233,.28);
}

.bubble-meta {
    font-size: .67rem;
    margin-top: 4px;
    padding: 0 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.from-admin .bubble-meta { color: #94a3b8; }
.from-user  .bubble-meta { color: #94a3b8; justify-content: flex-end; }
.read-tick { font-size: .75rem; }
.read-tick.read    { color: #34d399; }
.read-tick.unread  { color: #94a3b8; }

/* ── Footer input ── */
.chat-footer {
    padding: 12px 16px;
    background: white;
    border-top: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.chat-input-row {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}
.chat-input-text {
    flex: 1;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    padding: 10px 15px;
    font-size: .9rem;
    resize: none;
    outline: none;
    min-height: 44px;
    max-height: 110px;
    overflow-y: auto;
    font-family: inherit;
    background: #f8fafc;
    color: #1e293b;
    transition: border-color .2s, box-shadow .2s;
    line-height: 1.4;
}
.chat-input-text:focus {
    border-color: #0ea5e9;
    background: white;
    box-shadow: 0 0 0 3px rgba(14,165,233,.11);
}
.btn-send {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
    border: none;
    border-radius: 14px;
    color: white;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform .15s, box-shadow .15s;
    box-shadow: 0 3px 10px rgba(14,165,233,.3);
}
.btn-send:hover  { transform: scale(1.07); box-shadow: 0 5px 14px rgba(14,165,233,.4); }
.btn-send:active { transform: scale(.96); }

.chat-hint {
    font-size: .68rem;
    color: #94a3b8;
    text-align: center;
    margin-top: 6px;
}
</style>

<div class="chat-outer">

    <!-- Topbar -->
    <div class="chat-topbar">
        <div class="chat-topbar-left">
            <div class="chat-topbar-title">💬 <span>Chat Dokter</span></div>
            <div class="chat-topbar-sub">Konsultasi online dengan tim medis MediTrack</div>
        </div>
    </div>

    <!-- Card utama -->
    <div class="chat-card">

        <!-- Header -->
        <div class="chat-header">
            <div class="chat-header-avatar">🩺</div>
            <div>
                <div class="chat-header-name">Tim Medis MediTrack</div>
                <div class="chat-header-status">
                    <span class="online-dot"></span> Siap membantu
                </div>
            </div>
            <span class="chat-header-tag">Konsultasi Online</span>
        </div>

        <!-- Body (bubbles) -->
        <div class="chat-body" id="chatBody">

            <?php if (empty($messages)): ?>
            <div class="chat-empty">
                <div class="chat-empty-icon">💬</div>
                <div class="chat-empty-title">Mulai Konsultasi</div>
                <div class="chat-empty-sub">
                    Kirim pesan pertamamu! Tim medis kami akan membalas secepatnya.
                </div>
            </div>

            <?php else:
                $prevDate = '';
                foreach ($messages as $msg):
                    $tglMsg  = date('Y-m-d', strtotime($msg['created_at']));
                    $jamMsg  = date('H:i',   strtotime($msg['created_at']));
                    $isUser  = ($msg['pengirim'] === 'user');
                    $rowCls  = $isUser ? 'from-user' : 'from-admin';

                    // Separator tanggal
                    if ($tglMsg !== $prevDate):
                        $today     = date('Y-m-d');
                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                        if ($tglMsg === $today)          $labelDate = 'Hari ini';
                        elseif ($tglMsg === $yesterday)  $labelDate = 'Kemarin';
                        else                             $labelDate = date('d M Y', strtotime($tglMsg));
            ?>
                <div class="date-sep"><?= htmlspecialchars($labelDate) ?></div>
            <?php      $prevDate = $tglMsg;
                    endif; ?>

                <div class="bubble-row <?= $rowCls ?>">
                    <!-- Avatar -->
                    <div class="bubble-avatar <?= $isUser ? 'av-user' : 'av-admin' ?>">
                        <?= $isUser ? strtoupper(mb_substr($userName, 0, 1)) : '👨‍⚕️' ?>
                    </div>

                    <!-- Bubble + meta -->
                    <div class="bubble-wrap">
                        <?php if (!$isUser): ?>
                            <div class="bubble-sender-name">Tim Medis</div>
                        <?php endif; ?>

                        <div class="bubble <?= $rowCls ?>">
                            <?= nl2br(htmlspecialchars($msg['pesan'])) ?>
                        </div>

                        <div class="bubble-meta">
                            <span><?= $jamMsg ?></span>
                            <?php if ($isUser): ?>
                                <span class="read-tick <?= $msg['dibaca'] ? 'read' : 'unread' ?>">
                                    <?= $msg['dibaca'] ? '✓✓' : '✓' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endforeach; endif; ?>

        </div><!-- /chat-body -->

        <!-- Footer input -->
        <div class="chat-footer">
            <form method="POST" id="chatForm">
                <div class="chat-input-row">
                    <textarea
                        name="pesan"
                        id="chatInput"
                        class="chat-input-text"
                        placeholder="Tulis pesan konsultasi kamu… (Enter kirim, Shift+Enter baris baru)"
                        rows="1"
                        required
                    ></textarea>
                    <button type="submit" name="kirim_pesan" value="1" class="btn-send" title="Kirim pesan">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </div>
            </form>
            <div class="chat-hint">
                Untuk keadaan darurat hubungi <strong>119</strong>. Pesan kamu dibalas oleh tim medis kami.
            </div>
        </div>

    </div><!-- /chat-card -->

</div><!-- /chat-outer -->

<script>
(function () {
    // Auto-scroll ke bawah
    const body = document.getElementById('chatBody');
    if (body) body.scrollTop = body.scrollHeight;

    // Auto-resize textarea
    const ta = document.getElementById('chatInput');
    if (!ta) return;

    ta.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 110) + 'px';
    });


    ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (this.value.trim()) {
                document.getElementById('chatForm').submit();
            }
        }
    });

    // Fokus otomatis ke input
    ta.focus();
})();
</script>