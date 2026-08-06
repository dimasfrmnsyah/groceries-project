@extends('layouts.app')

<link href="{{ asset('assets/plugins/select2/css/select2.min.css') }}" rel="stylesheet">
<link href="{{ asset('assets/plugins/select2/css/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
<style>
    #audit-cashier + .select2-container { width: 100% !important; }
    #audit-cashier + .select2-container .select2-selection--single { min-height: 38px; }
    #audit-cashier + .select2-container .select2-selection__rendered { line-height: 36px; padding-left: 12px; }
    #audit-cashier + .select2-container .select2-selection__arrow { height: 36px; }
</style>

@section('content')
@php
    $isEdit = $mode === 'edit';
    $savedCounts = json_decode($row->denominations ?? '{}', true) ?: [];
    $auditValue = old('audited_at', $isEdit && $row->audited_at
        ? \Carbon\Carbon::parse($row->audited_at)->format('Y-m-d\TH:i')
        : now('Asia/Jakarta')->format('Y-m-d\TH:i'));
@endphp

<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Cash Opname</div>
    <div class="ps-3 text-muted">Audit kas pada waktu tertentu</div>
    <div class="ms-auto"><a href="{{ route('accounting.cash-opname.index') }}" class="btn btn-secondary">Kembali</a></div>
</div>

@if($errors->any())
    <div class="alert alert-danger">Form belum dapat disimpan. Periksa kembali input yang ditandai.</div>
@endif

<form method="POST" action="{{ $isEdit ? route('accounting.cash-opname.update', $row->id) : route('accounting.cash-opname.store') }}" id="cash-audit-form">
    @csrf
    @if($isEdit) @method('PUT') @endif
    <input type="hidden" name="denominations_payload" id="denominations-payload" value="">

    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="bx bx-time-five fs-4"></i></div>
                <div><h5 class="mb-1">Titik Waktu Audit</h5><div class="text-muted">Omzet dihitung dari awal hari sampai waktu audit ini.</div></div>
            </div>
            <div class="row g-3">
                <div class="col-lg-4">
                    <label class="form-label fw-semibold">Toko</label>
                    <select name="store_id" id="audit-store" class="form-select @error('store_id') is-invalid @enderror" required>
                        <option value="">Pilih Toko</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('store_id', $row->store_id ?? 0) === (int) $store->id)>{{ $store->store_name }}</option>
                        @endforeach
                    </select>
                    @error('store_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-lg-4">
                    <label class="form-label fw-semibold">Kasir yang Diaudit</label>
                    <input type="hidden" name="cashier_name" id="audit-cashier-name" value="{{ old('cashier_name', $row->cashier_name ?? '') }}">
                    <select id="audit-cashier" class="form-select @error('cashier_name') is-invalid @enderror" required>
                        <option value="">Pilih Kasir</option>
                        @foreach($cashiers as $cashier)
                            <option value="{{ $cashier->store_id }}::{{ $cashier->name }}"
                                    data-store-id="{{ $cashier->store_id }}"
                                    data-cashier-name="{{ $cashier->name }}"
                                    @selected(old('cashier_name', $row->cashier_name ?? '') === $cashier->name && (int) old('store_id', $row->store_id ?? 0) === (int) $cashier->store_id)>
                                {{ $cashier->name }} — {{ $cashier->store_name ?? 'Toko tidak diketahui' }}
                            </option>
                        @endforeach
                    </select>
                    @error('cashier_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-lg-4">
                    <label class="form-label fw-semibold">Waktu Audit</label>
                    <input type="datetime-local" name="audited_at" id="audit-time" value="{{ $auditValue }}" max="{{ now('Asia/Jakarta')->format('Y-m-d\TH:i') }}" class="form-control @error('audited_at') is-invalid @enderror" required>
                    @error('audited_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="mb-4"><h5 class="mb-1">Hitung Uang Fisik</h5><div class="text-muted">Masukkan jumlah lembar atau keping. Subtotal dan total fisik dihitung otomatis.</div></div>
                    @foreach(collect($denominationOptions)->groupBy('kind', true) as $kind => $options)
                        <div class="text-uppercase text-muted fw-semibold small mb-2 mt-3">{{ $kind }}</div>
                        <div class="row g-2">
                            @foreach($options as $key => $option)
                                @php $count = old('denominations.'.$key, $savedCounts[$key] ?? 0); @endphp
                                <div class="col-md-6">
                                    <div class="border rounded p-3 denomination-row" data-value="{{ $option['value'] }}">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="flex-grow-1"><div class="fw-semibold">{{ $option['label'] }}</div><small class="text-muted denomination-subtotal">Rp 0</small></div>
                                            <div class="input-group" style="width:145px">
                                                <input type="number" name="denominations[{{ $key }}]" value="{{ $count }}" min="0" max="100000" step="1" class="form-control text-end denomination-count" aria-label="Jumlah {{ $option['label'] }}">
                                                <span class="input-group-text">{{ $kind === 'Uang kertas' ? 'lembar' : 'keping' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    @error('denominations.'.$key) <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm position-sticky" style="top:90px">
                <div class="card-body p-4">
                    <h5 class="mb-3">Hasil Audit</h5>
                    <div class="rounded bg-light p-3 mb-2">
                        <div class="text-muted small">Omzet sampai waktu audit</div>
                        <div class="fs-4 fw-semibold" id="turnover-display">Rp {{ number_format($row->running_turnover ?? 0, 0, ',', '.') }}</div>
                        <small class="text-muted" id="turnover-status">Pilih toko, kasir, dan waktu audit.</small>
                    </div>
                    <div class="rounded bg-light p-3 mb-2">
                        <div class="text-muted small">Total uang fisik</div>
                        <div class="fs-4 fw-semibold" id="physical-display">Rp 0</div>
                    </div>
                    <div class="rounded p-3 mb-3 border" id="difference-box">
                        <div class="text-muted small">Selisih fisik − omzet</div>
                        <div class="fs-3 fw-bold" id="difference-display">Rp 0</div>
                        <div class="small" id="difference-label">Seimbang</div>
                    </div>
                    <label class="form-label fw-semibold">Catatan Audit</label>
                    <textarea name="description" rows="3" maxlength="255" class="form-control mb-3" placeholder="Opsional">{{ old('description', $row->description ?? '') }}</textarea>
                    <button class="btn btn-primary w-100 py-2"><i class="bx bx-save me-1"></i>{{ $isEdit ? 'Perbarui Audit' : 'Simpan Hasil Audit' }}</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="{{ asset('assets/plugins/select2/js/select2.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const store = document.getElementById('audit-store');
    const cashier = document.getElementById('audit-cashier');
    const cashierName = document.getElementById('audit-cashier-name');
    const auditTime = document.getElementById('audit-time');
    const turnoverDisplay = document.getElementById('turnover-display');
    const turnoverStatus = document.getElementById('turnover-status');
    const physicalDisplay = document.getElementById('physical-display');
    const differenceDisplay = document.getElementById('difference-display');
    const differenceLabel = document.getElementById('difference-label');
    const differenceBox = document.getElementById('difference-box');
    const auditForm = document.getElementById('cash-audit-form');
    const denominationsPayload = document.getElementById('denominations-payload');
    const turnoverUrl = @json(route('accounting.cash-opname.turnover'));
    let turnover = Number(@json((float) ($row->running_turnover ?? 0)));
    let physical = 0;

    if (window.jQuery && jQuery.fn.select2) {
        jQuery(cashier).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Cari nama kasir atau toko',
            allowClear: true,
            language: { noResults: () => 'Kasir tidak ditemukan' }
        });
    }

    function filterCashiersByStore() {
        const selectedStore = String(store.value || '');
        Array.from(cashier.options).forEach(function (option) {
            if (!option.value) return;
            option.disabled = selectedStore !== '' && option.dataset.storeId !== selectedStore;
        });
        const selectedOption = cashier.options[cashier.selectedIndex];
        if (selectedOption?.disabled) {
            cashier.value = '';
            cashierName.value = '';
            if (window.jQuery && jQuery.fn.select2) jQuery(cashier).trigger('change.select2');
        }
    }

    const rupiah = value => 'Rp ' + Math.abs(value).toLocaleString('id-ID');

    function calculatePhysical() {
        physical = 0;
        const counts = {};
        document.querySelectorAll('.denomination-row').forEach(function (row) {
            const input = row.querySelector('.denomination-count');
            const count = Math.max(0, Math.trunc(Number(input.value || 0)));
            const subtotal = count * Number(row.dataset.value);
            physical += subtotal;
            counts[input.name.match(/\[([^\]]+)\]/)?.[1]] = count;
            row.querySelector('.denomination-subtotal').textContent = rupiah(subtotal);
        });
        denominationsPayload.value = JSON.stringify(counts);
        physicalDisplay.textContent = rupiah(physical);
        renderDifference();
    }

    function renderDifference() {
        const difference = physical - turnover;
        differenceDisplay.textContent = (difference > 0 ? '+ ' : difference < 0 ? '− ' : '') + rupiah(difference);
        differenceLabel.textContent = difference > 0 ? 'Kas lebih' : difference < 0 ? 'Kas kurang' : 'Seimbang';
        differenceBox.classList.toggle('border-success', difference > 0);
        differenceBox.classList.toggle('text-success', difference > 0);
        differenceBox.classList.toggle('border-danger', difference < 0);
        differenceBox.classList.toggle('text-danger', difference < 0);
    }

    async function loadTurnover() {
        const selectedCashier = cashier.options[cashier.selectedIndex]?.dataset.cashierName || '';
        cashierName.value = selectedCashier;
        if (!store.value || !selectedCashier || !auditTime.value) {
            turnover = 0;
            turnoverDisplay.textContent = rupiah(0);
            turnoverStatus.textContent = 'Pilih toko, kasir, dan waktu audit.';
            renderDifference();
            return;
        }
        turnoverStatus.textContent = 'Mengambil omzet berjalan...';
        const url = new URL(turnoverUrl, window.location.origin);
        url.searchParams.set('store_id', store.value);
        url.searchParams.set('cashier_name', selectedCashier);
        url.searchParams.set('audited_at', auditTime.value);
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) throw new Error();
            const data = await response.json();
            turnover = Number(data.turnover || 0);
            turnoverDisplay.textContent = rupiah(turnover);
            turnoverStatus.textContent = 'Snapshot omzet sesuai waktu audit.';
            renderDifference();
        } catch (error) {
            turnoverStatus.textContent = 'Omzet gagal dimuat. Periksa pilihan lalu coba lagi.';
        }
    }

    document.querySelectorAll('.denomination-count').forEach(input => input.addEventListener('input', calculatePhysical));
    store.addEventListener('change', function () {
        filterCashiersByStore();
        loadTurnover();
    });
    cashier.addEventListener('change', function () {
        cashierName.value = cashier.options[cashier.selectedIndex]?.dataset.cashierName || '';
        loadTurnover();
    });
    auditTime.addEventListener('change', loadTurnover);
    auditForm.addEventListener('submit', calculatePhysical);
    filterCashiersByStore();
    calculatePhysical();
    loadTurnover();
});
</script>
@endsection
