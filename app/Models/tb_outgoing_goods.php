<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Syncable;
use Illuminate\Database\Eloquent\SoftDeletes;
class tb_outgoing_goods extends Model
{
    use HasFactory,Syncable,SoftDeletes;

    protected $fillable = [
        'product_id',
        'sell_id',
        'store_id',
        'date',
        'quantity_out',
        'discount',
        'recorded_by',
        'created_by',
        'description',
        'source_type',
        'uuid',
        'is_pending_stock',
        'synced_at',
    ];


    public function product()
    {
        return $this->belongsTo(tb_products::class, 'product_id');
    }

    public function sell()
    {
        return $this->belongsTo(tb_sell::class, 'sell_id');
    }
}
