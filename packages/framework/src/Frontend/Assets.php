<?php

namespace ZoltaGaska\Frontend;

class Assets
{
    public function __construct(
        private string $buildDirectory,
        private ?string $devServer = null,
    ) {}

    public function version(): string
    {
        if ($this->devServer) return 'development';
        $manifest = $this->buildDirectory . '/.vite/manifest.json';
        if (!is_file($manifest)) throw new \RuntimeException('Missing frontend manifest.');
        return hash_file('sha256', $manifest);
    }

    public function tags(): string
    {
        if ($this->devServer) {
            $base = rtrim($this->devServer, '/') . '/build';
            $refresh = json_encode($base . '/@react-refresh', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $preamble = '<script type="module">import RefreshRuntime from ' . $refresh . ';'
                . 'RefreshRuntime.injectIntoGlobalHook(window);'
                . 'window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (type) => type;'
                . 'window.__vite_plugin_react_preamble_installed__ = true;</script>';
            return $preamble
                . $this->script($base . '/@vite/client')
                . $this->script($base . '/app/client.jsx');
        }

        $path = $this->buildDirectory . '/.vite/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Brak builda frontendu. Uruchom npm run build lub npm run dev.');
        }
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $entry = $manifest['app/client.jsx'] ?? null;
        if (!is_array($entry) || !isset($entry['file'])) {
            throw new \RuntimeException('Nieaktualny manifest frontendu. Uruchom npm run build.');
        }
        $css = [];
        $visited = [];
        $collect = function (string $key) use (&$collect, &$css, &$visited, $manifest): void {
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            $chunk = $manifest[$key] ?? [];
            foreach ($chunk['imports'] ?? [] as $import) {
                $collect($import);
            }
            foreach ($chunk['css'] ?? [] as $file) {
                $css[$file] = true;
            }
        };
        $collect('app/client.jsx');
        $tags = '';
        foreach (array_keys($css) as $file) {
            $tags .= '<link rel="stylesheet" href="' . self::escape('/build/' . $file) . '">';
        }
        return $tags . $this->script('/build/' . $entry['file']);
    }

    private function script(string $url): string
    {
        return '<script type="module" src="' . self::escape($url) . '"></script>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
