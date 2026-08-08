<?php

use App\Http\Controllers\TbBrandsController;
use App\Http\Controllers\TbIncomingGoodsController;
use App\Http\Controllers\TbProductsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TbCustomersController;
use App\Http\Controllers\TbStoresController;
use App\Http\Controllers\TbSuppliersController;
use App\Http\Controllers\TbTypesController;
use App\Http\Controllers\TbUnitsController;
use App\Http\Controllers\TbUserController;
use App\Http\Controllers\TbPurchaseController;
use App\Http\Controllers\TbSalesController;
use App\Http\Controllers\DailySalesReportController;
use App\Http\Controllers\CashierMonthlyReportController;
use App\Http\Controllers\StoreMonthlyReportController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\TbSellController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\OrderStockController;
use App\Http\Controllers\StockThresholdController;
use App\Http\Controllers\ItemMovingController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\AccountingController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TbMasterMenusController;
use App\Http\Controllers\TbMasterRolesController;
use App\Http\Controllers\Settings\MenuAccessController;
use App\Support\MenuHelper;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Route::get('/', function () {
//     return view('login');
// });

Auth::routes();


Route::group(['middleware' => ['auth']], function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/staff/logout-revenue', [StaffController::class, 'submitRevenueAndLogout'])->name('staff.submitRevenueAndLogout');

    Route::get('/check-daily-revenue', function (Request $request) {
        return response()->json([
            'exists' => \App\Models\tb_daily_revenues::where('user_id', auth()->id())
                ->where('date', $request->get('date'))
                ->exists()
        ]);
    });
    Route::get('/export-penjualan', [App\Http\Controllers\HomeController::class, 'exportPenjualan'])->name('home.export.penjualan');
Route::get('/sync/manual', [SyncController::class, 'manual'])->name('sync.manual');

    Route::prefix('master-type')->group(function () {
        Route::get('/', [TbTypesController::class, 'index'])->name('master-types.index');
        Route::get('/create', [TbTypesController::class, 'create'])->name('master-types.create');
        Route::get('/edit/{id}', [TbTypesController::class, 'edit'])->name('master-types.edit');
        Route::post('/store', [TbTypesController::class, 'store'])->name('master-type.store');
        Route::put('/update/{id}', [TbTypesController::class, 'update'])->name('master-type.update');
        Route::delete('/delete/{id}', [TbTypesController::class, 'destroy'])->name('master-type.delete');

    });
    Route::prefix('master-brand')->group(function () {
        Route::get('/', [TbBrandsController::class, 'index'])->name('master-brand.index');
        Route::get('/create', [TbBrandsController::class, 'create'])->name('master-brand.create');
        Route::get('/edit/{id}', [TbBrandsController::class, 'edit'])->name('master-brand.edit');
        Route::post('/store', [TbBrandsController::class, 'store'])->name('master-brand.store');
        Route::put('/update/{id}', [TbBrandsController::class, 'update'])->name('master-brand.update');
        Route::delete('/delete/{id}', [TbBrandsController::class, 'destroy'])->name('master-brand.delete');

    });
    Route::prefix('master-unit')->group(callback: function () {
        Route::get('/', [TbUnitsController::class, 'index'])->name('master-unit.index');
        Route::get('/create', [TbUnitsController::class, 'create'])->name('master-unit.create');
        Route::get('/edit/{id}', [TbUnitsController::class, 'edit'])->name('master-unit.edit');
        Route::post('/store', [TbUnitsController::class, 'store'])->name('master-unit.store');
        Route::put('/update/{id}', [TbUnitsController::class, 'update'])->name('master-unit.update');
        Route::delete('/delete/{id}', [TbUnitsController::class, 'destroy'])->name('master-unit.delete');
    });
    Route::prefix('master-product')->group(function () {
        Route::get('/', [TbProductsController::class, 'index'])->name('master-product.index');
        Route::get('/create', [TbProductsController::class, 'create'])->name('master-product.create');
        Route::get('/{id}', [TbProductsController::class, 'show'])->name('master-product.show');
        Route::get('/edit/{id}', [TbProductsController::class, 'edit'])->name('master-product.edit');
        Route::post('/store', [TbProductsController::class, 'store'])->name('master-product.store');
        Route::put('/update/{id}', [TbProductsController::class, 'update'])->name('master-product.update');
        Route::delete('/delete/{id}', [TbProductsController::class, 'destroy'])->name('master-product.delete');
        Route::get('/import', [TbProductsController::class, 'import'])->name('master-product.import');
        Route::post('/import', [TbProductsController::class, 'import'])->name('master-product.import');
        Route::get('/preview', [TbProductsController::class, 'preview'])->name('master-product.preview');
        Route::post('/save-imported', [TbProductsController::class, 'saveImported'])->name('master-product.saveImported');
    });

    Route::prefix('user')->group(function () {
        Route::get('/', [TbUserController::class, 'index'])->name('user.index');
        Route::get('/create', [TbUserController::class, 'create'])->name('user.create');
        Route::get('/edit/{id}', [TbUserController::class, 'edit'])->name('user.edit');
        Route::post('/store', [TbUserController::class, 'store'])->name('user.store');
        Route::put('/update/{id}', [TbUserController::class, 'update'])->name('user.update');
        Route::put('/update/password/{id}', [TbUserController::class, 'updatePassword'])->name('user.update.password');
        Route::delete('/delete/{id}', [TbUserController::class, 'destroy'])->name('user.delete');
    });

    Route::prefix('supplier')->group(function () {
        Route::get('/', [TbSuppliersController::class, 'index'])->name('supplier.index');
        Route::get('/create', [TbSuppliersController::class, 'create'])->name('supplier.create');
        Route::get('/edit/{id}', [TbSuppliersController::class, 'edit'])->name('supplier.edit');
        Route::post('/store', [TbSuppliersController::class, 'store'])->name('supplier.store');
        Route::put('/update/{id}', [TbSuppliersController::class, 'update'])->name('supplier.update');
        Route::delete('/delete/{id}', [TbSuppliersController::class, 'destroy'])->name('supplier.delete');
    });

    Route::prefix('purchase')->group(function () {
        Route::get('/', [TbPurchaseController::class, 'index'])->name('purchase.index');
        Route::get('/create', [TbPurchaseController::class, 'create'])->name('purchase.create');
        Route::get('/edit/{id}', [TbPurchaseController::class, 'edit'])->name('purchase.edit');
        Route::post('/store', [TbPurchaseController::class, 'store'])->name('purchase.store');
        Route::put('/update/{id}', [TbPurchaseController::class, 'update'])->name('purchase.update');
        Route::delete('/delete/{id}', [TbPurchaseController::class, 'destroy'])->name('purchase.destroy');
    });

    Route::prefix('sell')->group(function () {
        Route::get('/', [TbSellController::class, 'index'])->name('sell.index');
        Route::get('/detail/{id}', [TbSellController::class, 'detail'])->name('sell.detail');
        Route::get('/edit/{id}', [TbSellController::class, 'edit'])->name('sell.edit');
        Route::put('/update/{id}', [TbSellController::class, 'update'])->name('sell.update');
    });

    Route::prefix('store')->group(function () {
        Route::get('/', [TbStoresController::class, 'index'])->name('store.index');
        Route::get('/create', [TbStoresController::class, 'create'])->name('store.create');
        Route::get('/edit/{id}', [TbStoresController::class, 'edit'])->name('store.edit');
        Route::post('/store', [TbStoresController::class, 'store'])->name('store.store');
        Route::put('/update/{id}', [TbStoresController::class, 'update'])->name('store.update');
        Route::delete('/delete/{id}', [TbStoresController::class, 'destroy'])->name('store.delete');
        Route::post('/{id}/toggle-online', [TbStoresController::class, 'toggleOnline'])->name('store.toggle_online');
    });

    Route::prefix('customer')->group(function () {
        Route::get('/', [TbCustomersController::class, 'index'])->name('customer.index');
        Route::get('/create', [TbCustomersController::class, 'create'])->name('customer.create');
        Route::get('/edit/{id}', [TbCustomersController::class, 'edit'])->name('customer.edit');
        Route::post('/store', [TbCustomersController::class, 'store'])->name('customer.store');
        Route::put('/udpate/{id}', [TbCustomersController::class, 'update'])->name('customer.update');
        Route::delete('/delete/{id}', [TbCustomersController::class, 'destroy'])->name('customer.delete');
    });

    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/adjust-stock', [InventoryController::class, 'adjustStock'])->name('inventory.adjustStock');
        Route::post('/adjust-stock-bulk', [InventoryController::class, 'adjustStockBulkV3'])->name('inventory.adjustStockBulk');
        Route::post('/adjust-stock-bulk-v3', [InventoryController::class, 'adjustStockBulkV3'])
            ->name('inventory.adjustStockBulkV3');
        Route::post('/adjust-stock-preview', [InventoryController::class, 'adjustStockPreview'])
            ->name('inventory.adjustStockPreview');
        Route::get('/adjust-stock-preview', [InventoryController::class, 'adjustStockPreviewPage'])
            ->name('inventory.adjustStockPreviewPage');
        Route::get('/csrf-refresh', [InventoryController::class, 'refreshCsrf'])
            ->name('inventory.refreshCsrf');
        Route::post('/normalize-negative-stock', [InventoryController::class, 'normalizeNegativeStock'])
            ->name('inventory.normalizeNegativeStock');
    });


    Route::prefix('sales')->group(function () {
        Route::get('/', [TbSalesController::class, 'index'])->name('sales.index');
        Route::post('/', [TbSalesController::class, 'store'])->name('sales.store');

    });

    Route::prefix('order-stock')->group(function () {
        Route::get('/', [OrderStockController::class, 'index'])->name('order-stock.index');
        Route::get('/summary', [OrderStockController::class, 'summary'])->name('order-stock.summary');
        Route::post('/restock', [OrderStockController::class, 'restock'])->name('order-stock.restock');
        Route::get('/export', [OrderStockController::class, 'export'])->name('order-stock.export');
    });

    Route::prefix('stock-threshold')->group(function () {
        Route::get('/', [StockThresholdController::class, 'index'])->name('stock-threshold.index');
        Route::post('/', [StockThresholdController::class, 'save'])->name('stock-threshold.save');
    });

    Route::prefix('item-moving')->group(function () {
        Route::get('/', [ItemMovingController::class, 'index'])->name('item-moving.index');
    });

    Route::prefix('stock-transfer')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('stock-transfer.index');
        Route::post('/', [StockTransferController::class, 'store'])->name('stock-transfer.store');
    });

    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/accounts', [AccountingController::class, 'accounts'])->name('accounts.index');
        Route::get('/accounts/create', [AccountingController::class, 'createAccount'])->name('accounts.create');
        Route::post('/accounts', [AccountingController::class, 'storeAccount'])->name('accounts.store');
        Route::get('/accounts/{id}/edit', [AccountingController::class, 'editAccount'])->name('accounts.edit');
        Route::put('/accounts/{id}', [AccountingController::class, 'updateAccount'])->name('accounts.update');
        Route::delete('/accounts/{id}', [AccountingController::class, 'destroyAccount'])->name('accounts.destroy');
        Route::post('/settings', [AccountingController::class, 'updateSettings'])->name('settings.update');
        Route::get('/cash-book', [AccountingController::class, 'cashBook'])->name('cash-book.index');
        Route::get('/budgeting', [AccountingController::class, 'budgeting'])->name('budgeting.index');
        Route::get('/budgeting/create', [AccountingController::class, 'createBudgeting'])->name('budgeting.create');
        Route::post('/budgeting', [AccountingController::class, 'storeBudgeting'])->name('budgeting.store');
        Route::get('/budgeting/{id}/edit', [AccountingController::class, 'editBudgeting'])->name('budgeting.edit');
        Route::put('/budgeting/{id}', [AccountingController::class, 'updateBudgeting'])->name('budgeting.update');
        Route::delete('/budgeting/{id}', [AccountingController::class, 'destroyBudgeting'])->name('budgeting.destroy');
        Route::get('/expenses', [AccountingController::class, 'expenses'])->name('expenses.index');
        Route::get('/expenses/create', [AccountingController::class, 'createExpense'])->name('expenses.create');
        Route::post('/expenses', [AccountingController::class, 'storeExpense'])->name('expenses.store');
        Route::get('/expenses/{id}/edit', [AccountingController::class, 'editExpense'])->name('expenses.edit');
        Route::put('/expenses/{id}', [AccountingController::class, 'updateExpense'])->name('expenses.update');
        Route::delete('/expenses/{id}', [AccountingController::class, 'destroyExpense'])->name('expenses.destroy');
        Route::get('/receivables', [AccountingController::class, 'receivables'])->name('receivables.index');
        Route::get('/receivables/create', [AccountingController::class, 'createReceivable'])->name('receivables.create');
        Route::post('/receivables', [AccountingController::class, 'storeReceivable'])->name('receivables.store');
        Route::get('/receivables/{id}/edit', [AccountingController::class, 'editReceivable'])->name('receivables.edit');
        Route::put('/receivables/{id}', [AccountingController::class, 'updateReceivable'])->name('receivables.update');
        Route::delete('/receivables/{id}', [AccountingController::class, 'destroyReceivable'])->name('receivables.destroy');
        Route::get('/receivables/{id}/payment', [AccountingController::class, 'showReceivablePayment'])->name('receivables.payment');
        Route::post('/receivables/{id}/pay', [AccountingController::class, 'payReceivable'])->name('receivables.pay');
        Route::get('/supplier-debts', [AccountingController::class, 'supplierDebts'])->name('supplier-debts.index');
        Route::get('/supplier-debts/create', [AccountingController::class, 'createSupplierDebt'])->name('supplier-debts.create');
        Route::post('/supplier-debts', [AccountingController::class, 'storeSupplierDebt'])->name('supplier-debts.store');
        Route::get('/supplier-debts/{id}/edit', [AccountingController::class, 'editSupplierDebt'])->name('supplier-debts.edit');
        Route::put('/supplier-debts/{id}', [AccountingController::class, 'updateSupplierDebt'])->name('supplier-debts.update');
        Route::delete('/supplier-debts/{id}', [AccountingController::class, 'destroySupplierDebt'])->name('supplier-debts.destroy');
        Route::get('/supplier-debts/{id}/payment', [AccountingController::class, 'showSupplierDebtPayment'])->name('supplier-debts.payment');
        Route::post('/supplier-debts/{id}/pay', [AccountingController::class, 'paySupplierDebt'])->name('supplier-debts.pay');
        Route::get('/cash-opname', [AccountingController::class, 'cashOpname'])->name('cash-opname.index');
        Route::get('/cash-opname/create', [AccountingController::class, 'createCashOpname'])->name('cash-opname.create');
        Route::get('/cash-opname/turnover', [AccountingController::class, 'cashOpnameTurnover'])->name('cash-opname.turnover');
        Route::post('/cash-opname', [AccountingController::class, 'storeCashOpname'])->name('cash-opname.store');
        Route::get('/cash-opname/{id}/edit', [AccountingController::class, 'editCashOpname'])->name('cash-opname.edit');
        Route::put('/cash-opname/{id}', [AccountingController::class, 'updateCashOpname'])->name('cash-opname.update');
        Route::delete('/cash-opname/{id}', [AccountingController::class, 'destroyCashOpname'])->name('cash-opname.destroy');
    });

    Route::prefix('settings')->group(function() {
        Route::prefix('/roles')->group(function() {
            Route::get('/', [TbMasterRolesController::class, 'index'])->name('settings.roles.index');
            Route::get('/create', [TbMasterRolesController::class, 'create'])->name('settings.roles.create');
            Route::get('/edit/{id}', [TbMasterRolesController::class, 'edit'])->name('settings.roles.edit');
            Route::post('/', [TbMasterRolesController::class, 'store'])->name('settings.roles.store');
            Route::put('/{id}', [TbMasterRolesController::class, 'update'])->name('settings.roles.update');
            Route::delete('/{id}', [TbMasterRolesController::class, 'destroy'])->name('settings.roles.delete');
        });

        Route::prefix('/menus')->group(function() {
            Route::get('/', [TbMasterMenusController::class, 'index'])->name('settings.menus.index');
            Route::get('/create', [TbMasterMenusController::class, 'create'])->name('settings.menus.create');
            Route::get('/edit/{id}', [TbMasterMenusController::class, 'edit'])->name('settings.menus.edit');
            Route::post('/', [TbMasterMenusController::class, 'store'])->name('settings.menus.store');
            Route::put('/{id}', [TbMasterMenusController::class, 'update'])->name('settings.menus.update');
            Route::delete('/{id}', [TbMasterMenusController::class, 'destroy'])->name('settings.menus.delete');
        });
    });

    Route::prefix('options')->group(function () {
        Route::get('/incoming-goods', [TbIncomingGoodsController::class, 'options'])->name('options.incoming_goods');
    });


Route::prefix('report')->name('report.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/data', [ReportController::class, 'indexData'])->name('index.data');
    Route::get('/detail/{id}', [ReportController::class, 'detail'])->name('detail');
    Route::get('/detail/{id}/data', [ReportController::class, 'detailData'])->name('detail.data');

    // Daily sales report (baru)
    Route::get('/sales/today', [DailySalesReportController::class, 'index'])->name('sales.today');
    Route::get('/sales/today/data', [DailySalesReportController::class, 'data'])->name('sales.today.data');
    Route::get('/sales/today/export', [DailySalesReportController::class, 'export'])->name('sales.today.export');

    // Monthly cashier report (penjualan asli)
    Route::get('/cashier-monthly', [CashierMonthlyReportController::class, 'index'])->name('cashier.monthly');
    Route::get('/cashier-monthly/data', [CashierMonthlyReportController::class, 'data'])->name('cashier.monthly.data');
    Route::get('/cashier-monthly/detail', [CashierMonthlyReportController::class, 'detail'])->name('cashier.monthly.detail');
    Route::get('/cashier-monthly/detail/data', [CashierMonthlyReportController::class, 'detailData'])->name('cashier.monthly.detail.data');

    // Monthly store report (penjualan asli)
    Route::get('/store-monthly', [StoreMonthlyReportController::class, 'index'])->name('store.monthly');
    Route::get('/store-monthly/data', [StoreMonthlyReportController::class, 'data'])->name('store.monthly.data');
    Route::get('/store-monthly/detail', [StoreMonthlyReportController::class, 'detail'])->name('store.monthly.detail');
    Route::get('/store-monthly/detail/data', [StoreMonthlyReportController::class, 'detailData'])->name('store.monthly.detail.data');

    // Legacy laporan penjualan
    Route::get('/sales-report', [SalesReportController::class, 'index'])->name('sales_report.index');
    Route::get('/sales-report/data', [SalesReportController::class, 'data'])->name('sales_report.data');
});


Route::prefix('settings')->group(function () {
    Route::get('access',  [MenuAccessController::class, 'index'])->name('settings.access.index');
    Route::post('access', [MenuAccessController::class, 'save'])->name('settings.access.save');
});

// routes/web.php


});
    Route::prefix('master-stock')->group(function () {
        Route::get('/', [ProductStockController::class, 'index'])->name('master-stock.index');
        Route::get('/data', [ProductStockController::class, 'data'])->name('master-stock.data');
        Route::get('/export', [ProductStockController::class, 'export'])->name('master-stock.export');
    });
