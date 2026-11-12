<?php

namespace ZoltaGaska\Routing;

use ZoltaGaska\Http\Request;

class Router
{
    private array $routes = [];

    public function __construct(private string $pagesPath)
    {
        $this->pagesPath = rtrim($pagesPath, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pagesPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\.(jsx|js|tsx|ts|php)$/', $file->getFilename())) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->pagesPath) + 1));
            if (preg_match('~(^|/)[_.]~', $relative)) {
                continue;
            }
            // PHP files are API handlers; JS/TS files are browser pages.
            $api = str_starts_with($relative, 'api/');
            if ($api !== ($file->getExtension() === 'php')) {
                continue;
            }
            $segments = explode('/', preg_replace('/\.(jsx|js|tsx|ts|php)$/', '', $relative));
            if (end($segments) === 'index') {
                array_pop($segments);
            }
            [$pattern, $catchAll, $priority] = $this->compile($segments);
            $this->routes[] = [
                'pattern' => $pattern, 'catchAll' => $catchAll,
                'priority' => $priority, 'file' => $file->getPathname(),
            ];
        }
        // Compare each segment: literal > parameter > catch-all > optional catch-all.
        usort($this->routes, static function (array $a, array $b): int {
            $count = max(count($a['priority']), count($b['priority']));
            for ($i = 0; $i < $count; $i++) {
                $difference = ($b['priority'][$i] ?? 5) <=> ($a['priority'][$i] ?? 5);
                if ($difference !== 0) {
                    return $difference;
                }
            }
            return strcmp($a['file'], $b['file']);
        });
    }

    public function dispatch(Request $request): ?Route
    {
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->uri, $matches, PREG_UNMATCHED_AS_NULL)) {
                continue;
            }
            $params = [];
            foreach ($matches as $name => $value) {
                if (!is_string($name) || $value === null) {
                    continue;
                }
                $params[$name] = in_array($name, $route['catchAll'], true)
                    ? array_map('rawurldecode', explode('/', $value))
                    : rawurldecode($value);
            }
            return new Route($route['file'], $params);
        }
        return null;
    }

    private function compile(array $segments): array
    {
        $pattern = '';
        $catchAll = [];
        $priority = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\[\[\.\.\.([a-zA-Z_]\w*)\]\]$/', $segment, $match)) {
                $pattern .= '(?:/(?P<' . $match[1] . '>[^/]+(?:/[^/]+)*))?';
                $catchAll[] = $match[1];
                $priority[] = 1;
            } elseif (preg_match('/^\[\.\.\.([a-zA-Z_]\w*)\]$/', $segment, $match)) {
                $pattern .= '/(?P<' . $match[1] . '>[^/]+(?:/[^/]+)*)';
                $catchAll[] = $match[1];
                $priority[] = 2;
            } elseif (preg_match('/^\[([a-zA-Z_]\w*)\]$/', $segment, $match)) {
                $pattern .= '/(?P<' . $match[1] . '>[^/]+)';
                $priority[] = 3;
            } else {
                $pattern .= '/' . preg_quote($segment, '#');
                $priority[] = 4;
            }
        }
        return ['#^' . $pattern . '/?$#D', $catchAll, $priority];
    }
}
