<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total' => $this->total,
            'customer' => new CustomerResource(
                $this->whenLoaded('customer')
            ),
            'items' => OrderItemResource::collection(
                $this->whenLoaded('items')
            ),
        ];
    }
}
