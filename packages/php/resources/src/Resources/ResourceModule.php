<?php
namespace ZoltaGaska\Resources;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;
use ZoltaGaska\Module\Module;
use ZoltaGaska\Module\Registry;
use ZoltaGaska\Security\IdentityProvider;

final class ResourceModule implements Module
{
    /** The composition root supplies identities and the Kernel authorizer. */
    public function __construct(private ResourceDefinition $resource, private IdentityProvider $identities, private array $requires = []) {}

    public function id(): string { return 'resource-' . $this->resource->id; }
    public function dependencies(): array { return $this->requires; }

    public function register(Registry $registry): void
    {
        $resource = $this->resource;
        $prefix = '/api/resources/' . $resource->id;
        $registry->route($this->id() . '.schema', 'GET', $prefix, '/schema',
            fn () => $resource->schema(), [$resource->readPolicy]);
        $registry->route($this->id() . '.query', 'GET', $prefix, '', function (Request $request) use ($resource): array {
            $options = QueryOptions::fromRequest($request, $resource->sortable, $resource->filterable);
            $result = ($resource->query)($options, $this->identities->identity($request));
            if (!is_array($result) || !is_array($result['items'] ?? null) || !array_is_list($result['items']) || count($result['items']) > $options->limit
                || (!is_string($result['nextCursor'] ?? null) && ($result['nextCursor'] ?? null) !== null)) {
                throw new \RuntimeException('Invalid resource query result.');
            }
            return ['items' => array_map(fn (array $row) => $resource->serialize($row), $result['items']),
                'nextCursor' => $result['nextCursor'] ?? null];
        }, [$resource->readPolicy]);
        foreach ($resource->actions as $action) {
            $registry->route($this->id() . '.' . $action->name, 'POST', $prefix, '/actions/' . $action->name,
                function (Request $request, array $params) use ($action): mixed {
                    $data = $request->json();
                    $errors = $action->validator->validate($data);
                    if ($errors) throw new HttpException(422, 'Validation failed.', $errors);
                    return ($action->handler)($data, $this->identities->identity($request), $params);
                }, [$action->policy]);
        }
    }
}
