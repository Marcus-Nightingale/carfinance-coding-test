<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read int $id
 * @property-read Order $order
 *
 * @property int $order_id
 * @property string $product_name
 * @property string $sku
 * @property int $quantity
 * @property float $unit_price
 *
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'product_name', 'sku', 'quantity', 'unit_price'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
