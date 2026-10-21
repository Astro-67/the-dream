import { useEffect, useRef, useState } from 'react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { currencyDecimals } from '@/lib/utils';

export function toMinorUnits(value: string, currency: string): number | '' {
    if (value.trim() === '') return '';
    const minor = Math.round(Number(value) * 10 ** currencyDecimals(currency));
    return Number.isSafeInteger(minor) ? minor : '';
}

type MoneyInputProps = Omit<
    ComponentProps<'input'>,
    'type' | 'step' | 'value' | 'defaultValue' | 'min' | 'max' | 'placeholder'
> & {
    currency: string;
    defaultValue?: number | string;
    min?: number;
    max?: number;
    placeholder?: number;
};

export function MoneyInput({
    currency,
    name,
    defaultValue = '',
    min,
    max,
    placeholder,
    onChange,
    ...props
}: MoneyInputProps) {
    const factor = 10 ** currencyDecimals(currency);
    const [initial] = useState(
        defaultValue === '' ? '' : String(Number(defaultValue) / factor),
    );
    const [amount, setAmount] = useState(initial);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const form = input.current?.form;
        const reset = () => setAmount(initial);
        form?.addEventListener('reset', reset);
        return () => form?.removeEventListener('reset', reset);
    }, [initial]);

    return (
        <>
            <Input
                {...props}
                ref={input}
                type="number"
                inputMode={factor === 1 ? 'numeric' : 'decimal'}
                step={1 / factor}
                min={min === undefined ? undefined : min / factor}
                max={max === undefined ? undefined : max / factor}
                placeholder={
                    placeholder === undefined
                        ? undefined
                        : String(placeholder / factor)
                }
                value={amount}
                onChange={(event) => {
                    setAmount(event.target.value);
                    onChange?.(event);
                }}
            />
            <input
                type="hidden"
                name={name}
                value={toMinorUnits(amount, currency)}
                disabled={props.disabled}
                form={props.form}
            />
        </>
    );
}
