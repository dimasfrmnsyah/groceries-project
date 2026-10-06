<div id="attendance-widget" class="ms-3 d-flex align-items-center gap-2"
     data-status-url="{{ route('attendance.status') }}"
     data-check-in-url="{{ route('attendance.check-in') }}"
     data-check-out-url="{{ route('attendance.check-out') }}">
    <button type="button" id="attendance-action" class="btn btn-sm btn-outline-primary text-nowrap" disabled>Memuat absensi…</button>
    <button type="button" id="attendance-detail" class="btn btn-sm btn-link p-0 text-nowrap d-none" aria-label="Lihat detail absensi">Detail</button>
    <small id="attendance-hint" class="text-muted d-none d-xl-inline" aria-live="polite"></small>
</div>
<script src="{{ asset('assets/js/attendance.js') }}" defer></script>
