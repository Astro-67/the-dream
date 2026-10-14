import { Head, Link, usePage } from '@inertiajs/react';
import {
    BedDouble,
    Check,
    ConciergeBell,
    Receipt,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { cn, formatMoney } from '@/lib/utils';
import { dashboard, login, register } from '@/routes';

type Plan = {
    name: string;
    price: number;
    currency: string;
    period: 'monthly' | 'yearly';
    propertyLimit: number;
    roomLimit: number;
    trialDays: number;
};

// Only what the product does today; integrations awaiting certification are not advertised (PRD §13).
const features = [
    {
        slot: 'feature-front-desk',
        icon: ConciergeBell,
        title: 'Front desk that keeps up',
        body: 'Arrivals, departures and in-house guests on one screen. Assign rooms, check guests in and out, and book walk-ins in seconds.',
        points: [
            'Arrivals and departures by business date',
            'Room assignment with live availability',
            'Guest profiles and booking history',
        ],
    },
    {
        slot: 'feature-housekeeping',
        icon: BedDouble,
        title: 'Rooms, rates and housekeeping',
        body: 'Room types, rate plans and a rate calendar, with room status your housekeepers update as they work.',
        points: [
            'Dirty, clean and inspection status per room',
            'Out-of-service periods kept out of availability',
            'Rate calendar by room type and date',
        ],
    },
    {
        slot: 'feature-billing',
        icon: Receipt,
        title: 'Billing and night audit',
        body: 'Folios, receipts, cashier shifts and a daily close that keeps your books traceable from the first night.',
        points: [
            'Room charges posted at every night audit',
            'Approvals for refunds, fees and write-offs',
            'TZS, USD and EUR, never mixed in one total',
        ],
    },
    {
        slot: 'feature-team',
        icon: Users,
        title: 'Your team, your permissions',
        body: 'Invite staff with roles limited to the properties they work in. Every change is recorded in the audit trail.',
        points: [
            'Owner, manager, front desk, finance, housekeeping and auditor roles',
            'Access scoped to one property or the whole group',
            'Two-factor sign-in and passkeys',
        ],
    },
];

const screens = [
    { key: 'dashboard', label: 'Dashboard' },
    { key: 'reservations', label: 'Reservations' },
    { key: 'rooms', label: 'Room status' },
];

const faqs = [
    {
        q: 'Is there a free trial?',
        a: 'Yes. Every new organization starts on a free trial with the full product. No card is needed to start.',
    },
    {
        q: 'Can I run more than one property?',
        a: 'Yes. One organization can hold several properties, and staff only see the properties you give them.',
    },
    {
        q: 'Is Zanzibar compliance an extra?',
        a: 'No. Legal compliance is included in every plan and is never sold as an upgrade.',
    },
    {
        q: 'Who can see my data?',
        a: 'Only members of your organization. Every hotel’s data is kept separate, and access is checked on every request.',
    },
    {
        q: 'Can I cancel?',
        a: 'Yes. Contact us to cancel; it takes effect at the end of the period you paid for, with no renewal invoice.',
    },
];

/** A marketing photo; until the file exists the slot shows a neutral panel (design.md, Marketing surfaces). */
function Photo({
    slot,
    alt = '',
    className,
}: {
    slot: string;
    alt?: string;
    className?: string;
}) {
    const [missing, setMissing] = useState(false);

    return (
        <div className={cn('overflow-hidden bg-muted', className)}>
            {!missing && (
                <img
                    src={`/images/marketing/${slot}.webp`}
                    alt={alt}
                    loading="lazy"
                    onError={() => setMissing(true)}
                    className="size-full object-cover"
                />
            )}
        </div>
    );
}

function Screenshot({ name, label }: { name: string; label: string }) {
    const { resolvedAppearance } = useAppearance();

    return (
        <Photo
            key={`${name}-${resolvedAppearance}`}
            slot={`screens/${name}-${resolvedAppearance}`}
            className="aspect-[16/10] rounded-xl border bg-card"
            alt={label}
        />
    );
}

function PlanCard({ plan }: { plan: Plan }) {
    return (
        <li className="flex flex-col gap-6 rounded-xl border bg-card p-6">
            <div className="space-y-2">
                <h3 className="text-lg font-semibold">{plan.name}</h3>
                <p className="text-sm text-muted-foreground">
                    For an independent property getting started.
                </p>
            </div>
            <p>
                <span className="text-3xl font-semibold tabular-nums">
                    {formatMoney(plan.price, plan.currency)}
                </span>
                <span className="text-sm text-muted-foreground">
                    {' '}
                    / {plan.period === 'monthly' ? 'month' : 'year'}
                </span>
            </p>
            <ul className="space-y-2 text-sm">
                {[
                    `Up to ${plan.propertyLimit} properties and ${plan.roomLimit} rooms`,
                    'Front desk, rooms, rates and housekeeping',
                    'Billing, night audit and basic reports',
                    'Staff roles and permissions',
                    'Zanzibar compliance included',
                ].map((item) => (
                    <li key={item} className="flex gap-2">
                        <Check className="mt-0.5 size-4 shrink-0 text-status-success" />
                        {item}
                    </li>
                ))}
            </ul>
            <Button asChild className="mt-auto">
                <Link href={register()}>
                    Start {plan.trialDays}-day free trial
                </Link>
            </Button>
        </li>
    );
}

function ContactPlanCard({
    name,
    audience,
    items,
    email,
}: {
    name: string;
    audience: string;
    items: string[];
    email: string;
}) {
    return (
        <li className="flex flex-col gap-6 rounded-xl border bg-card p-6">
            <div className="space-y-2">
                <h3 className="text-lg font-semibold">{name}</h3>
                <p className="text-sm text-muted-foreground">{audience}</p>
            </div>
            <p className="text-3xl font-semibold">Talk to us</p>
            <ul className="space-y-2 text-sm">
                {items.map((item) => (
                    <li key={item} className="flex gap-2">
                        <Check className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        {item}
                    </li>
                ))}
            </ul>
            <Button asChild variant="outline" className="mt-auto">
                <a
                    href={`mailto:${email}?subject=${encodeURIComponent(`${name} plan`)}`}
                >
                    Contact us
                </a>
            </Button>
        </li>
    );
}

export default function Welcome({
    plans,
    contactEmail,
}: {
    plans: Plan[];
    contactEmail: string;
}) {
    const { auth, name } = usePage().props;
    const [screen, setScreen] = useState(screens[0].key);
    const trialDays = plans[0]?.trialDays;

    return (
        <>
            <Head title="Hotel management for your property" />
            <div className="min-h-svh bg-background text-foreground">
                <header className="sticky top-0 z-20 border-b bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/70">
                    <nav
                        aria-label="Main"
                        className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 md:px-6"
                    >
                        <Link
                            href="/"
                            className="flex items-center gap-2 font-semibold"
                        >
                            <AppLogoIcon className="size-8 fill-primary" />
                            <span>{name}</span>
                        </Link>
                        <div className="hidden items-center gap-6 text-sm text-muted-foreground md:flex">
                            <a
                                href="#features"
                                className="hover:text-foreground"
                            >
                                Features
                            </a>
                            <a
                                href="#pricing"
                                className="hover:text-foreground"
                            >
                                Pricing
                            </a>
                            <a href="#faq" className="hover:text-foreground">
                                FAQ
                            </a>
                        </div>
                        <div className="flex items-center gap-2">
                            <AppearanceToggle />
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Go to dashboard
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        asChild
                                        variant="ghost"
                                        className="hidden sm:inline-flex"
                                    >
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

                <main>
                    <section className="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 md:px-6 md:py-24 lg:grid-cols-[1fr_1.1fr]">
                        <div className="space-y-6">
                            <p className="text-sm font-medium text-muted-foreground">
                                Hotel management for East Africa
                            </p>
                            <h1 className="text-4xl font-semibold tracking-tight md:text-6xl">
                                Run your hotel from one secure place.
                            </h1>
                            <p className="max-w-xl text-lg text-muted-foreground">
                                Reservations, rooms, rates, housekeeping and
                                billing for independent hotels and groups. Set
                                up at your own pace; your progress is saved as
                                you go.
                            </p>
                            {!auth.user && (
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    <Button asChild size="lg">
                                        <Link href={register()}>
                                            {trialDays
                                                ? `Start ${trialDays}-day free trial`
                                                : 'Create your account'}
                                        </Link>
                                    </Button>
                                    <Button asChild size="lg" variant="outline">
                                        <a href="#pricing">See pricing</a>
                                    </Button>
                                </div>
                            )}
                            <ul className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                {[
                                    'No card to start',
                                    'Compliance included',
                                    'Light and dark themes',
                                ].map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-center gap-2"
                                    >
                                        <Check className="size-4 text-status-success" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="relative pb-10 lg:pb-16">
                            <Photo
                                slot="hero"
                                className="aspect-[4/3] rounded-2xl lg:aspect-[16/11]"
                            />
                            <div className="absolute right-4 -bottom-0 left-8 rounded-xl border bg-background p-1.5 lg:-left-12">
                                <Screenshot
                                    name="dashboard"
                                    label="Dashboard"
                                />
                            </div>
                        </div>
                    </section>

                    <section
                        id="features"
                        aria-labelledby="features-title"
                        className="scroll-mt-20 border-t bg-muted/40"
                    >
                        <div className="mx-auto max-w-6xl space-y-20 px-4 py-20 md:px-6">
                            <div className="max-w-2xl space-y-4">
                                <h2
                                    id="features-title"
                                    className="text-3xl font-semibold tracking-tight md:text-4xl"
                                >
                                    Everything your property runs on
                                </h2>
                                <p className="text-lg text-muted-foreground">
                                    One system for the front desk, the back
                                    office and the people who clean the rooms.
                                </p>
                            </div>
                            {features.map((feature, index) => (
                                <article
                                    key={feature.slot}
                                    className="grid items-center gap-10 md:grid-cols-2"
                                >
                                    <Photo
                                        slot={feature.slot}
                                        className={cn(
                                            'aspect-[4/3] rounded-2xl',
                                            index % 2 === 1 && 'md:order-last',
                                        )}
                                    />
                                    <div className="space-y-5">
                                        <span className="inline-flex size-10 items-center justify-center rounded-lg border bg-card text-foreground">
                                            <feature.icon className="size-5" />
                                        </span>
                                        <h3 className="text-2xl font-semibold">
                                            {feature.title}
                                        </h3>
                                        <p className="text-muted-foreground">
                                            {feature.body}
                                        </p>
                                        <ul className="space-y-2 text-sm">
                                            {feature.points.map((point) => (
                                                <li
                                                    key={point}
                                                    className="flex gap-2"
                                                >
                                                    <Check className="mt-0.5 size-4 shrink-0 text-status-success" />
                                                    {point}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section
                        aria-labelledby="showcase-title"
                        className="mx-auto max-w-6xl space-y-8 px-4 py-20 md:px-6"
                    >
                        <div className="flex flex-col justify-between gap-6 md:flex-row md:items-end">
                            <div className="max-w-2xl space-y-4">
                                <h2
                                    id="showcase-title"
                                    className="text-3xl font-semibold tracking-tight md:text-4xl"
                                >
                                    See it before you sign up
                                </h2>
                                <p className="text-lg text-muted-foreground">
                                    Real screens from the product. Switch the
                                    theme at the top to see them in light or
                                    dark.
                                </p>
                            </div>
                            <div
                                role="group"
                                aria-label="Screen"
                                className="inline-flex gap-1 self-start rounded-lg bg-muted p-1"
                            >
                                {screens.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        aria-pressed={screen === item.key}
                                        onClick={() => setScreen(item.key)}
                                        className={cn(
                                            'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                                            screen === item.key
                                                ? 'bg-card text-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <Screenshot
                            name={screen}
                            label={screens.find((s) => s.key === screen)!.label}
                        />
                    </section>

                    <section
                        aria-labelledby="compliance-title"
                        className="border-y bg-muted/40"
                    >
                        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-16 md:grid-cols-[auto_1fr] md:px-6">
                            <span className="inline-flex size-12 items-center justify-center rounded-xl border bg-card text-foreground">
                                <ShieldCheck className="size-6" />
                            </span>
                            <div className="max-w-3xl space-y-3">
                                <h2
                                    id="compliance-title"
                                    className="text-2xl font-semibold"
                                >
                                    Built for East Africa, compliance included
                                </h2>
                                <p className="text-muted-foreground">
                                    Thedream is built around how hotels in
                                    Tanzania and Zanzibar work: TZS, USD and EUR
                                    side by side, business dates and night
                                    audit, and the reporting Zanzibar properties
                                    need. Legal compliance is part of every
                                    plan; it is never sold as an upgrade.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section
                        id="pricing"
                        aria-labelledby="pricing-title"
                        className="mx-auto max-w-6xl scroll-mt-20 space-y-10 px-4 py-20 md:px-6"
                    >
                        <div className="mx-auto max-w-2xl space-y-4 text-center">
                            <h2
                                id="pricing-title"
                                className="text-3xl font-semibold tracking-tight md:text-4xl"
                            >
                                Simple pricing
                            </h2>
                            <p className="text-lg text-muted-foreground">
                                Start free, then pay per month. Every plan
                                includes compliance and product updates.
                            </p>
                        </div>
                        <ul className="grid gap-6 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard key={plan.name} plan={plan} />
                            ))}
                            <ContactPlanCard
                                name="Growth"
                                audience="For properties that want more sales channels."
                                email={contactEmail}
                                items={[
                                    'Everything in Essential',
                                    'Richer reports',
                                    'Help planning your sales channels',
                                ]}
                            />
                            <ContactPlanCard
                                name="Group / Enterprise"
                                audience="For multi-property operators and chains."
                                email={contactEmail}
                                items={[
                                    'Multiple properties in one organization',
                                    'Group roles scoped by property',
                                    'Onboarding support',
                                    'Negotiated capacity and service terms',
                                ]}
                            />
                        </ul>
                    </section>

                    <section
                        id="faq"
                        aria-labelledby="faq-title"
                        className="mx-auto max-w-3xl scroll-mt-20 space-y-8 px-4 pb-20 md:px-6"
                    >
                        <h2
                            id="faq-title"
                            className="text-center text-3xl font-semibold tracking-tight"
                        >
                            Questions
                        </h2>
                        <div className="divide-y rounded-xl border bg-card">
                            {faqs.map((faq) => (
                                <details
                                    key={faq.q}
                                    className="group px-5 py-4"
                                >
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                                        {faq.q}
                                        <span
                                            aria-hidden
                                            className="text-muted-foreground transition-transform group-open:rotate-45"
                                        >
                                            +
                                        </span>
                                    </summary>
                                    <p className="pt-3 text-sm text-muted-foreground">
                                        {faq.a}
                                    </p>
                                </details>
                            ))}
                        </div>
                    </section>

                    <section className="relative isolate overflow-hidden">
                        <Photo
                            slot="cta"
                            className="absolute inset-0 -z-10 bg-foreground"
                        />
                        <div className="absolute inset-0 -z-10 bg-black/60" />
                        <div className="mx-auto max-w-3xl space-y-6 px-4 py-24 text-center text-white md:px-6">
                            <h2 className="text-3xl font-semibold tracking-tight md:text-4xl">
                                Your property, ready by tonight’s audit.
                            </h2>
                            <p className="text-lg text-white/80">
                                Create your account, add your rooms and rates,
                                and go live when the checklist passes.
                            </p>
                            <Button asChild size="lg">
                                <Link
                                    href={auth.user ? dashboard() : register()}
                                >
                                    {auth.user
                                        ? 'Go to dashboard'
                                        : 'Get started free'}
                                </Link>
                            </Button>
                        </div>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex max-w-6xl flex-col justify-between gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row md:px-6">
                        <span>
                            © {new Date().getFullYear()} {name}
                        </span>
                        <div className="flex gap-6">
                            <a
                                href="#pricing"
                                className="hover:text-foreground"
                            >
                                Pricing
                            </a>
                            <a href="#faq" className="hover:text-foreground">
                                FAQ
                            </a>
                            <a
                                href={`mailto:${contactEmail}`}
                                className="hover:text-foreground"
                            >
                                Contact
                            </a>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
