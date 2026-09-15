<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

final class FincaraizClient
{
    private string $baseUrl;

    public function __construct()
    {
        $environment = Env::get('FINCARAIZ_ENV', 'production');
        $defaultUrl = $environment === 'qa'
            ? 'https://api-integrators.frcol.io/management/api/1.0'
            : 'https://msi-infofinca.fincaraiz.com.co/management/api/1.0';
        $this->baseUrl = rtrim((string) Env::get('FINCARAIZ_API_URL', $defaultUrl), '/');
    }

    public function createListing(array $payload): array
    {
        return $this->request('POST', '/listing', [$payload]);
    }

    public function updateListing(string $listingId, array $payload): array
    {
        $payload['listing_id'] = $listingId;
        return $this->request('PATCH', '/listing', [$payload]);
    }

    public function changeStatus(string $listingId, string $status, string $clientId): array
    {
        return $this->request('PATCH', '/listing/status', [[
            'listing_id' => $listingId,
            'client_id' => $clientId,
            'status' => strtoupper($status),
        ]]);
    }

    public function getTask(string $taskId): array
    {
        return $this->request('GET', '/task/' . rawurlencode($taskId));
    }

    public function getListing(string $listingId): array
    {
        return $this->request('GET', '/listing/' . rawurlencode($listingId));
    }

    public function listListings(string $clientId, int $page = 1, int $pageSize = 20, ?string $search = null): array
    {
        return $this->request('GET', '/listing', null, array_filter([
            'page' => max(1, $page),
            'page_size' => min(100, max(1, $pageSize)),
            'search' => $search,
            'ordering' => '-created',
            Env::get('FINCARAIZ_CACHE_BUSTER_NAME', 'go-cache') => bin2hex(random_bytes(8)),
        ], fn ($value) => $value !== null && $value !== ''), [
            'Cookie: ' . $clientId,
        ]);
    }

    public function findLocations(string $name): array
    {
        return $this->request('GET', '/location/' . rawurlencode($name));
    }

    private function request(string $method, string $path, ?array $payload = null, array $query = [], array $extraHeaders = []): array
    {
        $apiKey = trim((string) Env::get('FINCARAIZ_API_KEY', ''));
        if ($apiKey === '' || $apiKey === 'CAMBIAR_API_KEY') {
            throw new RuntimeException('Configura FINCARAIZ_API_KEY antes de procesar Finca Raiz.');
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
            'apikey: ' . $apiKey,
        ], $extraHeaders);

        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->curl($method, $url, $headers, $body);
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
            CURLOPT_TIMEOUT => (int) Env::get('FINCARAIZ_TIMEOUT', '45'),
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
