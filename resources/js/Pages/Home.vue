<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    launchDate: String,
    appName: String
});
</script>

<template>
    <Head :title="appName + ': Coming Soon'" />

    <div class="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-white p-6 relative overflow-hidden">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-blue-900/20 via-transparent to-transparent opacity-50"></div>

        <main class="z-10 text-center max-w-4xl w-full">
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

            <p class="text-xl md:text-2xl font-light text-slate-400 mb-8 tracking-widest uppercase">
                The Vigilance of the Seventh Layer
            </p>

            <div class="bg-slate-900/50 backdrop-blur-md border border-slate-800 p-8 rounded-2xl inline-block">
                <h2 class="text-2xl font-semibold mb-2">Coming Soon</h2>
                <p class="text-slate-500 text-sm mb-6">System initialization in progress...</p>

                <form @submit.prevent="" class="flex flex-col md:flex-row gap-3">
                    <input
                        type="email"
                        placeholder="Enter email for early access"
                        class="bg-slate-800 border-none rounded-lg px-4 py-3 w-full md:w-72 focus:ring-2 focus:ring-blue-500"
                    />
                    <button class="bg-blue-600 hover:bg-blue-500 transition-colors px-6 py-3 rounded-lg font-bold">
                        Secure Spot
                    </button>
                </form>
            </div>
        </main>

        <footer class="absolute bottom-8 text-slate-600 text-sm uppercase tracking-widest">
            &copy; 2026 Sentinel-L7 Security Systems
        </footer>
    </div>
</template>
