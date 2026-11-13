import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { App } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';
import AuthLayout from '@/layouts/auth-layout';
import ConfirmPassword from '@/pages/auth/confirm-password';
import ForgotPassword from '@/pages/auth/forgot-password';
import Invitation from '@/pages/auth/invitation';
import Login from '@/pages/auth/login';
import Register from '@/pages/auth/register';
import ResetPassword from '@/pages/auth/reset-password';
import TwoFactorChallenge from '@/pages/auth/two-factor-challenge';
import VerifyEmail from '@/pages/auth/verify-email';
import Welcome from '@/pages/welcome';

function render(component: string, content: () => ReactNode, signedIn = false) {
    return renderToStaticMarkup(
        <TooltipProvider>
            <App
                initialPage={{
                    component,
                    url: '/',
                    version: null,
                    clearHistory: false,
                    encryptHistory: false,
                    props: {
                        name: 'Karafuu PMS',
                        errors: {},
                        auth: { user: signedIn ? { id: 'owner' } : null },
                    },
                }}
                initialComponent={content}
                resolveComponent={async () => content}
            />
        </TooltipProvider>,
    );
}

const plans = [
    {
        name: 'Coastal',
        price: 12345,
        currency: 'EUR',
        period: 'yearly' as const,
        trialDays: 21,
    },
    {
        name: 'Town',
        price: 876,
        currency: 'USD',
        period: 'monthly' as const,
        trialDays: 0,
    },
];
const landing = render('welcome', () => (
    <Welcome plans={plans} contactEmail="owner@example.test" />
));
assert.match(landing, /Run the day\./);
assert.match(landing, /EUR 123\.45/);
assert.match(landing, /USD 8\.76/);
assert.match(landing, /per subscribed room \/ year/);
assert.match(landing, /per subscribed room \/ month/);
assert.match(landing, /21-day trial/);
assert.match(landing, /No trial included/);
assert.doesNotMatch(
    landing,
    /free trial|No card|Full product access|Everything in Essential|Growth|Group \/ Enterprise|Hotel operator/i,
);
const pricing =
    landing.match(/<section[^>]*id="pricing"[\s\S]*?<\/section>/)?.[0] ?? '';
assert.equal(
    (pricing.match(/class="landing-plan"/g) ?? []).length,
    plans.length,
);
assert.doesNotMatch(pricing, /Talk to us|Contact us/);
assert.doesNotMatch(landing, /49\.00|hello@example\.com|marketing\/screens/);
assert.match(landing, /aria-label="Pause hero animation"/);
const pauseControl =
    landing.match(
        /<button[^>]*aria-label="Pause hero animation"[^>]*>[\s\S]*?<\/button>/,
    )?.[0] ?? '';
assert.match(pauseControl, /lucide-pause/);
assert.match(pauseControl, /aria-hidden="true"/);
assert.match(pauseControl, /aria-controls="hero-photography"/);
assert.match(pauseControl, /aria-pressed="false"/);
assert.equal(pauseControl.replace(/<[^>]+>/g, '').trim(), '');
assert.match(landing, /aria-label="Main menu"/);
assert.match(landing, /aria-haspopup="menu"/);
assert.doesNotMatch(landing, /landing-flow/);
assert.match(landing, /Welcome the guest\./);
assert.equal((landing.match(/class="hero-line"/g) ?? []).length, 2);
const featureMarkup =
    landing.match(/<section[^>]*id="product"[\s\S]*?<\/section>/)?.[0] ?? '';
assert.match(featureMarkup, /Reservations and guest history/);
assert.doesNotMatch(featureMarkup, /lucide-check/);
assert.match(
    landing.match(/<section[^>]*id="pricing"[\s\S]*?<\/section>/)?.[0] ?? '',
    /lucide-check/,
);
assert.equal((landing.match(/class="hero-scene"/g) ?? []).length, 3);
assert.deepEqual(
    [...landing.matchAll(/class="hero-scene" src="([^"]+)"/g)].map(
        (match) => match[1],
    ),
    [
        '/images/marketing/coastal-reception-welcome.webp',
        '/images/marketing/coastal-prepared-room.webp',
        '/images/marketing/coastal-team-veranda.webp',
    ],
);
assert.match(landing, /two colleagues on a veranda/);
assert.doesNotMatch(
    landing,
    /images\/marketing\/(?:hero|feature-front-desk|feature-housekeeping|feature-billing|feature-team|cta)\.webp/,
);
assert.match(landing, /data-running="false"/);
const footer = landing.slice(landing.indexOf('<footer'));
assert.match(footer, /© .*Karafuu PMS/);
assert.match(footer, /href="#pricing">Pricing/);
assert.match(footer, /href="#faq">FAQ/);
assert.match(footer, /href="mailto:owner@example.test">Contact/);
const empty = render('welcome', () => (
    <Welcome plans={[]} contactEmail="sales@example.test" />
));
assert.match(empty, /No prices are published yet/);
const emptyPricing =
    empty.match(/<section[^>]*id="pricing"[\s\S]*?<\/section>/)?.[0] ?? '';
assert.equal((emptyPricing.match(/class="landing-plan"/g) ?? []).length, 1);
assert.match(emptyPricing, /href="mailto:sales@example.test"/);
assert.doesNotMatch(empty, /Growth|Group \/ Enterprise|free trial/i);
assert.doesNotMatch(empty, /undefined|NaN/);
const updated = render('welcome', () => (
    <Welcome
        plans={[
            {
                name: 'Admin configured',
                price: 76543,
                currency: 'TZS',
                period: 'monthly',
                trialDays: 5,
            },
        ]}
        contactEmail="sales@example.test"
    />
));
assert.match(updated, /Admin configured/);
assert.match(updated, /TZS 76,543/);
assert.match(updated, /per subscribed room \/ month/);
assert.match(updated, /5-day trial/);
assert.doesNotMatch(
    updated,
    /Coastal|123\.45|21-day|Growth|Group \/ Enterprise/,
);
const member = render(
    'welcome',
    () => <Welcome plans={plans} contactEmail="sales@example.test" />,
    true,
);
assert.match(member, /href="\/dashboard"/);
assert.doesNotMatch(member, /href="\/register"/);

const policy = {
    passwordRules: 'minlength: 15; required: upper;',
    passwordHint: 'Use at least 15 characters and an uppercase letter.',
};
const authPages = [
    [
        'login',
        <Login canResetPassword status="Password updated." />,
        Login.layout,
    ],
    ['register', <Register {...policy} />, Register.layout],
    [
        'forgot-password',
        <ForgotPassword status="Reset link sent." />,
        ForgotPassword.layout,
    ],
    [
        'reset-password',
        <ResetPassword
            {...policy}
            token="real-reset-token"
            email="owner@example.test"
        />,
        ResetPassword.layout,
    ],
    ['confirm-password', <ConfirmPassword />, ConfirmPassword.layout],
    [
        'verify-email',
        <VerifyEmail
            email="owner@example.test"
            status="verification-link-sent"
        />,
        VerifyEmail.layout,
    ],
    [
        'two-factor-challenge',
        <TwoFactorChallenge />,
        { title: 'Authentication code', description: 'Confirm access.' },
    ],
] as const;

for (const [page, content, layout] of authPages) {
    const html = render(`auth/${page}`, () => (
        <AuthLayout {...layout}>{content}</AuthLayout>
    ));
    assert.match(html, /auth-site/);
    assert.match(
        html,
        /images\/marketing\/coastal-doorway-ocean-portrait.webp/,
    );
    assert.match(
        html,
        /Carved wooden doors opening onto a sunlit courtyard and ocean view/,
    );
    assert.match(html, /karafuu-logo-dark.png/);
    assert.match(html, /Theme: System/);
    assert.match(
        html.match(/<a\b[^>]*class="public-back"[^>]*>/)?.[0] ?? '',
        /aria-label="Back to website"/,
    );
    assert.equal((html.match(/<h1/g) ?? []).length, 1);
    assert.doesNotMatch(html, /tabindex="[1-9]/i);
    assert.doesNotMatch(html, /readonly=""[^>]*type="password"/);
    if (page === 'register' || page === 'reset-password') {
        assert.match(html, /minlength: 15; required: upper;/);
        assert.match(html, /Use at least 15 characters/);
        assert.match(html, /aria-controls="password"/);
        assert.match(html, /aria-describedby="password-hint"/);
        assert.doesNotMatch(
            html,
            /aria-label="Show password"[^>]*tabindex="-1"/,
        );
    }
    if (page === 'login') {
        assert.match(html, /name="remember"/);
        assert.match(html, /href="\/forgot-password"/);
        assert.doesNotMatch(html, /Sign in with a passkey/);
    }
    if (page === 'verify-email') {
        assert.match(html, /aria-current="step"><span aria-hidden="true">2/);
        assert.match(html, /owner@example\.test/);
        assert.match(html, /verification-notification/);
        assert.doesNotMatch(html, /Preview verified|data-next/);
    }
}

const invitation = {
    organization: 'A very long organization name '.repeat(5),
    role: 'Front desk',
    email: 'invited@example.test',
    invitedBy: 'Owner',
    expiresAt: '2026-12-10T00:00:00Z',
};
for (const [signedInAs, refusal] of [
    [null, null],
    ['invited@example.test', null],
    ['wrong@example.test', 'This invitation is for another email.'],
] as const) {
    const html = render('auth/invitation', () => (
        <AuthLayout {...Invitation.layout}>
            <Invitation
                invitation={invitation}
                signedInAs={signedInAs}
                refusal={refusal}
                link={{ organization: 'org', token: 'secret' }}
            />
        </AuthLayout>
    ));
    assert.match(html, /auto_minmax\(0,1fr\)/);
    if (refusal) {
        assert.match(html, /This invitation is for another email/);
        assert.doesNotMatch(html, /Accept and join/);
    } else if (signedInAs) {
        assert.match(html, /Accept and join/);
    } else {
        assert.match(html, /href="\/login"/);
        assert.match(html, /href="\/register"/);
    }
}

const heroSource = readFileSync(
    'resources/js/components/public/landing-hero.tsx',
    'utf8',
);
assert.match(heroSource, /visible && pageVisible && !paused && !reducedMotion/);
assert.match(heroSource, /visibilitychange/);
assert.match(heroSource, /observer\.disconnect/);
assert.match(heroSource, /removeEventListener\('change'/);
const css = readFileSync('resources/css/landing.css', 'utf8');
assert.match(
    css,
    /\.landing-hero h1\s*\{[^}]*font-family: var\(--font-sans\);[^}]*font-weight: 500;/,
);
assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
assert.match(
    css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)')),
    /\.landing-menu-content,/,
);
assert.match(css, /\.hero-scene:first-child\s*\{\s*opacity: 1/);
const authCss = readFileSync('resources/css/public-auth.css', 'utf8');
assert.match(
    authCss,
    /@media \(max-width: 760px\)[\s\S]*\.public-back span\s*\{\s*display: none;/,
);
console.log(
    'Public/auth: live pricing, real navigation, nine page surfaces, password policy, invitation states and motion safeguards PASS',
);
