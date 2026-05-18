<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status'      => $this->faker->randomElement(['pending', 'paid', 'shipped', 'cancelled']),
            'total'       => $this->faker->randomFloat(2, 10, 500),
        ];
    }
}
