import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import CreateRatePlan from '@/pages/rates/create';

function render(Page: ComponentType) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'page',
                url: '/',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: { errors: {}, tenant: { property: { id: 'property' } } },
            }}
            initialComponent={Page}
            resolveComponent={async () => Page}
        />,
    );
}

const create = render(() => (
    <CreateRatePlan
        roomTypes={[{ id: 'room', name: 'Double', code: 'DBL' }]}
        currencies={['USD']}
        basePlans={[]}
        policyDefaults={{
            cancellation_free_days: null,
            cancellation_charge: null,
            cancellation_percent: null,
            no_show_charge: null,
            no_show_percent: null,
        }}
    />
));
assert.match(create, /Cancellation policy/);
assert.match(create, /name="cancellation_free_days"[^>]*value=""/);
assert.match(
    create,
    /name="cancellation_charge"[\s\S]*?<option value="" selected="">Nothing/,
);
assert.match(
    create,
    /name="no_show_charge"[\s\S]*?<option value="" selected="">Nothing/,
);
console.log('Rate plan creation includes the default policy: PASS');
