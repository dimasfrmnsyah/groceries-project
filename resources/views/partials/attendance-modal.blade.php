<div class="modal fade" id="attendance-modal" tabindex="-1" aria-labelledby="attendance-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="attendance-modal-title">Detail absensi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div id="attendance-message" class="alert alert-danger d-none" role="alert"></div>
                <p id="attendance-modal-intro" class="text-muted"></p>
                <dl class="row mb-0" id="attendance-summary">
                    <dt class="col-4">Absen masuk</dt><dd class="col-8" data-attendance-field="checked_in_at"></dd>
                    <dt class="col-4">Absen keluar</dt><dd class="col-8" data-attendance-field="checked_out_at"></dd>
                    <dt class="col-4">Durasi kerja</dt><dd class="col-8 fw-semibold" data-attendance-field="duration"></dd>
                    <dt class="col-4">Jam normal</dt><dd class="col-8" data-attendance-field="normal"></dd>
                    <dt class="col-4">Lembur</dt><dd class="col-8 text-success fw-semibold" data-attendance-field="overtime"></dd>
                    <dt class="col-4">Keterangan</dt><dd class="col-8" data-attendance-field="status"></dd>
                </dl>
                <div class="border-top pt-3 mt-2 text-muted small">
                    Sesi pada tanggal masuk dan toko yang sama dijumlahkan. Jam normal maksimal 8 jam per hari. Kelebihan durasi dihitung sebagai lembur, jeda antar-sesi tidak dihitung.
                    Shift tetap aktif sampai Anda menekan Absen Keluar.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-warning d-none" id="attendance-confirm-out">Ya, Absen Keluar</button>
            </div>
        </div>
    </div>
</div>
