<?php

namespace App\Http\Middleware;

use App\Models\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        // TODO: Is middleware the best place for this logic?
        // TODO: How should feature access be centralized?

        // BUG: This check ignores the global `enabled` flag on the feature.
        // A globally-disabled feature is still accessible if the user is assigned.
        $userId = $request->query('user_id');

        $feature = Feature::where('key', $featureKey)->first();

        if (! $feature) {
            abort(404, 'Feature not found.');
        }

        if (! $userId) {
            abort(403, 'No user provided.');
        }

        $hasAccess = $feature->users()->where('user_id', $userId)->exists();

        if (! $hasAccess) {
            abort(403, 'Feature not enabled for this user.');
        }

        return $next($request);
    }
}
