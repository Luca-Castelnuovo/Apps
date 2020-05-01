<?php

namespace App\Controllers\Auth;

use DB;
use Exception;
use App\Helpers\CaptchaHelper;
use App\Helpers\JWTHelper;
use App\Helpers\StateHelper;
use App\Validators\RegisterAuthValidator;
use Zend\Diactoros\ServerRequest;
use Ramsey\Uuid\Uuid;

class RegisterAuthController extends AuthController
{
    /**
     * Invite validation
     *
     * @return JsonResponse
     */
    public function invite(ServerRequest $request)
    {
        try {
            RegisterAuthValidator::invite($request->data);
        } catch (Exception $e) {
            return $this->respondJson(
                false,
                'Provided data was malformed',
                json_decode($e->getMessage()),
                422
            );
        }

        try {
            CaptchaHelper::validate($request->data->{'h-captcha-response'});
        } catch (Exception $e) {
            return $this->respondJson(
                false,
                'Please complete captcha',
                json_decode($e->getMessage()),
                422
            );
        }

        $invite = DB::get('invites', [
            'roles',
            'expires_at',
            'roles'
        ], ['code' => $request->data->invite_code]);

        if (!$invite) {
            return $this->respondJson(
                false,
                'Invite code not found'
            );
        }

        if ($invite['expires_at'] < date('Y-m-d H:i:s')) {
            return $this->respondJson(
                false,
                'Invite code has expired'
            );
        }

        DB::delete('invites', ['code' => $request->data->invite_code]);

        $jwt = JWTHelper::create('register', [
            'roles' => $invite['roles'],
            'state' => StateHelper::set()
        ]);

        return $this->respondJson(
            true,
            'Invite code valid',
            ['redirect' => "/auth/register?code={$jwt}"]
        );
    }

    /**
     * License validation
     *
     * @return JsonResponse
     */
    public function license(ServerRequest $request)
    {
        try {
            RegisterAuthValidator::license($request->data);
        } catch (Exception $e) {
            return $this->respondJson(
                false,
                'Provided data was malformed',
                json_decode($e->getMessage()),
                422
            );
        }

        try {
            CaptchaHelper::validate($request->data->{'h-captcha-response'});
        } catch (Exception $e) {
            return $this->respondJson(
                false,
                'Please complete captcha',
                json_decode($e->getMessage()),
                422
            );
        }

        // TODO: validate invite code with gumroad
        return $this->respondJson(
            false,
            'License code not found'
        );

        $roles = [];

        $jwt = JWTHelper::create('register', [
            'roles' => $roles,
            'state' => StateHelper::set()
        ]);

        return $this->respondJson(
            true,
            'License code valid',
            ['redirect' => "/auth/register?code={$jwt}"]
        );
    }

    /**
     * View register form
     * 
     * @param ServerRequest $request
     *
     * @return HtmlResponse
     */
    public function registerView(ServerRequest $request)
    {
        $code = $request->getQueryParams()['code'];

        try {
            $jwt = JWTHelper::valid('register', $code);
        } catch (Exception $e) {
            return $this->logout($e->getMessage());
        }

        if (!StateHelper::valid($jwt->state, false)) {
            return $this->logout('State is invalid');
        }

        return $this->respond('register.twig', ['code' => $code]);
    }

    /**
     * Register new user
     *
     * @param ServerRequest $request
     * @return void
     */
    public function register(ServerRequest $request)
    {
        try {
            RegisterAuthValidator::register($request->data);
        } catch (Exception $e) {
            return $this->respondJson(
                false,
                'Provided data was malformed',
                json_decode($e->getMessage()),
                422
            );
        }

        try {
            $jwt = JWTHelper::valid('register', $request->data->code);
        } catch (Exception $e) {
            return $this->logout($e->getMessage());
        }

        $type = $request->data->type;
        $type_query = [$type => $request->data->{$type}];

        if (!$request->data->{$type}) {
            return $this->respondJson(
                false,
                'Provided data was malformed',
                [],
                422
            );
        }

        if (DB::has('users', $type_query)) {
            return $this->respondJson(
                false,
                "This {$type} account is already used, please use another"
            );
        }

        if (!StateHelper::valid($jwt->state)) {
            return $this->respondJson(
                false,
                'State is invalid',
                ['redirect' => '/']
            );
        }

        $create_query = array_merge([
            'id' => Uuid::uuid4()->toString(),
            'roles' => $jwt->roles
        ], $type_query);
        DB::create('users', $create_query);

        // MAYBE: send welcome mail, if email present

        return $this->respondJson(
            true,
            'Account Created',
            ['redirect' => '/']
        );
    }
}
