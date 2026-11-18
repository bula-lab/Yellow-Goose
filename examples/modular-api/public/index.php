<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZoltaGaska\Http\{Kernel, Request};
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Security\{CsrfGuard, Gate, RequestAuthorizer, Session};

$config = require dirname(__DIR__) . '/modules.php';
$registry = Registry::boot($config['modules']);
$kernel = new Kernel($registry, new RequestAuthorizer($config['identities'], new Gate($registry->policies()), new CsrfGuard([Session::class, 'csrfToken'])));
$request = Request::capture();
$kernel->handle($request)->send($request->method === 'HEAD');
