import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Menu, Plus } from 'lucide-react';
import { useState } from 'react';
import { AppearanceToggle } from '@/components/appearance-toggle';
import BrandLogo from '@/components/brand-logo';
import LandingHero from '@/components/public/landing-hero';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatMoney } from '@/lib/utils';
import { dashboard, login, register } from '@/routes';
import '../../css/public-auth.css';
import '../../css/landing.css';

type Plan = {
    name: string;
    price: number;
    currency: string;
    period: 'monthly' | 'yearly';
    trialDays: number;
};

const features = [
    {
        label: 'Front desk',
        photo: 'coastal-reception-welcome',
        alt: 'A receptionist welcoming a guest across a carved wooden reception desk',
        title: 'From a reservation to a warm welcome.',
        body: 'Arrivals, departures and in-house guests in one workspace. Keep the details close, so your attention stays with the guest.',
        points: [
            'Reservations and guest history',
            'Room assignment and check-in',
            'Walk-ins, departures and room moves',
        ],
    },
    {
        label: 'Room readiness',
        photo: 'coastal-prepared-room',
        alt: 'A prepared guest room with a carved bed, blue cushions and an open courtyard doorway',
        title: 'Know the room before the guest arrives.',
        body: 'Room status, housekeeping and availability stay connected. Your front desk and housekeepers work from the same picture.',
        points: [
            'Dirty, clean and inspection status',
            'Out-of-order periods kept out of availability',
            'Room types, rates and a rate calendar',
        ],
    },
    {
        label: 'Billing',
        photo: 'coastal-reception-welcome',
        alt: 'A guest and receptionist at a carved wooden reception counter',
        title: 'Every charge. A clear account.',
        body: 'Keep folios, payments, receipts and the daily close traceable, from the first night to the final receipt.',
        points: [
            'Room charges posted at night audit',
            'Approvals for refunds and write-offs',
            'TZS, USD and EUR, never mixed in one total',
        ],
    },
];

const faqs = [
    [
        'Can I try Karafuu first?',
        'Trial availability and duration are shown with each plan in Pricing. Contact us if you need help choosing a plan.',
    ],
    [
        'Can I run more than one property?',
        'Yes. One organization can hold several properties, and staff only see the properties you give them.',
    ],
    [
        'Is Zanzibar compliance an extra?',
        'No. Legal compliance is included in every plan and is never sold as an upgrade.',
    ],
    [
        'Who can see my data?',
        'Only members of your organization. Every property’s data is kept separate, and access is checked on every request.',
    ],
    [
        'Can I cancel?',
        'Yes. Contact us to cancel; it takes effect at the end of the period you paid for, with no renewal invoice.',
    ],
];

export default function Welcome({
    plans,
    contactEmail,
}: {
    plans: Plan[];
    contactEmail: string;
    hasScreens?: boolean;
}) {
    const { auth, name } = usePage().props;
    const [area, setArea] = useState(0);
    const feature = features[area];
    const signedIn = Boolean(auth.user);
    const start = signedIn ? dashboard() : register();
    const startLabel = signedIn ? 'Go to dashboard' : 'Get started';

    return (
        <>
            <Head title="Property management for hotels, resorts and more" />
            <div className="public-surface landing-site">
                <a className="public-skip" href="#main-content">
                    Skip to content
                </a>
                <div className="public-wrap">
                    <header className="public-topbar">
                        <Link href="/" aria-label="Karafuu PMS home">
                            <BrandLogo className="public-brand" />
                        </Link>
                        <nav className="landing-nav" aria-label="Main">
                            <a href="#product">Product</a>
                            <a href="#team">Your team</a>
                            <a href="#pricing">Pricing</a>
                        </nav>
                        <div className="landing-header-actions">
                            <AppearanceToggle />
                            {!signedIn && (
                                <Link href={login()} className="landing-login">
                                    Log in
                                </Link>
                            )}
                            <Button asChild size="lg" className="landing-start">
                                <Link
                                    href={signedIn ? dashboard() : register()}
                                >
                                    {signedIn
                                        ? 'Go to dashboard'
                                        : 'Get started'}
                                </Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="landing-mobile-menu"
                                        aria-label="Main menu"
                                    >
                                        <Menu className="size-5" aria-hidden />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="landing-menu-content"
                                >
                                    {[
                                        ['#product', 'Product'],
                                        ['#team', 'Your team'],
                                        ['#pricing', 'Pricing'],
                                        ['#faq', 'FAQ'],
                                    ].map(([href, label]) => (
                                        <DropdownMenuItem asChild key={href}>
                                            <a href={href}>{label}</a>
                                        </DropdownMenuItem>
                                    ))}
                                    <DropdownMenuSeparator />
                                    {!signedIn && (
                                        <DropdownMenuItem asChild>
                                            <Link href={login()}>Log in</Link>
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                        asChild
                                        className="landing-menu-cta"
                                    >
                                        <Link
                                            href={
                                                signedIn
                                                    ? dashboard()
                                                    : register()
                                            }
                                        >
                                            {signedIn
                                                ? 'Go to dashboard'
                                                : 'Get started'}
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </header>
                    <main id="main-content">
                        <LandingHero signedIn={signedIn} />
                        <section
                            className="landing-pad"
                            id="product"
                            aria-labelledby="product-title"
                        >
                            <div className="landing-intro">
                                <h2 id="product-title">
                                    A connected stay.
                                    <br />
                                    Not scattered work.
                                </h2>
                                <p>
                                    Give each part of your property a place to
                                    work, with the same reservation at the heart
                                    of it.
                                </p>
                            </div>
                            <fieldset className="landing-feature-picker">
                                <legend className="sr-only">
                                    Explore product areas
                                </legend>
                                <div className="landing-feature-options">
                                    {features.map((item, index) => (
                                        <label key={item.label}>
                                            <input
                                                type="radio"
                                                name="product-area"
                                                checked={area === index}
                                                onChange={() => setArea(index)}
                                                aria-controls="product-panel"
                                            />
                                            <span>{item.label}</span>
                                        </label>
                                    ))}
                                </div>
                                <div
                                    className="landing-feature"
                                    id="product-panel"
                                >
                                    <img
                                        src={`/images/marketing/${feature.photo}.webp`}
                                        alt={feature.alt}
                                        loading="lazy"
                                        width="1448"
                                        height="1086"
                                    />
                                    <div>
                                        <h3>{feature.title}</h3>
                                        <p>{feature.body}</p>
                                        <ul>
                                            {feature.points.map((point) => (
                                                <li key={point}>{point}</li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            </fieldset>
                        </section>
                        <section
                            className="landing-team"
                            id="team"
                            aria-labelledby="team-title"
                        >
                            <img
                                src="/images/marketing/coastal-team-veranda.webp"
                                alt="Two uniformed colleagues talking on a shaded veranda"
                                loading="lazy"
                                width="1448"
                                height="1086"
                            />
                            <div>
                                <h2 id="team-title">
                                    One team.
                                    <br />
                                    The right access.
                                </h2>
                                <p>
                                    The front desk, housekeeping and finance do
                                    different work. Give each person access to
                                    the properties and responsibilities they
                                    need.
                                </p>
                                <ul>
                                    {[
                                        'Roles that match your team',
                                        'Property-scoped staff access',
                                        'An audit trail of changes',
                                        'Two-factor sign-in and passkeys',
                                    ].map((point) => (
                                        <li key={point}>{point}</li>
                                    ))}
                                </ul>
                            </div>
                        </section>
                        <section
                            className="landing-pad landing-pricing"
                            id="pricing"
                            aria-labelledby="pricing-title"
                        >
                            <div className="landing-intro">
                                <h2 id="pricing-title">
                                    A clear price.
                                    <br />
                                    Room to grow.
                                </h2>
                                <p>
                                    Pay per subscribed room. Know the currency,
                                    billing period and trial terms before you
                                    begin.
                                </p>
                            </div>
                            <ul className="landing-plans">
                                {plans.map((plan, index) => (
                                    <li
                                        className="landing-plan"
                                        key={`${plan.name}-${plan.currency}-${plan.period}-${index}`}
                                    >
                                        <h3>{plan.name}</h3>
                                        <p className="landing-price">
                                            {formatMoney(
                                                plan.price,
                                                plan.currency,
                                            )}
                                        </p>
                                        <p className="landing-price-basis">
                                            per subscribed room /{' '}
                                            {plan.period === 'monthly'
                                                ? 'month'
                                                : 'year'}
                                        </p>
                                        <p>
                                            {plan.trialDays > 0
                                                ? `${plan.trialDays}-day trial`
                                                : 'No trial included'}
                                        </p>
                                        <ul>
                                            {[
                                                'Pay only for the rooms you choose',
                                                'Front desk, rooms, rates and housekeeping',
                                                'Billing, night audit and basic reports',
                                                'Staff roles and permissions',
                                                'Zanzibar compliance included',
                                            ].map((point) => (
                                                <li key={point}>
                                                    <Check aria-hidden />
                                                    {point}
                                                </li>
                                            ))}
                                        </ul>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="lg"
                                        >
                                            <Link href={start}>
                                                {startLabel}
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                                {plans.length === 0 && (
                                    <li className="landing-plan">
                                        <h3>Plan pricing</h3>
                                        <p className="landing-price">
                                            Talk to us
                                        </p>
                                        <p>
                                            No prices are published yet. Contact
                                            us for a plan for your property.
                                        </p>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="lg"
                                        >
                                            <a href={`mailto:${contactEmail}`}>
                                                Contact us
                                            </a>
                                        </Button>
                                    </li>
                                )}
                            </ul>
                        </section>
                        <section
                            className="landing-pad landing-faq"
                            id="faq"
                            aria-labelledby="faq-title"
                        >
                            <div>
                                <h2 id="faq-title">
                                    A few things
                                    <br />
                                    worth knowing.
                                </h2>
                                <p>Clear answers before you get started.</p>
                            </div>
                            <div>
                                {faqs.map(([question, answer]) => (
                                    <details key={question}>
                                        <summary>
                                            {question}
                                            <Plus
                                                className="size-4 shrink-0"
                                                aria-hidden
                                            />
                                        </summary>
                                        <p>{answer}</p>
                                    </details>
                                ))}
                            </div>
                        </section>
                        <section
                            className="landing-closing"
                            aria-labelledby="start-title"
                        >
                            <div>
                                <h2 id="start-title">
                                    Make room for
                                    <br />a better day.
                                </h2>
                                <p>
                                    Bring reservations, rooms and billing
                                    together in Karafuu.
                                </p>
                            </div>
                            <Button asChild size="lg">
                                <Link href={start}>
                                    {startLabel}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        </section>
                    </main>
                    <footer className="public-footer">
                        <span>
                            © {new Date().getFullYear()} {name}
                        </span>
                        <nav aria-label="Footer navigation">
                            <a href="#pricing">Pricing</a>
                            <a href="#faq">FAQ</a>
                            <a href={`mailto:${contactEmail}`}>Contact</a>
                        </nav>
                    </footer>
                </div>
            </div>
        </>
    );
}
