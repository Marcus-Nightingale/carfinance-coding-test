<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderTest extends TestCase
{
    private function seedOrder(string $customerName = 'John Doe', string $customerEmail = 'john@example.com'): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name'  => $customerName,
            'email' => $customerEmail,
            'phone' => '555-0100',
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'customer_id' => $customerId,
            'status'      => 'pending',
            'total'       => 99.99,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            DB::table('order_items')->insert([
                'order_id'     => $orderId,
                'product_name' => "Product {$i}",
                'sku'          => "SKU-{$i}",
                'quantity'     => $i,
                'unit_price'   => 10.00 * $i,
            ]);
        }

        return $orderId;
    }

    public function test_orders_endpoint_returns_list(): void
    {
        $this->seedOrder();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'status',
                    'total',
                    'customer' => ['id', 'name', 'email'],
                    'items'    => [
                        '*' => ['id', 'product_name', 'sku', 'quantity', 'unit_price'],
                    ],
                ],
            ],
        ]);
    }

    public function test_orders_endpoint_returns_empty_list_when_no_orders(): void
    {
        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
    }

    public function test_order_includes_correct_customer(): void
    {
        $this->seedOrder('Jane Doe', 'jane@example.com');

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.customer.name', 'Jane Doe');
        $response->assertJsonPath('data.0.customer.email', 'jane@example.com');
    }
}
