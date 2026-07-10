<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    /**
     * GET /usage (ADR-0029) — dual per-pipeline cursor pull for Ledger-L5.
     *
     * Rows are returned as-is; billing classification (ADR-0028) is applied
     * client-side, not filtered here. Excludes rows younger than the
     * configured safety-lag window so an in-flight transaction has time to
     * commit before a cursor can advance past it (bounded, not eliminated —
     * see ADR-0029).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'since_transactions' => ['nullable', 'integer', 'min:0'],
            'since_compliance_events' => ['nullable', 'integer', 'min:0'],
        ]);

        $sinceTransactions = (int) $request->input('since_transactions', 0);
        $sinceComplianceEvents = (int) $request->input('since_compliance_events', 0);
        $pageSize = (int) config('sentinel.usage.page_size');
        $cutoff = now()->subSeconds((int) config('sentinel.usage.safety_lag_seconds'));

        $transactions = Transaction::where('id', '>', $sinceTransactions)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($pageSize)
            ->get();

        $complianceEvents = ComplianceEvent::where('id', '>', $sinceComplianceEvents)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($pageSize)
            ->get();

        return response()->json([
            'transactions' => $transactions->map(fn (Transaction $t) => [
                'id' => $t->id,
                'txn_id' => $t->txn_id,
                'merchant' => $t->merchant,
                'amount' => $t->amount,
                'currency' => $t->currency,
                'is_threat' => $t->is_threat,
                'message' => $t->message,
                'source' => $t->source,
                'created_at' => $t->created_at->toIso8601String(),
            ])->values(),
            'compliance_events' => $complianceEvents->map(fn (ComplianceEvent $e) => [
                'id' => $e->id,
                'source_id' => $e->source_id,
                'domain' => $e->domain,
                'status' => $e->status,
                'metric_value' => $e->metric_value,
                'anomaly_score' => $e->anomaly_score,
                'emitted_at' => $e->emitted_at?->toIso8601String(),
                'routed_to_ai' => $e->routed_to_ai,
                'audit_narrative' => $e->audit_narrative,
                'driver_used' => $e->driver_used,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
            'next_cursor' => [
                'since_transactions' => $transactions->max('id') ?? $sinceTransactions,
                'since_compliance_events' => $complianceEvents->max('id') ?? $sinceComplianceEvents,
            ],
        ]);
    }
}
