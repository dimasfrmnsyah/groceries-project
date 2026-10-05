@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Kategori Item Moving</div>
</div>

@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Toko</label>
                <select name="store" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Pilih Toko --</option>
                    <option value="all" @selected($allStores)>Semua Toko</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)$storeId === (int)$store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kategori</label>
                <select name="category" class="form-select" onchange="this.form.submit()">
                    <option value="all" @selected($category === 'all')>Semua</option>
                    <option value="fast" @selected($category === 'fast')>Fast Moving</option>
                    <option value="slow" @selected($category === 'slow')>Slow Moving</option>
                    <option value="dead" @selected($category === 'dead')>Dead Moving</option>
                    <option value="normal" @selected($category === 'normal')>Normal</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Avg</label>
                <select name="basis" class="form-select" onchange="this.form.submit()">
                    <option value="monthly" @selected($basis === 'monthly')>Per Bulan</option>
                    <option value="weekly" @selected($basis === 'weekly')>Per Minggu</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Cari Produk</label>
                <input type="text" name="q" value="{{ $search }}" class="form-control" placeholder="Kode / nama produk">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

@if(!$storeId && !$allStores)
    <div class="alert alert-info">Pilih toko untuk melihat kategori item.</div>
@else
<div class="card">
    <div class="card-body">
        @if($canDeleteProducts)
            <button type="button" id="delete-selected" class="btn btn-danger mb-3" disabled>Hapus Terpilih (0)</button>
            <div id="delete-feedback" role="status" class="mb-2"></div>
        @endif
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead>
                    <tr>
                        @if($canDeleteProducts)<th><input type="checkbox" id="select-all-products" aria-label="Pilih semua produk"></th>@endif
                        <th>Toko</th>
                        <th>Kode</th>
                        <th>Produk</th>
                        <th>Stok</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Avg 6 Bulan</th>
                        <th>Avg 3 Bulan</th>
                        <th>Terakhir Jual</th>
                        <th>Kategori</th>
                        <th>Transfer</th>
                        @if($canDeleteProducts)<th>Hapus</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            @if($canDeleteProducts)
                                <td><input type="checkbox" class="product-checkbox" value="{{ $row->id }}" aria-label="Pilih {{ $row->product_name }}"></td>
                            @endif
                            <td>{{ $row->store_name }}</td>
                            <td>{{ $row->product_code }}</td>
                            <td>{{ $row->product_name }}</td>
                            <td class="text-end">{{ number_format($row->stock_system, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->min_stock, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->max_stock, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->avg_six, 2, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->avg_three, 2, ',', '.') }}</td>
                            <td>{{ $row->last_sale_at ? \Carbon\Carbon::parse($row->last_sale_at)->format('Y-m-d') : '-' }}</td>
                            <td>
                                @php
                                    $badge = ['fast' => 'success', 'slow' => 'warning', 'dead' => 'danger', 'normal' => 'secondary'][$row->moving_category] ?? 'secondary';
                                @endphp
                                <span class="badge bg-{{ $badge }}">{{ strtoupper($row->moving_category) }}</span>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('stock-transfer.store') }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="date" value="{{ now('Asia/Jakarta')->toDateString() }}">
                                    <input type="hidden" name="from_store_id" value="{{ $row->store_id }}">
                                    <input type="hidden" name="product_id" value="{{ $row->id }}">
                                    <input type="number" name="quantity" class="form-control form-control-sm" min="1" max="{{ max(1, (int)$row->stock_system) }}" value="1" style="width:80px">
                                    <select name="to_store_id" class="form-select form-select-sm" style="width:150px" required>
                                        <option value="">Tujuan</option>
                                        @foreach($toStores->where('id', '!=', $row->store_id) as $store)
                                            <option value="{{ $store->id }}">{{ $store->store_name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary">Pindah</button>
                                </form>
                            </td>
                            @if($canDeleteProducts)
                                <td><button type="button" class="btn btn-sm btn-danger delete-product" data-product-id="{{ $row->id }}">Hapus</button></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canDeleteProducts ? 13 : 11 }}" class="text-center">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection

@section('scripts')
@if($canDeleteProducts)
<script>
    const boxes = [...document.querySelectorAll('.product-checkbox')];
    const selectAll = document.getElementById('select-all-products');
    const deleteSelected = document.getElementById('delete-selected');
    const feedback = document.getElementById('delete-feedback');
    let deleting = false;
    const selectedIds = () => [...new Set(boxes.filter(box => box.checked).map(box => box.value))];
    function updateSelection() {
        const count = selectedIds().length;
        deleteSelected.textContent = `Hapus Terpilih (${count})`;
        deleteSelected.disabled = deleting || count === 0;
        selectAll.checked = boxes.length > 0 && boxes.every(box => box.checked);
        selectAll.indeterminate = count > 0 && !selectAll.checked;
    }
    boxes.forEach(box => box.addEventListener('change', () => {
        boxes.filter(other => other.value === box.value).forEach(other => other.checked = box.checked);
        updateSelection();
    }));
    if (selectAll) selectAll.addEventListener('change', () => {
        boxes.forEach(box => box.checked = selectAll.checked);
        updateSelection();
    });
    async function deleteProducts(ids) {
        if (deleting || !ids.length) return;
        if (!confirm(`Hapus ${ids.length} master produk dari seluruh toko? Tindakan ini tidak dapat dibatalkan.`)) return;
        deleting = true;
        document.querySelectorAll('.delete-product, .product-checkbox, #select-all-products, #delete-selected').forEach(el => el.disabled = true);
        feedback.textContent = 'Menghapus...';
        try {
            const response = await fetch(@json(route('item-moving.delete-products')), {
                method: 'DELETE',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                body: JSON.stringify({product_ids: ids})
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(response.status === 419 ? 'Sesi telah kedaluwarsa. Muat ulang halaman sebelum mencoba lagi.' : (result.message || 'Produk gagal dihapus.'));
            window.location.reload();
        } catch (error) {
            feedback.textContent = error.message;
            deleting = false;
            document.querySelectorAll('.delete-product, .product-checkbox, #select-all-products').forEach(el => el.disabled = false);
            updateSelection();
        }
    }
    if (deleteSelected) deleteSelected.addEventListener('click', () => deleteProducts(selectedIds()));
    document.querySelectorAll('.delete-product').forEach(button => button.addEventListener('click', () => deleteProducts([button.dataset.productId])));
</script>
@endif
@endsection
