<?php
namespace ZoltaGaska\Module;

final class Registry
{
    private array $definitions = [];
    private array $instances = [];
    private array $resolving = [];
    private array $routes = [];
    private array $commands = [];
    private array $policies = [];
    private array $migrations = [];
    private array $requiredPolicies = [];
    private array $owners = [];
    private ?string $owner = null;
    private bool $frozen = false;

    /** @param list<Module> $modules */
    public static function boot(array $modules): self
    {
        $registry = new self();
        $byId = [];
        foreach ($modules as $module) {
            if (!$module instanceof Module || !preg_match('/^[a-z][a-z0-9-]*$/D', $module->id()) || $module->id() === 'app') {
                throw new \InvalidArgumentException('Invalid or reserved module ID.');
            }
            if (isset($byId[$module->id()])) throw new \LogicException('Duplicate module: ' . $module->id());
            $byId[$module->id()] = $module;
        }
        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $byId, $registry): void {
            if (!isset($byId[$id])) throw new \LogicException('Missing module: ' . $id);
            if (($state[$id] ?? 0) === 1) throw new \LogicException('Module dependency cycle: ' . $id);
            if (($state[$id] ?? 0) === 2) return;
            $state[$id] = 1;
            foreach ($byId[$id]->dependencies() as $dependency) $visit($dependency);
            $registry->owner = $id;
            $byId[$id]->register($registry);
            $state[$id] = 2;
        };
        foreach (array_keys($byId) as $id) $visit($id);
        foreach ($registry->requiredPolicies as $id) {
            if (!isset($registry->policies[$id])) throw new \LogicException('Missing policy: ' . $id);
        }
        $registry->owner = null;
        $registry->frozen = true;
        return $registry;
    }

    private function claim(string $kind, string $id): void
    {
        if ($this->frozen || $this->owner === null) throw new \LogicException('Registry is frozen.');
        if ($id === '' || isset($this->owners[$kind][$id])) throw new \LogicException('Duplicate or empty ' . $kind . ': ' . $id);
        $this->owners[$kind][$id] = $this->owner;
    }

    public function service(string $id, callable $factory): void
    {
        $this->claim('service', $id);
        $this->definitions[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) return $this->instances[$id];
        if (!isset($this->definitions[$id])) throw new \LogicException('Missing service: ' . $id);
        if (isset($this->resolving[$id])) throw new \LogicException('Service dependency cycle: ' . $id);
        $this->resolving[$id] = true;
        try { return $this->instances[$id] = ($this->definitions[$id])($this); }
        finally { unset($this->resolving[$id]); }
    }

    public function has(string $id): bool { return isset($this->definitions[$id]); }

    public function route(string $name, string $method, string $prefix, string $path, callable $handler, array $policies = []): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$policies) {
            throw new \LogicException('Mutating module route requires an explicit policy.');
        }
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            || !preg_match('~^/api/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $prefix)
            || !preg_match('~^(?:/[a-zA-Z0-9_{}-]+)*$~D', $path)) {
            throw new \InvalidArgumentException('Invalid module route or prefix.');
        }
        $fullPath = rtrim($prefix, '/') . $path;
        foreach ($this->routes as $route) {
            $methods = $method === 'GET' ? ['GET', 'HEAD'] : [$method];
            $existingMethods = $route['method'] === 'GET' ? ['GET', 'HEAD'] : [$route['method']];
            if (!array_intersect($methods, $existingMethods)) continue;
            $left = explode('/', $fullPath);
            $right = explode('/', $route['path']);
            if (count($left) !== count($right)) continue;
            $overlap = true;
            foreach ($left as $i => $segment) {
                if ($segment !== $right[$i] && !str_starts_with($segment, '{') && !str_starts_with($right[$i], '{')) {
                    $overlap = false;
                    break;
                }
            }
            if ($overlap) throw new \LogicException('Overlapping module route: ' . $fullPath);
        }
        $shape = preg_replace('/\{[a-zA-Z_]\w*\}/', '{}', $fullPath);
        $parameters = [];
        foreach (explode('/', $fullPath) as $segment) {
            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                if (!preg_match('/^\{([a-zA-Z_]\w*)\}$/D', $segment, $match) || isset($parameters[$match[1]])) {
                    throw new \InvalidArgumentException('Invalid route parameter.');
                }
                $parameters[$match[1]] = true;
            }
        }
        // HEAD is reserved alongside GET so registration order cannot change ownership.
        $keys = $method === 'GET' ? ['GET ' . $shape, 'HEAD ' . $shape] : [$method . ' ' . $shape];
        foreach ($keys as $key) if (isset($this->owners['path'][$key])) throw new \LogicException('Route conflict: ' . $key);
        $this->claim('route', $name);
        foreach ($keys as $key) $this->claim('path', $key);
        $pattern = preg_replace_callback('/\\\\\{([a-zA-Z_]\w*)\\\\\}/',
            static fn ($match) => '(?P<' . $match[1] . '>[^/]+)', preg_quote($fullPath, '~'));
        $this->routes[] = ['name' => $name, 'owner' => $this->owner, 'method' => $method,
            'path' => $fullPath, 'prefix' => $prefix, 'pattern' => '~^' . $pattern . '/?$~D', 'handler' => $handler, 'policies' => $policies];
        array_push($this->requiredPolicies, ...$policies);
    }

    public function command(string $name, callable $handler): void
    {
        $this->claim('command', $name);
        $this->commands[$name] = $handler;
    }

    public function policy(string $name, callable $policy): void
    {
        $this->claim('policy', $name);
        $this->policies[$name] = $policy;
    }

    public function migrations(string $directory): void
    {
        if (!is_dir($directory)) throw new \InvalidArgumentException('Missing migration directory.');
        $this->claim('migrations', $this->owner ?? '');
        $this->migrations[$this->owner] = $directory;
    }

    public function routes(): array { return $this->routes; }
    public function policies(): array { return $this->policies; }
    public function migrationDirectories(): array { return $this->migrations; }
    public function owners(): array { return $this->owners; }
    public function commands(): array { return array_keys($this->commands); }

    public function run(string $command, array $arguments = []): int
    {
        if (!isset($this->commands[$command])) throw new \InvalidArgumentException('Unknown module command: ' . $command);
        return ($this->commands[$command])($arguments, $this);
    }
}
