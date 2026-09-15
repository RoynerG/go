<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Url;
use App\Models\UserRepository;
use Throwable;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            Url::redirect('/panel/inmuebles');
        }

        Response::view('auth/login', ['error' => null]);
    }

    public function login(): void
    {
        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            Response::view('auth/login', ['error' => 'La sesion expiro. Intenta de nuevo.']);
            return;
        }

        try {
            $users = new UserRepository();
            $users->ensureDefaultAdmin();
            $user = $users->findByEmail(trim((string) ($_POST['email'] ?? '')));

            if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                Response::view('auth/login', ['error' => 'Correo o contrasena incorrectos.']);
                return;
            }

            Auth::login($user);
            $users->markLogin((int) $user['id']);
            Url::redirect('/panel/inmuebles');
        } catch (Throwable $exception) {
            Response::view('auth/login', ['error' => 'No se pudo iniciar sesion. Revisa la base de datos.']);
        }
    }

    public function logout(): void
    {
        if (Auth::verifyCsrf($_POST['_token'] ?? null)) {
            Auth::logout();
        }

        Url::redirect('/panel/login');
    }
}
