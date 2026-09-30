<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderPerformanceTest extends TestCase
{
    private function seedOrders(int $orderCount, int $itemsPerOrder = 2): void
    {
        for ($o = 1; $o <= $orderCount; $o++) {
            $customerId = DB::table('customers')->insertGetId([
                'name'  => "Customer {$o}",
                'email' => "customer{$o}@example.com",
                'phone' => '555-0100',
            ]);

            $orderId = DB::table('orders')->insertGetId([
                'customer_id' => $customerId,
                'status'      => 'pending',
                'total'       => 100.00,
            ]);

            for ($i = 1; $i <= $itemsPerOrder; $i++) {
                DB::table('order_items')->insert([
                    'order_id'     => $orderId,
                    'product_name' => "Product {$i}",
                    'sku'          => "SKU-{$i}",
                    'quantity'     => $i,
                    'unit_price'   => 10.00,
                ]);
            }
        }
    }

    public function test_orders_endpoint_avoids_n_plus_one_queries(): void
    {
        $this->seedOrders(10, 2);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/orders');

        $response->assertOk();

        // Eager-loaded + paginated endpoint must stay flat
        // (count + orders + customers + items), not ~21 queries.
        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        $this->assertLessThanOrEqual(
            6,
            $queryCount,
            'N+1 detected on GET /api/orders: '.$queryCount.' queries for 10 orders: '
            .json_encode(array_column($queries, 'query'))
        );
    }

    public function test_orders_endpoint_paginates_to_50_per_page_with_resource_shape(): void
    {
        $this->seedOrders(60, 2);

        $pageOne = $this->getJson('/api/orders');
        $pageOne->assertOk();
        $pageOne->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'status',
                    'total',
                    'customer' => ['id', 'name', 'email'],
                    'items' => [
                        '*' => ['id', 'product_name', 'sku', 'quantity', 'unit_price'],
                    ],
                ],
            ],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
        $pageOne->assertJsonCount(50, 'data');
        $pageOne->assertJsonPath('meta.per_page', 50);
        $pageOne->assertJsonPath('meta.total', 60);

        $pageTwo = $this->getJson('/api/orders?page=2');
        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(10, 'data');
        $pageTwo->assertJsonPath('meta.current_page', 2);
    }

    public function test_supporting_indexes_exist_for_orders_index_endpoint(): void
    {
        $this->assertTrue(Schema::hasIndex('orders', ['customer_id']));
        $this->assertTrue(Schema::hasIndex('order_items', ['order_id']));
    }
}
