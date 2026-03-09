import { Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    LayoutDashboard,
    ArrowLeftRight,
    AlertTriangle,
    FileText,
    Settings,
    ShieldCheck,
} from 'lucide-react';

// Navigation items. `href` matches the Laravel named route.
// `disabled` stubs future pages — clicking does nothing yet.
const NAV_ITEMS = [
    { label: 'Dashboard',     href: '/dashboard',    icon: LayoutDashboard },
    { label: 'Transactions',  href: '/transactions', icon: ArrowLeftRight,  disabled: true },
    { label: 'Flags',         href: '/flags',        icon: AlertTriangle,   disabled: true },
    { label: 'Policies',      href: '/policies',     icon: FileText,        disabled: true },
    { label: 'Settings',      href: '/settings',     icon: Settings,        disabled: true },
];

/**
 * AppLayout — shared shell for all authenticated pages.
 *
 * Usage:
 *   <AppLayout user={user}>
 *       <h2>Page content here</h2>
 *   </AppLayout>
 *
 * `children` is a built-in React prop — anything between the opening and
 * closing tags of a component is passed as `children` automatically.
 *
 * `usePage().url` gives us the current path so we can highlight the active
 * nav item without prop-drilling or a router hook.
 */
export default function AppLayout({ user, children }) {
    const { url } = usePage();

    function logout() {
        router.post('/logout');
    }

    return (
        <div className="min-h-screen bg-slate-950 text-white flex flex-col">
            {/* ── Top bar ────────────────────────────────────────────── */}
            <header className="border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0">
                <div className="flex items-center gap-2">
                    <ShieldCheck className="text-blue-500 w-5 h-5" />
                    <span className="text-xl font-black tracking-tighter italic">
                        SENTINEL-<span className="text-blue-500 font-mono">L7</span>
                    </span>
                </div>

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

            {/* ── Body: sidebar + content ─────────────────────────────── */}
            <div className="flex flex-1 overflow-hidden">
                {/* Sidebar */}
                <nav className="w-56 border-r border-slate-800 px-3 py-4 flex flex-col gap-1 shrink-0">
                    {NAV_ITEMS.map(({ label, href, icon: Icon, disabled }) => {
                        const isActive = url === href || url.startsWith(href + '/');

                        return (
                            <NavItem
                                key={label}
                                label={label}
                                href={href}
                                icon={Icon}
                                active={isActive}
                                disabled={disabled}
                            />
                        );
                    })}
                </nav>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto p-6">
                    {children}
                </main>
            </div>
        </div>
    );
}

// ── NavItem ────────────────────────────────────────────────────────────────
// Separated so the JSX stays readable. `disabled` items render as a <span>
// instead of a <Link> — no navigation, styled dimmer with a "coming soon" cursor.

function NavItem({ label, href, icon: Icon, active, disabled }) {
    const base =
        'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors';

    const activeClass   = 'bg-slate-800 text-white';
    const inactiveClass = 'text-slate-400 hover:text-white hover:bg-slate-800/50';
    const disabledClass = 'text-slate-600 cursor-not-allowed';

    const classes = `${base} ${disabled ? disabledClass : active ? activeClass : inactiveClass}`;

    if (disabled) {
        return (
            <span className={classes} title="Coming soon">
                <Icon className="w-4 h-4" />
                {label}
            </span>
        );
    }

    return (
        // Inertia's <Link> does a client-side visit — no full page reload.
        <Link href={href} className={classes}>
            <Icon className="w-4 h-4" />
            {label}
        </Link>
    );
}
