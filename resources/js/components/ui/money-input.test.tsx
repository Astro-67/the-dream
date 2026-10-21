import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { MoneyInput, toMinorUnits } from './money-input';
import { formatMoney } from '@/lib/utils';

describe('money input conversion', () => {
    it.each([
        ['25', 'USD', 2500],
        ['25.50', 'USD', 2550],
        ['25.50', 'EUR', 2550],
        ['0.29', 'USD', 29],
        ['0.01', 'USD', 1],
        ['25.506', 'USD', 2551],
        ['25', 'TZS', 25],
        ['0', 'USD', 0],
        ['', 'USD', ''],
        [' ', 'TZS', ''],
        ['invalid', 'USD', ''],
        ['Infinity', 'USD', ''],
        ['9007199254740992', 'TZS', ''],
        ['-25.50', 'USD', -2550],
    ])('converts %s %s to %s minor units', (amount, currency, expected) => {
        expect(toMinorUnits(String(amount), String(currency))).toBe(expected);
    });

    it.each([
        ['USD', 2550, '25.5', '0.01', '100'],
        ['EUR', 2550, '25.5', '0.01', '100'],
        ['TZS', 2550, '2550', '1', '10000'],
    ])(
        'displays minor-unit defaults and limits in %s',
        (currency, minor, visible, step, max) => {
            const html = renderToStaticMarkup(
                <MoneyInput
                    id="amount"
                    name="amount"
                    currency={currency}
                    defaultValue={minor}
                    min={1}
                    max={10000}
                    placeholder={minor}
                    required
                    aria-invalid
                />,
            );
            const inputs = html.match(/<input[^>]+>/g)!;
            expect(inputs).toHaveLength(2);
            expect(inputs[0]).toContain('id="amount"');
            expect(inputs[0]).not.toContain('name=');
            expect(inputs[0]).toContain(`value="${visible}"`);
            expect(inputs[0]).toContain(`step="${step}"`);
            expect(inputs[0]).toContain(`min="${step}"`);
            expect(inputs[0]).toContain(`max="${max}"`);
            expect(inputs[0]).toContain(`placeholder="${visible}"`);
            expect(inputs[0]).toContain('required=""');
            expect(inputs[0]).toContain('aria-invalid="true"');
            expect(inputs[1]).toContain('type="hidden"');
            expect(inputs[1]).toContain('name="amount"');
            expect(inputs[1]).toContain(`value="${minor}"`);
            expect(
                formatMoney(Number(toMinorUnits(visible, currency)), currency),
            ).toBe(formatMoney(minor, currency));
        },
    );

    it('keeps an optional empty amount empty rather than zero', () => {
        const html = renderToStaticMarkup(
            <MoneyInput name="amount" currency="USD" />,
        );
        expect(html).toContain('name="amount" value=""');
    });

    it('omits disabled amounts and respects an external form', () => {
        const html = renderToStaticMarkup(
            <MoneyInput name="amount" currency="USD" form="payment" disabled />,
        );
        const inputs = html.match(/<input[^>]+>/g)!;
        for (const input of inputs) {
            expect(input).toContain('disabled=""');
            expect(input).toContain('form="payment"');
        }
    });
});
