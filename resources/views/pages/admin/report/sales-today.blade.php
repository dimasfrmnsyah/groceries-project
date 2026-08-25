@extends('layouts.app')

@section('css')
<link href="{{ asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet" />
@endsection

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Penjualan Harian</div>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        @if($isSuperadmin)
            <div class="d-flex align-items-center gap-2">
                <label for="filter_store" class="mb-0">Toko</label>
                <select id="filter_store" class="form-select" style="min-width:220px">
                    <option value="">Semua Toko</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" {{ (string)$selectedStoreId === (string)$store->id ? 'selected' : '' }}>
                            {{ $store->store_name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @else
            <div>
                <span class="badge bg-secondary">
                    Toko: {{ $currentStoreName ?? 'Tidak diketahui' }}
                </span>
            </div>
        @endif

        <div class="vr d-none d-md-block"></div>

        <div class="vr d-none d-md-block"></div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <label for="filter_date_from" class="mb-0">Dari</label>
                <input type="date" id="filter_date_from" class="form-control" style="min-width:170px"
                       value="{{ $defaultDateFrom }}">
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="filter_date_to" class="mb-0">Sampai</label>
                <input type="date" id="filter_date_to" class="form-control" style="min-width:170px"
                       value="{{ $defaultDateTo }}">
            </div>
            <small id="date_hint" class="text-danger"></small>
        </div>

        <div class="d-flex align-items-center gap-2">
            <label for="filter_type" class="mb-0">Tipe</label>
            <select id="filter_type" class="form-select" style="min-width:220px">
                <option value="">Semua Tipe</option>
                @foreach($types as $type)
                    <option value="{{ $type->id }}" @selected((string)($selectedTypeId ?? '') === (string)$type->id)>
                        {{ $type->type_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="ms-auto">
            <a id="export_sales_excel"
               class="btn btn-outline-success btn-sm"
               data-base="{{ route('report.sales.today.export') }}"
               href="{{ route('report.sales.today.export') }}">
                Export Excel
            </a>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div>
            <small class="text-muted d-block">Mode Toko / Potong Stok</small>
            <div id="data_source_buttons" class="btn-group" role="group" aria-label="Sumber data">
                <button type="button" class="btn btn-outline-primary {{ ($initialSourceMode ?? 'all') === 'all' ? 'active' : '' }}" data-source-mode="all">Semua</button>
                <button type="button" class="btn btn-outline-secondary {{ ($initialSourceMode ?? 'all') === 'online' ? 'active' : '' }}" data-source-mode="online">Online</button>
                <button type="button" class="btn btn-outline-secondary {{ ($initialSourceMode ?? 'all') === 'offline' ? 'active' : '' }}" data-source-mode="offline">Offline</button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <strong>Filter Kasir:</strong>
            <div id="cashier_filters" class="btn-group flex-wrap" role="group" aria-label="Filter kasir">
                <button type="button" class="btn btn-outline-secondary btn-sm {{ empty($initialCashier) ? 'active' : '' }}" data-cashier-filter="">Semua Kasir</button>
                @foreach($cashiers as $cashier)
                    <button type="button" class="btn btn-outline-secondary btn-sm {{ ($initialCashier ?? '') === $cashier ? 'active' : '' }}" data-cashier-filter="{{ $cashier }}">
                        {{ $cashier }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="filter_cashier" value="{{ $initialCashier ?? '' }}">
<input type="hidden" id="filter_data_source" value="{{ $initialSourceMode ?? 'all' }}">

<div class="row g-3 mb-3">
    <div class="col-md-3" id="summary_items_card">
        <div class="card shadow-none border h-100">
            <div class="card-body">
                <small class="text-muted text-uppercase">Total Item</small>
                <h4 class="mb-0" id="summary_items">0</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-none border h-100">
            <div class="card-body">
                <small class="text-muted text-uppercase">Total Qty</small>
                <h4 class="mb-0" id="summary_quantity">0</h4>
            </div>
        </div>
    </div>
    @unless($hideSalesTotal ?? false)
    <div class="col-md-3" id="summary_sales_card">
        <div class="card shadow-none border h-100">
            <div class="card-body">
                <small class="text-muted text-uppercase">Total Harga Terjual</small>
                <h4 class="mb-0" id="summary_sales">Rp 0</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3" id="summary_hpp_card">
        <div class="card shadow-none border h-100">
            <div class="card-body">
                <small class="text-muted text-uppercase">Total HPP Terjual</small>
                <h4 class="mb-0" id="summary_hpp">Rp 0</h4>
            </div>
        </div>
    </div>
    @endunless
   
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table id="table_daily_sales" class="table table-striped table-bordered w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>No Invoice</th>
                        <th>Toko</th>
                        <th>Kasir</th>
                        <th>Produk</th>
                        <th>Qty</th>
                        <th>Tanggal</th>
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

<script>
    $(function () {
        $.fn.dataTable.ext.errMode = 'none';

        const $store   = $('#filter_store');
        const $cashier = $('#filter_cashier');
        const $type = $('#filter_type');
        const $dataSource = $('#filter_data_source');
        const $dateFrom = $('#filter_date_from');
        const $dateTo   = $('#filter_date_to');
        const $dateHint = $('#date_hint');
        const $cashierFilters = $('#cashier_filters');
        const $dataSourceButtons = $('#data_source_buttons');
        const $exportBtn = $('#export_sales_excel');
        const $summarySalesCard = $('#summary_sales_card');
        const $summaryHppCard = $('#summary_hpp_card');
        const exportBase = $exportBtn.data('base');

        let totalsFromServer = null;
        let table = null;
        let tableGeneration = 0;
        let typeMode = Boolean($type.val());
        const hideSales = @json($hideSalesTotal ?? false);

        const quantityColumn = {
            data: 'quantity_out',
            name: 'quantity_out',
            className: 'text-end',
            render: (data, type) => type === 'display' || type === 'filter'
                ? Number(data || 0).toLocaleString('id-ID')
                : data
        };

        const defaultColumns = [
            { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'invoices', name: 'invoices', render: d => d || '-' },
            { data: 'store_name', name: 'store_id', defaultContent: '-' },
            { data: 'recorded_by', name: 'recorded_by', defaultContent: '-' },
            {
                data: 'product_name',
                name: 'product_name',
                defaultContent: '-',
                render: (data, type, row) => {
                    const code = row?.product_code ? `<div class="text-muted small">Kode: ${row.product_code}</div>` : '';
                    return `${data || '-'}${code}`;
                }
            },
            quantityColumn,
            {
                data: 'activity_date',
                name: 'activity_date',
                render: (data) => data ? moment(data).format('DD MMM YYYY') : '-'
            },
            { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
        ];

        const typeColumns = [
            { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'product_code', name: 'product_code', defaultContent: '-' },
            { data: 'product_name', name: 'product_name', defaultContent: '-' },
            { data: 'type_name', name: 'type_name', defaultContent: '-' },
            quantityColumn,
            { data: 'unit_hpp', name: 'unit_hpp', className: 'text-end', render: renderCurrency },
            { data: 'total_hpp', name: 'total_hpp', className: 'text-end', render: renderCurrency }
        ];

        function updateTableHeaders() {
            const labels = typeMode
                ? ['No', 'Kode Produk', 'Nama Produk', 'Tipe', 'Qty Terjual', 'HPP Satuan', 'Total HPP']
                : ['No', 'No Invoice', 'Toko', 'Kasir', 'Produk', 'Qty', 'Tanggal', 'Aksi'];
            $('#table_daily_sales thead tr').html(labels.map(label => `<th>${label}</th>`).join(''));
        }

        function updateTypeModeUi() {
            $summarySalesCard.toggleClass('d-none', hideSales);
            $summaryHppCard.toggleClass('d-none', hideSales);
        }

        function buildTable() {
            const generation = ++tableGeneration;
            updateTableHeaders();
            if (table) {
                const settings = table.settings()[0];
                if (settings?.jqXHR) settings.jqXHR.abort();
                table.clear().destroy();
                $('#table_daily_sales tbody').empty();
                table = null;
            }

            const tableUrl = new URL("{{ route('report.sales.today.data') }}", window.location.origin);
            if (typeMode && $type.val()) {
                tableUrl.searchParams.set('type_id', $type.val());
            }

            table = $('#table_daily_sales').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: tableUrl.toString(),
                    data: function (d) {
                        d.store = $store.length ? $store.val() : '';
                        d.cashier = $cashier.val() || '';
                        d.type_id = $type.val() || '';
                        d.date_from = $dateFrom.val() || '';
                        d.date_to = $dateTo.val() || '';
                        d.source_mode = $dataSource.val() || 'all';
                    },
                    dataSrc: function (json) {
                        if (generation !== tableGeneration) return [];
                        const expectedMode = typeMode ? 'type' : 'detail';
                        if (json?.report_mode && json.report_mode !== expectedMode) {
                            console.error('Mode laporan tidak sesuai:', json.report_mode, expectedMode);
                            return [];
                        }
                        try {
                            totalsFromServer = json?.totals || null;
                            updateSummary();
                            renderCashierButtons(json?.cashiers || []);
                            return json?.data || [];
                        } catch (e) {
                            console.error('DataTables dataSrc error:', e);
                            return [];
                        }
                    },
                    error: function (xhr) {
                        console.error('DataTables AJAX error', xhr.responseText);
                        alert('Gagal memuat data. Silakan coba lagi.');
                    }
                },
                columns: typeMode ? typeColumns : defaultColumns,
                order: [],
                pageLength: 25,
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json'
                }
            });
        }

        updateTypeModeUi();
        buildTable();

        $store.on('change', reloadTable);
        $type.on('change', function () {
            const params = new URLSearchParams();
            const storeVal = $store.length ? ($store.val() || '') : '';
            const cashierVal = $cashier.val() || '';
            const typeVal = $(this).val() || '';
            const fromVal = $dateFrom.val() || '';
            const toVal = $dateTo.val() || '';
            const sourceMode = $dataSource.val() || 'all';

            if (storeVal) params.set('store', storeVal);
            if (cashierVal) params.set('cashier', cashierVal);
            if (typeVal) params.set('type_id', typeVal);
            if (fromVal) params.set('date_from', fromVal);
            if (toVal) params.set('date_to', toVal);
            if (sourceMode !== 'all') params.set('source_mode', sourceMode);

            window.location.assign(`${window.location.pathname}?${params.toString()}`);
        });
        $dateFrom.on('change', handleDateChange);
        $dateTo.on('change', handleDateChange);
        $cashierFilters.on('click', 'button[data-cashier-filter]', function () {
            const cashierVal = $(this).data('cashier-filter') ?? '';
            $cashier.val(cashierVal);
            setActiveCashierButton(cashierVal);
            reloadTable();
        });
        $dataSourceButtons.on('click', 'button[data-source-mode]', function () {
            const mode = $(this).data('source-mode') || 'online';
            $dataSource.val(mode);
            setActiveSourceButton(mode);
            reloadTable();
        });

        function reloadTable() {
            if (table) table.ajax.reload(null, false);
            updateExportLink();
        }

        function handleDateChange() {
            const fromVal = $dateFrom.val();
            const toVal   = $dateTo.val();

            if (!fromVal && !toVal) {
                const today = new Date().toISOString().slice(0, 10);
                $dateFrom.val(today);
                $dateTo.val(today);
            } else if (fromVal && !toVal) {
                $dateTo.val(fromVal);
            } else if (!fromVal && toVal) {
                $dateFrom.val(toVal);
            }

            const from = new Date($dateFrom.val());
            const to   = new Date($dateTo.val());
            if (from > to) {
                $dateHint.text('Tanggal "Dari" tidak boleh setelah "Sampai".');
                return;
            }
            $dateHint.text('');
            reloadTable();
        }

        function updateSummary() {
            const totals = totalsFromServer || {};
            $('#summary_items').text(Number(totals.items || 0).toLocaleString('id-ID'));
            $('#summary_quantity').text(Number(totals.quantity || 0).toLocaleString('id-ID'));
            if (!hideSales) {
                $('#summary_sales').text(formatCurrency(totals.sales || 0));
                $('#summary_hpp').text(formatCurrency(totals.hpp || 0));
            }
            if (hideSales) {
                $('#footer_total_sales').text('—');
            }
        }

        function renderCurrency(value, type) {
            if (type === 'display' || type === 'filter') {
                return formatCurrency(value);
            }
            return value;
        }

        function formatCurrency(value) {
            const number = Number(value || 0);
            const sign = number < 0 ? '- ' : '';
            return `${sign}Rp ${Math.abs(number).toLocaleString('id-ID')}`;
        }

        function renderCashierButtons(list) {
            const current = $cashier.val() || '';
            let buttonsHtml = `<button type="button" class="btn btn-outline-secondary btn-sm${current === '' ? ' active' : ''}" data-cashier-filter="">Semua Kasir</button>`;
            list.forEach(name => {
                const safeName = name ?? '-';
                const active = current === safeName ? ' active' : '';
                buttonsHtml += `<button type="button" class="btn btn-outline-secondary btn-sm${active}" data-cashier-filter="${safeName}">${safeName}</button>`;
            });
            $cashierFilters.html(buttonsHtml);
        }

        function setActiveCashierButton(value) {
            $cashierFilters.find('button[data-cashier-filter]').each(function () {
                const btnVal = $(this).data('cashier-filter') ?? '';
                $(this).toggleClass('active', btnVal === value);
            });
        }

        function setActiveSourceButton(mode) {
            $dataSourceButtons.find('button[data-source-mode]').each(function () {
                const btnMode = $(this).data('source-mode') || 'all';
                const isActive = btnMode === mode;
                $(this).toggleClass('active', isActive);
                $(this).toggleClass('btn-primary', isActive);
                $(this).toggleClass('btn-outline-primary', !isActive && btnMode === 'online');
                $(this).toggleClass('btn-outline-secondary', !isActive && btnMode !== 'online');
            });
        }

        function updateExportLink() {
            if (!$exportBtn.length) return;
            const params = new URLSearchParams();
            const storeVal = $store.length ? ($store.val() || '') : '';
            const cashierVal = $cashier.val() || '';
            const typeVal = $type.val() || '';
            const fromVal = $dateFrom.val() || '';
            const toVal = $dateTo.val() || '';
            const sourceMode = $dataSource.val() || '';

            if (storeVal) params.set('store', storeVal);
            if (cashierVal) params.set('cashier', cashierVal);
            if (typeVal) params.set('type_id', typeVal);
            if (fromVal) params.set('date_from', fromVal);
            if (toVal) params.set('date_to', toVal);
            if (sourceMode) params.set('source_mode', sourceMode);

            const qs = params.toString();
            $exportBtn.attr('href', qs ? `${exportBase}?${qs}` : exportBase);
        }

        updateExportLink();
    });
</script>
@endsection
