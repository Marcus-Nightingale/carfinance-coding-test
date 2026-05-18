<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function receive(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string',
            'type'     => 'required|string',
            'payload'  => 'required|array',
        ]);

        // TODO: How should duplicate events be handled?
        // TODO: What happens if two requests arrive simultaneously with the same event_id?
        $event = WebhookEvent::create([
            'event_id' => $request->input('event_id'),
            'provider' => $provider,
            'type'     => $request->input('type'),
            'payload'  => $request->input('payload'),
            'status'   => 'pending',
        ]);

        ProcessWebhookJob::dispatch($event);

        return response()->json([
            'id'     => $event->id,
            'status' => $event->status,
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $event = WebhookEvent::findOrFail($id);

        return response()->json([
            'id'            => $event->id,
            'event_id'      => $event->event_id,
            'provider'      => $event->provider,
            'type'          => $event->type,
            'status'        => $event->status,
            'error_message' => $event->error_message,
            'created_at'    => $event->created_at,
        ]);
    }
}
