@extends('layouts.app')

@section('content')
<h6 class="text-uppercase">Barang Tidak Laku</h6>
<p class="text-muted">Master produk aktif dengan stok tepat 0 di setiap toko. Produk yang belum memiliki mutasi stok juga ditampilkan.</p>
<div class="card"><div class="card-body">
    <form method="GET" class="row g-2 mb-3">
        <div class="col-sm-8"><input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Cari kode / nama produk" aria-label="Cari produk"></div>
        <div class="col-sm-4"><button class="btn btn-primary">Cari</button></div>
    </form>
    <p>Menampilkan {{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }} dari {{ number_format($products->total(), 0, ',', '.') }} produk yang memenuhi filter.</p>
    @if($canDeleteProducts)
        <button type="button" id="delete-selected" class="btn btn-danger mb-2" disabled>Hapus Terpilih (0)</button>
        <p class="text-muted small">Pilihan berlaku untuk halaman ini saja.</p>
    @endif
    <div id="delete-feedback" role="status"></div>
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead><tr>@if($canDeleteProducts)<th><input type="checkbox" id="select-all" aria-label="Pilih semua produk di halaman ini"></th>@endif<th>Kode</th><th>Produk</th><th>Stok di Semua Toko</th>@if($canDeleteProducts)<th>Aksi</th>@endif</tr></thead>
            <tbody>
            @forelse($products as $product)
                <tr>
                    @if($canDeleteProducts)<td><input type="checkbox" class="product-checkbox" value="{{ $product->id }}" aria-label="Pilih {{ $product->product_name }}"></td>@endif
                    <td>{{ $product->product_code }}</td><td>{{ $product->product_name }}</td><td>0</td>
                    @if($canDeleteProducts)
                    <td><button type="button" class="btn btn-sm btn-danger delete-product" data-url="{{ route('unsold-products.destroy', $product->id) }}" data-name="{{ $product->product_name }}">Hapus</button></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canDeleteProducts ? 5 : 3 }}" class="text-center">Tidak ada produk dengan stok 0 di semua toko.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links('pagination::bootstrap-5') }}
</div></div>
@endsection

@section('scripts')
<script>
    let deleting = false;
    const boxes = [...document.querySelectorAll('.product-checkbox')];
    const selectAll = document.getElementById('select-all');
    const deleteSelected = document.getElementById('delete-selected');
    const selectedIds = () => boxes.filter(box => box.checked).map(box => box.value);
    function updateSelection() {
        if (!deleteSelected || !selectAll) return;
        const count = selectedIds().length;
        deleteSelected.textContent = `Hapus Terpilih (${count})`;
        deleteSelected.disabled = deleting || count === 0;
        selectAll.checked = boxes.length > 0 && count === boxes.length;
        selectAll.indeterminate = count > 0 && count < boxes.length;
    }
    boxes.forEach(box => box.addEventListener('change', updateSelection));
    if (selectAll) selectAll.addEventListener('change', () => {
        boxes.forEach(box => box.checked = selectAll.checked);
        updateSelection();
    });
    async function deleteProducts(url, message, ids = null) {
        if (deleting || !confirm(message)) return;
        deleting = true;
        const controls = document.querySelectorAll('.delete-product, .product-checkbox, #select-all, #delete-selected');
        controls.forEach(control => control.disabled = true);
        const feedback = document.getElementById('delete-feedback');
        feedback.textContent = 'Menghapus...';
        try {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                ...(ids ? {body: JSON.stringify({product_ids: ids})} : {})
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(response.status === 419 ? 'Sesi kedaluwarsa. Muat ulang halaman dan coba lagi.' : (result.message || 'Produk gagal dihapus.'));
            window.location.reload();
        } catch (error) {
            feedback.textContent = error.message;
            deleting = false;
            controls.forEach(control => control.disabled = false);
            updateSelection();
        }
    }
    if (deleteSelected) deleteSelected.addEventListener('click', () => {
        const ids = selectedIds();
        if (!ids.length) return;
        deleteProducts(@json(route('unsold-products.destroy-selected')), `Hapus ${ids.length} master produk terpilih dari seluruh toko? Tindakan ini tidak dapat dibatalkan.`, ids);
    });
    document.querySelectorAll('.delete-product').forEach(button => button.addEventListener('click', () => {
        deleteProducts(button.dataset.url, `Hapus master produk "${button.dataset.name}" dari seluruh toko? Tindakan ini tidak dapat dibatalkan.`);
    }));
</script>
@endsection
