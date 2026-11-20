# @yellow-goose/vite

Yellow-Goose vite exports. Install the exact peer versions declared in package.json.
Node is used for development/build only. PHP serves the compiled application.

Viewer clients dynamically load the Babylon adapter after hydration.
The SSR resolver substitutes `@yellow-goose/babylon/ssr` for an import of `@yellow-goose/babylon/engine`.
The base build resolves this optional package only when a viewer is present.
