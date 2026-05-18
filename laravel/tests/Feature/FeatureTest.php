<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_features_list_is_returned(): void
    {
        Feature::create(['name' => 'Beta', 'key' => 'beta', 'enabled' => true]);
        Feature::create(['name' => 'Dark Mode', 'key' => 'dark-mode', 'enabled' => false]);

        $response = $this->getJson('/api/features');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonStructure([
            '*' => ['id', 'name', 'key', 'enabled'],
        ]);
    }

    public function test_feature_can_be_assigned_to_user(): void
    {
        $feature = Feature::create(['name' => 'Beta', 'key' => 'beta', 'enabled' => true]);
        $user = User::factory()->create();

        $response = $this->postJson("/api/features/{$feature->id}/assign/{$user->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('feature_user', [
            'feature_id' => $feature->id,
            'user_id'    => $user->id,
        ]);
    }

    public function test_beta_route_is_accessible_when_user_has_feature(): void
    {
        $feature = Feature::create(['name' => 'Beta', 'key' => 'beta', 'enabled' => true]);
        $user = User::factory()->create();
        $feature->users()->attach($user->id);

        $response = $this->getJson("/api/beta?user_id={$user->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Welcome to the beta!']);
    }

    public function test_beta_route_is_blocked_when_user_lacks_feature(): void
    {
        Feature::create(['name' => 'Beta', 'key' => 'beta', 'enabled' => true]);
        $user = User::factory()->create();

        $response = $this->getJson("/api/beta?user_id={$user->id}");

        $response->assertStatus(403);
    }

    public function test_beta_route_is_blocked_with_no_user(): void
    {
        Feature::create(['name' => 'Beta', 'key' => 'beta', 'enabled' => true]);

        $response = $this->getJson('/api/beta');

        $response->assertStatus(403);
    }
}
