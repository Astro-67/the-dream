import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Reads the theme tokens from app.css and checks them against design.md's WCAG
// thresholds and the approved navy, warm-neutral direction (spec #205).
const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

function block(selector: string): Record<string, string> {
    const body = css.match(new RegExp(`^${selector} \\{([^}]*)\\}`, 'm'))?.[1];
    assert.ok(body, `${selector} block missing`);

    return Object.fromEntries(
        [...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map((m) => [
            m[1],
            m[2].trim(),
        ]),
    );
}

type Rgb = [number, number, number];

function hex(value: string): Rgb {
    const h = value.replace('#', '');
    assert.match(h, /^[0-9a-f]{6}$/i, `expected a 6-digit hex, got ${value}`);

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as Rgb;
}

function resolve(tokens: Record<string, string>, name: string): Rgb {
    const value = tokens[name];
    assert.ok(value, `--${name} missing`);
    const ref = value.match(/^var\(--([\w-]+)\)$/);
    if (ref) return resolve(tokens, ref[1]);
    const mix = value.match(/^color-mix\(in srgb, (.+) (\d+)%, (.+)\)$/);
    if (!mix) return hex(value);
    const side = (v: string) =>
        v.startsWith('var(') ? resolve(tokens, v.slice(6, -1)) : hex(v);
    const [a, p, b] = [side(mix[1]), Number(mix[2]) / 100, side(mix[3])];

    return a.map((c, i) => c * p + b[i] * (1 - p)) as Rgb;
}

function luminance([r, g, b]: Rgb): number {
    const lin = (c: number) => {
        const s = c / 255;
        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function contrast(a: Rgb, b: Rgb): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
}

function hue([r, g, b]: Rgb): number {
    const [max, min] = [Math.max(r, g, b), Math.min(r, g, b)];
    if (max === min) return 0;
    const d = max - min;
    const h =
        max === r ? (g - b) / d : max === g ? (b - r) / d + 2 : (r - g) / d + 4;

    return (h * 60 + 360) % 360;
}

const statuses = [
    'success',
    'warning',
    'error',
    'info',
    'unavailable',
    'occupied',
    'inspection',
];

for (const [theme, selector] of [
    ['light', ':root'],
    ['dark', '\\.dark'],
] as const) {
    const t = block(selector);
    const c = (name: string) => resolve(t, name);
    const pass = (fg: string, bg: string, min: number) => {
        const ratio = contrast(c(fg), c(bg));
        assert.ok(
            ratio >= min,
            `${theme}: --${fg} on --${bg} is ${ratio.toFixed(2)}:1, needs ${min}:1`,
        );
    };

    for (const bg of ['background', 'card', 'popover', 'secondary', 'muted']) {
        pass('foreground', bg, 4.5);
        pass('muted-foreground', bg, 4.5);
    }
    for (const bg of ['background', 'card']) {
        pass('text-muted', bg, 4.5);
        pass('primary', bg, 4.5); // links
        pass('input', bg, 3);
        pass('ring', bg, 3);
        pass('brand-accent', bg, 3); // graphic only, never text
    }
    pass('primary-foreground', 'primary', 4.5);
    pass('primary-foreground', 'primary-hover', 4.5);
    pass('primary', 'primary-tint', 4.5); // active filters
    pass('sidebar-foreground', 'sidebar', 4.5);
    pass('sidebar-accent-foreground', 'sidebar-accent', 4.5);
    pass('accent-foreground', 'accent', 4.5);
    pass(
        theme === 'light' ? 'destructive-foreground' : 'destructive',
        'card',
        4.5,
    );
    for (const s of statuses) {
        pass(`status-${s}`, `status-${s}-tint`, 4.5);
        pass(`status-${s}`, 'card', 4.5);
    }

    const primaryHue = hue(c('primary'));
    assert.ok(
        primaryHue >= 210 && primaryHue <= 232,
        `${theme}: --primary hue ${primaryHue.toFixed(0)} is not navy`,
    );
    const accentHue = hue(c('brand-accent'));
    assert.ok(
        accentHue >= 85 && accentHue <= 125,
        `${theme}: --brand-accent must be the logo's leaf green`,
    );
    assert.ok(
        Math.abs(accentHue - hue(c('status-success'))) >= 25,
        `${theme}: --brand-accent must stay distinguishable from the success status`,
    );
}

const [r, , b] = resolve(block(':root'), 'background');
assert.ok(r > b, 'light --background must be a warm neutral');

console.log('Theme tokens contrast and brand direction: PASS');

for (const selector of [':root', '\\.dark']) {
    const t = block(selector);
    for (const name of ['elevation-panel', 'elevation-primary']) {
        assert.ok(t[name], `${selector}: --${name} elevation token missing`);
        assert.doesNotMatch(
            t[name],
            /gradient/,
            'elevation must not be a gradient',
        );
    }
}
assert.match(
    css,
    /@media print[^]*box-shadow: none !important/,
    'print surfaces must drop screen elevation',
);
console.log('Elevation tokens: PASS');
