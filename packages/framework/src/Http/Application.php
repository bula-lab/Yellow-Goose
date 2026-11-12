<?php
namespace ZoltaGaska\Http;

use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Frontend\Renderer;
use ZoltaGaska\Routing\Router;

class Application
{
    private Router $router;
    private string $loadersPath;

    public function __construct(
        private string $pagesPath, private Assets $assets,
        private string $csrfToken = '', ?string $loadersPath = null,
        private ?Renderer $renderer = null,
        private array $document = [],
    ) {
        $this->pagesPath = rtrim($pagesPath, '/\\');
        $this->loadersPath = $loadersPath ?? dirname($this->pagesPath) . '/loaders';
        $this->router = new Router($this->pagesPath);
    }

    public function handle(Request $request): Response
    {
        $api = $request->uri === '/api' || str_starts_with($request->uri, '/api/');
        try {
            $route = $this->router->dispatch($request);
            if ($route === null) throw new HttpException(404, 'Nie znaleziono strony lub zasobu.');
            if ($api) {
                if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $token = $request->header('X-CSRF-Token', '');
                    if (strlen($this->csrfToken) < 32 || !hash_equals($this->csrfToken, $token)) {
                        throw new HttpException(403, 'Sesja wygasła lub token CSRF jest nieprawidłowy. Odśwież stronę.');
                    }
                }
                $handler = require $route->filePath;
                if (!is_callable($handler)) throw new \RuntimeException('API handler must return a callable.');
                $result = $handler($request, $route->params);
                return $result instanceof Response ? $result : Response::json($result);
            }
            $request->requireMethod(['GET', 'HEAD']);
            try { $tags = $this->assets->tags(); }
            catch (\RuntimeException) {
                throw new HttpException(503, 'Frontend niedostępny. Uruchom npm run build lub serwery development.');
            }

            $page = str_replace('\\', '/', substr($route->filePath, strlen($this->pagesPath) + 1));
            $loaderFile = $this->loadersPath . '/' . preg_replace('/\.(jsx|js|tsx|ts)$/', '.php', $page);
            $props = [];
            if (is_file($loaderFile)) {
                $loader = require $loaderFile;
                if (!is_callable($loader)) throw new \RuntimeException('Page loader must return a callable.');
                $props = $loader($request, $route->params);
                if ($props instanceof Response) return $props;
                if (!is_array($props)) throw new \RuntimeException('Page loader must return props or a response.');
            }
            // Framework props cannot be overwritten by a loader.
            $props = ['params' => $route->params, 'csrfToken' => $this->csrfToken] + $props;
            $data = json_encode([
                'page' => $page,
                'props' => $props,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $html = $this->renderer?->render($page, $props) ?? '<p>Ładowanie strony…</p>';
            $mode = $this->renderer === null ? 'csr' : 'ssr';
            $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = $escape($this->document['title'] ?? 'Żółta-Gąska');
            $icons = '';
            foreach (['favicon' => 'icon', 'appleTouchIcon' => 'apple-touch-icon'] as $key => $rel) {
                if (!empty($this->document[$key])) {
                    $icons .= '<link rel="' . $rel . '" href="' . $escape($this->document[$key]) . '">';
                }
            }
            return Response::html(<<<HTML
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    {$icons}
    {$tags}
</head>
<body>
    <div id="app" data-render-mode="{$mode}">{$html}</div>
    <noscript>Włącz JavaScript, aby korzystać z formularzy i przycisków.</noscript>
    <script id="page-data" type="application/json">{$data}</script>
</body>
</html>
HTML);
        } catch (HttpException $error) {
            if ($api) return Response::json(
                ['error' => $error->getMessage(), 'errors' => $error->errors], $error->status, $error->headers,
            );
            $message = htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return new Response('<!doctype html><html lang="pl"><meta charset="utf-8"><title>'
                . $error->status . '</title><h1>' . $error->status . '</h1><p>' . $message
                . '</p><a href="/">Strona główna</a></html>', $error->status,
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'] + $error->headers);
        } catch (\Throwable $error) {
            error_log((string) $error);
            return $api
                ? Response::json(['error' => 'Wewnętrzny błąd serwera.'], 500)
                : Response::html('<!doctype html><html lang="pl"><meta charset="utf-8"><title>Błąd</title><h1>Wewnętrzny błąd serwera</h1></html>', 500);
        }
    }
}
