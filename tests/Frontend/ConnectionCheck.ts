import assert from 'node:assert/strict';
import { preventOfflineWrite } from '@/hooks/use-online';

const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');

try {
    for (const online of [true, false]) {
        Object.defineProperty(globalThis, 'navigator', {
            value: { onLine: online },
            configurable: true,
        });

        for (const method of [
            'get',
            'post',
            'put',
            'patch',
            'delete',
        ] as const) {
            const event = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: { visit: { method } },
            });
            preventOfflineWrite(event);
            assert.equal(
                event.defaultPrevented,
                !online && method !== 'get',
                `${method} ${online ? 'online' : 'offline'}`,
            );
        }
    }
} finally {
    if (original) Object.defineProperty(globalThis, 'navigator', original);
    else Reflect.deleteProperty(globalThis, 'navigator');
}

console.log(
    'Operational offline writes blocked; online writes and reads unchanged: PASS',
);
