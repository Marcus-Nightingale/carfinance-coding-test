<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        // TODO: This endpoint becomes slow with many orders. Investigate query count.
        $orders = Order::all();

        $result = [];

        foreach ($orders as $order) {
            $result[] = [
                'id'       => $order->id,
                'status'   => $order->status,
                'total'    => $order->total,
                'customer' => [
                    'id'    => $order->customer->id,
                    'name'  => $order->customer->name,
                    'email' => $order->customer->email,
                ],
                'items' => $order->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_name' => $item->product_name,
                    'sku'          => $item->sku,
                    'quantity'     => $item->quantity,
                    'unit_price'   => $item->unit_price,
                ]),
            ];
        }

        return response()->json($result);
    }
}
