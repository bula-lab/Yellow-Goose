# yellow-goose/react-bridge

Optional library for Yellow-Goose. PHP >=8.1; existing ZoltaGaska namespaces are preserved.
See the package manifest for dependencies. Installing this package does not register routes or run migrations.

A page loader can return an array of props, an HTTP response, or a
`ZoltaGaska\Frontend\PageData` instance. Both a document request and an
`X-YG-Navigation: 1` request use the same per-page title, description and canonical.
The bridge escapes HTML attributes and serializes props with JSON tag escaping.
It always supplies the request's route parameters and CSRF token itself.

```php
use ZoltaGaska\Frontend\PageData;

return fn ($request, $params, $site) => new PageData(
    ['product' => $site->published($params['id'])],
    title: 'Workshop',
    description: 'A published workshop.',
    canonical: '/garages/workshop',
);
```

Pass an application-owned object as the optional `loaderContext` argument to
`Application` to supply a third loader parameter. A canonical URL can be an
absolute HTTP(S) URL or a root-relative application path. Credentials, control
characters, backslashes and network-path references are rejected.
