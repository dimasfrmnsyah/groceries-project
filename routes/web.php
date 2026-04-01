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
            'exists' => 'daily_revenues'::where('user_id', auth()->id())
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
        Route::get('/store', [TbPurchaseController::class, 'store'])->name('purchase.store');
        Route::post('/store', [TbPurchaseController::class, 'store'])->name('purchase.store'); // Pastikan ini ada
        Route::get('/update/{id}', [TbPurchaseController::class, 'update'])->name('purchase.update');
        Route::put('/update/{id}', [TbPurchaseController::class, 'update'])->name('purchase.update');
        Route::get('/delete/{id}', [TbPurchaseController::class, 'delete'])->name('purchase.delete');
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
        Route::delete('/delete/{id}', [TbStoresController::class, 'delete'])->name('store.delete');
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
        Route::post('/adjust-stock-bulk', [InventoryController::class, 'adjustStockBulk'])->name('inventory.adjustStockBulk');
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
