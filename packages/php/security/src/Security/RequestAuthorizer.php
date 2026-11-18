<?php
namespace ZoltaGaska\Security;

use ZoltaGaska\Http\Request;

final class RequestAuthorizer
{
    public function __construct(private IdentityProvider $identities, private Gate $gate, private CsrfGuard $csrf) {}

    public function __invoke(string $policy, Request $request, array $params): void
    {
        $this->csrf->validate($request);
        $this->gate->authorize($policy, $this->identities->identity($request), ['request' => $request, 'params' => $params]);
    }
}
