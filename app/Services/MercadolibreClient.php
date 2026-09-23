<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Models\MercadolibreRepository;
use RuntimeException;

class MercadolibreClient
{
    private array $catalogCache = [];

    public function __construct(private ?MercadolibreRepository $repository = null)
    {
        $this->repository ??= new MercadolibreRepository();
    }

    public static function configured(): bool
    {
        return Env::get('MERCADOLIBRE_CLIENT_ID', '') !== ''
            && Env::get('MERCADOLIBRE_CLIENT_SECRET', '') !== ''
            && Env::get('MERCADOLIBRE_REDIRECT_URI', '') !== ''
            && strlen((string) base64_decode(Env::get('MERCADOLIBRE_TOKEN_KEY', ''), true)) === 32;
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        if (!self::configured()) {
            throw new RuntimeException('Faltan credenciales, redirect URI o clave de cifrado de Mercado Libre en .env.');
        }
        return 'https://auth.mercadolibre.com.co/authorization?' . http_build_query([
            'response_type' => 'code', 'client_id' => Env::get('MERCADOLIBRE_CLIENT_ID'),
            'redirect_uri' => Env::get('MERCADOLIBRE_REDIRECT_URI'), 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, string $verifier): void
    {
        if (!$this->repository->lock('token')) {
            throw new RuntimeException('La conexion se esta actualizando. Intenta nuevamente.');
        }
        try {
            $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code,
                'code_verifier' => $verifier, 'redirect_uri' => Env::get('MERCADOLIBRE_REDIRECT_URI')]);
        } finally {
            $this->repository->unlock('token');
        }
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path . ($query ? '?' . http_build_query($query) : ''));
    }

    public function catalog(string $path): array
    {
        if (!isset($this->catalogCache[$path])) {
            $result = $this->get($path);
            self::requireSuccess($result);
            $this->catalogCache[$path] = $result['body'];
        }
        return $this->catalogCache[$path];
    }

    public function create(array $payload): array
    {
        return $this->request('POST', '/items', $payload);
    }

    public function update(string $id, array $payload): array
    {
        return $this->request('PUT', '/items/' . rawurlencode($id), $payload);
    }

    public function description(string $id, string $text): array
    {
        return $this->request('PUT', '/items/' . rawurlencode($id) . '/description', ['plain_text' => $text]);
    }

    public function validate(array $payload): array
    {
        return $this->request('POST', '/items/validate', $payload);
    }

    public static function requireSuccess(array $result): array
    {
        if (!($result['success'] ?? false)) {
            $body = $result['body'] ?? [];
            $message = $body['message'] ?? $body['error_description'] ?? $body['error'] ?? 'No se pudo comunicar con Mercado Libre.';
            foreach ($body['cause'] ?? [] as $cause) {
                if (is_array($cause) && !empty($cause['message'])) {
                    $message .= ' ' . $cause['message'];
                }
            }
            throw new RuntimeException('Mercado Libre HTTP ' . (int) ($result['status'] ?? 0) . ': ' . $message, (int) ($result['status'] ?? 0));
        }
        return $result['body'] ?? [];
    }

    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->accessToken();
        $result = $this->http($method, $path, $payload, $token);
        if ($result['status'] === 401) {
            $token = $this->accessToken($token);
            $result = $this->http($method, $path, $payload, $token);
        }
        return $result;
    }

    private function accessToken(?string $rejectedToken = null): string
    {
        if (!self::configured()) {
            throw new RuntimeException('Configura la conexion de Mercado Libre en .env.');
        }
        if (!$this->repository->lock('token')) {
            throw new RuntimeException('Renovacion de token en curso; se reintentara.');
        }
        try {
            $account = $this->repository->account();
            if (!$account || $account['client_id'] !== Env::get('MERCADOLIBRE_CLIENT_ID')) {
                throw new RuntimeException('Conecta la cuenta de Mercado Libre desde el panel.');
            }
            $tokens = $this->decrypt($account['token_data']);
            if ((int) $account['expires_at'] <= time() + 60 || $rejectedToken === $tokens['access_token']) {
                $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
            }
            return $tokens['access_token'];
        } finally {
            $this->repository->unlock('token');
        }
    }

    private function tokenRequest(array $fields): array
    {
        $fields += ['client_id' => Env::get('MERCADOLIBRE_CLIENT_ID'), 'client_secret' => Env::get('MERCADOLIBRE_CLIENT_SECRET')];
        $result = $this->http('POST', '/oauth/token', $fields, null, true);
        if (!$result['success']) {
            // Never persist or display OAuth response bodies, which can contain credentials.
            throw new RuntimeException('No se pudo autorizar/renovar Mercado Libre (HTTP ' . $result['status'] . '). Revisa la aplicacion y vuelve a conectar.', $result['status']);
        }
        $tokens = $result['body'];
        if (empty($tokens['access_token']) || empty($tokens['refresh_token']) || empty($tokens['user_id']) || empty($tokens['expires_in'])) {
            throw new RuntimeException('La autorizacion no incluye token renovable. Habilita offline_access.');
        }
        if (($fields['grant_type'] ?? '') === 'authorization_code') {
            $profile = self::requireSuccess($this->http('GET', '/users/me', null, $tokens['access_token']));
            if (($profile['site_id'] ?? '') !== 'MCO' || (string) ($profile['id'] ?? '') !== (string) $tokens['user_id']) {
                throw new RuntimeException('Conecta la cuenta administradora de Mercado Libre Colombia.');
            }
        }
        $previous = $this->repository->account();
        if ($previous && (string) $previous['user_id'] !== (string) $tokens['user_id']) {
            throw new RuntimeException('Esta instalacion ya esta vinculada a otra cuenta de Mercado Libre.');
        }
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt(json_encode($tokens, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new RuntimeException('No se pudo cifrar el token.');
        }
        $this->repository->saveAccount((string) Env::get('MERCADOLIBRE_CLIENT_ID'), (string) $tokens['user_id'],
            base64_encode($iv . $tag . $encrypted), time() + (int) $tokens['expires_in']);
        return $tokens;
    }

    private function key(): string
    {
        $key = base64_decode(Env::get('MERCADOLIBRE_TOKEN_KEY', ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('MERCADOLIBRE_TOKEN_KEY debe ser una clave base64 de 32 bytes.');
        }
        return $key;
    }

    private function decrypt(string $data): array
    {
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Token almacenado no valido. Vuelve a conectar Mercado Libre.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('No se puede descifrar la conexion. Revisa MERCADOLIBRE_TOKEN_KEY.');
        }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    private function http(string $method, string $path, ?array $body, ?string $token, bool $form = false): array
    {
        $headers = ['Accept: application/json', 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json')];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init('https://api.mercadolibre.com' . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return ['success' => !$error && $status >= 200 && $status < 300,
            'status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
