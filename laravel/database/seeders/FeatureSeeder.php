<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $betaFeature = Feature::create([
            'name'        => 'Beta Access',
            'key'         => 'beta',
            'enabled'     => true,
            'description' => 'Access to the beta dashboard.',
        ]);

        Feature::create([
            'name'        => 'Dark Mode',
            'key'         => 'dark-mode',
            'enabled'     => false,
            'description' => 'Experimental dark mode UI.',
        ]);

        $betaUser = User::create([
            'name'     => 'Beta User',
            'email'    => 'beta@example.com',
            'password' => Hash::make('password'),
        ]);

        User::create([
            'name'     => 'Regular User',
            'email'    => 'regular@example.com',
            'password' => Hash::make('password'),
        ]);

        $betaFeature->users()->attach($betaUser->id);
    }
}
