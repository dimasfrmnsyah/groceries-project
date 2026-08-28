<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Syncable;
use Illuminate\Database\Eloquent\SoftDeletes;
class tb_daily_revenues extends Model
{
    use HasFactory,Syncable,SoftDeletes;

    protected $table = 'daily_revenues';

    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'amount',
        'qr',
        'pengeluaran',
        'denominations',
        'uuid'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'qr' => 'decimal:2',
        'pengeluaran' => 'decimal:2',
        'denominations' => 'array',
    ];
    
    // Relasi ke user jika dibutuhkan
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
