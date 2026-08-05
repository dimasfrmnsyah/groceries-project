<?php

namespace App\Http\Controllers;

use App\Models\tb_customers;
use App\Models\tb_outgoing_goods;
use App\Models\tb_products;
use App\Models\tb_sell;
use App\Models\tb_suppliers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingController extends Controller
{
    public function accounts()
    {
        return view('pages.admin.accounting.accounts', [
            'accounts' => DB::table('tb_accounting_accounts')->orderBy('account_number')->get(),
            'salesAccountId' => DB::table('tb_accounting_settings')->where('setting_key', 'sales_account_id')->value('account_id'),
        ]);
    }

    public function createAccount()
    {
        return view('pages.admin.accounting.account-form', [
            'mode' => 'create',
            'account' => null,
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'entries' => 'required|array|min:1|max:100',
            'entries.*.account_number' => 'required|string|max:50|distinct|unique:tb_accounting_accounts,account_number',
            'entries.*.account_name' => 'required|string|max:150',
            'entries.*.account_type' => 'required|string|max:50',
        ]);

        DB::transaction(function () use ($data) {
            $rows = array_map(fn (array $entry) => $this->withTimestamps($entry), $data['entries']);
            DB::table('tb_accounting_accounts')->insert($rows);
        });

        return redirect()->route('accounting.accounts.index')
            ->with('success', count($data['entries']).' account berhasil ditambahkan.');
    }

    public function editAccount(int $id)
    {
        $account = DB::table('tb_accounting_accounts')->where('id', $id)->first();
        if (!$account) abort(404);

        return view('pages.admin.accounting.account-form', [
            'mode' => 'edit',
            'account' => $account,
        ]);
    }

    public function updateAccount(Request $request, int $id)
    {
        $data = $request->validate([
            'account_number' => 'required|string|max:50|unique:tb_accounting_accounts,account_number,'.$id,
            'account_name' => 'required|string|max:150',
            'account_type' => 'required|string|max:50',
            'is_active' => 'required|boolean',
        ]);

        DB::table('tb_accounting_accounts')->where('id', $id)->update($this->withUpdateTimestamp($data));

        return redirect()->route('accounting.accounts.index')->with('success', 'Account berhasil diupdate. Data buku kas lama tetap memakai snapshot account lama.');
    }

    public function destroyAccount(int $id)
    {
        $used = DB::table('tb_accounting_entries')->where('account_id', $id)->exists();
        $isSalesDefault = (int) DB::table('tb_accounting_settings')->where('setting_key', 'sales_account_id')->value('account_id') === $id;

        if ($used || $isSalesDefault) {
            DB::table('tb_accounting_accounts')->where('id', $id)->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);
            return back()->with('success', 'Account sudah pernah dipakai, jadi dinonaktifkan agar buku kas lama tetap aman.');
        }

        DB::table('tb_accounting_accounts')->where('id', $id)->delete();

        return back()->with('success', 'Account berhasil dihapus.');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'sales_account_id' => 'required|integer|exists:tb_accounting_accounts,id',
        ]);

        DB::table('tb_accounting_settings')->updateOrInsert(
            ['setting_key' => 'sales_account_id'],
            ['account_id' => $data['sales_account_id'], 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', 'Mapping account penjualan berhasil diupdate.');
    }

    public function cashBook(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $query = DB::table('tb_accounting_entries as e')
            ->leftJoin('tb_stores as s', 's.id', '=', 'e.store_id')
            ->select('e.*', 's.store_name');

        if ($storeId) $query->where('e.store_id', $storeId);
        if ($request->filled('date_from')) $query->whereDate('e.date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('e.date', '<=', $request->date_to);
        if ($request->filled('source_type')) $query->where('e.source_type', $request->source_type);

        $totalIn = (clone $query)->where('e.direction', 'in')->sum('e.amount');
        $totalOut = (clone $query)->where('e.direction', 'out')->sum('e.amount');
        $entries = $query->orderByDesc('e.date')->orderByDesc('e.id')->limit(500)->get();

        return view('pages.admin.accounting.cash-book', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'entries' => $entries,
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
        ]);
    }

    public function budgeting(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $totalAmount = DB::table('tb_budgets')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->sum('amount');
        $rows = DB::table('tb_budgets as b')
            ->leftJoin('tb_stores as s', 's.id', '=', 'b.store_id')
            ->leftJoin('tb_accounting_accounts as a', 'a.id', '=', 'b.account_id')
            ->select('b.*', 's.store_name', 'a.account_number', 'a.account_name')
            ->when($storeId, fn ($q) => $q->where('b.store_id', $storeId))
            ->orderByDesc('b.date')
            ->orderByDesc('b.id')
            ->limit(200)
            ->get();

        return view('pages.admin.accounting.budgeting', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'rows' => $rows,
            'totalAmount' => $totalAmount,
        ]);
    }

    public function createBudgeting()
    {
        return view('pages.admin.accounting.budgeting-form', $this->formData([
            'mode' => 'create',
            'row' => null,
        ]));
    }

    public function storeBudgeting(Request $request)
    {
        $entries = $this->validateMoneyEntries($request);

        DB::transaction(function () use ($entries) {
            foreach ($entries as $entry) {
                $id = DB::table('tb_budgets')->insertGetId($this->withAudit($entry));
                $this->syncLedger($entry['date'], (int) $entry['store_id'], $entry['account_id'] ?? null, 'budgeting', $id, 'in', $entry['amount'], $entry['description'] ?? 'Budgeting');
            }
        });

        return redirect()->route('accounting.budgeting.index', $this->bulkStoreFilter($entries))
            ->with('success', count($entries).' budgeting berhasil disimpan dan masuk ke buku kas.');
    }

    public function editBudgeting(int $id)
    {
        return view('pages.admin.accounting.budgeting-form', $this->formData([
            'mode' => 'edit',
            'row' => $this->findScopedRow('tb_budgets', $id),
        ]));
    }

    public function updateBudgeting(Request $request, int $id)
    {
        $row = $this->findScopedRow('tb_budgets', $id);
        $data = $this->validateMoneyData($request);

        DB::transaction(function () use ($row, $data) {
            DB::table('tb_budgets')->where('id', $row->id)->update($this->withUpdateTimestamp($data));
            $this->syncLedger($data['date'], (int) $data['store_id'], $data['account_id'] ?? null, 'budgeting', (int) $row->id, 'in', $data['amount'], $data['description'] ?? 'Budgeting');
        });

        return redirect()->route('accounting.budgeting.index', ['store' => $data['store_id']])->with('success', 'Budgeting berhasil diupdate.');
    }

    public function destroyBudgeting(int $id)
    {
        $row = $this->findScopedRow('tb_budgets', $id);
        DB::transaction(function () use ($row) {
            $this->deleteLedger('budgeting', (int) $row->id);
            DB::table('tb_budgets')->where('id', $row->id)->delete();
        });

        return back()->with('success', 'Budgeting berhasil dihapus.');
    }

    public function expenses(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $totalAmount = DB::table('tb_expenses')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->sum('amount');
        $rows = DB::table('tb_expenses as e')
            ->leftJoin('tb_stores as s', 's.id', '=', 'e.store_id')
            ->leftJoin('tb_accounting_accounts as a', 'a.id', '=', 'e.account_id')
            ->select('e.*', 's.store_name', 'a.account_number', 'a.account_name')
            ->when($storeId, fn ($q) => $q->where('e.store_id', $storeId))
            ->orderByDesc('e.date')
            ->orderByDesc('e.id')
            ->limit(200)
            ->get();

        return view('pages.admin.accounting.expenses', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'rows' => $rows,
            'totalAmount' => $totalAmount,
        ]);
    }

    public function createExpense()
    {
        return view('pages.admin.accounting.expense-form', $this->formData([
            'mode' => 'create',
            'row' => null,
        ]));
    }

    public function storeExpense(Request $request)
    {
        $entries = $this->validateMoneyEntries($request);

        DB::transaction(function () use ($entries) {
            foreach ($entries as $entry) {
                $id = DB::table('tb_expenses')->insertGetId($this->withAudit($entry));
                $this->syncLedger($entry['date'], (int) $entry['store_id'], $entry['account_id'] ?? null, 'expense', $id, 'out', $entry['amount'], $entry['description'] ?? 'Pengeluaran');
            }
        });

        return redirect()->route('accounting.expenses.index', $this->bulkStoreFilter($entries))
            ->with('success', count($entries).' pengeluaran berhasil disimpan.');
    }

    public function editExpense(int $id)
    {
        return view('pages.admin.accounting.expense-form', $this->formData([
            'mode' => 'edit',
            'row' => $this->findScopedRow('tb_expenses', $id),
        ]));
    }

    public function updateExpense(Request $request, int $id)
    {
        $row = $this->findScopedRow('tb_expenses', $id);
        $data = $this->validateMoneyData($request);

        DB::transaction(function () use ($row, $data) {
            DB::table('tb_expenses')->where('id', $row->id)->update($this->withUpdateTimestamp($data));
            $this->syncLedger($data['date'], (int) $data['store_id'], $data['account_id'] ?? null, 'expense', (int) $row->id, 'out', $data['amount'], $data['description'] ?? 'Pengeluaran');
        });

        return redirect()->route('accounting.expenses.index', ['store' => $data['store_id']])->with('success', 'Pengeluaran berhasil diupdate.');
    }

    public function destroyExpense(int $id)
    {
        $row = $this->findScopedRow('tb_expenses', $id);
        DB::transaction(function () use ($row) {
            $this->deleteLedger('expense', (int) $row->id);
            DB::table('tb_expenses')->where('id', $row->id)->delete();
        });

        return back()->with('success', 'Pengeluaran berhasil dihapus.');
    }

    public function receivables(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $totals = DB::table('tb_customer_receivables')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('COALESCE(SUM(quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) AS paid_amount')
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS remaining_amount')
            ->first();
        $rows = DB::table('tb_customer_receivables as r')
            ->leftJoin('tb_customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('tb_products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('tb_stores as s', 's.id', '=', 'r.store_id')
            ->select('r.*', 'c.customer_name', 'p.product_name', 'p.product_code', 's.store_name')
            ->when($storeId, fn ($q) => $q->where('r.store_id', $storeId))
            ->orderByDesc('r.id')
            ->limit(200)
            ->get();

        return view('pages.admin.accounting.receivables', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    public function createReceivable()
    {
        return view('pages.admin.accounting.receivable-form', $this->formData([
            'customers' => tb_customers::orderBy('customer_name')->get(),
            'products' => tb_products::orderBy('product_name')->get(),
            'mode' => 'create',
            'row' => null,
        ]));
    }

    public function storeReceivable(Request $request)
    {
        $entries = $this->validateReceivableEntries($request);

        $requestedStock = [];
        foreach ($entries as $index => $entry) {
            $key = $entry['store_id'].':'.$entry['product_id'];
            $requestedStock[$key]['quantity'] = ($requestedStock[$key]['quantity'] ?? 0) + (int) $entry['quantity'];
            $requestedStock[$key]['store_id'] = (int) $entry['store_id'];
            $requestedStock[$key]['product_id'] = (int) $entry['product_id'];
            $requestedStock[$key]['indexes'][] = $index;
        }
        foreach ($requestedStock as $requestGroup) {
            $stock = $this->currentStock($requestGroup['store_id'], $requestGroup['product_id']);
            if ($stock < $requestGroup['quantity']) {
                return back()->withInput()->withErrors([
                    'entries.'.$requestGroup['indexes'][0].'.quantity' => 'Total qty untuk produk ini melebihi stok. Tersedia: '.$stock.', diminta: '.$requestGroup['quantity'].'.',
                ]);
            }
        }

        DB::transaction(function () use ($entries) {
            foreach ($entries as $index => $entry) {
                $sell = tb_sell::create([
                    'no_invoice' => 'AR-'.now('Asia/Jakarta')->format('YmdHis').'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'store_id' => $entry['store_id'],
                    'date' => $entry['date'],
                    'total_price' => 0,
                    'payment_amount' => 0,
                    'customer_id' => $entry['customer_id'] ?? 0,
                ]);

                tb_outgoing_goods::create($this->receivableOutgoingPayload($entry, $sell->id));

                DB::table('tb_customer_receivables')->insert($this->withAudit([
                    'date' => $entry['date'],
                    'store_id' => $entry['store_id'],
                    'customer_id' => $entry['customer_id'] ?? null,
                    'product_id' => $entry['product_id'],
                    'quantity' => $entry['quantity'],
                    'amount' => $entry['amount'],
                    'paid_amount' => 0,
                    'status' => 'open',
                    'sell_id' => $sell->id,
                    'description' => $entry['description'] ?? null,
                ]));
            }
        });

        return redirect()->route('accounting.receivables.index', $this->bulkStoreFilter($entries))
            ->with('success', count($entries).' piutang disimpan dan stok sudah berkurang.');
    }

    public function editReceivable(int $id)
    {
        return view('pages.admin.accounting.receivable-form', $this->formData([
            'customers' => tb_customers::orderBy('customer_name')->get(),
            'products' => tb_products::orderBy('product_name')->get(),
            'mode' => 'edit',
            'row' => $this->findScopedRow('tb_customer_receivables', $id),
        ]));
    }

    public function updateReceivable(Request $request, int $id)
    {
        $row = $this->findScopedRow('tb_customer_receivables', $id);
        $data = $this->validateReceivableData($request);
        $sameStockItem = (int) $row->store_id === (int) $data['store_id'] && (int) $row->product_id === (int) $data['product_id'];
        $available = $this->currentStock((int) $data['store_id'], (int) $data['product_id']) + ($sameStockItem ? (int) $row->quantity : 0);

        if ($available < (int) $data['quantity']) {
            return back()->withInput()->with('error', 'Stok tidak cukup. Stok tersedia: '.$available);
        }

        DB::transaction(function () use ($row, $data) {
            if ($row->sell_id) {
                tb_sell::where('id', $row->sell_id)->update([
                    'store_id' => $data['store_id'],
                    'date' => $data['date'],
                    'customer_id' => $data['customer_id'] ?? 0,
                    'updated_at' => now(),
                ]);
                tb_outgoing_goods::where('sell_id', $row->sell_id)->update($this->receivableOutgoingPayload($data, (int) $row->sell_id));
            }

            $paidAmount = min((float) $row->paid_amount, (float) $data['amount']);
            $status = $paidAmount <= 0 ? 'open' : ($paidAmount >= (float) $data['amount'] ? 'paid' : 'partial');

            DB::table('tb_customer_receivables')->where('id', $row->id)->update($this->withUpdateTimestamp([
                'date' => $data['date'],
                'store_id' => $data['store_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'amount' => $data['amount'],
                'paid_amount' => $paidAmount,
                'status' => $status,
                'description' => $data['description'] ?? null,
            ]));
        });

        return redirect()->route('accounting.receivables.index', ['store' => $data['store_id']])->with('success', 'Piutang berhasil diupdate.');
    }

    public function destroyReceivable(int $id)
    {
        $row = $this->findScopedRow('tb_customer_receivables', $id);
        DB::transaction(function () use ($row) {
            $this->deleteLedger('receivable_payment', (int) $row->id);
            if ($row->sell_id) {
                tb_outgoing_goods::where('sell_id', $row->sell_id)->delete();
                tb_sell::where('id', $row->sell_id)->delete();
            }
            DB::table('tb_customer_receivables')->where('id', $row->id)->delete();
        });

        return back()->with('success', 'Piutang berhasil dihapus dan stok dikembalikan.');
    }

    public function showReceivablePayment(int $id)
    {
        $row = DB::table('tb_customer_receivables as r')
            ->leftJoin('tb_customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('tb_products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('tb_stores as s', 's.id', '=', 'r.store_id')
            ->select('r.*', 'c.customer_name', 'p.product_name', 'p.product_code', 's.store_name')
            ->where('r.id', $id)
            ->first();
        if (!$row) abort(404);
        $this->requireStoreAccess((int) $row->store_id);

        return view('pages.admin.accounting.receivable-payment', [
            'accounts' => $this->activeAccounts(),
            'row' => $row,
        ]);
    }

    public function payReceivable(Request $request, int $id)
    {
        $data = $request->validate([
            'paid_amount' => 'required|numeric|min:0.01',
            'account_id' => 'nullable|integer|exists:tb_accounting_accounts,id',
        ]);

        $row = $this->findScopedRow('tb_customer_receivables', $id);
        if ($row->status === 'paid') return back()->with('error', 'Piutang sudah lunas.');

        $remaining = max(0, (float) $row->amount - (float) $row->paid_amount);
        $actualPaid = min($remaining, (float) $data['paid_amount']);
        if ($actualPaid <= 0) return back()->with('error', 'Tidak ada sisa piutang yang perlu dibayar.');

        $paid = min((float) $row->amount, (float) $row->paid_amount + $actualPaid);
        $status = $paid >= (float) $row->amount ? 'paid' : 'partial';

        DB::transaction(function () use ($row, $data, $paid, $status, $actualPaid) {
            DB::table('tb_customer_receivables')->where('id', $row->id)->update([
                'paid_amount' => $paid,
                'status' => $status,
                'updated_at' => now(),
            ]);
            $this->ledger(now('Asia/Jakarta')->toDateString(), (int) $row->store_id, $data['account_id'] ?? null, 'receivable_payment', (int) $row->id, 'in', $actualPaid, 'Pelunasan piutang');
        });

        return redirect()->route('accounting.receivables.index', ['store' => $row->store_id])->with('success', 'Pembayaran piutang disimpan.');
    }

    public function supplierDebts(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $totals = DB::table('tb_supplier_debts')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('COALESCE(SUM(budget_amount), 0) AS budget_amount')
            ->selectRaw('COALESCE(SUM(purchase_amount), 0) AS purchase_amount')
            ->selectRaw('COALESCE(SUM(debt_amount), 0) AS debt_amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) AS paid_amount')
            ->selectRaw('COALESCE(SUM(debt_amount - paid_amount), 0) AS remaining_amount')
            ->first();
        $rows = DB::table('tb_supplier_debts as d')
            ->leftJoin('tb_suppliers as sp', 'sp.id', '=', 'd.supplier_id')
            ->leftJoin('tb_stores as st', 'st.id', '=', 'd.store_id')
            ->select('d.*', 'sp.name as supplier_name', 'st.store_name')
            ->when($storeId, fn ($q) => $q->where('d.store_id', $storeId))
            ->orderByDesc('d.id')
            ->limit(200)
            ->get();

        return view('pages.admin.accounting.supplier-debts', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    public function createSupplierDebt()
    {
        return view('pages.admin.accounting.supplier-debt-form', $this->formData([
            'suppliers' => tb_suppliers::orderBy('name')->get(),
            'mode' => 'create',
            'row' => null,
        ]));
    }

    public function storeSupplierDebt(Request $request)
    {
        $entries = $this->validateSupplierDebtEntries($request);
        DB::transaction(function () use ($entries) {
            foreach ($entries as $entry) {
                DB::table('tb_supplier_debts')->insert($this->withAudit($this->supplierDebtPayload($entry)));
            }
        });

        return redirect()->route('accounting.supplier-debts.index', $this->bulkStoreFilter($entries))
            ->with('success', count($entries).' hutang supplier berhasil disimpan.');
    }

    public function editSupplierDebt(int $id)
    {
        return view('pages.admin.accounting.supplier-debt-form', $this->formData([
            'suppliers' => tb_suppliers::orderBy('name')->get(),
            'mode' => 'edit',
            'row' => $this->findScopedRow('tb_supplier_debts', $id),
        ]));
    }

    public function updateSupplierDebt(Request $request, int $id)
    {
        $row = $this->findScopedRow('tb_supplier_debts', $id);
        $data = $this->validateSupplierDebtData($request);
        $payload = $this->supplierDebtPayload($data, (float) $row->paid_amount);

        DB::table('tb_supplier_debts')->where('id', $row->id)->update($this->withUpdateTimestamp($payload));

        return redirect()->route('accounting.supplier-debts.index', ['store' => $data['store_id']])->with('success', 'Hutang supplier berhasil diupdate.');
    }

    public function destroySupplierDebt(int $id)
    {
        $row = $this->findScopedRow('tb_supplier_debts', $id);
        DB::transaction(function () use ($row) {
            $this->deleteLedger('supplier_debt_payment', (int) $row->id);
            DB::table('tb_supplier_debts')->where('id', $row->id)->delete();
        });

        return back()->with('success', 'Hutang supplier berhasil dihapus.');
    }

    public function showSupplierDebtPayment(int $id)
    {
        $row = DB::table('tb_supplier_debts as d')
            ->leftJoin('tb_suppliers as sp', 'sp.id', '=', 'd.supplier_id')
            ->leftJoin('tb_stores as st', 'st.id', '=', 'd.store_id')
            ->select('d.*', 'sp.name as supplier_name', 'st.store_name')
            ->where('d.id', $id)
            ->first();
        if (!$row) abort(404);
        $this->requireStoreAccess((int) $row->store_id);

        return view('pages.admin.accounting.supplier-debt-payment', [
            'accounts' => $this->activeAccounts(),
            'row' => $row,
        ]);
    }

    public function paySupplierDebt(Request $request, int $id)
    {
        $data = $request->validate([
            'paid_amount' => 'required|numeric|min:0.01',
            'account_id' => 'nullable|integer|exists:tb_accounting_accounts,id',
        ]);

        $row = $this->findScopedRow('tb_supplier_debts', $id);
        if ($row->status === 'paid') return back()->with('error', 'Hutang supplier sudah lunas.');

        $remaining = max(0, (float) $row->debt_amount - (float) $row->paid_amount);
        $actualPaid = min($remaining, (float) $data['paid_amount']);
        if ($actualPaid <= 0) return back()->with('error', 'Tidak ada sisa hutang yang perlu dibayar.');

        $paid = min((float) $row->debt_amount, (float) $row->paid_amount + $actualPaid);
        $status = $paid >= (float) $row->debt_amount ? 'paid' : 'partial';

        DB::transaction(function () use ($row, $data, $paid, $status, $actualPaid) {
            DB::table('tb_supplier_debts')->where('id', $row->id)->update([
                'paid_amount' => $paid,
                'status' => $status,
                'updated_at' => now(),
            ]);
            $this->ledger(now('Asia/Jakarta')->toDateString(), (int) $row->store_id, $data['account_id'] ?? null, 'supplier_debt_payment', (int) $row->id, 'out', $actualPaid, 'Pelunasan hutang supplier');
        });

        return redirect()->route('accounting.supplier-debts.index', ['store' => $row->store_id])->with('success', 'Pembayaran hutang supplier disimpan.');
    }

    public function cashOpname(Request $request)
    {
        $storeId = $this->selectedStoreId($request);
        $totalNominal = DB::table('tb_cash_opnames')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->sum('nominal');
        $rows = DB::table('tb_cash_opnames as c')
            ->leftJoin('tb_stores as s', 's.id', '=', 'c.store_id')
            ->select('c.*', 's.store_name')
            ->when($storeId, fn ($q) => $q->where('c.store_id', $storeId))
            ->orderByDesc('c.date')
            ->orderByDesc('c.id')
            ->limit(200)
            ->get();

        return view('pages.admin.accounting.cash-opname', [
            'stores' => $this->stores(),
            'selectedStoreId' => $storeId,
            'rows' => $rows,
            'totalNominal' => $totalNominal,
        ]);
    }

    public function createCashOpname()
    {
        return view('pages.admin.accounting.cash-opname-form', $this->formData([
            'mode' => 'create',
            'row' => null,
        ]));
    }

    public function storeCashOpname(Request $request)
    {
        $entries = $this->validateCashOpnameEntries($request);

        DB::transaction(function () use ($entries) {
            $rows = array_map(fn (array $entry) => $this->withAudit($entry), $entries);
            DB::table('tb_cash_opnames')->insert($rows);
        });

        $storeIds = array_values(array_unique(array_column($entries, 'store_id')));
        $routeParameters = count($storeIds) === 1 ? ['store' => $storeIds[0]] : [];

        return redirect()->route('accounting.cash-opname.index', $routeParameters)
            ->with('success', count($entries).' cash opname berhasil disimpan.');
    }

    public function editCashOpname(int $id)
    {
        return view('pages.admin.accounting.cash-opname-form', $this->formData([
            'mode' => 'edit',
            'row' => $this->findScopedRow('tb_cash_opnames', $id),
        ]));
    }

    public function updateCashOpname(Request $request, int $id)
    {
        $row = $this->findScopedRow('tb_cash_opnames', $id);
        $data = $this->validateCashOpnameData($request);
        DB::table('tb_cash_opnames')->where('id', $row->id)->update($this->withUpdateTimestamp($data));

        return redirect()->route('accounting.cash-opname.index', ['store' => $data['store_id']])->with('success', 'Cash opname berhasil diupdate.');
    }

    public function destroyCashOpname(int $id)
    {
        $row = $this->findScopedRow('tb_cash_opnames', $id);
        DB::table('tb_cash_opnames')->where('id', $row->id)->delete();

        return back()->with('success', 'Cash opname berhasil dihapus.');
    }

    public static function postSalesLedger(int $sellId): void
    {
        if (!Schema::hasTable('tb_accounting_entries') || !Schema::hasTable('tb_accounting_accounts')) {
            return;
        }

        $sell = DB::table('tb_sells')->where('id', $sellId)->first();
        if (!$sell || (str_starts_with((string) $sell->no_invoice, 'AR-') || str_starts_with((string) $sell->no_invoice, 'TRF-'))) {
            return;
        }

        $accountId = DB::table('tb_accounting_settings')->where('setting_key', 'sales_account_id')->value('account_id');
        (new self())->ledger($sell->date, (int) $sell->store_id, $accountId, 'sales', $sellId, 'in', $sell->total_price, 'Penjualan '.$sell->no_invoice);
    }

    private function validateMoneyData(Request $request): array
    {
        $data = $request->validate([
            'date' => 'required|date',
            'store_id' => 'required|integer|exists:tb_stores,id',
            'account_id' => 'nullable|integer|exists:tb_accounting_accounts,id',
            'category' => 'nullable|string|max:120',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);
        $this->requireStoreAccess((int) $data['store_id']);

        return $data;
    }

    private function validateMoneyEntries(Request $request): array
    {
        $data = $request->validate([
            'store_id' => 'required|integer|exists:tb_stores,id',
            'entries' => 'required|array|min:1|max:100',
            'entries.*.date' => 'required|date',
            'entries.*.account_id' => 'nullable|integer|exists:tb_accounting_accounts,id',
            'entries.*.category' => 'nullable|string|max:120',
            'entries.*.amount' => 'required|numeric|min:0',
            'entries.*.description' => 'nullable|string|max:255',
        ]);

        $this->requireStoreAccess((int) $data['store_id']);

        return array_map(fn (array $entry) => array_merge($entry, ['store_id' => (int) $data['store_id']]), $data['entries']);
    }

    private function validateReceivableData(Request $request): array
    {
        $data = $request->validate([
            'date' => 'required|date',
            'store_id' => 'required|integer|exists:tb_stores,id',
            'customer_id' => 'nullable|integer|exists:tb_customers,id',
            'product_id' => 'required|integer|exists:tb_products,id',
            'quantity' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);
        $this->requireStoreAccess((int) $data['store_id']);

        return $data;
    }

    private function validateReceivableEntries(Request $request): array
    {
        $data = $request->validate([
            'store_id' => 'required|integer|exists:tb_stores,id',
            'entries' => 'required|array|min:1|max:100',
            'entries.*.date' => 'required|date',
            'entries.*.customer_id' => 'nullable|integer|exists:tb_customers,id',
            'entries.*.product_id' => 'required|integer|exists:tb_products,id',
            'entries.*.quantity' => 'required|integer|min:1',
            'entries.*.amount' => 'required|numeric|min:0',
            'entries.*.description' => 'nullable|string|max:255',
        ]);

        $this->requireStoreAccess((int) $data['store_id']);

        return array_map(fn (array $entry) => array_merge($entry, ['store_id' => (int) $data['store_id']]), $data['entries']);
    }

    private function validateSupplierDebtData(Request $request): array
    {
        $data = $request->validate([
            'date' => 'required|date',
            'store_id' => 'required|integer|exists:tb_stores,id',
            'supplier_id' => 'nullable|integer|exists:tb_suppliers,id',
            'budget_amount' => 'required|numeric|min:0',
            'purchase_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);
        $this->requireStoreAccess((int) $data['store_id']);

        return $data;
    }

    private function validateSupplierDebtEntries(Request $request): array
    {
        $data = $request->validate([
            'store_id' => 'required|integer|exists:tb_stores,id',
            'entries' => 'required|array|min:1|max:100',
            'entries.*.date' => 'required|date',
            'entries.*.supplier_id' => 'nullable|integer|exists:tb_suppliers,id',
            'entries.*.budget_amount' => 'required|numeric|min:0',
            'entries.*.purchase_amount' => 'required|numeric|min:0',
            'entries.*.description' => 'nullable|string|max:255',
        ]);

        $this->requireStoreAccess((int) $data['store_id']);

        return array_map(fn (array $entry) => array_merge($entry, ['store_id' => (int) $data['store_id']]), $data['entries']);
    }

    private function validateCashOpnameData(Request $request): array
    {
        $data = $request->validate([
            'date' => 'required|date',
            'store_id' => 'required|integer|exists:tb_stores,id',
            'nominal' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);
        $this->requireStoreAccess((int) $data['store_id']);

        return $data;
    }

    private function validateCashOpnameEntries(Request $request): array
    {
        $data = $request->validate([
            'store_id' => 'required|integer|exists:tb_stores,id',
            'entries' => 'required|array|min:1|max:100',
            'entries.*.date' => 'required|date',
            'entries.*.nominal' => 'required|numeric|min:0',
            'entries.*.description' => 'nullable|string|max:255',
        ]);

        $this->requireStoreAccess((int) $data['store_id']);

        return array_map(fn (array $entry) => array_merge($entry, ['store_id' => (int) $data['store_id']]), $data['entries']);
    }

    private function supplierDebtPayload(array $data, float $paidAmount = 0): array
    {
        $debtAmount = max(0, (float) $data['purchase_amount'] - (float) $data['budget_amount']);
        $paidAmount = min($paidAmount, $debtAmount);

        return [
            'date' => $data['date'],
            'store_id' => $data['store_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'budget_amount' => $data['budget_amount'],
            'purchase_amount' => $data['purchase_amount'],
            'debt_amount' => $debtAmount,
            'paid_amount' => $paidAmount,
            'status' => $paidAmount <= 0 ? ($debtAmount > 0 ? 'open' : 'paid') : ($paidAmount >= $debtAmount ? 'paid' : 'partial'),
            'description' => $data['description'] ?? null,
        ];
    }

    private function receivableOutgoingPayload(array $data, int $sellId): array
    {
        $payload = [
            'product_id' => $data['product_id'],
            'sell_id' => $sellId,
            'date' => $data['date'],
            'quantity_out' => $data['quantity'],
            'discount' => 0,
            'recorded_by' => auth()->user()?->name ?? 'system',
            'description' => 'Piutang pelanggan',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('tb_outgoing_goods', 'store_id')) $payload['store_id'] = $data['store_id'];
        if (Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock')) $payload['is_pending_stock'] = 0;

        return $payload;
    }

    private function syncLedger($date, int $storeId, $accountId, string $sourceType, int $sourceId, string $direction, $amount, ?string $description): void
    {
        $this->deleteLedger($sourceType, $sourceId);
        $this->ledger($date, $storeId, $accountId, $sourceType, $sourceId, $direction, $amount, $description);
    }

    private function deleteLedger(string $sourceType, int $sourceId): void
    {
        if (!Schema::hasTable('tb_accounting_entries')) {
            return;
        }

        DB::table('tb_accounting_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }

    private function ledger($date, int $storeId, $accountId, string $sourceType, int $sourceId, string $direction, $amount, ?string $description): void
    {
        if (!Schema::hasTable('tb_accounting_entries')) {
            return;
        }

        $account = $accountId ? DB::table('tb_accounting_accounts')->where('id', $accountId)->first() : null;

        DB::table('tb_accounting_entries')->insert([
            'date' => $date,
            'store_id' => $storeId,
            'account_id' => $account?->id,
            'account_number' => $account?->account_number,
            'account_name' => $account?->account_name,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'direction' => $direction,
            'amount' => $amount,
            'description' => $description,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function activeAccounts()
    {
        return DB::table('tb_accounting_accounts')->where('is_active', 1)->orderBy('account_number')->get();
    }

    private function stores()
    {
        return store_access_list(auth()->user());
    }

    private function selectedStoreId(Request $request): ?int
    {
        return store_access_resolve_id($request, $request->user(), ['store']);
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'stores' => $this->stores(),
            'accounts' => $this->activeAccounts(),
        ], $extra);
    }

    private function findScopedRow(string $table, int $id)
    {
        $row = DB::table($table)->where('id', $id)->first();
        if (!$row) abort(404);
        if (property_exists($row, 'store_id') && $row->store_id) {
            $this->requireStoreAccess((int) $row->store_id);
        }

        return $row;
    }

    private function requireStoreAccess(int $storeId): void
    {
        $allowed = store_access_ids(auth()->user());
        if (!in_array($storeId, $allowed, true)) {
            abort(403, 'Toko tidak ada dalam akses user.');
        }
    }

    private function withAudit(array $data): array
    {
        $data['created_by'] = auth()->id();
        return $this->withTimestamps($data);
    }

    private function withTimestamps(array $data): array
    {
        $data['created_at'] = now();
        $data['updated_at'] = now();
        return $data;
    }

    private function bulkStoreFilter(array $entries): array
    {
        $storeIds = array_values(array_unique(array_map('intval', array_column($entries, 'store_id'))));
        return count($storeIds) === 1 ? ['store' => $storeIds[0]] : [];
    }

    private function withUpdateTimestamp(array $data): array
    {
        $data['updated_at'] = now();
        return $data;
    }

    private function currentStock(int $storeId, int $productId): int
    {
        $incoming = DB::table('tb_incoming_goods as ig')
            ->when(Schema::hasColumn('tb_incoming_goods', 'deleted_at'), fn ($q) => $q->whereNull('ig.deleted_at'))
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'store_id'),
                fn ($q) => $q->where('ig.store_id', $storeId),
                fn ($q) => $q->join('tb_purchases as p', 'p.id', '=', 'ig.purchase_id')->where('p.store_id', $storeId)
            )
            ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('ig.is_pending_stock')
                       ->orWhere('ig.is_pending_stock', 0);
                });
            })
            ->where('ig.product_id', $productId)
            ->sum('ig.stock');

        $outgoing = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('og.is_pending_stock')
                       ->orWhere('og.is_pending_stock', 0);
                });
            })
            ->where('s.store_id', $storeId)
            ->where('og.product_id', $productId)
            ->sum('og.quantity_out');

        return max(0, (int) $incoming - (int) $outgoing);
    }
}
