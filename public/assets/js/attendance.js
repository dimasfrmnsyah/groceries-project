(function () {
    'use strict';

    function init() {
        const widget = document.getElementById('attendance-widget');
        const modalElement = document.getElementById('attendance-modal');
        if (!widget || !modalElement || !window.bootstrap) return;
        const action = document.getElementById('attendance-action');
        const detail = document.getElementById('attendance-detail');
        const hint = document.getElementById('attendance-hint');
        const title = document.getElementById('attendance-modal-title');
        const intro = document.getElementById('attendance-modal-intro');
        const message = document.getElementById('attendance-message');
        const confirm = document.getElementById('attendance-confirm-out');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        let attendance = null;
        let ready = false;
        let busy = false;
        let requestKey = null;
        let confirmedId = null;

        function active() {
            return attendance && !attendance.checked_out_at;
        }

        function render() {
            action.disabled = busy;
            action.textContent = busy ? 'Memproses…' : (!ready ? 'Muat ulang absensi' : (active() ? 'Absen Keluar' : 'Absen Masuk'));
            action.className = 'btn btn-sm text-nowrap ' + (active() ? 'btn-warning' : 'btn-primary');
            detail.classList.toggle('d-none', !attendance);
            detail.disabled = busy;
            hint.textContent = active() ? 'Masuk: ' + attendance.checked_in_at : '';
            confirm.disabled = busy;
            confirm.textContent = busy ? 'Menyimpan…' : 'Ya, Absen Keluar';
        }

        function showDetails(confirmExit) {
            confirmedId = confirmExit && attendance ? attendance.id : null;
            title.textContent = confirmExit ? 'Akhiri shift sekarang?' : 'Detail absensi';
            const summary = attendance && (attendance.daily || attendance);
            intro.textContent = attendance && attendance.daily
                ? 'Rekap tanggal masuk ' + attendance.daily.date + ' · ' + attendance.daily.session_count + ' sesi. Total hanya menghitung sesi yang sudah selesai.'
                : 'Ringkasan absensi Anda.';
            if (confirmExit) intro.textContent += ' Konfirmasi untuk mengakhiri sesi yang sedang aktif.';
            modalElement.querySelectorAll('[data-attendance-field]').forEach(function (node) {
                node.textContent = summary ? (summary[node.dataset.attendanceField] ?? '—') : '—';
            });
            message.classList.add('d-none');
            confirm.classList.toggle('d-none', !confirmExit);
            modal.show();
        }

        function showError(error) {
            message.textContent = error.message || 'Absensi belum dapat diproses. Silakan coba lagi.';
            message.classList.remove('d-none');
            confirm.classList.add('d-none');
            title.textContent = 'Absensi belum dapat diproses';
            modal.show();
        }

        async function request(url, data) {
            const controller = new AbortController();
            const timeout = setTimeout(function () { controller.abort(); }, 15000);
            try {
                const response = await fetch(url, {
                    method: data ? 'POST' : 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    signal: controller.signal,
                    ...(data ? { body: JSON.stringify(data) } : {}),
                });
                const body = await response.json().catch(function () { return {}; });
                if (!response.ok) {
                    const error = new Error(response.status === 401 || response.status === 419
                        ? 'Sesi telah berakhir. Muat ulang halaman dan login kembali.'
                        : (response.status >= 500 ? 'Absensi belum tersedia. Silakan coba lagi atau hubungi admin.'
                            : body.message || 'Absensi gagal diproses. Silakan coba lagi.'));
                    error.status = response.status;
                    throw error;
                }
                return body.attendance;
            } catch (error) {
                if (error.name === 'AbortError' || error instanceof TypeError) {
                    throw new Error('Koneksi terputus. Muat ulang status absensi sebelum mencoba kembali.');
                }
                throw error;
            } finally {
                clearTimeout(timeout);
            }
        }

        async function refresh(reportError) {
            if (busy) return;
            busy = true;
            render();
            try {
                attendance = await request(widget.dataset.statusUrl);
                ready = true;
            } catch (error) {
                ready = false;
                if (reportError) showError(error);
                hint.textContent = 'Status absensi belum tersedia';
            } finally {
                busy = false;
                render();
            }
        }

        function uuid() {
            // getRandomValues also works on shops serving the app over local HTTP.
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;
            const hex = Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
            return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
        }

        async function save(checkOutId) {
            if (busy) return;
            busy = true;
            render();
            try {
                if (!checkOutId && !requestKey) requestKey = uuid();
                attendance = await request(checkOutId ? widget.dataset.checkOutUrl : widget.dataset.checkInUrl,
                    checkOutId ? { attendance_id: checkOutId } : { request_key: requestKey });
                requestKey = null;
                ready = true;
                showDetails(false);
                if (checkOutId) title.textContent = 'Absen keluar berhasil';
            } catch (error) {
                // Keep the same request key on an uncertain network outcome.
                // A retry can never accidentally create a second shift.
                if (error.status && error.status < 500) requestKey = null;
                ready = false;
                showError(error);
            } finally {
                busy = false;
                render();
            }
        }

        action.addEventListener('click', function () {
            if (!ready) return refresh(true);
            if (active()) return showDetails(true);
            return save(null);
        });
        detail.addEventListener('click', function () { showDetails(false); });
        confirm.addEventListener('click', function () {
            if (confirmedId) save(confirmedId);
        });
        window.addEventListener('focus', function () {
            if (!modalElement.classList.contains('show')) refresh(false);
        });
        refresh(false);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
