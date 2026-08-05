@php
    $bulkEntries = old('entries', [collect($bulkFields)->mapWithKeys(fn ($field) => [
        $field['name'] => $field['default'] ?? '',
    ])->all()]);
@endphp

@if(!empty($bulkUsesStore))
    <div class="rounded border bg-light p-3 mb-4">
        <div class="row align-items-end">
            <div class="col-lg-5 col-md-7">
                <label for="{{ $bulkKey }}-store" class="form-label fw-semibold">Toko untuk seluruh transaksi</label>
                <select id="{{ $bulkKey }}-store" name="store_id" class="form-select @error('store_id') is-invalid @enderror" required>
                    <option value="">Pilih Toko</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)old('store_id') === (int)$store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
                @error('store_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-lg-7 col-md-5 mt-2 mt-md-0">
                <div class="text-muted"><i class="bx bx-info-circle me-1"></i>Toko ini otomatis digunakan untuk semua baris di bawah.</div>
            </div>
        </div>
    </div>
@endif

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-1">{{ $bulkTitle }}</h5>
        <p class="text-muted mb-0">Tambahkan hingga 100 baris dalam sekali simpan.</p>
    </div>
    <button type="button" class="btn btn-outline-primary" id="{{ $bulkKey }}-add">
        <i class="bx bx-plus me-1"></i>Tambah Baris
    </button>
</div>

<div class="table-responsive">
    <table class="table table-bordered align-middle mb-0">
        <thead class="table-light">
            <tr>
                @foreach($bulkFields as $field)
                    <th style="min-width: {{ $field['width'] ?? '170px' }};" @class(['text-end' => ($field['align'] ?? null) === 'end'])>{{ $field['label'] }}</th>
                @endforeach
                <th style="width: 54px;"><span class="visually-hidden">Aksi</span></th>
            </tr>
        </thead>
        <tbody id="{{ $bulkKey }}-rows">
            @foreach($bulkEntries as $index => $entry)
                <tr class="bulk-entry-row">
                    @foreach($bulkFields as $field)
                        @php
                            $name = $field['name'];
                            $value = $entry[$name] ?? ($field['default'] ?? '');
                            $errorKey = "entries.$index.$name";
                        @endphp
                        <td>
                            @if(($field['type'] ?? 'text') === 'select')
                                <select name="entries[{{ $index }}][{{ $name }}]"
                                        class="form-select @error($errorKey) is-invalid @enderror"
                                        data-field="{{ $name }}"
                                        data-carry="{{ !empty($field['carry']) ? '1' : '0' }}"
                                        @if(!empty($field['required'])) required @endif>
                                    <option value="">{{ $field['placeholder'] ?? 'Pilih' }}</option>
                                    @foreach($field['options'] ?? [] as $option)
                                        <option value="{{ $option['value'] }}" @selected((string)$value === (string)$option['value'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $field['type'] ?? 'text' }}"
                                       name="entries[{{ $index }}][{{ $name }}]"
                                       value="{{ $value }}"
                                       class="form-control @if(($field['align'] ?? null) === 'end') text-end @endif @error($errorKey) is-invalid @enderror"
                                       data-field="{{ $name }}"
                                       data-default="{{ $field['default'] ?? '' }}"
                                       data-carry="{{ !empty($field['carry']) ? '1' : '0' }}"
                                       @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                                       @if(isset($field['step'])) step="{{ $field['step'] }}" @endif
                                       @if(isset($field['maxLength'])) maxlength="{{ $field['maxLength'] }}" @endif
                                       placeholder="{{ $field['placeholder'] ?? '' }}"
                                       @if(!empty($field['required'])) required @endif>
                            @endif
                            @error($errorKey) <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </td>
                    @endforeach
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger bulk-entry-remove" title="Hapus baris">
                            <i class="bx bx-trash me-0"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@error('entries') <div class="text-danger mt-2">{{ $message }}</div> @enderror

<div class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-muted"><strong id="{{ $bulkKey }}-count">{{ count($bulkEntries) }}</strong> baris akan disimpan</span>
    <button class="btn btn-primary px-4" type="submit"><i class="bx bx-save me-1"></i>Simpan Semua</button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.getElementById(@json($bulkKey.'-rows'));
    const addButton = document.getElementById(@json($bulkKey.'-add'));
    const count = document.getElementById(@json($bulkKey.'-count'));
    if (!body || !addButton || !count) return;

    function updateRows() {
        const rows = body.querySelectorAll('.bulk-entry-row');
        rows.forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/entries\[\d+\]/, `entries[${index}]`);
            });
        });
        count.textContent = rows.length;
        body.querySelectorAll('.bulk-entry-remove').forEach(function (button) {
            button.disabled = rows.length === 1;
        });
        addButton.disabled = rows.length >= 100;
    }

    addButton.addEventListener('click', function () {
        const rows = body.querySelectorAll('.bulk-entry-row');
        if (!rows.length || rows.length >= 100) return;
        const row = rows[rows.length - 1].cloneNode(true);
        row.querySelectorAll('.invalid-feedback').forEach(function (error) { error.remove(); });
        row.querySelectorAll('.is-invalid').forEach(function (field) { field.classList.remove('is-invalid'); });
        row.querySelectorAll('[data-field]').forEach(function (field) {
            if (field.dataset.carry !== '1') field.value = field.dataset.default || '';
        });
        body.appendChild(row);
        updateRows();
        row.querySelector('[data-carry="0"]')?.focus();
    });

    body.addEventListener('click', function (event) {
        const button = event.target.closest('.bulk-entry-remove');
        if (!button || body.querySelectorAll('.bulk-entry-row').length === 1) return;
        button.closest('.bulk-entry-row').remove();
        updateRows();
    });

    updateRows();
});
</script>
