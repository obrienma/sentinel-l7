<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    launchDate: String,
    appName: String
});

const form = useForm({ email: '' });
const success = computed(() => usePage().props.flash?.success);
const features = computed(() => usePage().props.features ?? {});
const appEnv = computed(() => features.value.app_env ?? 'production');

const envBadgeStyle = computed(() => {
    if (appEnv.value === 'local')   return 'bg-amber-500/10 border-amber-500/30 text-amber-400 shadow-amber-500/10';
    if (appEnv.value === 'staging') return 'bg-orange-500/10 border-orange-500/30 text-orange-400 shadow-orange-500/10';
    return 'bg-blue-500/10 border-blue-500/30 text-blue-400';
});

function submit() {
    form.post('/signup', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="appName + ': Coming Soon'" />

    <div class="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-white p-6 relative overflow-hidden">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-blue-900/20 via-transparent to-transparent opacity-50"></div>

        <!-- Environment badge — feature-flagged, non-production only -->
        <Transition
            enter-active-class="transition duration-500 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
        >
            <div
                v-if="features.env_badge"
                :class="['fixed top-4 right-4 z-50 flex items-center gap-2 px-3 py-1.5 rounded-full border text-[11px] font-mono uppercase tracking-widest shadow-lg', envBadgeStyle]"
            >
                <span class="w-1.5 h-1.5 rounded-full animate-pulse"
                      :class="{ 'bg-amber-400': appEnv === 'local', 'bg-orange-400': appEnv === 'staging', 'bg-blue-400': !['local','staging'].includes(appEnv) }"
                ></span>
                {{ appEnv }}
            </div>
        </Transition>

        <main class="z-10 text-center max-w-5xl w-full">
            <div class="mb-8 flex justify-center">
                <div class="relative group">
                    <div class="absolute -inset-1 bg-gradient-to-r from-blue-600 to-cyan-500 rounded-full blur opacity-25 group-hover:opacity-50 transition duration-1000"></div>
                    <img
                        src="/images/sentinel-l7-guard-500x500.png"
                        alt="Sentinel Guard"
                        class="relative w-48 h-48 md:w-64 md:h-64 object-cover rounded-full border-4 border-slate-800 shadow-2xl"
                    />
                </div>
            </div>

            <h1 class="text-5xl md:text-7xl font-black tracking-tighter mb-4 italic">
                SENTINEL-<span class="text-blue-500 font-mono">L7</span>
            </h1>

            <!-- Hero -->
            <h2 class="text-2xl md:text-4xl font-bold tracking-tight mb-4 leading-tight">
                Monitor <span class="text-blue-400">Business Intent</span>, Not Just Servers.
                <br class="hidden md:block" />
                <span class="text-slate-300">AI-Driven Auditing for High-Compliance APIs.</span>
            </h2>

            <p class="text-base md:text-lg text-slate-400 max-w-2xl mx-auto mb-12 leading-relaxed">
                Sentinel-L7 uses <span class="text-slate-200 font-medium">Semantic Caching</span> and
                <span class="text-slate-200 font-medium">Policy-Grounded RAG</span> to intercept fraud and
                compliance drift in real-time, slashing LLM costs by
                <span class="text-green-400 font-semibold">80%</span>.
            </p>

            <!-- Architecture Diagram -->
            <div class="mb-14">
                <div class="bg-slate-900/60 backdrop-blur-md border border-slate-700/50 rounded-2xl p-6 md:p-10 max-w-3xl mx-auto">
                    <h3 class="text-xs uppercase tracking-[0.25em] text-slate-500 mb-6">Message Lifecycle</h3>

                    <div class="flex flex-col md:flex-row items-center justify-center gap-3 md:gap-0 text-sm font-mono">
                        <!-- New -->
                        <div class="flex flex-col items-center">
                            <div class="w-28 py-2.5 rounded-lg bg-blue-600/20 border border-blue-500/40 text-blue-400">
                                XADD
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1">New</span>
                        </div>

                        <svg class="w-6 h-6 text-slate-600 rotate-90 md:rotate-0 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>

                        <!-- Pending -->
                        <div class="flex flex-col items-center">
                            <div class="w-28 py-2.5 rounded-lg bg-amber-600/20 border border-amber-500/40 text-amber-400">
                                PENDING
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1">Processing</span>
                        </div>

                        <svg class="w-6 h-6 text-slate-600 rotate-90 md:rotate-0 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>

                        <!-- XACK -->
                        <div class="flex flex-col items-center">
                            <div class="w-28 py-2.5 rounded-lg bg-green-600/20 border border-green-500/40 text-green-400">
                                XACK
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1">Acknowledged</span>
                        </div>
                    </div>

                    <!-- Recovery path -->
                    <div class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500">
                        <div class="w-20 h-px bg-slate-700"></div>
                        <span class="bg-red-600/15 border border-red-500/30 text-red-400 px-3 py-1 rounded-full font-mono">
                            XCLAIM
                        </span>
                        <span class="text-slate-600">Recovery — Zero data loss</span>
                        <div class="w-20 h-px bg-slate-700"></div>
                    </div>
                </div>
            </div>

            <!-- Signup Card -->
            <div class="bg-slate-900/50 backdrop-blur-md border border-slate-800 p-8 rounded-2xl inline-block">
                <h2 class="text-2xl font-semibold mb-2">Coming Soon</h2>
                <p class="text-slate-500 text-sm mb-6">System initialization in progress...</p>

                <div v-if="success" class="text-green-400 text-sm mb-4">
                    {{ success }}
                </div>

                <form @submit.prevent="submit" class="flex flex-col md:flex-row gap-3">
                    <div class="flex flex-col w-full md:w-72">
                        <input
                            v-model="form.email"
                            type="email"
                            placeholder="Enter email for early access"
                            :disabled="form.processing"
                            class="bg-slate-800 border-none rounded-lg px-4 py-3 w-full focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
                        />
                        <span v-if="form.errors.email" class="text-red-400 text-xs mt-1 text-left">
                            {{ form.errors.email }}
                        </span>
                    </div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-blue-600 hover:bg-blue-500 transition-colors px-6 py-3 rounded-lg font-bold disabled:opacity-50"
                    >
                        {{ form.processing ? 'Securing...' : 'Secure Spot' }}
                    </button>
                </form>
            </div>

            <!-- Dashboard CTA — feature-flagged, non-production only -->
            <Transition
                enter-active-class="transition duration-700 ease-out delay-150"
                enter-from-class="opacity-0 translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
            >
                <div v-if="features.dashboard_access" class="mt-6">
                    <div class="relative group inline-block">
                        <div class="absolute -inset-0.5 bg-gradient-to-r from-blue-600 to-cyan-500 rounded-xl blur opacity-20 group-hover:opacity-50 transition duration-500"></div>
                        <Link
                            href="/dashboard"
                            class="relative flex items-center justify-center gap-2.5 bg-slate-900 border border-slate-700/80 hover:border-blue-500/50 text-slate-300 hover:text-white transition-all duration-300 px-7 py-3 rounded-xl font-semibold text-sm tracking-wide"
                        >
                            <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                            Access Dashboard
                            <span class="text-[9px] bg-blue-500/15 border border-blue-500/25 text-blue-400 px-1.5 py-0.5 rounded font-mono tracking-widest">PREVIEW</span>
                        </Link>
                    </div>
                    <p class="text-slate-600 text-xs mt-2 font-mono uppercase tracking-widest">OAuth login required</p>
                </div>
            </Transition>
        </main>

        <footer class="absolute bottom-8 text-slate-600 text-sm uppercase tracking-widest">
            &copy; 2026 Sentinel-L7 Security Systems
        </footer>
    </div>
</template>
