<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id'     => Order::factory(),
            'product_name' => $this->faker->words(3, true),
            'sku'          => strtoupper($this->faker->bothify('??-####')),
            'quantity'     => $this->faker->numberBetween(1, 10),
            'unit_price'   => $this->faker->randomFloat(2, 5, 200),
        ];
    }
}
