import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-svh flex-col items-center justify-center gap-8 bg-background p-6 text-center">
                <AppLogoIcon className="size-16 fill-primary" />
                <div className="space-y-2">
                    <h1 className="text-3xl font-semibold">{name}</h1>
                    <p className="text-muted-foreground">
                        Run your hotels from one secure platform.
                    </p>
                </div>
                <nav className="flex gap-3">
                    {auth.user ? (
                        <Button asChild>
                            <Link href={dashboard()}>Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="outline">
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button asChild>
                                <Link href={register()}>Register</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </div>
        </>
    );
}
