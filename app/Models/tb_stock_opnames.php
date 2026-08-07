<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Syncable;
use Illuminate\Database\Eloquent\SoftDeletes;
class tb_stock_opnames extends Model
{
    use HasFactory,Syncable,SoftDeletes;

    protected $table = 'tb_stock_opnames';

    protected $fillable = [
        'product_id',
        'store_id',
        'physical_quantity',
        'system_quantity',
        'created_by',
        'uuid'
    ];
}
