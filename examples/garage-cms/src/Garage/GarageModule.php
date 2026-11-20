<?php
namespace Garage;

use ZoltaGaska\Cms\Catalog;
use ZoltaGaska\Http\{HttpException, Request};
use ZoltaGaska\Module\{Module, Registry};
use ZoltaGaska\Security\{Session, SessionAuthenticator};

final class GarageModule implements Module
{
    public function __construct(private Catalog $catalog, private SessionAuthenticator $auth, private Accounts $accounts, private Enquiries $enquiries) {}
    public function id(): string { return 'garage'; }
    public function dependencies(): array { return ['cms']; }

    public function register(Registry $registry): void
    {
        $registry->policy('garage.session', static fn () => true);
        $registry->policy('assets.write', fn ($identity, $subject) => $this->accounts->canEdit($identity, $subject['params']['project'] ?? ''));
        $registry->route('garage.session', 'GET', '/api/auth', '/session', function (Request $request) {
            return ['identity' => $this->auth->identity($request)?->id, 'csrfToken' => Session::csrfToken()];
        }, ['garage.session']);
        $registry->route('garage.login', 'POST', '/api/auth', '/login', function (Request $request) {
            $data = $request->json();
            if (array_diff(array_keys($data), ['login','password']) || !is_string($data['login'] ?? null) || !is_string($data['password'] ?? null)
                || strlen($data['login']) > 120 || strlen($data['password']) > 1024) throw new HttpException(422, 'Invalid login data.');
            $viewer = $this->auth->login($data['login'], $data['password']);
            return ['identity' => $viewer->id, 'csrfToken' => Session::csrfToken()];
        }, ['garage.session']);
        $registry->route('garage.logout', 'POST', '/api/auth', '/logout', function (Request $request) {
            if ($request->json()) throw new HttpException(422, 'Unknown logout fields.');
            $this->auth->logout();
            return ['identity' => null, 'csrfToken' => Session::csrfToken()];
        }, ['garage.session']);
        $registry->route('garage.confirm', 'POST', '/api/garage', '/offers/{id}/confirm', function (Request $request, $params) {
            $product = $this->catalog->published(Site::PROJECT, $params['id'], $this->auth->identity($request));
            return $this->enquiries->confirm($product, $request->json());
        }, ['garage.session']);
        $registry->route('garage.preview-quote', 'POST', '/api/garage', '/preview/{id}/quote', function (Request $request, $params) {
            $draft = $this->catalog->draft(Site::PROJECT, $params['id'], $this->auth->identity($request));
            return DemoRules::submittedQuote($draft, $request->json());
        }, ['cms.edit']);
        $registry->route('garage.enquire', 'POST', '/api/garage', '/offers/{id}/enquire', function (Request $request, $params) {
            $product = $this->catalog->published(Site::PROJECT, $params['id'], $this->auth->identity($request));
            return $this->enquiries->save(Site::PROJECT, $product, $request->json());
        }, ['garage.session']);
    }
}
