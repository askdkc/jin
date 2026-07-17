<script lang="ts">
    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 48 48"
    class={className}
    {...rest}
>
    <defs>
        <radialGradient id="ivory" cx="32%" cy="24%" r="76%">
            <stop offset="0" stop-color="#fffdf4" />
            <stop offset="0.68" stop-color="#eee0bd" />
            <stop offset="1" stop-color="#c9ad75" />
        </radialGradient>
        <linearGradient id="bronze-rim" x1="0" y1="0" x2="1" y2="1">
            <stop stop-color="#fff0ad" />
            <stop offset="0.45" stop-color="#b27b25" />
            <stop offset="1" stop-color="#6f4515" />
        </linearGradient>
        <linearGradient id="lacquer" x1="0" y1="0" x2="1" y2="1">
            <stop stop-color="#55514a" />
            <stop offset="0.38" stop-color="#242321" />
            <stop offset="1" stop-color="#080909" />
        </linearGradient>
        <linearGradient id="handle" x1="0" y1="0" x2="1" y2="0">
            <stop stop-color="#070808" />
            <stop offset="0.48" stop-color="#3f403c" />
            <stop offset="1" stop-color="#090a0a" />
        </linearGradient>
        <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1">
            <stop stop-color="#fff08b" />
            <stop offset="0.36" stop-color="#f4be24" />
            <stop offset="1" stop-color="#a86608" />
        </linearGradient>
        <filter id="outer-shadow" x="-20%" y="-20%" width="140%" height="150%">
            <feDropShadow
                dx="0"
                dy="1.5"
                stdDeviation="1.2"
                flood-opacity="0.28"
            />
        </filter>
        <filter id="object-shadow" x="-20%" y="-20%" width="140%" height="150%">
            <feDropShadow
                dx="0"
                dy="1.2"
                stdDeviation="1"
                flood-opacity="0.55"
            />
        </filter>
        <path
            id="gunbai-head"
            d="M24 5c6 0 11 1 14 5 3 4 1.5 9-2 12 4 3 5 8 2 12-3 4-9 4-14 1-5 3-11 3-14-1-3-4-2-9 2-12-3-3-5-8-2-12 3-4 8-5 14-5Z"
        />
    </defs>

    <circle
        cx="24"
        cy="23.5"
        r="21.5"
        fill="url(#ivory)"
        stroke="url(#bronze-rim)"
        stroke-width="1.8"
        filter="url(#outer-shadow)"
    />
    <circle
        cx="23.5"
        cy="23"
        r="19.8"
        fill="none"
        stroke="#fff"
        stroke-width="0.8"
        opacity="0.65"
    />

    <g transform="rotate(-31 24 24)">
        <use
            href="#gunbai-head"
            fill="url(#lacquer)"
            filter="url(#object-shadow)"
        />
        <use
            href="#gunbai-head"
            fill="none"
            stroke="#77736a"
            stroke-width="1"
            opacity="0.48"
        />

        <circle
            cx="15.5"
            cy="17.5"
            r="4.5"
            fill="url(#gold)"
            stroke="#7a4805"
            stroke-width="0.6"
        />
        <circle cx="14.2" cy="16.1" r="1.5" fill="#fff7b0" opacity="0.45" />
        <path
            d="M31.3 11a5.2 5.2 0 1 0 4.8 8 5.8 5.8 0 0 1-4.8-8Z"
            fill="url(#gold)"
            stroke="#7a4805"
            stroke-width="0.55"
        />

        <rect
            x="21.7"
            y="3.5"
            width="4.6"
            height="40"
            rx="2.1"
            fill="url(#handle)"
            filter="url(#object-shadow)"
        />
        <path
            d="M23.6 5v36.5"
            stroke="#fff"
            stroke-width="0.65"
            opacity="0.16"
        />
        <rect
            x="21.25"
            y="2.5"
            width="5.5"
            height="4.6"
            rx="1.1"
            fill="url(#gold)"
            stroke="#8a5308"
            stroke-width="0.55"
        />
        <rect
            x="21.25"
            y="40"
            width="5.5"
            height="4.5"
            rx="1.1"
            fill="url(#gold)"
            stroke="#8a5308"
            stroke-width="0.55"
        />
    </g>
</svg>
