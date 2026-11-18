<?php
namespace ZoltaGaska\Security;

use ZoltaGaska\Http\Request;

interface IdentityProvider
{
    public function identity(Request $request): ?Identity;
}
