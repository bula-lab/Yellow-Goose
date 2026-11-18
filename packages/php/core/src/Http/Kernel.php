<?php
namespace ZoltaGaska\Http;

use ZoltaGaska\Module\Registry;

final class Kernel
{
    private ?\Closure $authorize;

    public function __construct(private Registry $registry, ?callable $authorize = null)
    {
        $this->authorize = $authorize === null ? null : \Closure::fromCallable($authorize);
        foreach ($registry->routes() as $route) {
            if ($route['policies'] && $this->authorize === null) throw new \LogicException('Protected route requires an authorizer.');
        }
    }

    public function handle(Request $request): Response
    {
        try {
            $allowed = [];
            $matches = [];
            foreach ($this->registry->routes() as $route) {
                if (!preg_match($route['pattern'], $request->uri, $params)) continue;
                $allowed[] = $route['method'];
                if ($route['method'] === 'GET') $allowed[] = 'HEAD';
                if ($route['method'] === $request->method || ($request->method === 'HEAD' && $route['method'] === 'GET')) {
                    $matches[] = [$route, $params];
                }
            }
            if (count($matches) > 1) throw new \LogicException('Ambiguous module route.');
            if (!$matches) throw new HttpException($allowed ? 405 : 404, $allowed ? 'Method not allowed.' : 'Not found.', [],
                $allowed ? ['Allow' => implode(', ', array_unique($allowed))] : []);
            [$route, $params] = $matches[0];
            $params = array_map('rawurldecode', array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY));
            foreach ($route['policies'] as $policy) ($this->authorize)($policy, $request, $params);
            $result = ($route['handler'])($request, $params, $this->registry);
            return $result instanceof Response ? $result : Response::json($result);
        } catch (HttpException $error) {
            return Response::json(['error' => $error->getMessage(), 'errors' => $error->errors], $error->status, $error->headers);
        } catch (\Throwable $error) {
            error_log((string) $error);
            return Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    public function matches(Request $request): bool
    {
        foreach ($this->registry->routes() as $route) if (preg_match($route['pattern'], $request->uri)) return true;
        return false;
    }

    public function prefixes(): array
    {
        return array_values(array_unique(array_column($this->registry->routes(), 'prefix')));
    }
}
