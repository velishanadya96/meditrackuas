<?php
// pages/_form_jadwal.php
// $editData   → data dokter (kalau edit), null kalau tambah
// $hariAktif  → array hari yang sudah dipilih
$hariList = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
?>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Nama Dokter</label>
        <input type="text" name="nama" class="form-control"
               value="<?= htmlspecialchars($editData['nama'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Spesialis</label>
        <input type="text" name="spesialisasi" class="form-control"
               value="<?= htmlspecialchars($editData['spesialisasi'] ?? '') ?>" required>
    </div>
</div>

<div class="mt-3">
    <label class="form-label fw-semibold">Hari Praktik</label><br>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($hariList as $hari): ?>
        <div>
            <input type="checkbox" class="btn-check" name="hari[]"
                   id="hari_<?= $hari ?>" value="<?= $hari ?>"
                   <?= in_array($hari, $hariAktif) ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary btn-sm" for="hari_<?= $hari ?>"><?= $hari ?></label>
        </div>
        <?php endforeach; ?>
    </div>
    <small class="text-muted">Pilih satu atau beberapa hari sekaligus</small>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Jam Mulai</label>
        <input type="time" name="jam_mulai" class="form-control"
               value="<?= $editData['jam_mulai'] ?? '08:00' ?>" required>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Jam Selesai</label>
        <input type="time" name="jam_selesai" class="form-control"
               value="<?= $editData['jam_selesai'] ?? '12:00' ?>" required>
    </div>
</div>

<div class="mt-3">
    <label class="form-label fw-semibold">Status</label>
    <div class="row g-2">
        <div class="col-6">
            <input type="radio" class="btn-check" name="status" id="s_tersedia" value="tersedia"
                   <?= ($editData['status'] ?? 'tersedia') === 'tersedia' ? 'checked' : '' ?>>
            <label class="btn btn-outline-success w-100" for="s_tersedia">✅ Tersedia</label>
        </div>
        <div class="col-6">
            <input type="radio" class="btn-check" name="status" id="s_penuh" value="penuh"
                   <?= ($editData['status'] ?? '') === 'penuh' ? 'checked' : '' ?>>
            <label class="btn btn-outline-danger w-100" for="s_penuh">❌ Penuh</label>
        </div>
    </div>
</div>