@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10">

                @if(isset($lowStockItems) && $lowStockItems->count())
                    <div class="alert alert-warning d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Perhatian!</strong> Ada {{ $lowStockItems->count() }} produk di bawah stok minimum.
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('order-stock.index', ['store' => $selectedStoreId]) }}" class="btn btn-sm btn-outline-primary">Order Stock</a>
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#lowStockModal">Lihat</button>
                        </div>
                    </div>
                @endif

                <!-- Filter Form -->
             <form method="GET" action="{{ route('home') }}" class="d-flex gap-2 mb-4 align-items-center">
    @if(store_access_can_select(Auth::user()))
        <select name="store" class="form-select" onchange="this.form.submit()">
            <option value="">-- Semua Toko --</option>
            @foreach($stores as $store)
                <option value="{{ $store->id }}" {{ $selectedStoreId == $store->id ? 'selected' : '' }}>
                    {{ $store->store_name }}
                </option>
            @endforeach
        </select>
    @endif

  
    {{-- Specific date range --}}
    <input type="date" name="date_from" class="form-control"
           value="{{ request('date_from') }}" onchange="this.form.submit()">
    <span class="mx-1">s/d</span>
    <input type="date" name="date_to" class="form-control"
           value="{{ request('date_to') }}" onchange="this.form.submit()">
</form>

                <!-- Summary Cards -->
                <div class="d-flex justify-content-around mb-4">
                    <div class="card text-center p-3" style="width: 30%;">
                        <h5>Omset</h5>
                        <p class="fs-4">Rp {{ number_format($totalOmset, 0, ',', '.') }}</p>
                    </div>
                    <div class="card text-center p-3" style="width: 30%;">
                        <h5>HPP</h5>
                        <p class="fs-4">Rp {{ number_format($totalHpp, 0, ',', '.') }}</p>
                    </div>
                    <div class="card text-center p-3" style="width: 30%;">
                        <h5>Laba</h5>
                        <p class="fs-4">Rp {{ number_format($totalLaba, 0, ',', '.') }}</p>
                    </div>
                </div>

                <!-- Profit Chart -->
                <div class="card mb-4">
                    <div class="card-header">Grafik Omset, HPP, & Laba</div>
                    <div class="card-body">
                        <canvas id="profitChart" height="100"></canvas>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header">Grafik Penjualan Harian Bulan Ini</div>
                    <div class="card-body">
                        <canvas id="dailySalesChart" height="100"></canvas>
                    </div>
                </div>
                <!-- Top 5 Produk Paling Laku -->
                <div class="card mb-4">
                    <div class="card-header">Top 5 Produk Paling Laku</div>
                    <div class="card-body">
                        <table class="table table-bordered table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Jumlah Terjual</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($topProducts as $product)
                                    <tr>
                                        <td>{{ $product->product_name }}</td>
                                        <td>{{ $product->total_sold }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-center">Tidak ada data penjualan</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('profitChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($labels),
                datasets: [
                    {
                        label: 'Omset',
                        data: @json($omsetData),
                        backgroundColor: 'rgba(54, 162, 235, 0.7)'
                    },
                    {
                        label: 'HPP',
                        data: @json($hppData),
                        backgroundColor: 'rgba(255, 99, 132, 0.7)'
                    },
                    {
                        label: 'Laba',
                        data: @json($labaData),
                        backgroundColor: 'rgba(75, 192, 192, 0.7)'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: val => 'Rp ' + val.toLocaleString('id-ID')
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: Rp ${ctx.parsed.y.toLocaleString('id-ID')}`
                        }
                    }
                }
            }
        });

        const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: @json($dailyMonthLabels),
                datasets: [{
                    label: 'Penjualan Harian',
                    data: @json($dailyMonthSales),
                    borderColor: 'rgba(25, 135, 84, 1)',
                    backgroundColor: 'rgba(25, 135, 84, 0.12)',
                    tension: 0.25,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: val => 'Rp ' + val.toLocaleString('id-ID') }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: ctx => `Penjualan: Rp ${ctx.parsed.y.toLocaleString('id-ID')}`
                        }
                    }
                }
            }
        });
    </script>
    @if(isset($lowStockItems) && $lowStockItems->count())
    <div class="modal fade" id="lowStockModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Produk di bawah stok minimum</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        @php
                          $hasStore = isset($lowStockItems[0]) && isset($lowStockItems[0]->store_name);
                        @endphp
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    @if($hasStore)<th>Toko</th>@endif
                                    <th>Kode</th>
                                    <th>Produk</th>
                                    <th>Stok</th>
                                    <th>Min</th>
                                    <th>Max</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($lowStockItems as $item)
                                    <tr>
                                        @if($hasStore)<td>{{ $item->store_name }}</td>@endif
                                        <td>{{ $item->product_code }}</td>
                                        <td>{{ $item->product_name }}</td>
                                        <td>{{ $item->stock_system }}</td>
                                        <td>{{ $item->min_stock ?? '-' }}</td>
                                        <td>{{ $item->max_stock ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end">
                        <a href="{{ route('order-stock.index', ['store' => $selectedStoreId]) }}" class="btn btn-primary">Buka Order Stock</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('lowStockModal');
            if (el && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(el);
                modal.show();
            }
        });
    </script>
    @endif
@endsection
