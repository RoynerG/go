<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;

final class ProppitClient
{
    private PDO $pdo;
    private string $baseUrl;

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->baseUrl = rtrim((string) Env::get('PROPPIT_BASE_URL', 'https://real-time.proppit.com/api/v2'), '/');
    }

    public function createAd(string $country, array $payload): array
    {
        return $this->request('POST', "/proppit/{$country}/ads", $payload, $country);
    }

    public function updateAd(string $country, string $referenceId, array $payload): array
    {
        return $this->request('PUT', "/proppit/{$country}/ads/" . rawurlencode($referenceId), $payload, $country);
    }

    public function deleteAd(string $country, string $referenceId, string $publisherExternalId): array
    {
        $path = "/proppit/{$country}/ads/" . rawurlencode($referenceId) . '?externalId=' . rawurlencode($publisherExternalId);
        return $this->request('DELETE', $path, null, $country);
    }

    private function request(string $method, string $path, ?array $payload, string $country): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token($country),
        ];

        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        return $this->curl($method, $url, $headers, $body);
    }

    private function token(string $country): string
    {
        $account = $this->account($country);
        if (!empty($account['token']) && !empty($account['token_expires_at']) && strtotime($account['token_expires_at']) > time() + 60) {
            return $account['token'];
        }

        $response = null;
        $lastResponse = null;

        foreach ($this->tokenPayloads($account) as $payload) {
            $response = $this->curl('POST', $this->baseUrl . '/token', ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $lastResponse = $response;

            if ($response['success'] && !empty($response['body']['token'])) {
                break;
            }
        }

        if (!$response || !$response['success'] || empty($response['body']['token'])) {
            throw new RuntimeException('No fue posible obtener token de Proppit: ' . ($lastResponse['raw'] ?? 'sin respuesta'));
        }

        $expiresAt = date('Y-m-d H:i:s', (int) ($response['body']['expiration'] ?? time() + 3600));
        $statement = $this->pdo->prepare('UPDATE proppit_accounts SET token = :token, token_expires_at = :expires_at WHERE id = :id');
        $statement->execute([
            'token' => $response['body']['token'],
            'expires_at' => $expiresAt,
            'id' => $account['id'],
        ]);

        return (string) $response['body']['token'];
    }

    private function tokenPayloads(array $account): array
    {
        $clientId = Env::get('PROPPIT_CLIENT_ID', '');
        $clientSecret = Env::get('PROPPIT_CLIENT_SECRET', '');
        $crm = Env::get('PROPPIT_CRM', '');
        $payloads = [];

        if ($clientId !== '' && $clientSecret !== '' && $crm !== '') {
            $payloads[] = [
                'user' => $clientId,
                'password' => $clientSecret,
            ];
            $payloads[] = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'crm' => $crm,
            ];
            $payloads[] = [
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'crm' => $crm,
            ];
            $payloads[] = [
                'user' => $crm,
                'password' => $clientSecret,
            ];
        }

        $payloads[] = [
            'user' => $account['api_user'],
            'password' => $account['api_password'],
        ];

        return $payloads;
    }

    private function account(string $country): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM proppit_accounts WHERE country = :country AND active = 1 LIMIT 1');
        $statement->execute(['country' => $country]);
        $account = $statement->fetch();

        if (!$account) {
            return [
                'id' => 0,
                'api_user' => Env::get('PROPPIT_USER', ''),
                'api_password' => Env::get('PROPPIT_PASSWORD', ''),
                'token' => null,
                'token_expires_at' => null,
            ];
        }

        if ($account['api_user'] === 'CAMBIAR_USUARIO') {
            $account['api_user'] = Env::get('PROPPIT_USER', '');
            $account['api_password'] = Env::get('PROPPIT_PASSWORD', '');
        }

        return $account;
    }

    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extension cURL de PHP no esta disponible.');
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $success = $error === '' && $status >= 200 && $status < 300;

        return [
            'success' => $success,
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => is_string($raw) ? $raw : null,
            'error' => $error ?: null,
        ];
    }
}
