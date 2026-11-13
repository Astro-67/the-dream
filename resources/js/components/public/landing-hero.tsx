import { Link } from '@inertiajs/react';
import { ArrowRight, Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';

export default function LandingHero({ signedIn }: { signedIn: boolean }) {
    const frame = useRef<HTMLDivElement>(null);
    const [paused, setPaused] = useState(false);
    const [visible, setVisible] = useState(false);
    const [pageVisible, setPageVisible] = useState(false);
    const [reducedMotion, setReducedMotion] = useState(true);

    useEffect(() => {
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const updateMotion = () => setReducedMotion(motion.matches);
        const updateVisibility = () => setPageVisible(!document.hidden);
        const observer = new IntersectionObserver(
            ([entry]) => setVisible(entry.isIntersecting),
            { threshold: 0.15 },
        );
        updateMotion();
        updateVisibility();
        motion.addEventListener('change', updateMotion);
        document.addEventListener('visibilitychange', updateVisibility);
        if (frame.current) observer.observe(frame.current);

        return () => {
            observer.disconnect();
            motion.removeEventListener('change', updateMotion);
            document.removeEventListener('visibilitychange', updateVisibility);
        };
    }, []);

    return (
        <section
            className="landing-hero landing-pad"
            aria-labelledby="hero-title"
            data-running={visible && pageVisible && !paused && !reducedMotion}
        >
            <div className="hero-copy">
                <h1 id="hero-title">
                    {['Run the day.', 'Welcome the guest.'].map((line) => (
                        <span className="hero-line" key={line}>
                            <span>{line}</span>
                        </span>
                    ))}
                </h1>
                <p>
                    Property management that connects the work behind every
                    stay. From the first reservation to the final receipt.
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <Button asChild size="lg">
                        <Link href={signedIn ? dashboard() : register()}>
                            {signedIn ? 'Go to dashboard' : 'Get started'}
                            <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                    <a href="#product" className="public-text-link">
                        Meet Karafuu
                    </a>
                </div>
            </div>
            <figure>
                <div
                    ref={frame}
                    className="hero-frame"
                    id="hero-photography"
                    role="img"
                    aria-label="A receptionist welcoming a guest, a prepared room, and two colleagues on a veranda"
                >
                    {[
                        'coastal-reception-welcome',
                        'coastal-prepared-room',
                        'coastal-team-veranda',
                    ].map((photo, index) => (
                        <img
                            key={photo}
                            className="hero-scene"
                            src={`/images/marketing/${photo}.webp`}
                            alt=""
                            width="1448"
                            height="1086"
                            fetchPriority={index === 0 ? 'high' : 'auto'}
                        />
                    ))}
                </div>
                <figcaption>
                    <span>For the people behind a warm welcome.</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="hero-toggle"
                        aria-controls="hero-photography"
                        aria-label={
                            paused
                                ? 'Play hero animation'
                                : 'Pause hero animation'
                        }
                        aria-pressed={paused}
                        onClick={() => setPaused(!paused)}
                    >
                        {paused ? (
                            <Play className="size-5" aria-hidden />
                        ) : (
                            <Pause className="size-5" aria-hidden />
                        )}
                    </Button>
                </figcaption>
            </figure>
        </section>
    );
}
