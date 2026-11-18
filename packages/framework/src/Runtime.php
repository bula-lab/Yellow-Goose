<?php
namespace ZoltaGaska;

use ZoltaGaska\Config\Environment;
use ZoltaGaska\Database\Connection;
use ZoltaGaska\Frontend\Assets;
use ZoltaGaska\Frontend\V8JsRenderer;
use ZoltaGaska\Http\Application;
use ZoltaGaska\Security\Session;
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Http\Kernel;

final class Runtime
{
    public static function boot(string $root): void
    {
        Environment::load($root . '/.env');
        $config = $root . '/config/database.php';
        Connection::configure(is_file($config) ? require $config : null);
    }

    public static function application(string $root): Application
    {
        $modules = self::modules($root);
        $devServer = getenv('GASKA_VITE_DEV_SERVER') ?: null;
        $renderer = $devServer || getenv('GASKA_SSR') === '0' ? null : new V8JsRenderer($root . '/build/ssr/server.js');
        return new Application(
            $root . '/app/pages', new Assets($root . '/public/build', $devServer),
            Session::csrfToken(), renderer: $renderer,
            document: is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [],
            moduleKernel: $modules === null ? null : new Kernel($modules,
                $modules->has('http.authorizer') ? $modules->get('http.authorizer') : null),
        );
    }

    public static function modules(string $root): ?Registry
    {
        $file = $root . '/config/modules.php';
        if (!is_file($file)) return null;
        $modules = require $file;
        if (!is_array($modules)) throw new \RuntimeException('config/modules.php must return an explicit module list.');
        return Registry::boot($modules);
    }
}
