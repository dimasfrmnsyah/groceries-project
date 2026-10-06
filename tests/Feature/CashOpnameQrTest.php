<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountingController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashOpnameQrTest extends TestCase
{
    public function test_qr_is_added_to_cash_total_and_difference_and_defaults_to_zero(): void
    {
        config(['database.default' => 'qr_test', 'database.connections.qr_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::statement('CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, store_id INTEGER, date TEXT, total_price NUMERIC)');
        DB::statement('CREATE TABLE tb_outgoing_goods (sell_id INTEGER, quantity_out INTEGER, recorded_by TEXT)');
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'date' => '2026-10-06', 'total_price' => 150000]);
        DB::table('tb_outgoing_goods')->insert(['sell_id' => 1, 'quantity_out' => 1, 'recorded_by' => 'Kasir']);
        $controller = app(AccountingController::class);
        $options = (new \ReflectionMethod($controller, 'cashDenominations'))->invoke($controller);
        $key = array_key_first($options);
        $cash = $options[$key]['value'] * 2;
        $data = ['store_id' => 1, 'cashier_name' => 'Kasir', 'audited_at' => '2026-10-06 12:00:00', 'denominations' => [$key => 2], 'qr_amount' => 50000];
        $method = new \ReflectionMethod($controller, 'cashOpnamePayload');
        $payload = $method->invoke($controller, $data);
        $this->assertEquals(50000, $payload['qr_amount']);
        $this->assertEquals($cash + 50000, $payload['nominal']);
        $this->assertEquals($cash + 50000 - 150000, $payload['difference']);
        unset($data['qr_amount']);
        $payload = $method->invoke($controller, $data);
        $this->assertEquals(0, $payload['qr_amount']);
        $this->assertEquals($cash, $payload['nominal']);
    }
}
