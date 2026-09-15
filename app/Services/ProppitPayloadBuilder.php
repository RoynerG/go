<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;

final class ProppitPayloadBuilder
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function build(array $inmueble, array $ad): array
    {
        $ubicacion = $inmueble['ubicacion'] ?? null;
        if (!$ubicacion || (float) $ubicacion['latitud'] === 0.0 || (float) $ubicacion['longitud'] === 0.0) {
            throw new RuntimeException('El inmueble no tiene latitud/longitud validas para Proppit.');
        }

        $payload = [
            'referenceId' => $inmueble['reference_id'],
            'publisher' => [
                'externalId' => $ad['publisher_external_id'],
            ],
            'contact' => $this->contact(),
            'property' => [
                'type' => $this->map('tipo_inmueble', $inmueble['tipo_inmueble'], $ad['country']),
                'location' => [
                    'countryCode' => $ubicacion['pais'] ?: $ad['country'],
                    'visibility' => $ubicacion['visibilidad'] ?: 'approximate',
                    'geo' => $this->geo($ubicacion),
                    'coordinates' => [
                        'lat' => (float) $ubicacion['latitud'],
                        'long' => (float) $ubicacion['longitud'],
                    ],
                    'address' => $ubicacion['direccion'],
                ],
            ],
            'operations' => $this->operations($inmueble),
            'title' => [
                'locale' => Env::get('PROPPIT_DEFAULT_LOCALE', 'es-CO'),
                'text' => $this->limit((string) $inmueble['titulo'], 100),
            ],
            'description' => [
                'locale' => Env::get('PROPPIT_DEFAULT_LOCALE', 'es-CO'),
                'text' => trim(strip_tags((string) $inmueble['descripcion'])),
            ],
        ];

        if (!empty($ubicacion['postcode']) || !empty($ubicacion['codigo_postal'])) {
            $payload['property']['location']['postcode'] = (string) ($ubicacion['postcode'] ?? $ubicacion['codigo_postal']);
        }

        $this->addAreas($payload, $inmueble);
        $this->addOptionalNumbers($payload, $inmueble);
        $this->addCommunityFees($payload, $inmueble);
        $this->addAmenities($payload, $inmueble, $ad['country']);
        $this->addNearbyLocations($payload, $inmueble, $ad['country']);
        $this->addMultimedia($payload, $inmueble);

        return $this->withoutEmpty($payload);
    }

    private function contact(): array
    {
        return [
            'name' => Env::get('PROPPIT_CONTACT_NAME', 'Go Cartagena Real Estate'),
            'email' => Env::get('PROPPIT_CONTACT_EMAIL', 'contacto@gocartagenarealestate.com'),
            'phone' => Env::get('PROPPIT_CONTACT_PHONE', '+573000000000'),
            'whatsapp' => Env::get('PROPPIT_CONTACT_PHONE', '+573000000000'),
        ];
    }

    private function geo(array $ubicacion): array
    {
        $geo = [];
        if (!empty($ubicacion['departamento'])) {
            $geo[] = ['name' => $ubicacion['departamento'], 'level' => 'administrative_area_level_1'];
        }
        if (!empty($ubicacion['ciudad'])) {
            $geo[] = ['name' => $ubicacion['ciudad'], 'level' => 'locality'];
        }
        if (!empty($ubicacion['barrio'])) {
            $geo[] = ['name' => $ubicacion['barrio'], 'level' => 'neighborhood'];
        }

        return $geo ?: [['name' => $ubicacion['direccion'], 'level' => 'locality']];
    }

    private function operations(array $inmueble): array
    {
        $currency = Env::get('PROPPIT_DEFAULT_CURRENCY', 'COP');
        $salePrice = (float) ($inmueble['precio_venta'] ?? 0);
        $rentPrice = (float) ($inmueble['precio_arriendo'] ?? 0);

        if ($salePrice <= 0 && $rentPrice <= 0) {
            throw new RuntimeException('El inmueble no tiene precio de venta ni arriendo.');
        }

        if ($salePrice > 0 && $rentPrice > 0) {
            $operationType = $this->preferredOperationType($inmueble);
            $price = $operationType === 'rent' ? $rentPrice : $salePrice;
            return [[
                'type' => $operationType,
                'price' => [
                    'value' => $price,
                    'currency' => $currency,
                ],
            ]];
        }

        if ($salePrice > 0) {
            return [[
                'type' => 'sell',
                'price' => [
                    'value' => $salePrice,
                    'currency' => $currency,
                ],
            ]];
        }

        return [[
            'type' => 'rent',
            'price' => [
                'value' => $rentPrice,
                'currency' => $currency,
            ],
        ]];
    }

    private function preferredOperationType(array $inmueble): string
    {
        $text = strtolower(trim(implode(' ', [
            (string) ($inmueble['categoria'] ?? ''),
            (string) ($inmueble['destinacion'] ?? ''),
            (string) ($inmueble['titulo'] ?? ''),
        ])));

        if (str_contains($text, 'arriendo') || str_contains($text, 'rent')) {
            return 'rent';
        }

        return 'sell';
    }

    private function addAreas(array &$payload, array $inmueble): void
    {
        if ((float) ($inmueble['area_construida'] ?? 0) > 0) {
            $payload['floorArea'] = ['value' => (float) $inmueble['area_construida'], 'unit' => 'sqm'];
        }
        if ((float) ($inmueble['area_privada'] ?? 0) > 0) {
            $payload['usableArea'] = ['value' => (float) $inmueble['area_privada'], 'unit' => 'sqm'];
        }
        if ((float) ($inmueble['area_terreno'] ?? 0) > 0) {
            $payload['totalArea'] = ['value' => (float) $inmueble['area_terreno'], 'unit' => 'sqm'];
        }
    }

    private function addOptionalNumbers(array &$payload, array $inmueble): void
    {
        foreach ([
            'habitaciones' => 'bedrooms',
            'banos' => 'bathrooms',
            'parqueaderos' => 'parkingSpaces',
            'ano_construccion' => 'constructionYear',
            'estrato' => 'stratum',
            'is_boosted' => 'isBoosted',
            'is_exclusive' => 'isExclusive',
        ] as $local => $remote) {
            if ($inmueble[$local] !== null && $inmueble[$local] !== '') {
                if ($local === 'ano_construccion') {
                    $year = (int) $inmueble[$local];
                    if ($year < 1500 || $year > 2100) {
                        continue;
                    }
                }
                $payload[$remote] = in_array($local, ['is_boosted', 'is_exclusive'], true)
                    ? (bool) $inmueble[$local]
                    : (int) $inmueble[$local];
            }
        }

        if ($inmueble['pisos'] !== null && $inmueble['pisos'] !== '') {
            $payload['property']['floor'] = (string) $inmueble['pisos'];
        }
    }

    private function addCommunityFees(array &$payload, array $inmueble): void
    {
        if ((float) ($inmueble['precio_admin'] ?? 0) <= 0) {
            return;
        }

        $payload['property']['communityFees'] = [
            'value' => (float) $inmueble['precio_admin'],
            'currency' => Env::get('PROPPIT_DEFAULT_CURRENCY', 'COP'),
        ];
    }

    private function addAmenities(array &$payload, array $inmueble, string $country): void
    {
        $amenities = [];
        foreach ($inmueble['caracteristicas'] ?? [] as $feature) {
            if (!in_array($feature['tipo'], ['interna', 'externa', 'servicio'], true)) {
                continue;
            }
            $mapped = $this->map('amenity', $feature['valor'], $country, false);
            if ($mapped !== null) {
                $amenities[] = $mapped;
            }
        }
        if ($amenities !== []) {
            $payload['amenities'] = array_values(array_unique($amenities));
        }
    }

    private function addNearbyLocations(array &$payload, array $inmueble, string $country): void
    {
        $nearbyLocations = [];
        foreach ($inmueble['caracteristicas'] ?? [] as $feature) {
            if (!in_array($feature['tipo'], ['externa', 'servicio', 'cerca_de'], true)) {
                continue;
            }
            $mapped = $this->map('nearbyLocation', $feature['valor'], $country, false);
            if ($mapped !== null) {
                $nearbyLocations[] = $mapped;
            }
        }
        if ($nearbyLocations !== []) {
            $payload['property']['location']['nearbyLocations'] = array_values(array_unique($nearbyLocations));
        }
    }

    private function addMultimedia(array &$payload, array $inmueble): void
    {
        $pictures = [];
        foreach ($inmueble['multimedia'] ?? [] as $media) {
            if (in_array($media['tipo'], ['portada', 'galeria'], true)) {
                $pictures[] = ['url' => $media['url']];
            }
        }
        if ($pictures !== []) {
            $payload['multimedia'] = ['pictures' => $pictures];
        }
    }

    private function map(string $type, string $value, string $country, bool $fallback = true): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT valor_proppit FROM proppit_mapeos
             WHERE tipo = :tipo AND valor_local = :valor AND country = :country AND active = 1
             LIMIT 1'
        );
        $statement->execute(['tipo' => $type, 'valor' => $value, 'country' => $country]);
        $mapped = $statement->fetchColumn();
        if ($mapped) {
            return (string) $mapped;
        }

        return $fallback ? strtolower(trim($value)) : null;
    }

    private function withoutEmpty(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->withoutEmpty($value);
            }
            if ($payload[$key] === [] || $payload[$key] === '' || $payload[$key] === null) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function limit(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}
