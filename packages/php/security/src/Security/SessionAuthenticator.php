<?php
namespace ZoltaGaska\Security;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

final class SessionAuthenticator implements IdentityProvider
{
    public function __construct(private UserProvider $users) {}

    public function login(string $login, string $password): Identity
    {
        $account = $this->users->findByLogin($login);
        // Unknown users still pass through password verification.
        $hash = $account?->passwordHash ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password, $hash) || $account === null || !$account->enabled) {
            throw new HttpException(401, 'Invalid credentials.');
        }
        Session::start();
        if (!session_regenerate_id(true)) throw new \RuntimeException('Cannot rotate session.');
        $_SESSION = ['identity_id' => $account->identity->id, 'csrf' => bin2hex(random_bytes(32))];
        session_write_close();
        return $account->identity;
    }

    public function identity(Request $request): ?Identity
    {
        Session::start();
        $id = $_SESSION['identity_id'] ?? null;
        session_write_close();
        if (!is_string($id)) return null;
        $account = $this->users->findById($id);
        return $account?->enabled ? $account->identity : null;
    }

    public function logout(): void
    {
        Session::start();
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if (!session_regenerate_id(true)) throw new \RuntimeException('Cannot rotate session.');
        session_write_close();
    }
}
