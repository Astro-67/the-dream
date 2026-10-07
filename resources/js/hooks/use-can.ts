import { usePage } from '@inertiajs/react';

/**
 * Whether the member holds any of the given permissions in the current
 * property, for hiding actions they can't take. The server still checks every
 * request, so this is never a security boundary.
 */
export function useCan(): (...permissions: string[]) => boolean {
    const { permissions } = usePage().props.auth;

    return (...names) => names.some((name) => permissions.includes(name));
}
