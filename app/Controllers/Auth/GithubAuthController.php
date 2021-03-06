<?php

namespace App\Controllers\Auth;

use CQ\Config\Config;
use CQ\Helpers\State;
use CQ\Helpers\Str;
use Exception;
use League\OAuth2\Client\Provider\Github;

class GithubAuthController extends AuthController
{
    /**
     * Redirect to OAuth.
     *
     * @param object $request
     *
     * @return Redirect
     */
    public function request($request)
    {
        $popup = $request->getQueryParams()['popup'];
        $provider = $this->provider($popup);

        $authUrl = $provider->getAuthorizationUrl();

        // State isn't checked when in popup mode because
        // the /auth/register is only available by an already checked state
        if (!$popup) {
            State::set($provider->getState());
        }

        return $this->redirect($authUrl);
    }

    /**
     * Callback for OAuth.
     *
     * @param object $request
     *
     * @return Redirect
     */
    public function callback($request)
    {
        $popup = $request->getQueryParams()['popup'];
        $state = $request->getQueryParams()['state'];
        $code = $request->getQueryParams()['code'];

        if (!$popup && !State::valid($state)) {
            return $this->logout('state');
        }

        try {
            $provider = $this->provider($popup);
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $data = $provider->getResourceOwner($token);
            $id = Str::escape($data->toArray()['id']);
        } catch (Exception $e) {
            return $this->logout("Error: {$e}");
        }

        if ($popup) {
            return $this->respond('auth/popup.twig', ['id' => $id]);
        }

        return $this->login(['github' => $id]);
    }

    /**
     * Initialize the OAuth provider.
     *
     * @param bool $popup
     *
     * @return Github
     */
    private function provider($popup = false)
    {
        $queryString = $popup ? '?popup=1' : '';

        return new Github([
            'clientId' => Config::get('auth.github.client_id'),
            'clientSecret' => Config::get('auth.github.client_secret'),
            'redirectUri' => Config::get('app.url').'/auth/github/callback'.$queryString,
        ]);
    }
}
