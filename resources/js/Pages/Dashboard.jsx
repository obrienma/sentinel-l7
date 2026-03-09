import { useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/components/AppLayout';

// `metrics` is passed as a prop from DashboardController::metrics().
// Destructuring with defaults means the page still renders if metrics are missing.
export default function Dashboard({ user, metrics = {} }) {
    const {
        total    = 0,
        hit_rate = null,
    } = metrics;

    // Poll for fresh metrics every 3 seconds.
    // `router.reload({ only: ['metrics'] })` is an Inertia partial reload —
    // it re-runs the controller but only swaps the `metrics` prop, leaving
    // everything else (user, layout) untouched. No full page reload.
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['metrics'] });
        }, 3000);

        return () => clearInterval(id); // stop polling when component unmounts
    }, []); // [] = run once on mount, not on every render

    return (
        <AppLayout user={user}>
            <Head title="Dashboard" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold">Overview</h2>
                <p className="text-slate-400 text-sm mt-1">Compliance monitoring — real-time</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <StatCard label="Transactions Processed" value={total || '—'} />
                <StatCard label="Flags Raised" value="—" />
                <StatCard label="Cache Hit Rate" value={hit_rate ?? '—'} />
            </div>
        </AppLayout>
    );
}

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
