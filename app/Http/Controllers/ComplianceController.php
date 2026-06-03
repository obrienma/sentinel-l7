<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $query = ComplianceEvent::query()->orderBy('created_at', 'desc');

        if ($request->boolean('flagged', true)) {
            $query->where('routed_to_ai', true);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $filename = 'compliance-events-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id', 'source_id', 'domain', 'status', 'metric_value',
                'anomaly_score', 'routed_to_ai', 'risk_level', 'driver_used',
                'audit_narrative', 'emitted_at', 'created_at',
            ]);

            $query->chunk(500, function ($events) use ($handle) {
                foreach ($events as $e) {
                    fputcsv($handle, [
                        $e->id,
                        $e->source_id,
                        $e->domain,
                        $e->status,
                        $e->metric_value,
                        $e->anomaly_score,
                        $e->routed_to_ai ? 'true' : 'false',
                        $e->risk_level ?? '',
                        $e->driver_used,
                        $e->audit_narrative,
                        $e->emitted_at?->toISOString(),
                        $e->created_at->toISOString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

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
