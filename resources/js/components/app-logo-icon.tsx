import type { SVGAttributes } from 'react';

// The house and clove mark from public/karafuu-logo.png, in one colour so it takes the surface's fill.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 -14 64 64"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M2 32 32 6l30 26h-8L32 13 10 32Z" />
            <path d="M27 19h4.5v4.5H27Zm5.5 0H37v4.5h-4.5ZM27 24.5h4.5V29H27Zm5.5 0H37V29h-4.5Z" />
            <circle cx="51" cy="10" r="5.5" />
            <path d="M51.6 15.4 46.4 10.6 39.9 20 42.1 22Z" />
        </svg>
    );
}
