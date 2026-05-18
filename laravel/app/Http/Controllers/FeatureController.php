<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class FeatureController extends Controller
{
    public function index(): JsonResponse
    {
        $features = Feature::all()->map(fn ($f) => [
            'id'          => $f->id,
            'name'        => $f->name,
            'key'         => $f->key,
            'enabled'     => $f->enabled,
            'description' => $f->description,
        ]);

        return response()->json($features);
    }

    public function assign(int $featureId, int $userId): JsonResponse
    {
        $feature = Feature::findOrFail($featureId);
        $user = User::findOrFail($userId);

        // TODO: How should feature access be centralized?
        // Duplicate check: this logic also appears in FeatureEnabled middleware
        if (! $feature->enabled) {
            return response()->json(['message' => 'Feature is globally disabled.'], 403);
        }

        $feature->users()->syncWithoutDetaching([$user->id]);

        return response()->json(['message' => 'Feature assigned.']);
    }

    public function beta(): JsonResponse
    {
        return response()->json(['message' => 'Welcome to the beta!']);
    }
}
