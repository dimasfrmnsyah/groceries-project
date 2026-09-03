@extends('layouts.app')

@section('css')
    <link href="{{ asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet" />

@endsection

@section('content')
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">Penjualan</div>
        <div class="ms-auto">
            {{-- <div class="btn-group">
                <a href="{{ route('purchase.create') }}" class="btn btn-success">+ Tambah</a>
            </div> --}}
        </div>
    </div>

    <h6 class="mb-0 text-uppercase">Data Penjualan</h6>
    <hr />
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-end g-3">
                @if(!empty($canSelectStore))
                    <div class="col-md-4">
                        <label class="form-label">Pilih Toko</label>
                        <select id="store-filter" class="form-select">
                            <option value="">Semua Toko</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" {{ (string)$store->id === (string)$selectedStoreId ? 'selected' : '' }}>
                                    {{ $store->store_name ?? $store->name ?? ('Toko #' . $store->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-4">
                    <label class="form-label">Status Penjualan</label>
                    <select id="status-filter" class="form-select">
                        <option value="online" {{ ($saleStatus ?? 'online') === 'online' ? 'selected' : '' }}>Online</option>
                        <option value="offline" {{ ($saleStatus ?? 'online') === 'offline' ? 'selected' : '' }}>Offline / Pending</option>
                        <option value="all" {{ ($saleStatus ?? 'online') === 'all' ? 'selected' : '' }}>Semua</option>
                    </select>
                    <small class="text-muted">Offline masuk ke Online setelah toko di-online-kan.</small>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="table-sell" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No Invoice</th>
                            <th>Toko</th>
                            <th>Tanggal Transaksi</th>
                            <th>Jam</th>
                            <th>Total Pembelian</th>
                            <th>Uang Dibayarkan</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('assets/plugins/datatable/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatable/js/dataTables.bootstrap5.min.js') }}"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
    <script src="https://cdn.datatables.net/plug-ins/1.11.5/dataRender/datetime.js"></script>

    <script>
        const sellBaseUrl = "{{ route('sell.index') }}";
        let sellTable = null;

        $(document).ready(function () {
            const buildUrl = function () {
                const params = new URLSearchParams();
                const storeId = $('#store-filter').val();
                const status = $('#status-filter').val() || 'online';
                if (storeId) params.set('store_id', storeId);
                params.set('status', status);
                return `${sellBaseUrl}?${params.toString()}`;
            };

            sellTable = $('#table-sell').DataTable({
                processing: true,
                serverSide: true,
                ajax: buildUrl(),
                columns: [
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: function (data, type, row, meta) {
                            return meta.row + meta.settings._iDisplayStart + 1;
                        }
                    },
                    {
                        data: 'no_invoice', name: 'no_invoice'
                    },
                    { data: 'store.store_name', name: 'store.store_name' },
                    {
                        data: 'created_at',
                        name: 'created_at',
                        render: function (data) {
                            return moment(data).format('DD MMM YYYY');
                        }
                    },
                    {
                        data: 'created_at',
                        name: 'created_at',
                        render: function (data) {
                            return moment(data).format('HH:mm');
                        }
                    },
                    {   
                        data: 'total_price', 
                        name: 'total_price',
                        render: function(data) {
                            return formattedPrice(data)
                        }
                     },
                    { 
                        data: 'payment_amount', 
                        name: 'payment_amount',
                        render: function(data) {
                            return formattedPrice(data)
                        }
                    },
                    { data: 'status', name: 'status', orderable: false, searchable: false, className: 'text-center' },
                    { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center align-self-center' }
                ]
            });

            $('#store-filter, #status-filter').on('change', function () {
                if (!sellTable) return;
                sellTable.ajax.url(buildUrl()).load();
            });
        });

        const formattedPrice = (price) => {
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR'
            }).format(price);
        };
    </script>
@endsection

{{-- sesuaikan untuk createnya pula --}}
