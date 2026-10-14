import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

// Only what the product does today; integrations awaiting certification are not advertised (PRD §13).
const features = [
    {
        title: 'Reservations and front desk',
        body: 'Arrivals, departures, in-house guests, check-in and check-out from one screen.',
    },
    {
        title: 'Rooms, rates and housekeeping',
        body: 'Room types, rate plans and a rate calendar, with room status your housekeepers update.',
    },
    {
        title: 'Billing and night audit',
        body: 'Folios, receipts, cashier shifts and a daily close that keeps your books traceable.',
    },
    {
        title: 'Your team, your permissions',
        body: 'Invite staff with roles limited to the properties they work in.',
    },
];

const steps = [
    'Create your account and confirm your email.',
    'Add your business and first property.',
    'Follow the setup checklist: rooms, rates, taxes and staff.',
    'Go live when every requirement passes.',
];

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Hotel management for your property" />
            <div className="min-h-svh bg-background text-foreground">
                <header className="sticky top-0 z-10 border-b bg-background">
                    <nav
                        aria-label="Main"
                        className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 md:px-6"
                    >
                        <Link
                            href="/"
                            className="flex items-center gap-2 font-semibold"
                        >
                            <AppLogoIcon className="size-8 fill-primary" />
                            <span>{name}</span>
                        </Link>
                        <div className="flex items-center gap-2">
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Go to dashboard
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost">
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href={register()}>
                                            Get started
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                <main className="mx-auto flex max-w-5xl flex-col gap-16 px-4 py-12 md:px-6 md:py-20">
                    <section className="max-w-2xl space-y-6">
                        <h1 className="text-4xl font-semibold tracking-tight md:text-5xl">
                            Run your hotel from one secure place.
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            Reservations, rooms, rates, housekeeping and billing
                            for independent hotels and groups. Set up your
                            property at your own pace; your progress is saved as
                            you go.
                        </p>
                        {!auth.user && (
                            <div className="flex flex-col gap-3 sm:flex-row">
                                <Button asChild size="lg">
                                    <Link href={register()}>
                                        Create your account
                                    </Link>
                                </Button>
                                <Button asChild size="lg" variant="outline">
                                    <Link href={login()}>
                                        I already have an account
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </section>

                    <section aria-labelledby="features" className="space-y-6">
                        <h2 id="features" className="text-2xl font-semibold">
                            What you can do
                        </h2>
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {features.map((feature) => (
                                <li
                                    key={feature.title}
                                    className="space-y-2 rounded-xl border p-5"
                                >
                                    <h3 className="font-semibold">
                                        {feature.title}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {feature.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section aria-labelledby="steps" className="space-y-6">
                        <h2 id="steps" className="text-2xl font-semibold">
                            How setup works
                        </h2>
                        <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {steps.map((step, index) => (
                                <li
                                    key={step}
                                    className="space-y-2 rounded-xl border p-5"
                                >
                                    <span className="text-sm font-medium text-muted-foreground tabular-nums">
                                        Step {index + 1}
                                    </span>
                                    <p className="text-sm">{step}</p>
                                </li>
                            ))}
                        </ol>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto max-w-5xl px-4 py-6 text-sm text-muted-foreground md:px-6">
                        © {new Date().getFullYear()} {name}
                    </div>
                </footer>
            </div>
        </>
    );
}
