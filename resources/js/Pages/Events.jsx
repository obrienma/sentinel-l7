import { Head, router } from '@inertiajs/react';
import AppLayout from '@/components/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export default function Events({ user, events, filter }) {
    const { data, current_page, last_page, prev_page_url, next_page_url } = events;

    function setFilter(value) {
        router.get('/events', value !== 'flagged' ? { filter: value } : {}, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout user={user}>
            <Head title="Events" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-2xl font-bold">Compliance Events</h2>
                    <p className="text-slate-400 text-sm mt-1">Axiom audit trail from Synapse-L4</p>
                </div>

                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant={filter !== 'all' ? 'default' : 'outline'}
                        onClick={() => setFilter('flagged')}
                        className={filter !== 'all'
                            ? 'bg-blue-600 hover:bg-blue-500 text-white'
                            : 'border-slate-700 text-slate-400 hover:text-white'}
                    >
                        Flagged only
                    </Button>
                    <Button
                        size="sm"
                        variant={filter === 'all' ? 'default' : 'outline'}
                        onClick={() => setFilter('all')}
                        className={filter === 'all'
                            ? 'bg-blue-600 hover:bg-blue-500 text-white'
                            : 'border-slate-700 text-slate-400 hover:text-white'}
                    >
                        All events
                    </Button>
                </div>
            </div>

            <Card className="bg-slate-900 border-slate-800 text-white">
                <CardContent className="p-0">
                    {data.length === 0 ? (
                        <p className="text-slate-500 text-sm px-6 py-8 text-center">
                            No events yet — run <code className="text-slate-400">sentinel:watch-axioms</code> to start ingesting Axioms.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="border-slate-800 hover:bg-transparent">
                                    <TableHead className="text-slate-500">Source</TableHead>
                                    <TableHead className="text-slate-500">Status</TableHead>
                                    <TableHead className="text-slate-500">Score</TableHead>
                                    <TableHead className="text-slate-500">Routed</TableHead>
                                    <TableHead className="text-slate-500">Narrative</TableHead>
                                    <TableHead className="text-slate-500 text-right">Emitted</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.map(event => (
                                    <EventRow key={event.id} event={event} />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {last_page > 1 && (
                <div className="flex justify-end gap-2 mt-4">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!prev_page_url}
                        onClick={() => router.get(prev_page_url)}
                        className="border-slate-700 text-slate-400 hover:text-white disabled:opacity-30"
                    >
                        Previous
                    </Button>
                    <span className="text-slate-500 text-sm self-center">
                        {current_page} / {last_page}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!next_page_url}
                        onClick={() => router.get(next_page_url)}
                        className="border-slate-700 text-slate-400 hover:text-white disabled:opacity-30"
                    >
                        Next
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}

function EventRow({ event }) {
    const emitted = event.emitted_at
        ? new Date(event.emitted_at).toLocaleString()
        : new Date(event.created_at).toLocaleString();

    const score = event.anomaly_score != null
        ? event.anomaly_score.toFixed(2)
        : '—';

    const scoreColor = event.anomaly_score >= 0.9
        ? 'text-red-400'
        : event.anomaly_score >= 0.8
            ? 'text-amber-400'
            : 'text-slate-400';

    return (
        <TableRow className="border-slate-800 hover:bg-slate-800/40">
            <TableCell className="font-mono text-xs text-slate-300">
                {event.source_id}
            </TableCell>
            <TableCell>
                <StatusBadge status={event.status} />
            </TableCell>
            <TableCell className={`font-mono text-sm font-bold ${scoreColor}`}>
                {score}
            </TableCell>
            <TableCell>
                {event.routed_to_ai ? (
                    <Badge variant="destructive" className="uppercase text-xs">AI</Badge>
                ) : (
                    <Badge variant="outline" className="uppercase text-xs text-slate-500 border-slate-700">Sub-threshold</Badge>
                )}
            </TableCell>
            <TableCell className="max-w-xs">
                {event.audit_narrative ? (
                    <p className="text-xs text-slate-300 truncate" title={event.audit_narrative}>
                        {event.audit_narrative}
                    </p>
                ) : (
                    <span className="text-xs text-slate-600">—</span>
                )}
            </TableCell>
            <TableCell className="text-right text-xs text-slate-500 whitespace-nowrap">
                {emitted}
            </TableCell>
        </TableRow>
    );
}

function StatusBadge({ status }) {
    const variants = {
        critical: 'bg-red-950 text-red-400 border-red-900',
        warning:  'bg-amber-950 text-amber-400 border-amber-900',
        normal:   'bg-emerald-950 text-emerald-400 border-emerald-900',
    };

    const cls = variants[status?.toLowerCase()] ?? 'bg-slate-800 text-slate-400 border-slate-700';

    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${cls}`}>
            {status ?? '—'}
        </span>
    );
}
