<?php
namespace ZoltaGaska\Frontend;

use ZoltaGaska\Http\HttpException;

final class V8JsRenderer implements Renderer
{
    public function __construct(
        private string $bundle,
        private int $timeoutMs = 5000,
        private int $memoryLimit = 67108864,
        private int $maxOutput = 2097152,
    ) {
        if ($timeoutMs < 1 || $memoryLimit < 1 || $maxOutput < 1) {
            throw new \InvalidArgumentException('Invalid SSR limits.');
        }
    }

    public function render(string $page, array $props): string
    {
        if (!is_file($this->bundle)) throw new HttpException(503, 'Brak builda SSR. Uruchom npm run build.');
        $input = json_encode(['page' => $page, 'props' => (object) $props], JSON_THROW_ON_ERROR);
        if (strlen($input) > 1048576) throw new \RuntimeException('SSR props exceed 1 MiB.');
        if (!extension_loaded('v8js')) {
            throw new HttpException(503, 'SSR wymaga rozszerzenia PHP V8Js. Zainstaluj V8Js lub jawnie ustaw GASKA_SSR=0.');
        }
        $source = file_get_contents($this->bundle);
        if ($source === false) throw new \RuntimeException('Cannot read SSR bundle.');

        // A fresh context prevents component globals from leaking between requests.
        $engine = new \V8Js('Gaska');
        $engine->payload = $input;
        $script = 'var console = {log: function(){}, warn: function(){}, error: function(){}};'
            . $source . "\n;(function() {"
            . 'var input = JSON.parse(Gaska.payload);'
            . 'var html = GaskaSSR.render(input.page, input.props);'
            . 'if (typeof html !== "string") throw new Error("SSR render must return HTML");'
            . 'return html; })();';
        ob_start();
        try {
            $html = $engine->executeString($script, $this->bundle, \V8Js::FLAG_NONE, $this->timeoutMs, $this->memoryLimit);
        } finally {
            ob_end_clean();
        }
        if (!is_string($html)) throw new \RuntimeException('SSR render must return HTML');
        if (strlen($html) > $this->maxOutput) throw new \RuntimeException('SSR output limit exceeded');
        return $html;
    }
}
