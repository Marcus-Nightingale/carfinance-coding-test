<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_received_and_stored(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_001',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000, 'currency' => 'usd'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_001']);
    }

    public function test_webhook_requires_event_id(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'type'    => 'payment.succeeded',
            'payload' => ['amount' => 1000],
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_requires_payload(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_002',
            'type'     => 'payment.succeeded',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_status_can_be_retrieved(): void
    {
        $event = WebhookEvent::create([
            'event_id' => 'evt_003',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => 'completed',
        ]);

        $response = $this->getJson("/api/webhooks/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'event_id' => 'evt_003',
            'status'   => 'completed',
        ]);
    }

    public function test_webhook_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/webhooks/99999');

        $response->assertStatus(404);
    }
}
