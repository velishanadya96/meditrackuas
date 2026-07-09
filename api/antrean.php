<?php
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS antrean (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT NOT NULL,
            jadwal_id      INT NOT NULL,
            nomor_antrean  INT NOT NULL,
            status         ENUM('menunggu','dikonfirmasi','selesai','batal') DEFAULT 'menunggu',
            created_at     DATETIME DEFAULT NOW()
        )
    ");
} catch (Exception $e) { /* tabel sudah ada, lanjut */ }

$msgAntrean = '';
$subaction  = $_GET['subaction'] ?? 'list';

// ── Ambil Antrean ────────────────────────────────────────────────────────────
if ($subaction === 'ambil' && isset($_GET['jadwal_id'])) {
    $jadwalId = (int) $_GET['jadwal_id'];

    // Validasi jadwal masih tersedia
    $stmtCek = $db->prepare("
        SELECT j.*, d.nama AS nama_dokter, d.spesialisasi
        FROM jadwal_dokter j
        JOIN dokter d ON d.id = j.dokter_id
        WHERE j.id = ? AND j.status = 'tersedia' AND j.tanggal >= CURDATE()
    ");
    $stmtCek->execute([$jadwalId]);
    $jadwal = $stmtCek->fetch();

    if (!$jadwal) {
        $msgAntrean = 'danger|Jadwal tidak tersedia atau sudah penuh.';
    } else {
        // Cek apakah user sudah punya antrean aktif di jadwal ini
        $stmtSudah = $db->prepare("
            SELECT id FROM antrean
            WHERE user_id = ? AND jadwal_id = ? AND status IN ('menunggu','dikonfirmasi')
        ");
        $stmtSudah->execute([$userId, $jadwalId]);
        if ($stmtSudah->fetch()) {
            $msgAntrean = 'warning|Kamu sudah punya antrean aktif untuk jadwal ini.';
        } else {
            // Hitung nomor antrean berikutnya
            $stmtNomor = $db->prepare("
                SELECT COALESCE(MAX(nomor_antrean), 0) + 1 FROM antrean WHERE jadwal_id = ?
            ");
            $stmtNomor->execute([$jadwalId]);
            $nomorBaru = (int) $stmtNomor->fetchColumn();

            // Cek kuota
            if ($nomorBaru > $jadwal['kuota']) {
                $msgAntrean = 'danger|Kuota antrean untuk jadwal ini sudah penuh.';
            } else {
                $db->prepare("
                    INSERT INTO antrean (user_id, jadwal_id, nomor_antrean, status, created_at)
                    VALUES (?, ?, ?, 'menunggu', NOW())
                ")->execute([$userId, $jadwalId, $nomorBaru]);

                // Update terisi di jadwal_dokter
                $db->prepare("
                    UPDATE jadwal_dokter SET terisi = terisi + 1
                    WHERE id = ?
                ")->execute([$jadwalId]);

                // Kalau sudah penuh, ubah status jadwal
                $db->prepare("
                    UPDATE jadwal_dokter SET status = 'penuh'
                    WHERE id = ? AND terisi >= kuota
                ")->execute([$jadwalId]);

                // Verifikasi id benar-benar ke-generate (TiDB AUTO_INCREMENT kadang gagal / NULL)
                $stmtCekId = $db->prepare("
                    SELECT id FROM antrean
                    WHERE user_id = ? AND jadwal_id = ? AND nomor_antrean = ?
                    ORDER BY created_at DESC LIMIT 1
                ");
                $stmtCekId->execute([$userId, $jadwalId, $nomorBaru]);
                $rowCekId = $stmtCekId->fetch();

                if ($rowCekId && $rowCekId['id'] === null) {
                    // id NULL -> generate manual & isi
                    $stmtMaxId = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM antrean");
                    $idBaru = (int) $stmtMaxId->fetchColumn();
                    $db->prepare("
                        UPDATE antrean SET id = ?
                        WHERE user_id = ? AND jadwal_id = ? AND nomor_antrean = ? AND id IS NULL
                    ")->execute([$idBaru, $userId, $jadwalId, $nomorBaru]);
                }

                $msgAntrean = "success|Berhasil ambil antrean! Nomor kamu: <strong>#$nomorBaru</strong>";
            }
        }
    }
}

// ── Konfirmasi Antrean ───────────────────────────────────────────────────────
if ($subaction === 'konfirmasi' && isset($_GET['antrean_id'])) {
    $antreanId = (int) $_GET['antrean_id'];
    $stmtKonfirm = $db->prepare("
        UPDATE antrean SET status = 'dikonfirmasi'
        WHERE id = ? AND user_id = ? AND status = 'menunggu'
    ");
    $stmtKonfirm->execute([$antreanId, $userId]);
    if ($stmtKonfirm->rowCount() > 0) {
        $msgAntrean = 'success|Antrean berhasil dikonfirmasi kehadiranmu!';
    } else {
        $msgAntrean = 'danger|Gagal konfirmasi — antrean tidak ditemukan atau sudah dikonfirmasi.';
    }
}

// ── Batal Antrean ────────────────────────────────────────────────────────────
if ($subaction === 'batal' && isset($_GET['antrean_id'])) {
    $antreanId = (int) $_GET['antrean_id'];

    // Ambil jadwal_id dulu sebelum hapus
    $stmtGet = $db->prepare("SELECT jadwal_id FROM antrean WHERE id = ? AND user_id = ?");
    $stmtGet->execute([$antreanId, $userId]);
    $rowBatal = $stmtGet->fetch();

    if ($rowBatal) {
        $db->prepare("
            UPDATE antrean SET status = 'batal' WHERE id = ? AND user_id = ?
        ")->execute([$antreanId, $userId]);

        // Kurangi terisi & kembalikan status jadwal jadi tersedia
        $db->prepare("
            UPDATE jadwal_dokter
            SET terisi = GREATEST(terisi - 1, 0), status = 'tersedia'
            WHERE id = ?
        ")->execute([$rowBatal['jadwal_id']]);

        $msgAntrean = 'success|Antrean berhasil dibatalkan.';
    } else {
        $msgAntrean = 'danger|Antrean tidak ditemukan.';
    }
}

// ── Ambil jadwal 7 hari ke depan ─────────────────────────────────────────────
$tanggalMulai = date('Y-m-d');
$tanggalAkhir = date('Y-m-d', strtotime('+6 days'));

$stmtJadwal = $db->prepare("
    SELECT j.*, COALESCE(j.terisi, 0) AS terisi, d.nama AS nama_dokter, d.spesialisasi,
           (j.kuota - COALESCE(j.terisi, 0)) AS sisa_kuota
    FROM jadwal_dokter j
    JOIN dokter d ON d.id = j.dokter_id
    WHERE j.tanggal BETWEEN ? AND ?
    ORDER BY j.tanggal ASC, j.jam_mulai ASC
");
$stmtJadwal->execute([$tanggalMulai, $tanggalAkhir]);
$jadwalRaw = $stmtJadwal->fetchAll();

// Kelompokkan per tanggal (7 hari, tetap muncul walau kosong)
$jadwalPerTanggal = [];
for ($i = 0; $i < 7; $i++) {
    $tgl = date('Y-m-d', strtotime("+$i days"));
    $jadwalPerTanggal[$tgl] = [];
}
foreach ($jadwalRaw as $j) {
    $jadwalPerTanggal[$j['tanggal']][] = $j;
}

// ── Ambil antrean aktif milik user ───────────────────────────────────────────
$stmtMyAntrean = $db->prepare("
    SELECT a.*, j.tanggal, j.jam_mulai, j.jam_selesai,
           d.nama AS nama_dokter, d.spesialisasi
    FROM antrean a
    JOIN jadwal_dokter j ON j.id = a.jadwal_id
    JOIN dokter d ON d.id = j.dokter_id
    WHERE a.user_id = ?
    AND a.status IN ('menunggu','dikonfirmasi')
    ORDER BY j.tanggal ASC, j.jam_mulai ASC
");
$stmtMyAntrean->execute([$userId]);
$myAntrean = $stmtMyAntrean->fetchAll();

// IDs jadwal yang sudah punya antrean aktif (untuk disable tombol)
$jadwalSudahAmbil = array_column($myAntrean, 'jadwal_id');

// Helper
function labelHari(string $tgl): string {
    $hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    return $hari[date('w', strtotime($tgl))];
}
function labelBulan(string $tgl): string {
    $bln = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    return $bln[(int)date('n', strtotime($tgl))];
}
?>

<!-- ── Topbar ── -->
<div class="topbar">
    <div>
        <div class="topbar-title">📅 <span>Jadwal & Antrean</span></div>
        <div class="topbar-sub">Lihat jadwal dokter dan ambil nomor antrean</div>
    </div>
    <div class="topbar-badge">7 hari ke depan</div>
</div>

<!-- ── Alert ── -->
<?php if ($msgAntrean): [$alertType, $alertText] = explode('|', $msgAntrean, 2); ?>
<div class="alert alert-<?= $alertType ?> alert-dismissible fade show" style="border-radius:14px;">
    <?= $alertText ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Antrean Aktif Milik User ── -->
<?php if (!empty($myAntrean)): ?>
<div class="card-custom mb-4">
    <div class="card-header-custom d-flex align-items-center gap-2">
        <span style="font-size:1.1rem;">🎟️</span>
        <h5 class="mb-0 fw-bold">Antrean Aktif Kamu</h5>
        <span class="badge-custom ms-auto"><?= count($myAntrean) ?> Antrean</span>
    </div>
    <div class="p-3">
        <div class="row g-3">
        <?php foreach ($myAntrean as $ant): ?>
            <div class="col-md-6">
                <div style="background:<?= $ant['status']==='dikonfirmasi' ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#f0f9ff' ?>;border-radius:16px;padding:18px;border:2px solid <?= $ant['status']==='dikonfirmasi' ? '#34d399' : '#bae6fd' ?>;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div style="font-size:.72rem;color:#64748b;font-weight:600;text-transform:uppercase;">Nomor Antrean</div>
                            <div style="font-size:2.4rem;font-weight:900;color:<?= $ant['status']==='dikonfirmasi' ? '#059669' : '#0ea5e9' ?>;line-height:1;">#<?= $ant['nomor_antrean'] ?></div>
                        </div>
                        <span class="badge <?= $ant['status']==='dikonfirmasi' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.72rem;padding:6px 12px;border-radius:10px;">
                            <?= $ant['status']==='dikonfirmasi' ? '✅ Dikonfirmasi' : '⏳ Menunggu' ?>
                        </span>
                    </div>
                    <div style="font-weight:700;color:#0f172a;font-size:.9rem;"><?= htmlspecialchars($ant['nama_dokter']) ?></div>
                    <div style="font-size:.78rem;color:#64748b;"><?= htmlspecialchars($ant['spesialisasi']) ?></div>
                    <div style="font-size:.8rem;color:#475569;margin-top:8px;">
                        📅 <?= labelHari($ant['tanggal']) ?>, <?= date('d', strtotime($ant['tanggal'])) ?> <?= labelBulan($ant['tanggal']) ?> <?= date('Y', strtotime($ant['tanggal'])) ?>
                        &nbsp;·&nbsp; 🕐 <?= substr($ant['jam_mulai'],0,5) ?>–<?= substr($ant['jam_selesai'],0,5) ?>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <?php if ($ant['status'] === 'menunggu'): ?>
                        <a href="/api/dashboarduser.php?page=antrean&subaction=konfirmasi&antrean_id=<?= $ant['id'] ?>"
                           class="btn btn-sm btn-success flex-fill"
                           style="border-radius:10px;font-weight:600;"
                           onclick="return confirm('Konfirmasi kehadiranmu?')">
                           ✅ Konfirmasi Hadir
                        </a>
                        <?php endif; ?>
                        <a href="/api/dashboarduser.php?page=antrean&subaction=batal&antrean_id=<?= $ant['id'] ?>"
                           class="btn btn-sm btn-outline-danger <?= $ant['status']==='menunggu' ? '' : 'flex-fill' ?>"
                           style="border-radius:10px;font-weight:600;"
                           onclick="return confirm('Yakin mau batalkan antrean ini?')">
                           ✖ Batal
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Jadwal 7 Hari ke Depan ── -->
<div class="card-custom">
    <div class="card-header-custom">
        <h5 class="mb-0 fw-bold">🗓️ Jadwal Dokter — 7 Hari ke Depan</h5>
    </div>
    <div class="p-3">
    <?php foreach ($jadwalPerTanggal as $tgl => $jadwals): ?>
        <?php
            $isToday = ($tgl === date('Y-m-d'));
            $labelTgl = ($isToday ? '📌 Hari ini, ' : '') . labelHari($tgl) . ' ' . date('d', strtotime($tgl)) . ' ' . labelBulan($tgl) . ' ' . date('Y', strtotime($tgl));
        ?>
        <div class="mb-3">
            <div style="font-size:.78rem;font-weight:700;color:<?= $isToday ? '#0369a1' : '#64748b' ?>;background:<?= $isToday ? '#dbeafe' : '#f1f5f9' ?>;display:inline-block;padding:4px 12px;border-radius:8px;margin-bottom:8px;">
                <?= $labelTgl ?>
            </div>

            <?php if (empty($jadwals)): ?>
                <div style="background:#f8fafc;border-radius:12px;padding:14px 18px;color:#94a3b8;font-size:.85rem;">
                    Tidak ada jadwal dokter untuk hari ini.
                </div>
            <?php else: ?>
                <div class="row g-2">
                <?php foreach ($jadwals as $j):
                    $sudahAmbil = in_array($j['id'], $jadwalSudahAmbil);
                    $penuh = ($j['status'] === 'penuh') || ($j['sisa_kuota'] <= 0);
                ?>
                    <div class="col-md-6">
                        <div style="background:white;border-radius:14px;padding:16px;border:1.5px solid <?= $penuh ? '#fecaca' : '#bae6fd' ?>;display:flex;gap:14px;align-items:flex-start;">
                            <!-- Ikon spesialisasi -->
                            <div style="width:44px;height:44px;background:<?= $penuh ? '#fee2e2' : 'linear-gradient(135deg,#e0f2fe,#bae6fd)' ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                                🩺
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;color:#0f172a;font-size:.9rem;"><?= htmlspecialchars($j['nama_dokter']) ?></div>
                                <div style="font-size:.75rem;color:#64748b;margin-bottom:6px;"><?= htmlspecialchars($j['spesialisasi']) ?></div>
                                <div style="font-size:.78rem;color:#475569;">
                                    🕐 <?= substr($j['jam_mulai'],0,5) ?> – <?= substr($j['jam_selesai'],0,5) ?>
                                </div>
                                <div style="font-size:.75rem;margin-top:4px;">
                                    <?php if ($penuh): ?>
                                        <span style="color:#ef4444;font-weight:600;">❌ Penuh</span>
                                    <?php else: ?>
                                        <span style="color:#059669;font-weight:600;">✅ <?= $j['sisa_kuota'] ?> slot tersisa dari <?= $j['kuota'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <?php if ($sudahAmbil): ?>
                                        <span style="background:#dbeafe;color:#1d4ed8;font-size:.73rem;padding:5px 12px;border-radius:8px;font-weight:600;">🎟️ Sudah ambil antrean</span>
                                    <?php elseif ($penuh): ?>
                                        <span style="background:#fee2e2;color:#ef4444;font-size:.73rem;padding:5px 12px;border-radius:8px;font-weight:600;">Kuota habis</span>
                                    <?php else: ?>
                                        <a href="/api/dashboarduser.php?page=antrean&subaction=ambil&jadwal_id=<?= $j['id'] ?>"
                                           onclick="return confirm('Ambil antrean ke dr. <?= htmlspecialchars(addslashes($j['nama_dokter'])) ?>?')"
                                           style="background:linear-gradient(135deg,#0ea5e9,#0369a1);color:white;font-size:.78rem;padding:6px 16px;border-radius:10px;font-weight:700;text-decoration:none;display:inline-block;">
                                           + Ambil Antrean
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($tgl !== array_key_last($jadwalPerTanggal)): ?>
            <hr style="border-color:#e2e8f0;margin:4px 0 14px;">
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</div>