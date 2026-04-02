<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController extends Controller
{
    public function index(Request $request): Response
    {
        $flaggedOnly = $request->boolean('flagged', true);

        $query = ComplianceEvent::query()
            ->orderBy('created_at', 'desc');

        if ($flaggedOnly) {
            $query->where('routed_to_ai', true);
        }

        $events = $query->paginate(25)->through(fn ($e) => [
            'id' => $e->id,
            'source_id' => $e->source_id,
            'status' => $e->status,
            'metric_value' => $e->metric_value,
            'anomaly_score' => $e->anomaly_score,
            'routed_to_ai' => $e->routed_to_ai,
            'audit_narrative' => $e->audit_narrative,
            'driver_used' => $e->driver_used,
            'emitted_at' => $e->emitted_at?->toISOString(),
            'created_at' => $e->created_at->toISOString(),
        ]);

        return Inertia::render('Compliance', [
            'user' => [
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
            ],
            'events' => $events,
            'flaggedOnly' => $flaggedOnly,
        ]);
    }
}
