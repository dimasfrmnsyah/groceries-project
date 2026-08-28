@extends('layouts.app')

@section('content')
<style>
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
          </form>
        </div>
      </div>

      @if(!$selected)
        <div class="alert alert-info">Pilih toko untuk melihat produk yang perlu dipesan.</div>
      @elseif($items->isEmpty())
        <div class="alert alert-success">Semua stok aman.</div>
      @else
      <form method="POST" action="{{ route('order-stock.restock') }}">
        @csrf
        <input type="hidden" name="idempotency_key" id="order-stock-idempotency-key" value="">
        <input type="hidden" name="store_id" value="{{ $selected }}">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="fw-bold">Produk di bawah stok minimum</span>
              <a href="{{ route('order-stock.export', ['store' => $selected]) }}" class="btn btn-sm btn-outline-success">Export Excel</a>
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
                <tbody>
                  @foreach($items as $item)
                    <tr class="order-row" data-price="{{ $item->purchase_price ?? 0 }}">
                      <td><input type="checkbox" name="items[]" value="{{ $item->id }}" class="row-check"></td>
                      <td>{{ $item->product_code }}</td>
                      <td>{{ $item->product_name }}</td>
                      <td>{{ $item->stock_system }}</td>
                      <td>{{ $item->min_stock ?? '-' }}</td>
                      <td>{{ $item->max_stock ?? '-' }}</td>
                      <td>{{ number_format($item->purchase_price ?? 0, 0, ',', '.') }}</td>
                      <td class="po-cell">
                        <input type="number"
                               name="po_qty[{{ $item->id }}]"
                               class="form-control form-control-sm po-input"
                               min="0"
                               value="{{ $item->po_qty ?? 0 }}">
                      </td>
                      <td class="row-total fw-semibold">0</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="d-flex justify-content-end mt-2">
              <div class="fw-bold">Total Harga: <span id="grand-total">0</span></div>
            </div>
            <div class="text-end mt-3">
              <button type="submit" class="btn btn-primary" id="order-stock-submit">Checklist &amp; Restock ke Max</button>
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
  const checkAll = document.getElementById('check-all');
  if (checkAll) {
    checkAll.addEventListener('change', (e) => {
      document.querySelectorAll('.row-check').forEach(cb => cb.checked = e.target.checked);
    });
  }

  function formatRupiah(num) {
    const n = Number(num || 0);
    return n.toLocaleString('id-ID', { minimumFractionDigits: 0 });
  }

  function recalcTotals() {
    let grand = 0;
    document.querySelectorAll('.order-row').forEach(row => {
      const price = Number(row.getAttribute('data-price') || 0);
      const input = row.querySelector('input[name^="po_qty"]');
      const qty = Number(input?.value || 0);
      const total = price * qty;
      grand += total;
      const cell = row.querySelector('.row-total');
      if (cell) cell.textContent = formatRupiah(total);
    });
    const grandEl = document.getElementById('grand-total');
    if (grandEl) grandEl.textContent = formatRupiah(grand);
  }

  // initial calc
  recalcTotals();
  document.querySelectorAll('input[name^="po_qty"]').forEach(inp => {
    inp.addEventListener('input', recalcTotals);
    inp.addEventListener('change', recalcTotals);
  });

  const orderStockForm = document.querySelector('form[action="{{ route('order-stock.restock') }}"]');
  const orderStockKey = document.getElementById('order-stock-idempotency-key');
  const orderStockSubmit = document.getElementById('order-stock-submit');
  if (orderStockForm && orderStockKey && orderStockSubmit) {
    orderStockKey.value = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : ('order-stock-' + Date.now() + '-' + Math.random().toString(36).slice(2));

    orderStockForm.addEventListener('submit', () => {
      orderStockSubmit.disabled = true;
      orderStockSubmit.textContent = 'Memproses...';
    });
  }
</script>
@endsection
