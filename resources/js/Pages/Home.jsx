import { Head, useForm, usePage } from '@inertiajs/react';

export default function Home({ launchDate, appName }) {
    const { props } = usePage();
    const success = props.flash?.success;

    const form = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        form.post('/signup', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <>
            <Head title={`${appName}: Coming Soon`} />

            <div className="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-white p-6 relative overflow-hidden">
                <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-blue-900/20 via-transparent to-transparent opacity-50"></div>

                <main className="z-10 text-center max-w-5xl w-full">
                    <div className="mb-8 flex justify-center">
                        <div className="relative group">
                            <div className="absolute -inset-1 bg-gradient-to-r from-blue-600 to-cyan-500 rounded-full blur opacity-25 group-hover:opacity-50 transition duration-1000"></div>
                            <img
                                src="/images/sentinel-l7-guard-500x500.png"
                                alt="Sentinel Guard"
                                className="relative w-48 h-48 md:w-64 md:h-64 object-cover rounded-full border-4 border-slate-800 shadow-2xl"
                            />
                        </div>
                    </div>

                    <h1 className="text-5xl md:text-7xl font-black tracking-tighter mb-4 italic">
                        SENTINEL-<span className="text-blue-500 font-mono">L7</span>
                    </h1>

                    {/* Hero */}
                    <h2 className="text-2xl md:text-4xl font-bold tracking-tight mb-4 leading-tight">
                        Monitor <span className="text-blue-400">Business Intent</span>, Not Just Servers.
                        <br className="hidden md:block" />
                        <span className="text-slate-300">AI-Driven Auditing for High-Compliance APIs.</span>
                    </h2>

                    <p className="text-base md:text-lg text-slate-400 max-w-2xl mx-auto mb-12 leading-relaxed">
                        Sentinel-L7 uses <span className="text-slate-200 font-medium">Semantic Caching</span> and{' '}
                        <span className="text-slate-200 font-medium">Policy-Grounded RAG</span> to intercept fraud and
                        compliance drift in real-time, slashing LLM costs by{' '}
                        <span className="text-green-400 font-semibold">80%</span>.
                    </p>

                    {/* Architecture Diagram */}
                    <div className="mb-14">
                        <div className="bg-slate-900/60 backdrop-blur-md border border-slate-700/50 rounded-2xl p-6 md:p-10 max-w-3xl mx-auto">
                            <h3 className="text-xs uppercase tracking-[0.25em] text-slate-500 mb-6">Message Lifecycle</h3>

                            <div className="flex flex-col md:flex-row items-center justify-center gap-3 md:gap-0 text-sm font-mono">
                                {/* New */}
                                <div className="flex flex-col items-center">
                                    <div className="w-28 py-2.5 rounded-lg bg-blue-600/20 border border-blue-500/40 text-blue-400">
                                        XADD
                                    </div>
                                    <span className="text-[10px] text-slate-500 mt-1">New</span>
                                </div>

                                <svg className="w-6 h-6 text-slate-600 rotate-90 md:rotate-0 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>

                                {/* Pending */}
                                <div className="flex flex-col items-center">
                                    <div className="w-28 py-2.5 rounded-lg bg-amber-600/20 border border-amber-500/40 text-amber-400">
                                        PENDING
                                    </div>
                                    <span className="text-[10px] text-slate-500 mt-1">Processing</span>
                                </div>

                                <svg className="w-6 h-6 text-slate-600 rotate-90 md:rotate-0 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>

                                {/* XACK */}
                                <div className="flex flex-col items-center">
                                    <div className="w-28 py-2.5 rounded-lg bg-green-600/20 border border-green-500/40 text-green-400">
                                        XACK
                                    </div>
                                    <span className="text-[10px] text-slate-500 mt-1">Acknowledged</span>
                                </div>
                            </div>

                            {/* Recovery path */}
                            <div className="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500">
                                <div className="w-20 h-px bg-slate-700"></div>
                                <span className="bg-red-600/15 border border-red-500/30 text-red-400 px-3 py-1 rounded-full font-mono">
                                    XCLAIM
                                </span>
                                <span className="text-slate-600">Recovery — Zero data loss</span>
                                <div className="w-20 h-px bg-slate-700"></div>
                            </div>
                        </div>
                    </div>

                    {/* Signup Card */}
                    <div className="bg-slate-900/50 backdrop-blur-md border border-slate-800 p-8 rounded-2xl inline-block">
                        <h2 className="text-2xl font-semibold mb-2">Coming Soon</h2>
                        <p className="text-slate-500 text-sm mb-6">System initialization in progress...</p>

                        {success && (
                            <div className="text-green-400 text-sm mb-4">{success}</div>
                        )}

                        <form onSubmit={submit} className="flex flex-col md:flex-row gap-3">
                            <div className="flex flex-col w-full md:w-72">
                                <input
                                    type="email"
                                    value={form.data.email}
                                    onChange={e => form.setData('email', e.target.value)}
                                    placeholder="Enter email for early access"
                                    disabled={form.processing}
                                    className="bg-slate-800 border-none rounded-lg px-4 py-3 w-full focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
                                />
                                {form.errors.email && (
                                    <span className="text-red-400 text-xs mt-1 text-left">{form.errors.email}</span>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="bg-blue-600 hover:bg-blue-500 transition-colors px-6 py-3 rounded-lg font-bold disabled:opacity-50"
                            >
                                {form.processing ? 'Securing...' : 'Secure Spot'}
                            </button>
                        </form>
                    </div>
                </main>

                <footer className="absolute bottom-8 text-slate-600 text-sm uppercase tracking-widest">
                    &copy; 2026 Sentinel-L7 Security Systems
                </footer>
            </div>
        </>
    );
}
