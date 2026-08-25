<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_products extends Model
{
    use HasFactory;

    protected $table = 'tb_products';

    protected $fillable = [
        'product_code',
        'product_name',
        'type_id',
        'brand_id',
        'unit_id',
        'purchase_price',
        'selling_price',
        'product_discount',
        'description',
        'is_active',
        'uuid',
        'tier_prices',
    ];

    protected $casts = [
        'tier_prices' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where($this->getTable().'.is_active', true);
    }

    public function type()
    {
        return $this->belongsTo(tb_types::class, 'type_id');
    }

    public function brand()
    {
        return $this->belongsTo(tb_brands::class, 'brand_id');
    }

    public function unit()
    {
        return $this->belongsTo(tb_units::class, 'unit_id');
    }

    public function incomingGoods()
    {
        return $this->hasMany(tb_incoming_goods::class, 'product_id');
    }

    public function outgoingGoods()
    {
        return $this->hasMany(tb_outgoing_goods::class, 'product_id');
    }
    public function storePrices()
    {
        return $this->hasMany(tb_product_store_price::class, 'product_id');
    }

    /**
     * Resolve harga per toko dengan fallback ke harga dasar produk.
     */
    public function priceForStore(?int $storeId): array
    {
        $override = $storeId
            ? $this->storePrices
                ->loadMissing('store')
                ->firstWhere('store_id', $storeId)
            : null;

        return [
            'purchase_price'   => $override->purchase_price ?? $this->purchase_price,
            'selling_price'    => $override->selling_price ?? $this->selling_price,
            'product_discount' => $override->product_discount ?? $this->product_discount,
            'tier_prices'      => $override->tier_prices ?? $this->tier_prices,
        ];
    }

    public function unitPriceForQty(int $qty, ?int $storeId = null): float
    {
        $priceSet = $this->priceForStore($storeId);
        $base = (float) ($priceSet['selling_price'] ?? 0);

        $tiers = collect($priceSet['tier_prices'] ?? [])
            ->mapWithKeys(fn($price, $q) => [(int)$q => (float)$price])
            ->sortKeys();

        $eligible = $tiers->filter(fn($price, $q) => $qty >= $q);

        return $eligible->isNotEmpty() ? (float)$eligible->last() : $base;
    }
}
