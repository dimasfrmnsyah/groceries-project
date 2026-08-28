<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\tb_daily_revenues;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\CashDenominations;
use App\Support\StockLedger;

class StaffController extends Controller
{
    private const CASHIER_ROLES = ['staff', 'kasir', 'cashier'];

    public function checkDailyRevenue(Request $request)
    {
        $user = $request->user();
        $this->ensureCashier($user);
        $date = Carbon::now('Asia/Jakarta')->toDateString();
        $locked = $this->isRevenueLocked($user);
        $expected = $locked ? $this->salesTotalForUser($user, $date) : null;
        $entered = $request->input('amount');
        $hasEnteredAmount = $entered !== null && $entered !== '';

        return response()->json([
            'date' => $date,
            'locked' => $locked,
            'matches' => !$locked || ($hasEnteredAmount && $this->moneyEquals($entered, (float) $expected)),
            'exists' => tb_daily_revenues::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->exists(),
        ]);
    }

    public function submitRevenueAndLogout(Request $request)
    {
        $user = $request->user();
        $this->ensureCashier($user);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'denominations_payload' => ['nullable', 'json'],
            'denominations' => ['nullable', 'array'],
        ]);

        $date = Carbon::now('Asia/Jakarta')->toDateString();
        $hasDenominationInput = trim((string) ($data['denominations_payload'] ?? '')) !== ''
            || is_array($data['denominations'] ?? null);
        if (!$hasDenominationInput && trim((string) ($data['amount'] ?? '')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'Pendapatan wajib diisi.',
            ]);
        }
        $denominations = $hasDenominationInput
            ? $this->validateDenominations(
                $data['denominations_payload'] ?? null,
                $data['denominations'] ?? null
            )
            : null;
        // Kompatibilitas browser lama: sebelum rincian pecahan tersedia,
        // nominal lama tetap boleh dipakai. Browser baru selalu mengirim
        // denominations sehingga nominal dihitung ulang di server.
        $calculatedAmount = $denominations !== null
            ? $this->denominationsTotal($denominations)
            : (float) ($data['amount'] ?? 0);

        if ($this->isRevenueLocked($user)) {
            $expected = $this->salesTotalForUser($user, $date);
            if (!$this->moneyEquals($calculatedAmount, $expected)) {
                return back()
                    ->withInput()
                    ->with('revenue_error', 'Pendapatan belum sesuai. Periksa kembali rincian uang yang diinput.');
            }
        }

        $revenuePayload = [
            'user_id' => $user->id,
            'date' => $date,
            'amount' => $calculatedAmount,
        ];
        if (Schema::hasColumn('daily_revenues', 'store_id')) {
            $revenuePayload['store_id'] = $this->userStoreId($user);
        }
        if ($denominations !== null && Schema::hasColumn('daily_revenues', 'denominations')) {
            $revenuePayload['denominations'] = $denominations;
        }
        tb_daily_revenues::create($revenuePayload);

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

    private function ensureCashier(?User $user): void
    {
        abort_unless(
            $user && in_array(strtolower(trim((string) $user->roles)), self::CASHIER_ROLES, true),
            403,
            'Fitur ini hanya tersedia untuk akun kasir/staff.'
        );
    }

    private function userStoreId(User $user): ?int
    {
        $storeId = (int) ($user->store_id ?? 0);
        if ($storeId > 0) {
            return $storeId;
        }

        $fallback = collect(store_access_ids($user))->map(fn ($id) => (int) $id)->first();
        return $fallback > 0 ? $fallback : null;
    }

    private function validateDenominations(?string $payload, ?array $fallback = null): array
    {
        $submitted = trim((string) $payload) !== ''
            ? json_decode((string) $payload, true)
            : $fallback;
        if (!is_array($submitted)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'denominations_payload' => 'Rincian pecahan uang tidak valid.',
            ]);
        }

        $options = CashDenominations::all();
        $unknownKeys = array_diff(array_keys($submitted), array_keys($options));
        if ($unknownKeys) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'denominations_payload' => 'Terdapat pecahan uang yang tidak dikenali.',
            ]);
        }

        $rules = [];
        foreach (array_keys($options) as $key) {
            $rules[$key] = ['present', 'integer', 'min:0', 'max:100000'];
        }

        return validator($submitted, $rules)->validate();
    }

    private function denominationsTotal(array $denominations): int
    {
        $total = 0;
        foreach (CashDenominations::all() as $key => $option) {
            $total += (int) ($denominations[$key] ?? 0) * (int) $option['value'];
        }

        return $total;
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
            ->when($this->userStoreId($user), fn ($q, $storeId) => $q->where('s.store_id', $storeId));

        // Hanya nota yang benar-benar memiliki movement stok normal yang
        // dihitung sebagai penjualan kasir. Header yatim/tidak lengkap tidak
        // boleh membuat nominal logout salah.
        $query->whereExists(function ($movement) use ($hasOutgoingDeleted) {
            $movement->from('tb_outgoing_goods as og')
                ->whereColumn('og.sell_id', 's.id')
                ->whereBetween('og.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
                ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('og.deleted_at'));
        });

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
