@extends('layouts.app')

@section('content')
<div class="container">
  <div class="row justify-content-center">
    <div class="col-lg-10">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Atur Stok Min / Max per Toko</h4>
        @if(session('success')) <span class="text-success fw-bold">{{ session('success') }}</span> @endif
        @if(session('warning')) <span class="text-warning fw-bold">{{ session('warning') }}</span> @endif
        @if(session('error')) <span class="text-danger fw-bold">{{ session('error') }}</span> @endif
      </div>

      <div class="card mb-3">
        <div class="card-body">
          @php $canSelectStore = store_access_can_select(Auth::user()); @endphp
          <form method="GET" action="{{ route('stock-threshold.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
              <label class="form-label">Pilih Toko</label>
              @if($canSelectStore)
                <select name="store" class="form-select" onchange="this.form.submit()">
                  <option value="">-- pilih toko --</option>
                  @foreach($stores as $store)
                    <option value="{{ $store->id }}" @selected($storeId == $store->id)>{{ $store->store_name }}</option>
                  @endforeach
                </select>
              @else
                <input type="text" class="form-control" value="{{ optional(Auth::user()->store)->store_name ?? '-' }}" disabled>
              @endif
            </div>
            <div class="col-md-6">
              <label class="form-label">Cari Produk</label>
              <div class="input-group">
                <input type="text" id="threshold-search" class="form-control" name="q" value="{{ $search ?? '' }}" placeholder="Scan barcode / ketik nama atau kode">
                <button class="btn btn-outline-secondary" type="submit">Cari</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      @if(!$storeId)
        <div class="alert alert-info">Pilih toko untuk mengatur batas stok.</div>
      @else
      <form method="POST" action="{{ route('stock-threshold.save') }}" id="threshold-form">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="expected_count" value="0">
        <input type="hidden" name="items_payload" id="threshold-items-payload" value="">
        <div id="threshold-message" class="text-warning mb-2" role="status" aria-live="polite"></div>
        @if($errors->any())
          <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif
        <div class="card">
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-striped">
                <thead>
                  <tr>
                    <th>Kode</th>
                    <th>Produk</th>
                    <th>Stok</th>
                    <th style="width:90px" class="text-end">Avg</th>
                    <th>Stok Min</th>
                    <th>Stok Max</th>
                  </tr>
                </thead>
                <tbody id="threshold-table-body">
                  @foreach($rows as $row)
                    <tr data-id="{{ $row->id }}" data-name="{{ strtolower($row->product_name) }}" data-code="{{ strtolower($row->product_code) }}">
                      <td>{{ $row->product_code }}</td>
                      <td>{{ $row->product_name }}</td>
                      <td>{{ $row->stock_system }}</td>
                      <td class="text-end">{{ number_format((float)($row->avg_daily_sales ?? 0), 0, ',', '.') }}</td>
                      <td style="max-width:150px">
                        <input type="number" min="0" class="form-control" name="items[{{ $row->id }}][min_stock]" value="{{ old("items.$row->id.min_stock", $row->min_stock) }}" data-original="{{ $row->min_stock }}">
                      </td>
                      <td style="max-width:150px">
                        <input type="number" min="0" class="form-control" name="items[{{ $row->id }}][max_stock]" value="{{ old("items.$row->id.max_stock", $row->max_stock) }}" data-original="{{ $row->max_stock }}">
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="text-end mt-3">
              <button type="submit" class="btn btn-primary" id="threshold-submit">
                <span class="btn-label">Simpan</span>
                <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>
      </form>
      @endif
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('threshold-search');
  const body = document.getElementById('threshold-table-body');
  const form = document.getElementById('threshold-form');
  const submitButton = document.getElementById('threshold-submit');
  const expectedCountInput = form ? form.querySelector('input[name="expected_count"]') : null;
  const payloadInput = document.getElementById('threshold-items-payload');
  const message = document.getElementById('threshold-message');
  let isSubmitting = false;
  if (!searchInput || !body) return;

  const setButtonBusy = (button, busy, busyLabel = 'Menyimpan...') => {
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

  const rows = Array.from(body.querySelectorAll('tr'));
  const dirtyIds = new Set();

  const updateRowDirty = (tr) => {
    const inputs = tr.querySelectorAll('input[type="number"]');
    let dirty = false;
    inputs.forEach(input => {
      if (input.dataset.original === undefined) {
        input.dataset.original = input.value ?? '';
      }
      const current = input.value ?? '';
      if (current !== input.dataset.original) dirty = true;
    });
    const rowId = tr.dataset.id;
    if (!rowId) return;
    if (dirty) {
      dirtyIds.add(rowId);
    } else {
      dirtyIds.delete(rowId);
    }
  };

  rows.forEach(tr => {
    tr.querySelectorAll('input[type="number"]').forEach(input => {
      if (input.dataset.original === undefined) {
        input.dataset.original = input.value ?? '';
      }
      input.addEventListener('input', () => updateRowDirty(tr));
    });
  });
  const filter = (term) => {
    const keyword = (term || '').toLowerCase().trim();
    let firstMatch = null;
    rows.forEach(tr => {
      const name = tr.dataset.name || '';
      const code = tr.dataset.code || '';
      const show = !keyword || name.includes(keyword) || code.includes(keyword);
      tr.style.display = show ? '' : 'none';
      if (show && !firstMatch) firstMatch = tr;
    });
    if (keyword && firstMatch) {
      firstMatch.classList.add('table-warning');
      firstMatch.scrollIntoView({behavior:'smooth', block:'center'});
      setTimeout(() => firstMatch.classList.remove('table-warning'), 900);
    }
  };

  searchInput.addEventListener('input', () => filter(searchInput.value));
  filter(searchInput.value);
  searchInput.focus();

  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    isSubmitting = false;
    rows.forEach(tr => {
      tr.querySelectorAll('input[type="number"]').forEach(input => { input.disabled = false; });
    });
    setButtonBusy(submitButton, false);
  });

  if (form && submitButton) {
    form.addEventListener('submit', async (e) => {
      // Keep the page and its edits until the current session token is available.
      e.preventDefault();
      if (isSubmitting) {
        return;
      }
      // Re-read actual values: browser autofill/history may not emit input.
      rows.forEach(updateRowDirty);
      if (dirtyIds.size === 0) {
        e.preventDefault();
        if (message) message.textContent = 'Belum ada perubahan Min/Max untuk disimpan.';
        return;
      }
      if (message) message.textContent = '';
      const items = {};
      rows.forEach(tr => {
        if (!dirtyIds.has(tr.dataset.id)) return;
        const inputs = tr.querySelectorAll('input[type="number"]');
        items[tr.dataset.id] = {min_stock: inputs[0].value, max_stock: inputs[1].value};
      });
      if (expectedCountInput) expectedCountInput.value = String(Object.keys(items).length);
      // One JSON field prevents PHP max_input_vars from truncating large edits.
      payloadInput.value = JSON.stringify(items);
      rows.forEach(tr => {
        tr.querySelectorAll('input[type="number"]').forEach(input => { input.disabled = true; });
      });
      isSubmitting = true;
      setButtonBusy(submitButton, true);
      try {
        const response = await fetch("{{ route('inventory.refreshCsrf') }}", {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {'Accept': 'application/json'},
        });
        if (response.status === 401 || response.status === 419 || response.redirected) {
          throw new Error('Sesi telah berakhir. Silakan login kembali di tab lain, lalu klik Simpan lagi. Nilai Min/Max tetap tersedia di halaman ini.');
        }
        if (!response.ok) throw new Error('Gagal memeriksa sesi. Silakan coba Simpan lagi.');
        const data = await response.json();
        if (typeof data.token !== 'string' || !data.token.trim()) {
          throw new Error('Gagal memperbarui token sesi. Silakan coba Simpan lagi.');
        }
        form.querySelector('input[name="_token"]').value = data.token;
        form.submit();
      } catch (error) {
        isSubmitting = false;
        rows.forEach(tr => {
          tr.querySelectorAll('input[type="number"]').forEach(input => { input.disabled = false; });
        });
        setButtonBusy(submitButton, false);
        if (message) message.textContent = error.message || 'Gagal memeriksa sesi. Silakan coba Simpan lagi.';
      }
    });
  }
});
</script>
@endsection
