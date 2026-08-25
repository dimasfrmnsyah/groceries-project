<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\tb_daily_revenues;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StaffController extends Controller
{
    private const CASHIER_ROLES = ['staff', 'kasir', 'cashier'];

    public function checkDailyRevenue(Request $request)
    {
        $user = $request->user();
        $date = Carbon::now('Asia/Jakarta')->toDateString();
        $locked = $this->isRevenueLocked($user);
        $expected = $this->salesTotalForUser($user, $date);
        $entered = $request->input('amount');
        $hasEnteredAmount = $entered !== null && $entered !== '';

        return response()->json([
            'date' => $date,
            'locked' => $locked,
            'matches' => !$locked || ($hasEnteredAmount && $this->moneyEquals($entered, $expected)),
            'exists' => tb_daily_revenues::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->exists(),
        ]);
    }

    public function submitRevenueAndLogout(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $date = Carbon::now('Asia/Jakarta')->toDateString();

        if ($this->isRevenueLocked($user)) {
            $expected = $this->salesTotalForUser($user, $date);
            if (!$this->moneyEquals($data['amount'], $expected)) {
                return back()
                    ->withInput()
                    ->with('revenue_error', 'Pendapatan tidak sesuai dengan total penjualan sistem hari ini.');
            }
        }

        tb_daily_revenues::create([
            'user_id' => $user->id,
            'date' => $date,
            'amount' => $data['amount'],
        ]);

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', 'Berhasil logout dan mencatat pendapatan hari ini.');
    }

    private function isRevenueLocked(?User $user): bool
    {
        if (!$user || !in_array(strtolower((string) $user->roles), self::CASHIER_ROLES, true)) {
            return false;
        }

        return Schema::hasColumn('users', 'is_lock') && (bool) $user->is_lock;
    }

    private function salesTotalForUser(?User $user, string $date): float
    {
        if (!$user || !Schema::hasTable('tb_sells') || !Schema::hasTable('tb_outgoing_goods')) {
            throw new \RuntimeException('Data penjualan belum tersedia untuk memvalidasi pendapatan kasir.');
        }

        $hasCreatedBy = Schema::hasColumn('tb_sells', 'created_by');
        $hasSellDeleted = Schema::hasColumn('tb_sells', 'deleted_at');
        $hasOutgoingDeleted = Schema::hasColumn('tb_outgoing_goods', 'deleted_at');
        $hasOutgoingCreatedBy = Schema::hasColumn('tb_outgoing_goods', 'created_by');
        $hasInvoice = Schema::hasColumn('tb_sells', 'no_invoice');

        $query = DB::table('tb_sells as s')
            ->whereDate('s.date', $date)
            ->when($hasSellDeleted, fn ($q) => $q->whereNull('s.deleted_at'))
            ->when($user->store_id, fn ($q) => $q->where('s.store_id', $user->store_id));

        // Data baru memakai tb_sells.created_by. Data lama dilacak dari
        // nama kasir pada movement outgoing agar tetap ikut tervalidasi.
        $legacyCashierName = strtolower(trim((string) $user->name));
        $query->where(function ($q) use ($hasCreatedBy, $user, $legacyCashierName, $hasOutgoingDeleted, $hasOutgoingCreatedBy) {
            if ($hasCreatedBy) {
                $q->where('s.created_by', $user->id);
            }

            $legacy = function ($legacyQuery) use ($legacyCashierName, $user, $hasOutgoingDeleted, $hasOutgoingCreatedBy) {
                $legacyQuery->from('tb_outgoing_goods as og')
                    ->whereColumn('og.sell_id', 's.id')
                    ->where(function ($identity) use ($legacyCashierName, $user, $hasOutgoingCreatedBy) {
                        if ($hasOutgoingCreatedBy) {
                            $identity->where('og.created_by', $user->id)
                                ->orWhereRaw('LOWER(TRIM(COALESCE(og.recorded_by, ""))) = ?', [$legacyCashierName]);
                        } else {
                            $identity->whereRaw('LOWER(TRIM(COALESCE(og.recorded_by, ""))) = ?', [$legacyCashierName]);
                        }
                    })
                    ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('og.deleted_at'));
            };

            if ($hasCreatedBy) {
                $q->orWhereExists($legacy);
            } else {
                $q->whereExists($legacy);
            }
        });

        if ($hasInvoice) {
            $query->where(function ($q) {
                $q->whereNull('s.no_invoice')
                    ->orWhere(function ($normal) {
                        $normal->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                            ->where('s.no_invoice', 'not like', 'AR-%')
                            ->where('s.no_invoice', 'not like', 'TRF-%');
                    });
            });
        }

        return (float) $query->sum('s.total_price');
    }

    private function moneyEquals($entered, float $expected): bool
    {
        $enteredText = trim((string) $entered);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $enteredText)) {
            return false;
        }

        return number_format((float) $enteredText, 2, '.', '')
            === number_format($expected, 2, '.', '');
    }
}
