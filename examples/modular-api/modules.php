<?php
use Symfony\Component\Validator\Constraints as Assert;
use ZoltaGaska\Http\{HttpException, Request};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Resources\{Action, ResourceDefinition, ResourceModule, SymfonyValidator};
use ZoltaGaska\Security\{Account, CsrfGuard, Gate, Identity, Session, SessionAuthenticator, UserProvider};

$users = new class implements UserProvider {
    public function findByLogin(string $login): ?Account { return $login === 'editor' ? $this->findById('editor') : null; }
    public function findById(string $id): ?Account {
        $hash = getenv('YG_EXAMPLE_PASSWORD_HASH');
        return $id === 'editor' && is_string($hash) && $hash !== ''
            ? new Account(new Identity('editor', ['editor']), $hash) : null;
    }
};
$auth = new SessionAuthenticator($users);
$security = new class($auth) implements Module {
    public function __construct(private SessionAuthenticator $auth) {}
    public function id(): string { return 'auth'; }
    public function dependencies(): array { return []; }
    public function register(Registry $registry): void {
        $registry->policy('auth.session', fn () => true);
        $registry->policy('auth.login', fn () => true);
        $registry->policy('notes.read', fn ($identity) => $identity !== null);
        $registry->policy('notes.save', fn ($identity) => in_array('editor', $identity?->roles ?? [], true));
        $registry->route('auth.session', 'GET', '/api/auth', '/session', function (Request $request) {
            $identity = $this->auth->identity($request);
            return ['identity' => $identity?->id, 'csrfToken' => Session::csrfToken()];
        }, ['auth.session']);
        $registry->route('auth.login', 'POST', '/api/auth', '/login', function (Request $request) {
            $data = $request->json();
            if (array_diff(array_keys($data), ['login', 'password']) || !is_string($data['login'] ?? null)
                || !is_string($data['password'] ?? null) || strlen($data['login']) > 200 || strlen($data['password']) > 1024) {
                throw new HttpException(422, 'Invalid credentials payload.');
            }
            $identity = $this->auth->login($data['login'], $data['password']);
            return ['identity' => $identity->id, 'csrfToken' => Session::csrfToken()];
        }, ['auth.login']);
        $registry->route('auth.logout', 'POST', '/api/auth', '/logout', function () {
            $this->auth->logout();
            return ['identity' => null, 'csrfToken' => Session::csrfToken()];
        }, ['auth.session']);
        $registry->command('example:status', function () { echo "Explicit modules enabled\n"; return 0; });
    }
};
$resource = new ResourceDefinition('notes', 'notes.read', [
    'id' => ['type' => 'integer', 'label' => 'ID'],
    'title' => ['type' => 'string', 'label' => 'Title', 'required' => true, 'maxLength' => 200],
], fn ($options, $identity) => ['items' => [['id' => 1, 'title' => 'Private example for ' . $identity->id]], 'nextCursor' => null], [
    new Action('save', 'notes.save', new SymfonyValidator(['title' => new Assert\Sequentially([
        new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 200),
    ])]), fn ($data, $identity) => ['accepted' => ['title' => $data['title']], 'actor' => $identity->id]),
], sortable: ['title'], filterable: ['title']);
return ['modules' => [$security, new ResourceModule($resource, $auth, ['auth'])], 'identities' => $auth];
