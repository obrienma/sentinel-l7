<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use Inertia\Inertia;

class EventsController extends Controller
{
    public function index()
    {
        $filter    = request('filter', 'flagged');
        $flaggedOnly = $filter !== 'all';

        $events = ComplianceEvent::query()
            ->when($flaggedOnly, fn ($q) => $q->where('routed_to_ai', true))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Events', [
            'events' => $events,
            'filter' => $filter,
        ]);
    }
}
