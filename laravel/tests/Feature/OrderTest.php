<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_endpoint_returns_list(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create();
        OrderItem::factory(3)->for($order)->create();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'status',
                'total',
                'customer' => ['id', 'name', 'email'],
                'items'    => [
                    '*' => ['id', 'product_name', 'sku', 'quantity', 'unit_price'],
                ],
            ],
        ]);
    }

    public function test_orders_endpoint_returns_empty_list_when_no_orders(): void
    {
        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    public function test_order_includes_correct_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        Order::factory()->for($customer)->create();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('0.customer.name', 'Jane Doe');
        $response->assertJsonPath('0.customer.email', 'jane@example.com');
    }
}
