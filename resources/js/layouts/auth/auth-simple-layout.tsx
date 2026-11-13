import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AppearanceToggle } from '@/components/appearance-toggle';
import BrandLogo from '@/components/brand-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';
import '../../../css/public-auth.css';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { component, props } = usePage();
    const isSignup = ['auth/register', 'auth/verify-email'].includes(component);
    const signupStep = component === 'auth/verify-email' ? 1 : 0;

    return (
        <div className="public-surface auth-site">
            <a className="public-skip" href="#auth-content">
                Skip to content
            </a>
            <div className="public-wrap">
                <header className="public-topbar">
                    <Link href={home()} aria-label="Karafuu PMS home">
                        <BrandLogo className="public-brand" />
                    </Link>
                    <div className="flex items-center gap-3">
                        <AppearanceToggle />
                        <Link
                            href={home()}
                            className="public-back"
                            aria-label="Back to website"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            <span>Back to website</span>
                        </Link>
                    </div>
                </header>
                {isSignup && (
                    <ol className="auth-steps" aria-label="Account setup">
                        {['Account', 'Email', 'Business', 'Property'].map(
                            (step, index) => (
                                <li
                                    key={step}
                                    aria-current={
                                        index === signupStep
                                            ? 'step'
                                            : undefined
                                    }
                                >
                                    <span aria-hidden>{index + 1}</span>
                                    {step}
                                </li>
                            ),
                        )}
                    </ol>
                )}
                <main className="auth-layout" id="auth-content">
                    <section
                        className="auth-panel"
                        aria-labelledby="auth-title"
                    >
                        <div className="auth-content">
                            <div className="auth-intro">
                                <h1 id="auth-title">{title}</h1>
                                <p>{description}</p>
                            </div>
                            {children}
                        </div>
                    </section>
                    <aside
                        className="auth-photo"
                        aria-label="Karafuu hospitality"
                    >
                        <img
                            src="/images/marketing/coastal-doorway-ocean-portrait.webp"
                            alt="Carved wooden doors opening onto a sunlit courtyard and ocean view"
                            width="1024"
                            height="1536"
                        />
                        <div>
                            <h2>
                                A warm welcome.
                                <br />A well-run day.
                            </h2>
                            <p>From the first arrival to the final receipt.</p>
                        </div>
                        {isSignup ? (
                            <div className="auth-signoff">
                                <p>
                                    Your account. Your business. Your property.
                                </p>
                                <p>One connected start.</p>
                            </div>
                        ) : (
                            <p className="auth-signoff">
                                The heart of hospitality.
                            </p>
                        )}
                    </aside>
                </main>
                <footer className="public-footer">
                    <span>
                        © {new Date().getFullYear()} {props.name}
                    </span>
                </footer>
            </div>
        </div>
    );
}
