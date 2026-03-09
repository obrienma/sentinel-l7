import { useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/components/AppLayout';

export default function Dashboard({ user, metrics = {}, recentTxns = [] }) {
    const {
        total    = 0,
        threats  = 0,
        hit_rate = null,
    } = metrics;

    // Partial reload — refreshes metrics and the transaction feed, leaves layout alone.
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['metrics', 'recentTxns'] });
        }, 3000);

        return () => clearInterval(id);
    }, []);

    return (
        <AppLayout user={user}>
            <Head title="Dashboard" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold">Overview</h2>
                <p className="text-slate-400 text-sm mt-1">Compliance monitoring — real-time</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <StatCard label="Transactions Processed" value={total || '—'} />
                <StatCard label="Flags Raised" value={threats || '—'} />
                <StatCard label="Cache Hit Rate" value={hit_rate ?? '—'} />
            </div>

            <TransactionFeed transactions={recentTxns} />
        </AppLayout>
    );
}

// ── StatCard ──────────────────────────────────────────────────────────────────

function StatCard({ label, value }) {
    return (
        <Card className="bg-slate-900 border-slate-800 text-white">
            <CardHeader>
                <CardTitle className="text-xs uppercase tracking-widest text-slate-500 font-normal">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-4xl font-black">{value}</p>
            </CardContent>
        </Card>
    );
}

// ── TransactionFeed ───────────────────────────────────────────────────────────
// Renders the most recent transactions newest-first.
// Each row shows merchant, amount, a THREAT/CLEAR badge, and the analysis source.

const SOURCE_LABEL = {
    cache_hit:  'cache hit',
    cache_miss: 'cache miss',
    fallback:   'fallback',
};

function TransactionFeed({ transactions }) {
    return (
        <Card className="bg-slate-900 border-slate-800 text-white">
            <CardHeader>
                <CardTitle className="text-xs uppercase tracking-widest text-slate-500 font-normal">
                    Recent Transactions
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                {transactions.length === 0 ? (
                    <p className="text-slate-500 text-sm px-6 py-4">
                        No transactions yet — run <code className="text-slate-400">sentinel:watch</code> and stream some data.
                    </p>
                ) : (
                    <ul className="divide-y divide-slate-800">
                        {transactions.map((txn, i) => (
                            <TransactionRow key={txn.id ?? i} txn={txn} />
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function TransactionRow({ txn }) {
    // Format the ISO timestamp to a readable local time
    const time = txn.at ? new Date(txn.at).toLocaleTimeString() : '—';

    return (
        <li className="flex items-center justify-between px-6 py-3 hover:bg-slate-800/40 transition-colors">
            {/* Left: merchant + amount */}
            <div className="flex flex-col min-w-0">
                <span className="font-medium text-sm truncate">{txn.merchant}</span>
                <span className="text-xs text-slate-400">
                    {txn.currency} {txn.amount} · {time}
                </span>
            </div>

            {/* Right: threat badge + source */}
            <div className="flex items-center gap-3 ml-4 shrink-0">
                <span className="text-xs text-slate-600">{SOURCE_LABEL[txn.source] ?? txn.source}</span>
                {txn.is_threat ? (
                    <Badge variant="destructive" className="uppercase text-xs">Threat</Badge>
                ) : (
                    <Badge variant="outline" className="uppercase text-xs text-emerald-400 border-emerald-800">Clear</Badge>
                )}
            </div>
        </li>
    );
}
