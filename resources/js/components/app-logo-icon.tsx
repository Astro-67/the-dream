import type { SVGAttributes } from 'react';

// Traced from public/thedream-icon.png.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="432 407 1056 1108"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M432 1135 740 990V1515H432Z" />
            <path d="M784 407 1170 617V933L1054 985V1515H784Z" />
            <path d="M1117 955 1488 800V1515H1117Z" />
        </svg>
    );
}
