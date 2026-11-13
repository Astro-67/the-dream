import type { GlobalEvent } from '@inertiajs/core';
import { useSyncExternalStore } from 'react';

function subscribe(listener: () => void) {
    window.addEventListener('online', listener);
    window.addEventListener('offline', listener);

    return () => {
        window.removeEventListener('online', listener);
        window.removeEventListener('offline', listener);
    };
}

export function useOnline() {
    return useSyncExternalStore(
        subscribe,
        () => navigator.onLine,
        () => true,
    );
}

export function preventOfflineWrite(event: GlobalEvent<'before'>) {
    if (!navigator.onLine && event.detail.visit.method !== 'get') {
        event.preventDefault();
    }
}
