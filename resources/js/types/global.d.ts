import type { Auth } from '@/types/auth';
import type { Tenant } from '@/types/tenancy';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            tenant: Tenant;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
