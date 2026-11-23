import { useState } from 'react';

const sites = {
    mine: { x: 174, y: 177, label: 'Mine' },
    lab: { x: 362, y: 105, label: 'Lab' },
    reactor: { x: 438, y: 219, label: 'Reactor' },
};

function Building({ kind }) {
    if (kind === 'mine') return <g>
        <path fill="#824e40" d="m-35 -13 48 -20 35 14 -48 22z"/><path fill="#4f3432" d="m0 3 48 -22v21L0 24z"/>
        <path fill="#ba7756" d="m-35 -13 35 16v21l-35 -16z"/><path fill="#ebaf6c" d="m-19 -12 9 -4 24 11 -9 4z"/>
        <path fill="#65483f" d="M-23 -28v-26l8 -4v27z"/><path fill="#be916a" d="m-32 -33 9 5v-26l-9 -4z"/>
        <path fill="#f4c888" d="m-32 -58 7 -3 10 3 -8 4z"/>
    </g>;
    if (kind === 'lab') return <g>
        <path fill="#2d5d67" d="m-42 0 42 -18 44 17v12L0 31l-42 -19z"/>
        <path fill="#8fc6bd" d="m-42 0 42 -18 44 17L0 18z"/>
        <path fill="#b8e1d2" d="M-29 -2a29 29 0 0 1 58 0Q0 13 -29 -2z"/>
        <path d="M-14 -1q0 -19 14 -29q14 10 14 29M-24 -16q24 10 48 0" fill="none" stroke="#4b8e92" strokeWidth="2"/>
    </g>;
    return <g>
        <ellipse cy="8" rx="33" ry="13" fill="#544839"/><path fill="#b17f4c" d="M-23 -37q23 10 46 0V3q-23 17 -46 0z"/>
        <ellipse cy="-37" rx="23" ry="9" fill="#e6bd77"/><ellipse cy="-37" rx="13" ry="5" fill="#6a6252"/>
        <path d="M-18 -22q18 9 36 0m-36 13q18 9 36 0" fill="none" stroke="#644b38" strokeWidth="4"/>
    </g>;
}

export function ColonyMap({ levels }) {
    const [selection, select] = useState('mine');
    const [zoom, setZoom] = useState(1);
    const size = 620 / zoom;
    return <section className="map" aria-label="Colony map">
        <div className="map-tools"><span>Surface map</span><div>
            <button aria-label="Zoom out" disabled={zoom <= 1} onClick={() => setZoom(z => Math.max(1, z - 0.25))}>−</button>
            <button aria-label="Zoom in" disabled={zoom >= 1.75} onClick={() => setZoom(z => Math.min(1.75, z + 0.25))}>+</button>
        </div></div>
        <svg data-testid="colony-map" data-selected={selection} data-zoom={zoom} aria-label="Colony sites"
            viewBox={[(620 - size) / 2, (340 - size * 340 / 620) / 2, size, size * 340 / 620].join(' ')}>
            <defs><pattern id="surface-grid" width="50" height="24" patternUnits="userSpaceOnUse">
                <path d="m0 12 25 -12 25 12 -25 12z" fill="none" stroke="#64817c" strokeWidth=".65" opacity=".42"/></pattern>
                <clipPath id="plateau"><path d="m30 178 45 -40 82 -29 43 -23 111 -44 65 20 40 2 47 31 61 27 62 54 -11 29 -60 25 -48 42 -108 43 -48 -4 -59 17 -65 -40 -50 -17 -79 -51z"/></clipPath>
            </defs>
            <g clipPath="url(#plateau)">
                <path fill="#2e4946" d="M0 0h620v340H0z"/><path fill="#385651" d="m-5 129 173 -48 101 6 82 -56 144 50 -84 51 -124 -4 -108 52 -81 -6z"/>
                <path fill="#45605a" d="m-10 199 132 -61 49 30 70 -41 112 -17 58 -40 67 13 -54 37 -76 8 -90 33 -88 28 -98 33z"/>
                <path fill="#233e3d" d="m17 214 65 -28 79 17 104 -35 78 7 47 -19 76 -1 60 -39 89 18v123l-48 -7 -60 -43 -52 22 -46 -18 -128 23 -62 -2 -97 24z"/>
                <rect width="620" height="340" fill="url(#surface-grid)"/>
            </g>
            <path d="m165 181 90 37 114 -55 73 32m-73 -32 -56 -25 47 -23" fill="none" stroke="#879381" strokeWidth="9"/>
            <path d="m165 181 90 37 114 -55 73 32m-73 -32 -56 -25 47 -23" fill="none" stroke="#d6c398" strokeWidth="1.4" strokeDasharray="5 5"/>
            {Object.entries(sites).map(([kind, { x, y, label }]) => <g key={kind} transform={'translate(' + x + ' ' + y + ')'}
                role="button" tabIndex="0" aria-label={'Select ' + label} aria-pressed={selection === kind} onClick={() => select(kind)}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(kind); } }}>
                <ellipse cy="7" rx="55" ry="23" fill="#152e2c" opacity=".7" />
                {selection === kind && <ellipse cy="7" rx="55" ry="23" fill="none" stroke="#f6d797" strokeDasharray="4 4"/>}
                <Building kind={kind}/><text y="52" textAnchor="middle">{label}</text>
            </g>)}
        </svg><div className="map-selection"><span>Selected site</span><strong>{sites[selection].label}</strong>
            <span>Level {levels[selection] || 0}</span></div>
    </section>;
}
