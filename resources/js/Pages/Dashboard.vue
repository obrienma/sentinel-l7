<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    appName: String,
});

const env = usePage().props.features?.app_env ?? 'unknown';
</script>

<template>
    <Head :title="appName + ': Dashboard'" />

    <div class="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-white p-6 relative overflow-hidden">
        <!-- Background glow -->
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-blue-900/20 via-transparent to-transparent opacity-50 pointer-events-none"></div>

        <main class="z-10 text-center max-w-2xl w-full">
            <!-- Icon -->
            <div class="mb-8 flex justify-center">
                <div class="relative">
                    <div class="absolute -inset-3 bg-gradient-to-r from-blue-600 to-cyan-500 rounded-full blur-md opacity-20"></div>
                    <div class="relative w-20 h-20 rounded-full bg-slate-900 border border-slate-700 flex items-center justify-center">
                        <svg class="w-10 h-10 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                    </div>
                </div>
            </div>

            <h1 class="text-4xl md:text-5xl font-black tracking-tighter mb-3 italic">
                SENTINEL-<span class="text-blue-500 font-mono">L7</span>
            </h1>

            <!-- Environment pill -->
            <div class="flex justify-center mb-8">
                <span class="text-[10px] font-mono uppercase tracking-[0.25em] px-3 py-1 rounded-full border"
                      :class="{
                          'bg-amber-500/10 border-amber-500/30 text-amber-400': env === 'local',
                          'bg-orange-500/10 border-orange-500/30 text-orange-400': env === 'staging',
                          'bg-blue-500/10 border-blue-500/30 text-blue-400': !['local','staging'].includes(env),
                      }">
                    {{ env }} environment
                </span>
            </div>

            <!-- Card -->
            <div class="bg-slate-900/60 backdrop-blur-md border border-slate-700/50 rounded-2xl p-10">
                <div class="flex items-center justify-center gap-2 mb-4">
                    <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    <h2 class="text-xl font-semibold text-slate-200">Authentication Required</h2>
                </div>

                <p class="text-slate-400 text-sm leading-relaxed mb-6 max-w-sm mx-auto">
                    The Sentinel-L7 command dashboard requires OAuth authentication.
                    Live threat feeds, vector cache metrics, and stream controls will be available here.
                </p>

                <div class="grid grid-cols-3 gap-3 mb-8 text-xs font-mono">
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-3 text-slate-500">
                        <div class="text-blue-400 text-lg font-bold mb-1">—</div>
                        Cache Hit Rate
                    </div>
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-3 text-slate-500">
                        <div class="text-green-400 text-lg font-bold mb-1">—</div>
                        Threats / hr
                    </div>
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-lg p-3 text-slate-500">
                        <div class="text-amber-400 text-lg font-bold mb-1">—</div>
                        Avg Latency
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <button
                        disabled
                        class="bg-blue-600/50 text-blue-300 cursor-not-allowed px-6 py-3 rounded-lg font-bold text-sm flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                        </svg>
                        Sign in with OAuth
                        <span class="text-[10px] bg-slate-700 text-slate-400 px-1.5 py-0.5 rounded font-mono">soon</span>
                    </button>

                    <Link
                        href="/"
                        class="border border-slate-700 hover:border-slate-500 text-slate-400 hover:text-slate-200 transition-colors px-6 py-3 rounded-lg font-bold text-sm"
                    >
                        ← Back to Home
                    </Link>
                </div>
            </div>
        </main>

        <footer class="absolute bottom-8 text-slate-600 text-sm uppercase tracking-widest">
            &copy; 2026 Sentinel-L7 Security Systems
        </footer>
    </div>
</template>
