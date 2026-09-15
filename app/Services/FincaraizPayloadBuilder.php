<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;

final class FincaraizPayloadBuilder
{
    private const FEATURE_IDS = [
        'aire acondicionado' => 1,
        'piso en baldosa marmol' => 4,
        'parqueadero visitantes' => 5,
        'jardin' => 7,
        'terraza' => 10,
        'deposito bodega' => 11,
        'conjunto cerrado' => 12,
        'ascensor' => 13,
        'patio' => 16,
        'piscina' => 17,
        'amoblado' => 19,
        'cocina integral' => 20,
        'balcon' => 32,
        'gimnasio' => 103,
        'zona infantil' => 106,
        'zonas verdes' => 107,
        'salon comunal' => 112,
        'vigilancia' => 119,
        'chimenea' => 129,
        'zona de lavanderia' => 134,
        'transporte publico cercano' => 141,
        'seguridad 24 horas' => 147,
        'zona de bbq' => 177,
        'servicio de internet' => 190,
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function build(array $inmueble, array $ad): array
    {
        $ubicacion = $inmueble['ubicacion'] ?? null;
        if (!$ubicacion) {
            throw new RuntimeException('El inmueble no tiene ubicacion cargada.');
        }

        $clientId = trim((string) ($ad['client_id'] ?? Env::get('FINCARAIZ_CLIENT_ID', '7750b42d-a577-11f1-8d89-06ecc7fea243')));
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $clientId)) {
            throw new RuntimeException('FINCARAIZ_CLIENT_ID debe ser un UUID valido.');
        }

        $latitude = $this->number($ubicacion['latitud'] ?? null);
        $longitude = $this->number($ubicacion['longitud'] ?? null);
        if ($latitude === null || $latitude < -90 || $latitude > 90 || $longitude === null || $longitude < -180 || $longitude > 180) {
            throw new RuntimeException('El inmueble no tiene latitud/longitud validas para Finca Raiz.');
        }

        $offer = $this->offer($inmueble);
        $price = $offer === 'rent' || $offer === 'lease'
            ? (float) ($inmueble['precio_arriendo'] ?? 0)
            : (float) ($inmueble['precio_venta'] ?? 0);
        $area = (float) (($inmueble['area_construida'] ?? 0) ?: ($inmueble['area_privada'] ?? 0) ?: ($inmueble['area_terreno'] ?? 0));
        $propertyType = $this->propertyType((string) ($inmueble['tipo_inmueble'] ?? ''));
        $description = $this->description((string) ($inmueble['descripcion'] ?: $inmueble['titulo'] ?? ''));

        if ($propertyType === '') {
            throw new RuntimeException('Tipo de inmueble no homologado para Finca Raiz.');
        }
        if ($price <= 0) {
            throw new RuntimeException('El precio para Finca Raiz debe ser mayor que cero.');
        }
        if ($area <= 0) {
            throw new RuntimeException('El area para Finca Raiz debe ser mayor que cero.');
        }
        if ($description === '') {
            throw new RuntimeException('La descripcion es obligatoria para Finca Raiz.');
        }

        $payload = [
            'external_code' => (string) $inmueble['reference_id'],
            'client_id' => $clientId,
            'offer' => $offer,
            'property_type' => $propertyType,
            'description' => $description,
            'price' => $price,
            'negotiable' => false,
            'condition' => 3,
            'stratum' => $this->stratum($inmueble['estrato'] ?? null),
            'area' => $area,
            'age' => $this->age($inmueble['ano_construccion'] ?? null),
            'address' => ['address' => $this->text($ubicacion['direccion'] ?? '')],
            'locations' => array_filter([
                'location_point' => [
                    'longitude' => $longitude,
                    'latitude' => $latitude,
                ],
                'view_map' => Env::bool('FINCARAIZ_SHOW_EXACT_ADDRESS', false) ? 0 : 2,
                'location_main_id' => $this->locationId((string) ($ubicacion['barrio'] ?? '')),
            ], fn ($value) => $value !== null && $value !== ''),
            'capacity' => 0,
            'rooms' => $this->bucket((int) ($inmueble['habitaciones'] ?? 0), 19, 20),
            'baths' => $this->bucket((int) ($inmueble['banos'] ?? 0), 9, 10),
            'floor' => $this->bucket((int) ($inmueble['pisos'] ?? 0), 16, 18),
            'garages' => $this->bucket((int) ($inmueble['parqueaderos'] ?? 0), 10, 11),
            'listing_contact' => $this->contact($inmueble),
        ];

        if ((float) ($inmueble['precio_admin'] ?? 0) > 0) {
            $payload['administration'] = [
                'is_included' => false,
                'price' => (float) $inmueble['precio_admin'],
            ];
        }

        if ((float) ($inmueble['area_privada'] ?? 0) > 0) {
            $payload['living_area'] = (float) $inmueble['area_privada'];
        }

        $categories = $this->categories($inmueble);
        if ($categories !== []) {
            $payload['categories'] = $categories;
        }

        $photos = $this->photos($inmueble);
        if ($photos === []) {
            throw new RuntimeException('El inmueble no tiene fotos publicas para Finca Raiz.');
        }
        $payload['photos'] = $photos;

        if ($agent = $this->positiveInteger(Env::get('FINCARAIZ_CLIENT_AGENT'))) {
            $payload['client_agent'] = $agent;
        }

        return $this->withoutEmpty($payload);
    }

    private function contact(array $inmueble): array
    {
        $publication = null;
        foreach ($inmueble['contactos'] ?? [] as $contact) {
            if (($contact['tipo'] ?? '') === 'publicacion') {
                $publication = $contact;
                break;
            }
        }

        $email = trim((string) (Env::get('FINCARAIZ_CONTACT_EMAIL') ?: ($publication['correo'] ?? '')));
        $phone = $this->phone(Env::get('FINCARAIZ_CONTACT_PHONE') ?: ($publication['celular'] ?? ''));
        $whatsapp = $this->phone(Env::get('FINCARAIZ_CONTACT_WHATSAPP') ?: Env::get('FINCARAIZ_CONTACT_PHONE') ?: ($publication['celular'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Falta FINCARAIZ_CONTACT_EMAIL o un correo valido del asesor.');
        }
        if ($phone === '') {
            throw new RuntimeException('Falta FINCARAIZ_CONTACT_PHONE o un celular valido del asesor.');
        }

        return [
            'emails' => [[
                'is_main' => true,
                'email' => $email,
                'sort_order' => 0,
            ]],
            'phones' => [[
                'phone' => $phone,
                'is_whatsapp_number' => $whatsapp !== '' && $whatsapp === $phone,
                'is_click_to_call' => true,
                'sort_order' => 0,
            ]],
        ];
    }

    private function photos(array $inmueble): array
    {
        $photos = [];
        foreach ($inmueble['multimedia'] ?? [] as $media) {
            if (!in_array(($media['tipo'] ?? ''), ['portada', 'galeria'], true)) {
                continue;
            }
            $url = trim((string) ($media['url'] ?? ''));
            if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) {
                $photos[] = [
                    'sort_order' => count($photos),
                    'is_main' => count($photos) === 0,
                    'image' => $this->fincaraizImageUrl((int) ($media['id'] ?? 0), $url),
                ];
            }
            if (count($photos) >= 30) {
                break;
            }
        }

        return $photos;
    }

    private function fincaraizImageUrl(int $mediaId, string $fallbackUrl): string
    {
        if ($mediaId <= 0) {
            return $fallbackUrl;
        }

        $baseUrl = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($baseUrl === '') {
            return $fallbackUrl;
        }

        return $baseUrl . '/media/fincaraiz/' . $mediaId . '.jpg';
    }

    private function categories(array $inmueble): array
    {
        $text = implode(' ', array_map(
            fn (array $item): string => (string) ($item['valor'] ?? ''),
            $inmueble['caracteristicas'] ?? []
        ));
        $normalized = $this->normalize($text);
        $ids = [];
        foreach (self::FEATURE_IDS as $needle => $id) {
            if (str_contains($normalized, $needle)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function locationId(string $barrio): ?string
    {
        $configured = trim((string) Env::get('FINCARAIZ_LOCATION_ID', ''));
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $configured)) {
            return $configured;
        }
        if (trim($barrio) === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT location_id FROM fincaraiz_location_mappings WHERE barrio_norm = :barrio_norm LIMIT 1');
        $statement->execute(['barrio_norm' => $this->normalizeBarrio($barrio)]);
        $locationId = $statement->fetchColumn();

        return is_string($locationId) && preg_match('/^[0-9a-fA-F-]{36}$/', $locationId) ? $locationId : null;
    }

    private function offer(array $inmueble): string
    {
        $text = $this->normalize(implode(' ', [
            (string) ($inmueble['categoria'] ?? ''),
            (string) ($inmueble['destinacion'] ?? ''),
            (string) ($inmueble['titulo'] ?? ''),
        ]));
        $hasSale = (float) ($inmueble['precio_venta'] ?? 0) > 0;
        $hasRent = (float) ($inmueble['precio_arriendo'] ?? 0) > 0;
        if ($hasSale && $hasRent) {
            return Env::get('FINCARAIZ_DUAL_OFFER', 'rent') === 'sale' ? 'sell' : 'rent';
        }
        if ($hasRent || str_contains($text, 'arriendo') || str_contains($text, 'alquiler')) {
            return 'rent';
        }

        return 'sell';
    }

    private function propertyType(string $value): string
    {
        $slug = str_replace(' ', '-', $this->normalize($value));

        return [
            'lote' => 'lot',
            'lote-urbano' => 'lot',
            'local' => 'commercial',
            'oficina' => 'office',
            'bodega' => 'warehouse',
            'finca' => 'farm',
            'apartamento' => 'apartment',
            'casa' => 'house',
            'habitacion' => 'room',
            'consultorio' => 'consulting-room',
            'edificio' => 'building',
            'cabana' => 'cabin',
            'casa-campestre' => 'country-house',
            'apartaestudio' => 'studio',
            'casa-lote' => 'house-lot',
            'parqueadero' => 'parking',
        ][$slug] ?? '';
    }

    private function description(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $value);
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '', (string) $value);
        $value = preg_replace('/(?:\+?57\s*)?(?:\d[\s.-]*){10,}/', '', (string) $value);
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, 49000) : substr($value, 0, 49000);
    }

    private function age($constructionYear): int
    {
        $year = (int) $constructionYear;
        if ($year >= 1500 && $year <= (int) date('Y')) {
            $years = max(0, (int) date('Y') - $year);
        } else {
            $years = 0;
        }

        return match (true) {
            $years === 0 => 0,
            $years < 1 => 1,
            $years <= 8 => 2,
            $years <= 15 => 3,
            $years <= 30 => 4,
            default => 5,
        };
    }

    private function bucket(int $value, int $max, int $overflow): int
    {
        return $value > $max ? $overflow : max(0, $value);
    }

    private function stratum($value): int
    {
        $value = (int) $value;
        return in_array($value, [0, 1, 2, 3, 4, 5, 6, 100, 110], true) ? $value : 0;
    }

    private function phone($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '57')) {
            return '+' . $digits;
        }

        return '+57' . $digits;
    }

    private function number($value): ?float
    {
        $clean = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', (string) $value));
        return is_numeric($clean) ? (float) $clean : null;
    }

    private function positiveInteger($value): ?int
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $value);
        return $clean !== '' ? (int) $clean : null;
    }

    private function text($value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
    }

    private function normalize(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }

        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    private function normalizeBarrio(string $value): string
    {
        $value = $this->normalize($value);
        return [
            'morros' => 'zona norte',
            'barcelona' => 'barcelona de indias',
        ][$value] ?? $value;
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
}
