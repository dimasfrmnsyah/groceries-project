@extends('layouts.app')

@section('content')
<style>
  /* Buat kolom jumlah fisik cukup lebar untuk layar kecil */
  #stock-table input.physical-qty {
    min-width: 110px;
    width: 100%;
    box-sizing: border-box;
    text-align: right;
  }

  #stock-table .money-col {
    min-width: 140px;
    white-space: nowrap;
  }

  #stock-table td.physical-col {
    min-width: 140px;
  }

  #stock-table td,
  #stock-table th {
    vertical-align: middle;
  }

  .stockopname-table {
    overflow-x: auto;
    overflow-y: visible;
  }

  @media (max-width: 768px) {
    #stock-table td, #stock-table th {
      white-space: nowrap;
    }
  }
</style>
<div class="container">
    <h4 class="mb-4">Kelola Stok Fisik Produk Per Toko</h4>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(store_access_can_select(Auth::user()))
    <form method="GET" action="{{ route('inventory.index') }}" class="mb-3 w-auto">
        <select name="store_id" class="form-select" onchange="this.form.submit()">
            <option value="">-- Pilih Toko --</option>
            @foreach($stores as $store)
                <option value="{{ $store->id }}" {{ (int)($storeId ?? 0) === $store->id ? 'selected' : '' }}>
                    {{ $store->store_name }}
                </option>
            @endforeach
        </select>
    </form>
    @endif

    @if($storeId)
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <form method="POST" action="{{ route('inventory.normalizeNegativeStock') }}"
              data-csrf-refresh="1"
              onsubmit="return confirm('Normalisasi akan menambahkan stok untuk semua produk yang minus. Lanjutkan?');">
            @csrf
            @if(store_access_can_select(Auth::user()))
                <input type="hidden" name="store_id" value="{{ $storeId }}">
            @endif
            <button type="submit" class="btn btn-warning">
                Normalisasi Stok Minus
            </button>
        </form>
        <span class="text-muted small">Gunakan jika stok sistem sudah negatif.</span>
    </div>
    @endif

    @if(!$query->isEmpty())
    <div class="mb-3">
        <input type="text" id="search-product" class="form-control" placeholder="Scan barcode atau ketik nama/kode produk...">
        <small class="text-muted">Arahkan scanner ke input ini untuk langsung memfilter item sesuai barcode/kode.</small>
    </div>
    @endif

    <form id="stock-form" action="{{ route('inventory.adjustStockPreview') }}" method="POST"
          onsubmit="return stockFormReady();">
        @csrf
        @if($storeId)
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
        <input type="hidden" name="total_items" value="{{ $query->count() }}">

        <div class="table-responsive stockopname-table">
            <table class="table table-bordered table-striped" id="stock-table">
                <thead>
                    <tr>
                        <th style="width:40px;">No.</th>
                        <th>Nama Produk</th>
                        <th>Nama Toko</th>
                        <th class="text-end">Harga Beli (toko)</th>
                        <th class="text-end">Harga Jual (toko)</th>
                        <th class="text-end">Jumlah Sistem</th>
                        <th>Jumlah Fisik</th>
                        <th class="text-end">Selisih (+/-)</th>
                        <th class="text-end">Minus Barang (unit)</th>
                        <th class="text-end money-col">Total Minus (Rp)</th>
                        <th class="text-end">Plus Barang (unit)</th>
                        <th class="text-end money-col">Total Plus (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($query as $index => $row)
                    <tr data-price="{{ $row->purchase_price }}"
                        data-code="{{ strtolower($row->product_code ?? '') }}"
                        data-name="{{ strtolower($row->product_name ?? '') }}">
                        <td>{{ $index + 1 }}</td>
                        <td class="product-name">
                            <div class="fw-semibold">{{ $row->product_name }}</div>
                            @if(!empty($row->product_code))
                                <div class="text-muted small">Kode: {{ $row->product_code }}</div>
                            @endif
                        </td>
                        <td>{{ $row->store_name }}</td>
                        <td class="text-end">{{ number_format((float)$row->purchase_price, 2, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$row->selling_price, 2, ',', '.') }}</td>

                        {{-- GUNAKAN system_stock_raw untuk tampilan stok sistem --}}
                        <td class="text-end system-stock">{{ number_format((int)$row->system_stock_raw) }}</td>

                        <td class="physical-col">
                            <input type="hidden" name="product_id[]" value="{{ $row->product_id }}">
                            <input type="hidden" name="store_id[]" value="{{ $row->store_id }}">

                            {{-- Prefill fisik = nilai draft jika ada; kalau tidak pakai stok sistem --}}
                            @php
                                $defaultPhysical = (int)$row->system_stock_raw;
                                $physicalValue = array_key_exists($row->product_id, $draftQuantities ?? [])
                                    ? (int)$draftQuantities[$row->product_id]
                                    : $defaultPhysical;
                            @endphp
                            <input type="number" name="physical_quantity[]"
                                   value="{{ $physicalValue }}"
                                   min="0" max="1000000" step="1" inputmode="numeric"
                                   class="form-control physical-qty" required>
                        </td>

                        <td class="text-end diff-qty">0</td>
                        <td class="text-end minus-qty">0</td>
                        <td class="text-end minus-value money-col">Rp 0</td>
                        <td class="text-end plus-qty">0</td>
                        <td class="text-end plus-value money-col">Rp 0</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="7" class="text-end">TOTAL</td>
                        <td class="text-end" id="total-diff-qty">0</td>
                        <td class="text-end" id="total-minus-unit">0</td>
                        <td class="text-end money-col" id="total-minus-value">Rp 0</td>
                        <td class="text-end" id="total-plus-unit">0</td>
                        <td class="text-end money-col" id="total-plus-value">Rp 0</td>
                    </tr>
                    <tr class="fw-bold">
                        <td colspan="11" class="text-end">TOTAL PLUS - MINUS</td>
                        <td class="text-end money-col" id="total-plus-minus">Rp 0</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button type="submit" class="btn btn-primary mt-3" id="adjustAllBtn">
            <span class="btn-label">Simpan</span>
            <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
        </button>
    </form>
</div>

@endsection

@section('scripts')
<script>
// Guard: cegah form ter-submit secara NATIVE (form-encoded) sebelum JavaScript siap.
// Submit native mengirim 3xN field (product_id[]/store_id[]/physical_quantity[]) sehingga
// menembus batas max_input_vars PHP -> array terpotong -> "Payload tidak valid".
// Selama belum siap, submit (klik tombol maupun tekan Enter) diblokir. Setelah siap,
// handler fetch di bawah yang mengirim JSON (kebal max_input_vars).
window.__stockFormReady = false;
function stockFormReady() {
    if (window.__stockFormReady) return true;
    alert('Halaman masih menyiapkan data. Mohon tunggu beberapa detik, lalu tekan Simpan lagi.');
    return false;
}
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('stock-table');
    const form = document.getElementById('stock-form');
    const adjustAllBtn = document.getElementById('adjustAllBtn');
    let isSubmitting = false;
    const csrfRefreshUrl = "{{ route('inventory.refreshCsrf') }}";
    let csrfRefreshPromise = null;

    const setCsrfToken = (token) => {
        if (!token) return;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
        document.querySelectorAll('input[name="_token"]').forEach(input => {
            input.value = token;
        });
    };

    const refreshCsrfToken = () => {
        if (csrfRefreshPromise) return csrfRefreshPromise;
        csrfRefreshPromise = fetch(csrfRefreshUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(async res => {
            if (!res.ok) {
                throw new Error('Gagal memperbarui CSRF token.');
            }
            const data = await res.json().catch(() => ({}));
            if (!data || !data.token) {
                throw new Error('Response token tidak valid.');
            }
            setCsrfToken(data.token);
            return data.token;
        }).finally(() => {
            csrfRefreshPromise = null;
        });
        return csrfRefreshPromise;
    };

    const keepAliveIntervalMs = 5 * 60 * 1000;
    setInterval(() => {
        refreshCsrfToken().catch(() => {});
    }, keepAliveIntervalMs);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refreshCsrfToken().catch(() => {});
        }
    });
    document.querySelectorAll('form[data-csrf-refresh="1"]').forEach(formEl => {
        formEl.addEventListener('submit', (e) => {
            if (e.defaultPrevented || formEl.dataset.csrfRefreshing === '1') return;
            e.preventDefault();
            formEl.dataset.csrfRefreshing = '1';
            refreshCsrfToken().catch(() => {}).finally(() => {
                formEl.submit();
            });
        });
    });

    const totalDiffQtyEl = document.getElementById('total-diff-qty');
    const totalMinusUnitEl = document.getElementById('total-minus-unit');
    const totalMinusValueEl = document.getElementById('total-minus-value');
    const totalPlusUnitEl = document.getElementById('total-plus-unit');
    const totalPlusValueEl = document.getElementById('total-plus-value');
    const totalPlusMinusEl = document.getElementById('total-plus-minus');
    let highlightTimeout = null;

    const parseSignedInt = (text) => {
        const cleaned = (text || '')
            .replace(/\./g, '')
            .replace(/[^0-9+-]/g, '');
        const parsed = parseInt(cleaned, 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    };

    const formatSignedNumber = (value) => {
        if (!Number.isFinite(value)) return '0';
        if (value === 0) return '0';
        const absText = Math.abs(value).toLocaleString('id-ID');
        return (value > 0 ? '+' : '-') + absText;
    };

    function updateTotals() {
        let totalDiffQty = 0, totalMinusUnit = 0, totalMinusValue = 0, totalPlusUnit = 0, totalPlusValue = 0;
        table.querySelectorAll('tbody tr').forEach(tr => {
            const diffQty = parseSignedInt(tr.querySelector('.diff-qty')?.textContent);
            const minusUnit = parseSignedInt(tr.querySelector('.minus-qty')?.textContent);
            const minusValue = parseInt(tr.querySelector('.minus-value').textContent.replace(/[^\d]/g, '')) || 0;
            const plusQty = parseSignedInt(tr.querySelector('.plus-qty')?.textContent);
            const plusValue = parseInt(tr.querySelector('.plus-value').textContent.replace(/[^\d]/g, '')) || 0;
            totalDiffQty += diffQty; totalMinusUnit += minusUnit; totalMinusValue += minusValue;
            totalPlusUnit += plusQty; totalPlusValue += plusValue;
        });
        totalDiffQtyEl.textContent = formatSignedNumber(totalDiffQty);
        totalMinusUnitEl.textContent = totalMinusUnit.toLocaleString('id-ID');
        totalMinusValueEl.textContent = 'Rp ' + totalMinusValue.toLocaleString('id-ID');
        totalPlusUnitEl.textContent = totalPlusUnit.toLocaleString('id-ID');
        totalPlusValueEl.textContent = 'Rp ' + totalPlusValue.toLocaleString('id-ID');
        totalPlusMinusEl.textContent = 'Rp ' + (totalPlusValue - totalMinusValue).toLocaleString('id-ID');
    }

    table.querySelectorAll('.physical-qty').forEach(input => {
        input.addEventListener('input', (e) => {
            const tr = e.target.closest('tr');
            const systemStock = parseInt(tr.querySelector('.system-stock').textContent.replace(/[^\d]/g, '')) || 0;
            const physicalQty = parseInt(e.target.value) || 0;
            const purchasePrice = parseInt(tr.getAttribute('data-price')) || 0;

            const minusQty = Math.max(0, systemStock - physicalQty);
            const plusQty  = Math.max(0, physicalQty - systemStock);
            const diffQty = physicalQty - systemStock;

            const diffEl = tr.querySelector('.diff-qty');
            if (diffEl) diffEl.textContent = formatSignedNumber(diffQty);
            const minusEl = tr.querySelector('.minus-qty');
            if (minusEl) minusEl.textContent = minusQty.toLocaleString('id-ID');
            tr.querySelector('.minus-value').textContent = 'Rp ' + (minusQty * purchasePrice).toLocaleString('id-ID');
            tr.querySelector('.plus-qty').textContent = plusQty.toLocaleString('id-ID');
            tr.querySelector('.plus-value').textContent = 'Rp ' + (plusQty * purchasePrice).toLocaleString('id-ID');

            updateTotals();
        });
    });

    updateTotals();

    const setButtonBusy = (button, busy, busyLabel = 'Memproses...') => {
        if (!button) return;
        button.disabled = busy;
        const label = button.querySelector('.btn-label');
        if (label) {
            if (!label.dataset.original) label.dataset.original = label.textContent;
            label.textContent = busy ? busyLabel : label.dataset.original;
        }
        const spinner = button.querySelector('.spinner-border');
        if (spinner) spinner.classList.toggle('d-none', !busy);
    };

    const setSubmitting = (busy) => {
        isSubmitting = busy;
        setButtonBusy(adjustAllBtn, busy);
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (isSubmitting) return;
        submitForm();
    });

    function readCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        const hidden = document.querySelector('#stock-form input[name="_token"]');
        return hidden ? hidden.value : '';
    }

    async function submitForm(retry = false) {
        if (isSubmitting && !retry) return;
        if (!retry) {
            setSubmitting(true);
            try {
                await refreshCsrfToken();
            } catch (_) {}
        }

        // KIRIM JSON ke endpoint preview (hanya item berubah)
        const MAX_QTY = 1000000;
        const items = [];
        let invalidRow = null;
        table.querySelectorAll('tbody tr').forEach(tr => {
            const productId = parseInt(tr.querySelector('input[name="product_id[]"]').value);
            const storeId   = parseInt(tr.querySelector('input[name="store_id[]"]').value);
            const systemStock = parseInt(tr.querySelector('.system-stock').textContent.replace(/[^\d-]/g, '')) || 0;
            const physical  = parseInt(tr.querySelector('.physical-qty').value) || 0;
            if (!invalidRow && (physical < 0 || physical > MAX_QTY)) {
                invalidRow = tr;
            }
            if (physical !== systemStock) {
                items.push({ product_id: productId, store_id: storeId, physical_quantity: physical });
            }
        });

        if (invalidRow) {
            setSubmitting(false);
            const name = (invalidRow.querySelector('.product-name')?.textContent || '').trim();
            invalidRow.classList.add('table-danger');
            invalidRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Jumlah fisik tidak valid (harus 0 sampai ' + MAX_QTY.toLocaleString('id-ID') + ').\nPeriksa produk: ' + name);
            return;
        }

        const storeIdField = form.querySelector('input[name="store_id"]');
        const totalItemsField = form.querySelector('input[name="total_items"]');
        const storeIdValue = storeIdField ? parseInt(storeIdField.value) || 0 : 0;
        const totalItemsValue = totalItemsField ? parseInt(totalItemsField.value) || 0 : 0;

        const csrfToken = readCsrfToken();
        const xsrfCookie = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie[1]) : csrfToken;

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-XSRF-TOKEN': xsrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    items,
                    store_id: storeIdValue,
                    total_items: totalItemsValue,
                    _token: csrfToken
                })
            });

            const ct = res.headers.get('content-type') || '';
            const payload = ct.includes('application/json') ? await res.json().catch(() => ({})) : await res.text();

            if (!res.ok) {
                if (res.status === 419 && !retry) {
                    try {
                        await refreshCsrfToken();
                    } catch (_) {}
                    return submitForm(true);
                }
                const msg = typeof payload === 'string' ? payload : (payload.message || JSON.stringify(payload));
                const statusMessage = res.status === 419
                    ? 'Sesi berakhir/CSRF token kedaluwarsa. Mohon refresh halaman lalu kirim lagi.'
                    : (msg || `HTTP ${res.status}`);
                throw new Error(statusMessage);
            }

            if (payload && payload.redirect_url) {
                window.location.assign(payload.redirect_url);
                return;
            }
            throw new Error('Redirect ringkasan tidak tersedia.');
        } catch (err) {
            setSubmitting(false);
            alert('Gagal menyiapkan ringkasan.\n' + (err && err.message ? err.message : err));
        }
    }

    // SEARCH
    const searchInput = document.getElementById('search-product');
    if (searchInput) {
        const rows = table.querySelectorAll('tbody tr');

        const filterRows = (term) => {
            const keyword = (term || '').trim().toLowerCase();
            let firstMatch = null;

            rows.forEach(tr => {
                const name = (tr.dataset.name || tr.querySelector('.product-name').textContent || '').toLowerCase();
                const code = (tr.dataset.code || '').toLowerCase();
                const matches = !keyword || name.includes(keyword) || code.includes(keyword);
                tr.style.display = matches ? '' : 'none';
                if (matches && !firstMatch) {
                    firstMatch = tr;
                }
            });

            // Highlight dan scroll ke hasil pertama untuk scanner
            table.querySelectorAll('tbody tr.table-warning').forEach(row => row.classList.remove('table-warning'));
            if (keyword && firstMatch) {
                firstMatch.classList.add('table-warning');
                firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
                clearTimeout(highlightTimeout);
                highlightTimeout = setTimeout(() => {
                    firstMatch.classList.remove('table-warning');
                }, 1200);
            }
        };

        searchInput.addEventListener('input', () => filterRows(searchInput.value));
        filterRows(searchInput.value);
        searchInput.focus();
    }

    // Semua handler sudah ter-bind: mulai sekarang submit lewat fetch/JSON (kebal max_input_vars).
    window.__stockFormReady = true;
});
</script>
@endsection
