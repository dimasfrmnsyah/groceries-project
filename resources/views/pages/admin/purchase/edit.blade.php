@extends('layouts.app')

@section('css')
    <link href="{{ asset('assets/plugins/select2/css/select2.min.css') }}" rel="stylesheet" />
    <style>
        .select2-container { width: 100% !important; }
    </style>
@endsection

@section('content')
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">Edit Pembelian</div>
        <div class="ms-auto">
            <div class="btn-group">
                <a href="{{ route('purchase.index') }}" class="btn btn-secondary">Kembali</a>
            </div>
        </div>
    </div>

    <h6 class="mb-0 text-uppercase">Edit Pembelian</h6>
    <hr />
    <div class="card">
        <div class="card-body">
            <form action="{{ route('purchase.update', $purchase->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Pilih Supplier</label>
                        <select class="form-control select2" name="supplier_id" required>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" 
                                    {{ old('supplier_id', $purchase->supplier_id) == $supplier->id ? 'selected' : '' }}>
                                    {{ $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Toko</label>
                        <select class="form-control select2" name="store_id" required>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" 
                                    {{ old('store_id', $purchase->store_id) == $store->id ? 'selected' : '' }}>
                                    {{ $store->store_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Total Harga</label>
                        <input type="number" name="total_price" id="total_price" class="form-control"
                               value="{{ old('total_price', $purchase->total_price) }}" readonly>
                    </div>
                </div>

                <h5 class="mt-4">Input Produk</h5>
                <p class="text-muted small">Tekan <strong>Enter</strong> atau tombol Tambah untuk memasukkan produk ke daftar.</p>
                <div class="table-responsive">
                    <table class="table table-bordered" id="product-entry-table">
                        <thead class="table-light">
                            <tr>
                                <th>Produk</th>
                                <th>Stock</th>
                                <th>Harga</th>
                                <th>Deskripsi</th>
                                <th style="min-width: 120px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select id="product-input" class="form-control select2">
                                        <option value="">Pilih Produk</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}" data-price="{{ $product->purchase_price }}" data-code="{{ $product->product_code }}">
                                                [{{ $product->product_code }}] {{ $product->product_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" id="product-stock" class="form-control" min="1" value="1"></td>
                                <td><input type="number" id="product-price" class="form-control" value="0" readonly></td>
                                <td><input type="text" id="product-description" class="form-control"></td>
                                <td>
                                    <button type="button" id="add-product" class="btn btn-primary">Tambah</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="product-list-section" class="mt-4" style="display: none;">
                    <h5>Daftar Produk</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="products-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width: 60px;">No</th>
                                    <th>Produk</th>
                                    <th>Stock</th>
                                    <th>Harga</th>
                                    <th>Deskripsi</th>
                                    <th style="min-width: 160px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="product-list"></tbody>
                        </table>
                    </div>
                </div>

                <button type="submit" class="btn btn-success mt-3">Update</button>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('assets/plugins/select2/js/select2.min.js') }}"></script>
    <script>
        $(document).ready(function () {
            const productMatcher = function (params, data) {
                if ($.trim(params.term) === '') {
                    return data;
                }
                const term = params.term.toLowerCase();
                const text = (data.text || '').toLowerCase();
                const code = ($(data.element).data('code') || '').toString().toLowerCase();
                if (text.includes(term) || code.includes(term)) {
                    return data;
                }
                return null;
            };

            $('.select2').select2({ width: '100%', matcher: productMatcher });

            let selectedProducts = @json($purchase->incomingGoods->values()->map(function ($item) {
                return [
                    'product_id' => (int) $item->product_id,
                    'product_label' => '['.($item->product?->product_code ?? '-').'] '.($item->product?->product_name ?? 'Produk tidak ditemukan'),
                    'stock' => (int) $item->stock,
                    'price' => (float) ($item->product?->purchase_price ?? 0),
                    'description' => $item->description ?? '',
                ];
            })->all());
            let editingIndex = null;

            function escapeHtml(value) {
                return $('<div>').text(value ?? '').html();
            }

            function resetProductInput(focusAfterReset = true) {
                editingIndex = null;
                $('#product-input').val('').trigger('change.select2');
                $('#product-stock').val(1);
                $('#product-price').val(0);
                $('#product-description').val('');
                $('#add-product').text('Tambah').removeClass('btn-warning').addClass('btn-primary');

                if (focusAfterReset) {
                    setTimeout(() => $('#product-input').select2('open'), 0);
                }
            }

            function selectedProductPayload() {
                const $option = $('#product-input option:selected');
                const productId = parseInt($option.val(), 10);
                const stock = parseInt($('#product-stock').val(), 10);

                if (!productId) {
                    alert('Pilih produk terlebih dahulu.');
                    $('#product-input').select2('open');
                    return null;
                }

                if (!Number.isFinite(stock) || stock < 1) {
                    alert('Stock minimal 1.');
                    $('#product-stock').focus().select();
                    return null;
                }

                return {
                    product_id: productId,
                    product_label: $option.text().trim(),
                    stock: stock,
                    price: parseFloat($option.attr('data-price')) || 0,
                    description: $('#product-description').val() || '',
                };
            }

            function addOrUpdateProduct() {
                const payload = selectedProductPayload();
                if (!payload) {
                    return;
                }

                if (editingIndex !== null) {
                    selectedProducts[editingIndex] = payload;
                } else {
                    const existingIndex = selectedProducts.findIndex(item => item.product_id === payload.product_id);
                    if (existingIndex >= 0) {
                        selectedProducts[existingIndex].stock += payload.stock;
                        if (payload.description) {
                            selectedProducts[existingIndex].description = payload.description;
                        }
                    } else {
                        selectedProducts.push(payload);
                    }
                }

                renderProductList();
                resetProductInput();
            }

            function renderProductList() {
                const $list = $('#product-list').empty();

                if (selectedProducts.length === 0) {
                    $('#product-list-section').hide();
                    updateTotalPrice();
                    return;
                }

                $('#product-list-section').show();
                selectedProducts.forEach((item, index) => {
                    $list.append(`
                        <tr>
                            <td>${index + 1}</td>
                            <td>
                                ${escapeHtml(item.product_label)}
                                <input type="hidden" name="products[${index}][product_id]" value="${item.product_id}">
                            </td>
                            <td>
                                ${item.stock}
                                <input type="hidden" name="products[${index}][stock]" value="${item.stock}">
                            </td>
                            <td>${item.price}</td>
                            <td>
                                ${escapeHtml(item.description)}
                                <input type="hidden" name="products[${index}][description]" value="${escapeHtml(item.description)}">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning update-product" data-index="${index}">Update</button>
                                <button type="button" class="btn btn-sm btn-danger delete-product" data-index="${index}">Delete</button>
                            </td>
                        </tr>
                    `);
                });

                updateTotalPrice();
            }

            function updateTotalPrice() {
                const totalPrice = selectedProducts.reduce((total, item) => total + (item.price * item.stock), 0);
                $('#total_price').val(totalPrice);
            }

            $('#product-input').on('change', function () {
                const price = parseFloat($('#product-input option:selected').attr('data-price')) || 0;
                $('#product-price').val(price);
                if ($(this).val()) {
                    setTimeout(() => $('#product-stock').focus().select(), 0);
                }
            });

            $('#product-stock, #product-description').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addOrUpdateProduct();
                }
            });

            $('#add-product').on('click', function () {
                addOrUpdateProduct();
            });

            $(document).on('click', '.update-product', function () {
                const index = parseInt($(this).data('index'), 10);
                const item = selectedProducts[index];
                if (!item) {
                    return;
                }

                editingIndex = index;
                $('#product-input').val(item.product_id).trigger('change.select2');
                $('#product-stock').val(item.stock);
                $('#product-price').val(item.price);
                $('#product-description').val(item.description);
                $('#add-product').text('Update').removeClass('btn-primary').addClass('btn-warning');
                $('#product-stock').focus().select();
            });

            $(document).on('click', '.delete-product', function () {
                const index = parseInt($(this).data('index'), 10);
                selectedProducts.splice(index, 1);
                renderProductList();
                resetProductInput(false);
            });

            $('form').submit(function (e) {
                if (selectedProducts.length === 0) {
                    e.preventDefault();
                    alert('Tambahkan minimal satu produk.');
                    $('#product-input').select2('open');
                    return;
                }

                updateTotalPrice();
            });

            renderProductList();
        });
    </script>
@endsection
