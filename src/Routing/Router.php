<?php

namespace ZoltaGaska\Routing;

use ZoltaGaska\Http\Request;

class Router
{
    protected array $routes = [];

    public function __construct(protected string $pagesPath)
    {
        $this->routes = $this->scanPagesDirectory();
    }

    public function dispatch(Request $request): ?Route
    {
        foreach ($this->routes as $pattern => $filePath) {
            if (preg_match($pattern, $request->uri, $matches)) {
                // Usuń z dopasowań cały string (indeks 0)
                array_shift($matches);

                // Pobierz nazwy parametrów z wyrażenia regularnego
                preg_match_all('~\(\?P<(\w+)>~_is', $pattern, $paramNames);
                $paramNames = $paramNames[1];

                $params = [];
                foreach ($matches as $index => $value) {
                    // Obsługa catch-all, gdzie parametr jest tablicą
                    if (isset($paramNames[$index])) {
                        if (str_starts_with($paramNames[$index], 'slug')) {
                             $params[$paramNames[$index]] = explode('/', $value);
                        } else {
                             $params[$paramNames[$index]] = $value;
                        }
                    }
                }

                return new Route($filePath, $params);
            }
        }

        return null; // Nie znaleziono trasy
    }

    private function scanPagesDirectory(): array
    {
        $routes = [];
        $directory = new \RecursiveDirectoryIterator($this->pagesPath);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = new \RegexIterator($iterator, '/\.(?:jsx|js|ts|tsx|php)$/');

        foreach ($files as $file) {
            // `app/pages/posts/[id].jsx` => `/posts/[id]`
            $routePath = str_replace([$this->pagesPath, '.jsx', '.js', '.tsx', '.ts', '.php'], '', $file->getPathname());
            
            // `index` staje się `/`
            if (str_ends_with($routePath, '/index')) {
                $routePath = rtrim(substr($routePath, 0, -5), '/');
                if (empty($routePath)) {
                    $routePath = '/';
                }
            }

            $regex = $this->convertToRegex($routePath);
            $routes[$regex] = $file->getPathname();
        }
        
        // Sortuj, aby trasy statyczne miały priorytet nad dynamicznymi
        krsort($routes);

        return $routes;
    }

    private function convertToRegex(string $routePath): string
    {
        // Obsługa opcjonalnych tras catch-all: /search/[[...query]]
        $pattern = preg_replace('/\[\[\.\.\.(\w+)\]\]/', '(?:/(?P<$1>.+))?', $routePath);

        // Obsługa tras catch-all: /docs/[...slug]
        $pattern = preg_replace('/\[\.\.\.(\w+)\]/', '/(?P<$1>.+)', $pattern);

        // Obsługa tras dynamicznych: /posts/[id]
        $pattern = preg_replace('/\[(\w+)\]/', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $pattern . '$#';
    }
}
