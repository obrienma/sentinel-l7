import { useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/components/AppLayout';

export default function Compliance({ user, events, flaggedOnly }) {
    // Auto-refresh the event list every 5 seconds.
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['events'] });
        }, 5000);
        return () => clearInterval(id);
    }, []);

    function toggleFilter() {
        router.get('/compliance', { flagged: flaggedOnly ? 0 : 1 }, { preserveScroll: true });
    }

    function goToPage(url) {
        if (url) router.get(url, {}, { preserveScroll: true });
    }

    return (
        <AppLayout user={user}>
            <Head title="Compliance Events" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-2xl font-bold">Compliance Events</h2>
                    <p className="text-slate-400 text-sm mt-1">
                        Axiom-ingested events from the Synapse-L4 pipeline
                    </p>
                </div>
                <Button
                    onClick={toggleFilter}
                    variant="outline"
                    className="border-slate-700 text-slate-300 hover:text-white hover:bg-slate-800"
                >
                    {flaggedOnly ? 'Show All Events' : 'Show Flagged Only'}
                </Button>
            </div>

            <EventFeed events={events} flaggedOnly={flaggedOnly} onPageChange={goToPage} />
        </AppLayout>
    );
}

// ── EventFeed ─────────────────────────────────────────────────────────────────

function EventFeed({ events, flaggedOnly, onPageChange }) {
    const { data, links, meta } = events;

    return (
        <Card className="bg-slate-900 border-slate-800 text-white">
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="text-xs uppercase tracking-widest text-slate-500 font-normal">
                    {flaggedOnly ? 'Flagged Events (AI-Routed)' : 'All Events'}
                </CardTitle>
                {meta && (
                    <span className="text-xs text-slate-600">
                        {meta.total} total
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {data.length === 0 ? (
                    <p className="text-slate-500 text-sm px-6 py-4">
                        No compliance events yet — run <code className="text-slate-400">sentinel:watch-axioms</code> to process the stream.
                    </p>
                ) : (
                    <>
                        <ul className="divide-y divide-slate-800">
                            {data.map((event) => (
                                <EventRow key={event.id} event={event} />
                            ))}
                        </ul>
                        {links && <Pagination links={links} onNavigate={onPageChange} />}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

// ── EventRow ──────────────────────────────────────────────────────────────────

function EventRow({ event }) {
    const time = event.created_at
        ? new Date(event.created_at).toLocaleString()
        : '—';

    const score = event.anomaly_score != null
        ? event.anomaly_score.toFixed(3)
        : null;

    return (
        <li className="px-6 py-4 hover:bg-slate-800/40 transition-colors">
            <div className="flex items-start justify-between gap-4">
                {/* Left: source + meta */}
                <div className="flex flex-col gap-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-sm text-slate-200 truncate">
                            {event.source_id}
                        </span>
                        {event.status && (
                            <StatusBadge status={event.status} />
                        )}
                    </div>
                    <div className="flex items-center gap-3 text-xs text-slate-500 flex-wrap">
                        {score != null && (
                            <span>
                                anomaly <span className={anomalyColor(event.anomaly_score)}>{score}</span>
                            </span>
                        )}
                        {event.metric_value != null && (
                            <span>metric {event.metric_value.toFixed(2)}</span>
                        )}
                        {event.driver_used && (
                            <span className="text-slate-600">{event.driver_used}</span>
                        )}
                        <span>{time}</span>
                    </div>
                    {event.audit_narrative && (
                        <p className="text-xs text-slate-400 mt-1 line-clamp-2">
                            {event.audit_narrative}
                        </p>
                    )}
                </div>

                {/* Right: AI-routed badge */}
                <div className="shrink-0">
                    {event.routed_to_ai ? (
                        <Badge variant="destructive" className="uppercase text-xs whitespace-nowrap">
                            AI Flagged
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="uppercase text-xs text-slate-500 border-slate-700 whitespace-nowrap">
                            Passed
                        </Badge>
                    )}
                </div>
            </div>
        </li>
    );
}

// ── Pagination ────────────────────────────────────────────────────────────────

function Pagination({ links, onNavigate }) {
    const prev = links.find(l => l.label.includes('Previous'));
    const next = links.find(l => l.label.includes('Next'));

    if (!prev?.url && !next?.url) return null;

    return (
        <div className="flex justify-between items-center px-6 py-3 border-t border-slate-800">
            <Button
                variant="ghost"
                size="sm"
                disabled={!prev?.url}
                onClick={() => onNavigate(prev?.url)}
                className="text-slate-400 hover:text-white disabled:opacity-30"
            >
                ← Previous
            </Button>
            <Button
                variant="ghost"
                size="sm"
                disabled={!next?.url}
                onClick={() => onNavigate(next?.url)}
                className="text-slate-400 hover:text-white disabled:opacity-30"
            >
                Next →
            </Button>
        </div>
    );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function StatusBadge({ status }) {
    const normalized = status?.toLowerCase();
    if (normalized === 'anomalous' || normalized === 'flagged') {
        return <Badge variant="destructive" className="text-xs uppercase">{status}</Badge>;
    }
    if (normalized === 'normal' || normalized === 'clear') {
        return <Badge variant="outline" className="text-xs uppercase text-emerald-400 border-emerald-800">{status}</Badge>;
    }
    return <Badge variant="outline" className="text-xs uppercase text-slate-500 border-slate-700">{status}</Badge>;
}

function anomalyColor(score) {
    if (score >= 0.7) return 'text-red-400';
    if (score >= 0.4) return 'text-amber-400';
    return 'text-slate-400';
}
