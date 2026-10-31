import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import CreateRatePlan from '@/pages/rates/create';
import EditRatePlan from '@/pages/rates/edit';

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

function edit(kind: 'percent' | 'amount' | null, adjustment: number | null) {
    return render(() => (
        <EditRatePlan
            plan={{
                id: 'plan',
                name: 'Breakfast',
                code: 'BB',
                currency: 'USD',
                room_type_ids: ['room'],
                baseName: kind === null ? null : 'Base',
                adjustment_kind: kind,
                adjustment,
                cancellation_free_days: null,
                cancellation_charge: null,
                cancellation_percent: null,
                no_show_charge: null,
                no_show_percent: null,
            }}
            roomTypes={[{ id: 'room', name: 'Double', code: 'DBL' }]}
        />
    ));
}

const percent = edit('percent', 10);
assert.match(
    percent,
    /name="adjustment_kind"[\s\S]*?<option value="percent" selected="">/,
);
assert.match(percent, /name="adjustment"[^>]*value="10"/);
const amount = edit('amount', -500);
assert.match(
    amount,
    /name="adjustment_kind"[\s\S]*?<option value="amount" selected="">/,
);
assert.match(amount, /id="adjustment"[^>]*value="-5"/);
assert.match(amount, /type="hidden" name="adjustment" value="-500"/);
assert.doesNotMatch(edit(null, null), /name="adjustment/);
console.log('Derived plan editing includes the saved adjustment: PASS');
