@extends('layouts.app')

@section('content')
<style>
  #order-stock-card[hidden] {
    display: none;
  }

  .order-stock-table .po-cell {
    min-width: 140px;
  }

  .order-stock-table .po-input {
    min-width: 120px;
    text-align: right;
  }
</style>
<div class="container">
  <div class="row justify-content-center">
    <div class="col-lg-10">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Order Stock</h4>
        <div class="d-flex gap-2">
          @if(session('success')) <span class="text-success fw-bold">{{ session('success') }}</span> @endif
          @if(session('warning')) <span class="text-warning fw-bold">{{ session('warning') }}</span> @endif
          @if(session('error'))   <span class="text-danger fw-bold">{{ session('error') }}</span> @endif
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <form method="GET" action="{{ route('order-stock.index') }}" class="row g-2 align-items-end">
            @if($isSuperadmin)
              <div class="col-md-6">
                <label class="form-label">Pilih Toko</label>
                <select name="store" class="form-select" onchange="this.form.submit()">
                  <option value="">-- pilih toko --</option>
                  @foreach($stores as $store)
                    <option value="{{ $store->id }}" @selected($selected == $store->id)>{{ $store->store_name }}</option>
                  @endforeach
                </select>
              </div>
            @else
              <div class="col-md-6">
                <label class="form-label">Toko</label>
                <input type="text" class="form-control" value="{{ $currentStore ?? '-' }}" disabled>
              </div>
            @endif
            <div class="col-md-6">
              <label for="order-stock-search" class="form-label">Cari Produk</label>
              <div class="input-group">
                <input type="search" id="order-stock-search" name="q" class="form-control"
                       value="{{ $search ?? '' }}" placeholder="Nama produk / scan barcode">
                <button type="submit" class="btn btn-primary">Cari</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      @if(!$selected)
        <div class="alert alert-info">Pilih toko untuk melihat produk yang perlu dipesan.</div>
      @else
      <div id="order-stock-status" class="text-muted small mb-2" role="status" aria-live="polite">Stok dan kebutuhan PO diperbarui otomatis setiap 30 detik.</div>
      <div id="order-stock-empty" class="alert alert-info" @if($items->isNotEmpty()) hidden @endif>
        {{ ($search ?? '') !== '' ? 'Tidak ada produk yang perlu dipesan sesuai pencarian.' : 'Semua stok aman.' }}
      </div>
      <form method="POST" action="{{ route('order-stock.restock') }}" id="order-stock-form"
            data-store-id="{{ $selected }}"
            data-stock-url="{{ route('order-stock.index', ['store' => $selected, 'q' => $search ?? '']) }}">
        @csrf
        <input type="hidden" name="idempotency_key" id="order-stock-idempotency-key" value="">
        <input type="hidden" name="store_id" value="{{ $selected }}">
        <div class="card" id="order-stock-card" @if($items->isEmpty()) hidden @endif>
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="fw-bold">Produk di bawah stok minimum</span>
              <a href="{{ route('order-stock.export', ['store' => $selected, 'q' => $search ?? '']) }}" class="btn btn-sm btn-outline-success">Export Excel</a>
            </div>
            <div class="table-responsive">
              <table class="table table-striped order-stock-table">
                <thead>
                  <tr>
                    <th style="width:30px"><input type="checkbox" id="check-all"></th>
                    <th>Kode</th>
                    <th>Produk</th>
                    <th>Stok</th>
                    <th>Min</th>
                    <th>Max</th>
                    <th>Harga Beli</th>
                    <th>PO</th>
                    <th>Total Harga</th>
                  </tr>
                </thead>
                <tbody id="order-stock-body">
                  @foreach($items as $item)
                    @include('pages.admin.order-stock.row', ['item' => $item])
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="d-flex justify-content-end mt-2">
              <div class="fw-bold">Total Harga: <span id="grand-total">0</span></div>
            </div>
            <div class="text-end mt-3">
              <button type="submit" class="btn btn-primary" id="order-stock-submit" @disabled($items->isEmpty())>Checklist &amp; Restock ke Max</button>
            </div>
          </div>
        </div>
      </form>
      <template id="order-stock-row-template">
        @include('pages.admin.order-stock.row', ['item' => null])
      </template>
      @endif
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('order-stock-form');
  const body = document.getElementById('order-stock-body');
  const template = document.getElementById('order-stock-row-template');
  if (!form || !body || !template) return;

  const checkAll = document.getElementById('check-all');
  const status = document.getElementById('order-stock-status');
  const empty = document.getElementById('order-stock-empty');
  const card = document.getElementById('order-stock-card');
  const submit = document.getElementById('order-stock-submit');
  const key = document.getElementById('order-stock-idempotency-key');
  let refreshing = false;
  let submitting = false;
  const rows = () => Array.from(body.querySelectorAll('.order-row'));
  const formatRupiah = value => Number(value || 0).toLocaleString('id-ID', {minimumFractionDigits: 0});

  const recalcTotals = () => {
    let grand = 0;
    const currentRows = rows();
    currentRows.forEach(row => {
      const total = Number(row.dataset.price || 0) * Number(row.querySelector('.po-input').value || 0);
      row.querySelector('.row-total').textContent = formatRupiah(total);
      grand += total;
    });
    document.getElementById('grand-total').textContent = formatRupiah(grand);
    const selected = currentRows.filter(row => row.querySelector('.row-check').checked).length;
    checkAll.checked = currentRows.length > 0 && selected === currentRows.length;
    checkAll.indeterminate = selected > 0 && selected < currentRows.length;
  };

  checkAll.addEventListener('change', event => {
    rows().forEach(row => { row.querySelector('.row-check').checked = event.target.checked; });
    recalcTotals();
  });
  body.addEventListener('input', recalcTotals);
  body.addEventListener('change', recalcTotals);

  const renderItems = items => {
    const existing = new Map(rows().map(row => [row.dataset.id, row]));
    items.forEach(item => {
      const id = String(item.id);
      let row = existing.get(id);
      const isNew = !row;
      if (isNew) row = template.content.firstElementChild.cloneNode(true);
      const poInput = row.querySelector('.po-input');
      // Only automatic quantities follow the new suggestion; keep manual edits.
      const manuallyEdited = !isNew && (row.dataset.manualPo === '1' || poInput.value !== row.dataset.suggestedPo);
      row.dataset.manualPo = manuallyEdited ? '1' : '0';
      row.dataset.id = id;
      row.dataset.price = String(item.purchase_price ?? 0);
      row.dataset.suggestedPo = String(item.po_qty ?? 0);
      row.querySelector('.row-check').value = id;
      poInput.name = `po_qty[${id}]`;
      if (!manuallyEdited) poInput.value = row.dataset.suggestedPo;
      const fields = {
        code: item.product_code, name: item.product_name, stock: item.stock_system,
        min: item.min_stock ?? '-', max: item.max_stock ?? '-', price: formatRupiah(item.purchase_price),
      };
      Object.entries(fields).forEach(([field, value]) => {
        row.querySelector(`[data-field="${field}"]`).textContent = String(value ?? '');
      });
      if (isNew) body.appendChild(row);
      existing.delete(id);
    });
    // Replenished products no longer need an order, even if previously checked.
    existing.forEach(row => row.remove());
    empty.hidden = items.length > 0;
    card.hidden = items.length === 0;
    submit.disabled = submitting || items.length === 0;
    recalcTotals();
  };

  const refreshStock = async () => {
    if (refreshing || submitting || document.hidden) return;
    refreshing = true;
    try {
      const response = await fetch(form.dataset.stockUrl, {
        credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'},
      });
      if (!response.ok || response.redirected) throw new Error('Order stock request failed');
      const payload = await response.json();
      if (String(payload.store_id) !== form.dataset.storeId || !Array.isArray(payload.items)) {
        throw new Error('Invalid order stock response');
      }
      if (submitting) return;
      renderItems(payload.items);
      status.textContent = 'Stok dan kebutuhan PO terbaru sudah dimuat. Diperbarui otomatis setiap 30 detik.';
    } catch (error) {
      status.textContent = 'Pembaruan stok gagal. Data terakhir dan edit PO tetap tersedia; akan dicoba kembali otomatis.';
    } finally {
      refreshing = false;
    }
  };

  key.value = window.crypto?.randomUUID
    ? window.crypto.randomUUID()
    : ('order-stock-' + Date.now() + '-' + Math.random().toString(36).slice(2));
  form.addEventListener('submit', () => {
    submitting = true;
    submit.disabled = true;
    submit.textContent = 'Memproses...';
  });
  recalcTotals();
  refreshStock();
  window.setInterval(refreshStock, 30000);
  window.addEventListener('focus', refreshStock);
  document.addEventListener('visibilitychange', refreshStock);
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    submitting = false;
    submit.disabled = rows().length === 0;
    submit.textContent = 'Checklist & Restock ke Max';
    refreshStock();
  });
});
</script>
@endsection
