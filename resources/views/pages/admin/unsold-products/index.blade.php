@extends('layouts.app')

@section('content')
<h6 class="text-uppercase">Barang Tidak Laku</h6>
<p class="text-muted">Master produk aktif dengan stok tepat 0 di setiap toko. Produk yang belum memiliki mutasi stok juga ditampilkan.</p>
<div class="card"><div class="card-body">
    <form method="GET" class="row g-2 mb-3">
        <div class="col-sm-8"><input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Cari kode / nama produk" aria-label="Cari produk"></div>
        <div class="col-sm-4"><button class="btn btn-primary">Cari</button></div>
    </form>
    <p>{{ number_format($products->total(), 0, ',', '.') }} produk</p>
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead><tr><th>Kode</th><th>Produk</th><th>Stok di Semua Toko</th></tr></thead>
            <tbody>
            @forelse($products as $product)
                <tr><td>{{ $product->product_code }}</td><td>{{ $product->product_name }}</td><td>0</td></tr>
            @empty
                <tr><td colspan="3" class="text-center">Tidak ada produk dengan stok 0 di semua toko.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links('pagination::bootstrap-5') }}
</div></div>
@endsection
