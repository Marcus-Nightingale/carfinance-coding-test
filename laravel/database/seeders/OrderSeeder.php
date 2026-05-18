<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        // 50 customers × 20 orders × 5 items = 5,000 rows
        // Enough volume that N+1 queries are measurably slow
        Customer::factory(50)->create()->each(function (Customer $customer) {
            Order::factory(20)
                ->for($customer)
                ->create()
                ->each(function (Order $order) {
                    OrderItem::factory(5)->for($order)->create();
                });
        });
    }
}
