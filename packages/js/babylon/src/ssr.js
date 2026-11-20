// The SSR build redirects the browser entry here. No engine or browser globals are evaluated.
export function mount() { throw new Error('A viewport can only mount in a browser.'); }
