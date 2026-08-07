@extends('layouts.app')

@section('content')
<div class="container py-4">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Detail Penjualan</h4>
        <a href="{{ route('sell.index') }}" class="btn btn-secondary">Kembali</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div><strong>Invoice:</strong> {{ $sell->no_invoice }}</div>
            <div><strong>Toko:</strong> {{ $sell->store?->store_name ?? '-' }}</div>
            <div><strong>Tanggal:</strong> {{ $sell->date }}</div>
            <div><strong>Kasir:</strong> {{ $sell->creator?->name ?? $sell->created_by ?? '-' }}</div>
            <div><strong>Total:</strong> Rp {{ number_format((float) $sell->total_price, 0, ',', '.') }}</div>
            <div><strong>Pembayaran:</strong> Rp {{ number_format((float) $sell->payment_amount, 0, ',', '.') }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Diskon</th></tr>
                </thead>
                <tbody>
                @forelse($outgoingGoods as $item)
                    <tr>
                        <td>{{ $item->product?->product_name ?? 'Produk tidak ditemukan' }}</td>
                        <td class="text-end">{{ number_format((int) $item->quantity_out, 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format((float) ($item->discount ?? 0), 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">Tidak ada barang keluar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
