<header>
    <div class="topbar d-flex align-items-center">
        <nav class="navbar navbar-expand">
            <div class="mobile-toggle-menu"><i class='bx bx-menu'></i></div>
            <div class="search-bar flex-grow-1"></div>
            <div class="top-menu ms-auto">
                <ul class="navbar-nav align-items-center">
                    @auth
                        @php
                            $roleHeader = strtolower(Auth::user()->roles ?? '');
                            $requiresRevenueLogout = in_array($roleHeader, ['staff', 'kasir', 'cashier'], true);
                            $revenueLockEnabled = $requiresRevenueLogout && (bool) (Auth::user()->is_lock ?? false);
                            $userStoreId = Auth::user()->store_id ?? null;
                            $userStoreName = optional(Auth::user()->store)->store_name ?? '-';
                            $userStoreOnline = optional(Auth::user()->store)->is_online ?? false;
                            $canOpenOrderStock = Route::has('order-stock.index')
                                && ($roleHeader === 'superadmin' || \App\Support\MenuHelper::roleHasRoute('order-stock.index'));
                        @endphp
                        @if($canOpenOrderStock)
                        <li class="nav-item">
                            <button class="btn btn-sm btn-warning d-none"
                                    id="btn-low-stock-warning"
                                    data-bs-toggle="modal"
                                    data-bs-target="#lowStockHeaderModal">
                                Perlu PO
                            </button>
                        </li>
                        @endif
                        @if(in_array($roleHeader, ['superadmin','admin'], true) && $userStoreId)
                        <li class="nav-item d-flex align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold">{{ $userStoreName }}</span>
                                <span class="badge {{ $userStoreOnline ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $userStoreOnline ? 'Online' : 'Offline' }}
                                </span>
                                <button class="btn btn-sm {{ $userStoreOnline ? 'btn-outline-secondary' : 'btn-outline-success' }}" id="btn-toggle-store-self-header">
                                    {{ $userStoreOnline ? 'Offline' : 'Online' }}
                                </button>
                            </div>
                        </li>
                        @endif
                    @endauth
                    <li class="nav-item mobile-search-icon">
                        <a class="nav-link" href="#"><i class='bx bx-search'></i></a>
                    </li>
                </ul>
            </div>
            <div class="user-box dropdown">
                @guest
                    <div class="user-info ps-3">
                        <a class="nav-link" href="{{ route('login') }}">{{ __('Login') }}</a>
                    </div>
                @else
                    <a class="d-flex align-items-center nav-link dropdown-toggle dropdown-toggle-nocaret"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="{{ asset('assets/images/avatars/avatar-2.png') }}" class="user-img" alt="user avatar">
                        <div class="user-info ps-3">
                            <p class="user-name mb-0">{{ Auth::user()->name }}</p>
                            <p class="designattion mb-0">{{ Auth::user()->email }}</p>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="javascript:;"><i class="bx bx-user"></i><span>Profile</span></a>
                        </li>
                        <div class="dropdown-divider mb-0"></div>
                        <li>
                            @if($requiresRevenueLogout)
                                <a class="dropdown-item" href="#" onclick="event.preventDefault(); showRevenueModal();">
                                    <i class='bx bx-log-out-circle'></i><span>{{ __('Logout') }}</span>
                                </a>
                            @else
                                <a class="dropdown-item" href="{{ route('logout') }}"
                                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                    <i class='bx bx-log-out-circle'></i><span>{{ __('Logout') }}</span>
                                </a>
                            @endif
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        </li>
                    </ul>
                @endguest
            </div>
        </nav>
    </div>

    @if(Route::has('order-stock.index'))
    <div class="modal fade" id="lowStockHeaderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header d-flex align-items-center justify-content-between">
                    <div class="d-flex flex-column">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark">Perlu PO</span>
                            <h5 class="modal-title mb-0">Peringatan Stok Minimum</h5>
                        </div>
                        <small class="text-muted">Produk di bawah stok minimum per toko</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="{{ route('order-stock.index') }}" class="btn btn-sm btn-primary">Menu Permintaan Order</a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body" id="lowStockHeaderModalBody">
                    <div class="text-muted">Memuat data stok minimum...</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Modal input pendapatan harian (khusus staff) --}}
    @if(Auth::check() && in_array(strtolower(Auth::user()->roles ?? ''), ['staff', 'kasir', 'cashier'], true))
    @php
        $revenueDenominationOptions = \App\Support\CashDenominations::all();
        $revenueOldCounts = json_decode((string) old('denominations_payload', ''), true)
            ?: (old('denominations', []) ?: []);
    @endphp
    <div class="modal fade" id="revenueModal" tabindex="-1" aria-labelledby="revenueModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form id="revenueForm"
                  method="POST"
                  action="{{ route('staff.submitRevenueAndLogout') }}"
                  data-revenue-locked="{{ $revenueLockEnabled ? '1' : '0' }}">
                @csrf
                <input type="hidden" name="amount" id="amount" value="{{ old('amount', 0) }}">
                <input type="hidden" name="denominations_payload" id="denominations-payload" value="{{ old('denominations_payload', '') }}">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="revenueModalLabel">Pendapatan Hari Ini</h5>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="fw-semibold">Rincian pendapatan kasir</div>
                            <small class="text-muted">Masukkan jumlah lembar atau keping uang yang diterima hari ini.</small>
                        </div>
                        @foreach(collect($revenueDenominationOptions)->groupBy('kind', true) as $kind => $options)
                            <div class="text-uppercase text-muted fw-semibold small mb-2 mt-3">{{ $kind }}</div>
                            <div class="row g-2">
                                @foreach($options as $key => $option)
                                    <div class="col-md-6">
                                        <div class="border rounded p-2 revenue-denomination-row" data-key="{{ $key }}" data-value="{{ $option['value'] }}">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="flex-grow-1">
                                                    <div class="fw-semibold">{{ $option['label'] }}</div>
                                                    <small class="text-muted revenue-denomination-subtotal">Rp 0</small>
                                                </div>
                                                <input type="number"
                                                       name="denominations[{{ $key }}]"
                                                       value="{{ (int) ($revenueOldCounts[$key] ?? 0) }}"
                                                       min="0"
                                                       max="100000"
                                                       step="1"
                                                       inputmode="numeric"
                                                       class="form-control text-end revenue-denomination-count"
                                                       style="max-width:150px"
                                                       aria-label="Jumlah {{ $option['label'] }}">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                        <div class="row g-3 mt-3">
                            <div class="col-md-6">
                                <label for="revenue-qr" class="form-label fw-semibold">QR</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number"
                                           name="qr"
                                           id="revenue-qr"
                                           value="{{ old('qr', 0) }}"
                                           min="0"
                                           max="999999999999.99"
                                           step="1"
                                           inputmode="numeric"
                                           class="form-control text-end"
                                           aria-label="Total pembayaran QR">
                                </div>
                                <small class="text-muted">Total pembayaran melalui QR hari ini.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="revenue-pengeluaran" class="form-label fw-semibold">Pengeluaran</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number"
                                           name="pengeluaran"
                                           id="revenue-pengeluaran"
                                           value="{{ old('pengeluaran', 0) }}"
                                           min="0"
                                           max="999999999999.99"
                                           step="1"
                                           inputmode="numeric"
                                           class="form-control text-end"
                                           aria-label="Total pengeluaran">
                                </div>
                                <small class="text-muted">Pengeluaran kas yang terjadi hari ini.</small>
                            </div>
                        </div>
                        <div class="rounded border bg-light p-3 mt-4">
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Uang fisik</span><span id="revenue-cash-display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>QR</span><span id="revenue-qr-display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Pengeluaran</span><span id="revenue-expense-display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <span class="text-muted small">Total pendapatan yang dihitung</span>
                                <span class="fs-3 fw-bold" id="revenue-total-display">Rp 0</span>
                            </div>
                        </div>
                        @if($revenueLockEnabled)
                            <div class="mt-3 rounded border border-warning-subtle bg-warning-subtle p-3">
                                <div id="revenueValidationMessage" class="small mt-1 text-danger">
                                    Tombol logout aktif setelah rincian sesuai.
                                </div>
                            </div>
                        @endif
                        @if(session('revenue_error'))
                            <div class="alert alert-danger mt-3 mb-0">{{ session('revenue_error') }}</div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="submit"
                                id="revenueSubmitButton"
                                class="btn btn-primary"
                                {{ $revenueLockEnabled ? 'disabled' : '' }}>
                            Submit & Logout
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif
</header>

{{-- Script Modal Logout Staff --}}
@if(Auth::check() && in_array(strtolower(Auth::user()->roles ?? ''), ['staff', 'kasir', 'cashier'], true))
    <script>
        const revenueForm = document.getElementById('revenueForm');
        const revenueAmountInput = document.getElementById('amount');
        const revenueDenominationsPayload = document.getElementById('denominations-payload');
        const revenueQrInput = document.getElementById('revenue-qr');
        const revenuePengeluaranInput = document.getElementById('revenue-pengeluaran');
        const revenueCashDisplay = document.getElementById('revenue-cash-display');
        const revenueQrDisplay = document.getElementById('revenue-qr-display');
        const revenueExpenseDisplay = document.getElementById('revenue-expense-display');
        const revenueTotalDisplay = document.getElementById('revenue-total-display');
        const revenueSubmitButton = document.getElementById('revenueSubmitButton');
        const revenueValidationMessage = document.getElementById('revenueValidationMessage');
        const revenueLocked = revenueForm?.dataset.revenueLocked === '1';
        let revenueValidationTimer = null;

        const revenueRupiah = (value) => 'Rp ' + Number(value || 0).toLocaleString('id-ID');

        const calculateRevenueAmount = () => {
            let cashTotal = 0;
            const counts = {};
            document.querySelectorAll('.revenue-denomination-row').forEach((row) => {
                const input = row.querySelector('.revenue-denomination-count');
                const count = Math.max(0, Math.trunc(Number(input?.value || 0)));
                const subtotal = count * Number(row.dataset.value || 0);
                counts[row.dataset.key] = count;
                cashTotal += subtotal;
                const subtotalNode = row.querySelector('.revenue-denomination-subtotal');
                if (subtotalNode) subtotalNode.textContent = revenueRupiah(subtotal);
            });
            const qr = Math.max(0, Number(revenueQrInput?.value || 0));
            const pengeluaran = Math.max(0, Number(revenuePengeluaranInput?.value || 0));
            const total = cashTotal + qr + pengeluaran;
            if (revenueAmountInput) revenueAmountInput.value = String(total);
            if (revenueDenominationsPayload) revenueDenominationsPayload.value = JSON.stringify(counts);
            if (revenueCashDisplay) revenueCashDisplay.textContent = revenueRupiah(cashTotal);
            if (revenueQrDisplay) revenueQrDisplay.textContent = revenueRupiah(qr);
            if (revenueExpenseDisplay) revenueExpenseDisplay.textContent = revenueRupiah(pengeluaran);
            if (revenueTotalDisplay) revenueTotalDisplay.textContent = revenueRupiah(total);
            return total;
        };

        const validateRevenueAmount = () => {
            if (!revenueLocked || !revenueAmountInput || !revenueSubmitButton) return;

            calculateRevenueAmount();

            revenueSubmitButton.disabled = true;
            if (revenueValidationMessage) {
                revenueValidationMessage.textContent = 'Memeriksa nominal...';
                revenueValidationMessage.className = 'small mt-1 text-muted';
            }

            const url = new URL("{{ route('staff.checkDailyRevenue') }}", window.location.origin);
            if (revenueAmountInput.value !== '') {
                url.searchParams.set('amount', revenueAmountInput.value);
            }

            fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            })
                .then(async (response) => {
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(payload.message || 'Validasi pendapatan gagal.');

                    const matches = payload.matches === true;
                    revenueSubmitButton.disabled = !matches;
                    if (revenueValidationMessage) {
                        revenueValidationMessage.textContent = matches
                            ? 'Nominal sudah sesuai.'
                            : 'Nominal belum sesuai. Submit & Logout masih dikunci.';
                        revenueValidationMessage.className = matches
                            ? 'small mt-1 text-success'
                            : 'small mt-1 text-danger';
                    }
                })
                .catch((error) => {
                    revenueSubmitButton.disabled = true;
                    if (revenueValidationMessage) {
                        revenueValidationMessage.textContent = error.message;
                        revenueValidationMessage.className = 'small mt-1 text-danger';
                    }
                });
        };

        document.querySelectorAll('.revenue-denomination-count').forEach((input) => input.addEventListener('input', () => {
            calculateRevenueAmount();
            if (!revenueLocked) return;
            window.clearTimeout(revenueValidationTimer);
            revenueValidationTimer = window.setTimeout(validateRevenueAmount, 250);
        }));
        [revenueQrInput, revenuePengeluaranInput].forEach((input) => input?.addEventListener('input', () => {
            calculateRevenueAmount();
            if (!revenueLocked) return;
            window.clearTimeout(revenueValidationTimer);
            revenueValidationTimer = window.setTimeout(validateRevenueAmount, 250);
        }));
        if (revenueLocked) {
            document.getElementById('revenueModal')?.addEventListener('shown.bs.modal', validateRevenueAmount);
        }
        revenueForm?.addEventListener('submit', calculateRevenueAmount);
        calculateRevenueAmount();

        function showRevenueModal() {
            const modal = new bootstrap.Modal(document.getElementById('revenueModal'));
            modal.show();
        }

        @if(session('revenue_error'))
            document.addEventListener('DOMContentLoaded', () => showRevenueModal());
        @endif
    </script>
@endif

@auth
@php
    $userStoreOnline = Auth::check() ? (optional(Auth::user()->store)->is_online ?? false) : false;
@endphp
<script>
document.addEventListener('DOMContentLoaded', () => {
    const role = "{{ strtolower(Auth::user()->roles ?? '') }}";
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(Number(value || 0));
    const lowStockButton = document.getElementById('btn-low-stock-warning');
    const lowStockModalBody = document.getElementById('lowStockHeaderModalBody');

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const renderLowStockSummary = (payload) => {
        if (!lowStockButton || !lowStockModalBody) return;

        const groups = Array.isArray(payload?.groups) ? payload.groups : [];
        const totalCount = Number(payload?.count || 0);

        if (!totalCount || groups.length === 0) {
            lowStockButton.classList.add('d-none');
            lowStockModalBody.innerHTML = '<div class="text-muted">Tidak ada produk di bawah stok minimum.</div>';
            return;
        }

        lowStockButton.textContent = `Perlu PO (${totalCount})`;
        lowStockButton.classList.remove('d-none');

        lowStockModalBody.innerHTML = groups.map((group) => {
            const storeId = group.store_id ?? '';
            const storeName = escapeHtml(group.store_name ?? 'Toko');
            const orderUrl = storeId ? `{{ route('order-stock.index') }}?store=${encodeURIComponent(storeId)}` : `{{ route('order-stock.index') }}`;
            const exportUrl = storeId ? `{{ route('order-stock.export') }}?store=${encodeURIComponent(storeId)}` : '';
            const rows = (Array.isArray(group.items) ? group.items : []).map((item) => `
                <tr>
                    <td>${escapeHtml(item.product_code)}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td>${escapeHtml(item.stock_system)}</td>
                    <td>${escapeHtml(item.min_stock ?? '-')}</td>
                    <td>${escapeHtml(item.max_stock ?? '-')}</td>
                    <td>${escapeHtml(item.po_qty ?? 0)}</td>
                </tr>
            `).join('');

            return `
                <div class="mb-3 border rounded">
                    <div class="p-2 bg-light fw-bold d-flex justify-content-between">
                        <span>${storeName}</span>
                        <div class="d-flex gap-2">
                            <a href="${orderUrl}" class="btn btn-sm btn-outline-primary">Order Stock</a>
                            ${exportUrl ? `<a href="${exportUrl}" class="btn btn-sm btn-outline-success">Export Excel</a>` : ''}
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Produk</th>
                                    <th>Stok</th>
                                    <th>Min</th>
                                    <th>Max</th>
                                    <th>PO</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            `;
        }).join('');
    };

    const loadLowStockSummary = () => {
        if (!lowStockButton || !lowStockModalBody) return;

        const params = new URLSearchParams(window.location.search);
        const requestedStoreId = params.get('store_id') || params.get('store') || "{{ Auth::user()->store_id ?? '' }}";
        const url = new URL("{{ route('order-stock.summary') }}", window.location.origin);

        if (requestedStoreId) {
            url.searchParams.set('store_id', requestedStoreId);
        }

        fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Gagal memuat notifikasi PO.');
                }
                renderLowStockSummary(payload);
            })
            .catch(() => {
                lowStockButton.classList.add('d-none');
                lowStockModalBody.innerHTML = '<div class="text-muted">Notifikasi PO tidak dapat dimuat saat ini.</div>';
            });
    };

    const updateStatus = (storeId, isOnline, note='') => {
        return fetch(`/store/${storeId}/toggle-online`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ offline_note: note, is_online: isOnline ? 1 : 0 })
        }).then(async res => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'Gagal memperbarui status');
            return data;
        });
    };

    const select = document.getElementById('header-store-select');
    const btnToggle = document.getElementById('btn-toggle-store');
    const btnToggleLabel = document.getElementById('btn-toggle-store-label');
    const statusText = document.getElementById('header-store-status');

    const refreshAdminButton = () => {
        const opt = select?.selectedOptions[0];
        if (!opt || !opt.value) {
            btnToggleLabel.textContent = 'Pilih toko';
            btnToggle.className = 'btn btn-sm btn-outline-primary';
            statusText.textContent = '';
            return;
        }
        const online = Number(opt.dataset.online || 0) === 1;
        btnToggleLabel.textContent = online ? 'Matikan' : 'Nyalakan';
        btnToggle.className = online ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-outline-success';
        statusText.textContent = online ? 'Sedang online' : 'Sedang offline';
    };

    if (btnToggle && select) {
        refreshAdminButton();
        select.addEventListener('change', refreshAdminButton);
        btnToggle.addEventListener('click', () => {
            const opt = select.selectedOptions[0];
            if (!opt || !opt.value) return Swal.fire({icon:'warning', title:'Pilih toko terlebih dahulu'});
            const storeId = opt.value;
            const online = Number(opt.dataset.online || 0) === 1;
            const desiredOnline = !online;
            const note = desiredOnline ? '' : prompt('Catatan offline (opsional):', '');
            updateStatus(storeId, desiredOnline, note)
                .then((data) => {
                    if (desiredOnline && data.released?.sales > 0) {
                        return Swal.fire({
                            icon: 'success',
                            title: 'Toko online',
                            text: `${data.released.sales} penjualan pending (${formatCurrency(data.released.sales_amount)}) dan ${data.released.outgoing_rows} baris stok sudah diposting.`
                        }).then(() => window.location.reload());
                    }
                    return window.location.reload();
                })
                .catch(err => Swal.fire({icon:'error', title:'Oops', text: err.message}));
        });
    }

    const btnToggleSelf = document.getElementById('btn-toggle-store-self');
    const btnToggleSelfHeader = document.getElementById('btn-toggle-store-self-header');
    if (btnToggleSelf) {
        let selfOnline = {{ $userStoreOnline ? 'true' : 'false' }};
        const storeId = "{{ Auth::user()->store_id ?? '' }}";
        const setSelfLabel = () => {
            btnToggleSelf.textContent = selfOnline ? 'Offline' : 'Online';
            btnToggleSelf.className = selfOnline ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-outline-success';
        };
        setSelfLabel();
        btnToggleSelf.addEventListener('click', () => {
            if (!storeId) return Swal.fire({icon:'error', title:'Tidak ada store_id'});
            const desired = !selfOnline;
            const note = desired ? '' : prompt('Catatan offline (opsional):', '');
            updateStatus(storeId, desired, note)
                .then((data) => {
                    if (desired && data.released?.sales > 0) {
                        return Swal.fire({
                            icon: 'success',
                            title: 'Toko online',
                            text: `${data.released.sales} penjualan pending (${formatCurrency(data.released.sales_amount)}) dan ${data.released.outgoing_rows} baris stok sudah diposting.`
                        }).then(() => window.location.reload());
                    }
                    return window.location.reload();
                })
                .catch(err => Swal.fire({icon:'error', title:'Oops', text: err.message}));
        });
    }

    if (btnToggleSelfHeader) {
        let selfOnline2 = {{ $userStoreOnline ? 'true' : 'false' }};
        const storeId2 = "{{ Auth::user()->store_id ?? '' }}";
        const setSelfLabel2 = () => {
            btnToggleSelfHeader.textContent = selfOnline2 ? 'Offline' : 'Online';
            btnToggleSelfHeader.className = selfOnline2 ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-outline-success';
        };
        setSelfLabel2();
        btnToggleSelfHeader.addEventListener('click', () => {
            if (!storeId2) return Swal.fire({icon:'error', title:'Tidak ada store_id'});
            const desired = !selfOnline2;
            const note = desired ? '' : prompt('Catatan offline (opsional):', '');
            updateStatus(storeId2, desired, note)
                .then((data) => {
                    if (desired && data.released?.sales > 0) {
                        return Swal.fire({
                            icon: 'success',
                            title: 'Toko online',
                            text: `${data.released.sales} penjualan pending (${formatCurrency(data.released.sales_amount)}) dan ${data.released.outgoing_rows} baris stok sudah diposting.`
                        }).then(() => window.location.reload());
                    }
                    return window.location.reload();
                })
                .catch(err => Swal.fire({icon:'error', title:'Oops', text: err.message}));
        });
    }

    setTimeout(loadLowStockSummary, 250);
});
</script>
@endauth
