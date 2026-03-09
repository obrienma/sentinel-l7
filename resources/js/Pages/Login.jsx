import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

// Login is a React component — a plain function that returns JSX.
// Inertia passes props from the controller directly as function arguments.
export default function Login() {
    // useForm sets up form state. The object here defines the initial field values.
    // form.data holds the current values; form.errors holds validation errors from Laravel.
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault(); // prevent the browser's default full-page form submit

        form.post('/login', {
            onFinish: () => form.reset('password'), // clear password from memory after submit
        });
    }

    return (
        <>
            <Head title="Login" />

            <div className="min-h-screen bg-slate-950 flex items-center justify-center p-6">
                <div className="w-full max-w-sm">
                    {/* Branding */}
                    <div className="text-center mb-8">
                        <h1 className="text-3xl font-black tracking-tighter italic text-white">
                            SENTINEL-<span className="text-blue-500 font-mono">L7</span>
                        </h1>
                        <p className="text-slate-500 text-sm mt-1">Compliance Operations Center</p>
                    </div>

                    {/* Card is a shadcn component — it's just a styled div.
                        We're overriding its default bg with explicit Tailwind classes. */}
                    <Card className="bg-slate-900 border-slate-800">
                        <CardHeader>
                            <CardTitle className="text-white">Sign in</CardTitle>
                            <CardDescription>Access the monitoring dashboard</CardDescription>
                        </CardHeader>

                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="space-y-1">
                                    <label className="text-sm text-slate-400" htmlFor="email">
                                        Email
                                    </label>
                                    {/* Controlled input: value always reflects form.data.email,
                                        onChange updates it via setData */}
                                    <input
                                        id="email"
                                        type="email"
                                        autoComplete="email"
                                        value={form.data.email}
                                        onChange={e => form.setData('email', e.target.value)}
                                        disabled={form.processing}
                                        className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
                                    />
                                    {/* Conditionally render the error — only shows when Laravel returns one */}
                                    {form.errors.email && (
                                        <p className="text-red-400 text-xs">{form.errors.email}</p>
                                    )}
                                </div>

                                <div className="space-y-1">
                                    <label className="text-sm text-slate-400" htmlFor="password">
                                        Password
                                    </label>
                                    <input
                                        id="password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={form.data.password}
                                        onChange={e => form.setData('password', e.target.value)}
                                        disabled={form.processing}
                                        className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
                                    />
                                    {form.errors.password && (
                                        <p className="text-red-400 text-xs">{form.errors.password}</p>
                                    )}
                                </div>

                                <div className="flex items-center gap-2">
                                    <input
                                        id="remember"
                                        type="checkbox"
                                        checked={form.data.remember}
                                        onChange={e => form.setData('remember', e.target.checked)}
                                        className="rounded border-slate-700 bg-slate-800"
                                    />
                                    <label htmlFor="remember" className="text-sm text-slate-400">
                                        Remember me
                                    </label>
                                </div>

                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="w-full bg-blue-600 hover:bg-blue-500 text-white"
                                >
                                    {form.processing ? 'Signing in…' : 'Sign in'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
