import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

// The `user` prop comes directly from DashboardController::index()
export default function Dashboard({ user }) {
    function logout() {
        // router.post is Inertia's way to make non-GET requests without a <form>.
        // It triggers the POST /logout route and handles the redirect response.
        router.post('/logout');
    }

    return (
        <>
            <Head title="Dashboard" />

            <div className="min-h-screen bg-slate-950 text-white">
                {/* Top bar */}
                <header className="border-b border-slate-800 px-6 py-4 flex items-center justify-between">
                    <h1 className="text-xl font-black tracking-tighter italic">
                        SENTINEL-<span className="text-blue-500 font-mono">L7</span>
                    </h1>
                    <div className="flex items-center gap-4">
                        <span className="text-slate-400 text-sm">{user.email}</span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={logout}
                            className="text-slate-400 hover:text-white"
                        >
                            Sign out
                        </Button>
                    </div>
                </header>

                {/* Main content */}
                <main className="p-6 max-w-7xl mx-auto">
                    <div className="mb-6">
                        <h2 className="text-2xl font-bold">Overview</h2>
                        <p className="text-slate-400 text-sm mt-1">Compliance monitoring — real-time</p>
                    </div>

                    {/* Stat cards — placeholders until we wire up real data */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <StatCard label="Transactions Processed" value="—" />
                        <StatCard label="Flags Raised" value="—" />
                        <StatCard label="Cache Hit Rate" value="—" />
                    </div>
                </main>
            </div>
        </>
    );
}

// A small reusable component defined in the same file for now.
// When it grows or gets used elsewhere, we'd extract it to components/StatCard.jsx.
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
