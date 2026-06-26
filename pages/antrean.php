<?php
// pages/antrean.php
// Dipanggil via include dari dashboarduser.php

require_once __DIR__ . '/../db.php';

// Hanya admin yang bisa tambah/edit/hapus
$isAdmin = ($_SESSION['user_role'] ?? 'user') === 'admin';

$message  = '';
$editData = null;
$action   = $_GET['subaction'] ?? 'list';
$id       = $_GET['did'] ?? null;

$pdo = getDB(); // gunakan getDB() sesuai db.php

// HAPUS
if ($isAdmin && $action === 'hapus' && $id) {
    $stmt = $pdo->prepare("DELETE FROM dokter WHERE id = ?");
    $stmt->execute([$id]);
    $message = 'success|Dokter berhasil dihapus.';
    $action  = 'list';
}

// PROSES POST (tambah / edit)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $nama         = trim($_POST['nama']);
    $spesialisasi = trim($_POST['spesialisasi']);
    $hari         = implode(' - ', $_POST['hari'] ?? []);
    $jam_mulai    = $_POST['jam_mulai'];
    $jam_selesai  = $_POST['jam_selesai'];
    $status       = $_POST['status'];

    if ($_POST['form_action'] === 'tambah') {
        $stmt = $pdo->prepare("INSERT INTO dokter (nama, spesialisasi, hari, jam_mulai, jam_selesai, status) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$nama, $spesialisasi, $hari, $jam_mulai, $jam_selesai, $status]);
        $message = 'success|Dokter berhasil ditambahkan.';
    } elseif ($_POST['form_action'] === 'edit') {
        $stmt = $pdo->prepare("UPDATE dokter SET nama=?, spesialisasi=?, hari=?, jam_mulai=?, jam_selesai=?, status=? WHERE id=?");
        $stmt->execute([$nama, $spesialisasi, $hari, $jam_mulai, $jam_selesai, $status, $_POST['edit_id']]);
        $message = 'success|Jadwal dokter berhasil diupdate.';
    }
    $action = 'list';
}

// Ambil data edit
if ($isAdmin && $action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM dokter WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Ambil semua dokter
$dokters = $pdo->query("SELECT * FROM dokter ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper: parse hari string jadi array
function parseHari(string $hariStr): array {
    $hariList = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
    $parts = array_map('trim', explode('-', $hariStr));
    if (count($parts) < 2) return $parts;
    $start = array_search($parts[0], $hariList);
    $end   = array_search($parts[1], $hariList);
    if ($start === false || $end === false) return $parts;
    return array_slice($hariList, $start, $end - $start + 1);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">📅 Jadwal & Antrean</h4>
        <small class="text-muted">Cek jadwal dokter dan ambil nomor antrean</small>
    </div>
    <?php if ($isAdmin): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
        <i class="bi bi-plus-circle me-1"></i> Tambah Dokter
    </button>
    <?php endif; ?>
</div>

<?php if ($message):
    [$type, $text] = explode('|', $message, 2);
    $alertClass = $type === 'success' ? 'alert-success' : 'alert-danger';
?>
<div class="alert <?= $alertClass ?> alert-dismissible fade show">
    <?= htmlspecialchars($text) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Form Edit (muncul kalau klik Edit) -->
<?php if ($isAdmin && $editData): ?>
<div class="card mb-4 shadow-sm border-warning">
    <div class="card-body">
        <h6 class="fw-bold mb-3">✏️ Edit Jadwal: <?= htmlspecialchars($editData['nama']) ?></h6>
        <form method="POST" action="?page=antrean">
            <input type="hidden" name="form_action" value="edit">
            <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
            <?php 
            $hariAktif = parseHari($editData['hari']);
            include __DIR__ . '/_form_jadwal.php'; 
            ?>
            <div class="d-flex gap-2 mt-3">
                <a href="?page=antrean" class="btn btn-secondary btn-sm">Batal Edit</a>
                <button type="submit" class="btn btn-warning btn-sm flex-grow-1">Update Jadwal</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Tabel Jadwal Dokter -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">Daftar Jadwal Dokter</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Dokter</th>
                    <th>Hari</th>
                    <th>Jam Praktik</th>
                    <th>Status</th>
                    <?php if ($isAdmin): ?><th class="text-end">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($dokters)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data dokter.</td></tr>
            <?php else: ?>
                <?php foreach ($dokters as $d): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($d['nama']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($d['spesialisasi']) ?></small>
                    </td>
                    <td>
                        <span class="badge bg-primary bg-opacity-10 text-primary fw-normal">
                            <?= htmlspecialchars($d['hari']) ?>
                        </span>
                    </td>
                    <td>
                        <i class="bi bi-clock text-muted me-1"></i>
                        <?= date('H:i', strtotime($d['jam_mulai'])) ?> - <?= date('H:i', strtotime($d['jam_selesai'])) ?>
                    </td>
                    <td>
                        <?php if ($d['status'] === 'tersedia'): ?>
                            <span class="badge bg-success-subtle text-success border border-success">✅ Tersedia</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger">❌ Penuh</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td class="text-end">
                        <a href="?page=antrean&subaction=edit&did=<?= $d['id'] ?>" class="text-warning fw-bold text-decoration-none me-2">Edit</a>
                        <a href="?page=antrean&subaction=hapus&did=<?= $d['id'] ?>"
                           class="text-danger fw-bold text-decoration-none"
                           onclick="return confirm('Hapus <?= htmlspecialchars($d['nama']) ?>?')">Hapus</a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah (admin only) -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=antrean">
                <input type="hidden" name="form_action" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Dokter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php 
                    $hariAktif = [];
                    $editData  = null;
                    include __DIR__ . '/_form_jadwal.php'; 
                    ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>