<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

final class MercadolibrePayloadBuilder
{
    public function __construct(private MercadolibreClient $client) {}

    public static function contactIssues(): array
    {
        $issues = [];
        $phone = (string) Env::get('MERCADOLIBRE_CONTACT_PHONE', '');
        $whatsapp = (string) (Env::get('MERCADOLIBRE_CONTACT_WHATSAPP') ?: $phone);
        if (self::phone($phone) === '') $issues['MERCADOLIBRE_CONTACT_PHONE'] = 'Telefono comercial colombiano de 10 digitos.';
        if (self::phone($whatsapp) === '') $issues['MERCADOLIBRE_CONTACT_WHATSAPP'] = 'WhatsApp comercial colombiano de 10 digitos.';
        if (!filter_var(trim((string) Env::get('MERCADOLIBRE_CONTACT_EMAIL', '')), FILTER_VALIDATE_EMAIL)) {
            $issues['MERCADOLIBRE_CONTACT_EMAIL'] = 'Correo comercial valido.';
        }
        return $issues;
    }

    public static function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii === false ? $value : $ascii)));
    }

    public static function available(array $property): bool
    {
        return in_array(self::normalize((string) ($property['estado'] ?? '')), ['disponible', 'publico', 'publicado', '1'], true);
    }

    public static function fingerprint(array $property, string $intent): string
    {
        $data = array_intersect_key($property, array_flip(['reference_id','titulo','descripcion','tipo_inmueble',
            'categoria','destinacion','precio_venta','precio_arriendo','precio_admin','estrato','habitaciones',
            'banos','parqueaderos','area_construida','area_privada','area_terreno','pisos','ano_construccion']));
        foreach (['ubicacion' => ['direccion','barrio','ciudad','departamento','latitud','longitud'],
            'multimedia' => ['tipo','url','orden'], 'caracteristicas' => ['tipo','valor']] as $key => $columns) {
            $source = $property[$key] ?? [];
            if ($key === 'ubicacion') {
                $source = [$source];
            }
            $data[$key] = array_map(fn ($row) => array_intersect_key($row, array_flip($columns)), $source);
        }
        $data['available'] = self::available($property);
        $data['intent'] = $intent;
        $source = json_decode((string) ($property['source_payload'] ?? '{}'), true) ?: [];
        $data['source_attributes'] = array_intersect_key($source, array_flip(['ambientes','rooms','edad_inmueble','antiguedad','mercadolibre_attributes']));
        $data['property_age'] = self::propertyAge($property);
        foreach (['CONTACT_NAME','CONTACT_EMAIL','CONTACT_PHONE','CONTACT_WHATSAPP','DUAL_OFFER','CATEGORY_MAP','LOCATION_MAP'] as $key) {
            $data['config_' . $key] = Env::get('MERCADOLIBRE_' . $key, '');
        }
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function rootCategory(): string
    {
        return $this->match($this->client->catalog('/sites/MCO/categories'), ['inmuebles'], 'categoria de inmuebles MCO')['id'];
    }

    public function build(array $property, string $listingType): array
    {
        $sale = (float) ($property['precio_venta'] ?? 0);
        $rent = (float) ($property['precio_arriendo'] ?? 0);
        $offer = $rent > 0 && ($sale <= 0 || Env::get('MERCADOLIBRE_DUAL_OFFER', 'sale') === 'rent') ? 'rent' : 'sale';
        $price = $offer === 'rent' ? $rent : $sale;
        if ($price <= 0) {
            throw new RuntimeException('El inmueble necesita un precio mayor que cero.');
        }
        $category = $this->category($property, $offer);
        $settings = $category['settings'] ?? [];
        if (empty($settings['listing_allowed']) || ($settings['vertical'] ?? '') !== 'real_estate') {
            throw new RuntimeException('La categoria seleccionada no permite publicar inmuebles.');
        }
        $pictures = [];
        $media = $property['multimedia'] ?? [];
        usort($media, fn ($a, $b) => (($a['tipo'] ?? '') !== 'portada') <=> (($b['tipo'] ?? '') !== 'portada'));
        foreach ($media as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if (in_array($item['tipo'] ?? '', ['portada','galeria'], true) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) {
                $pictures[$url] = ['source' => $url];
            }
        }
        if (!$pictures) {
            throw new RuntimeException('No hay fotos publicas: Mercado Libre requiere al menos una imagen.');
        }
        $title = trim(strip_tags((string) ($property['titulo'] ?? '')));
        $description = trim(html_entity_decode(strip_tags((string) ($property['descripcion'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '' || $description === '') {
            throw new RuntimeException('Falta titulo o descripcion del inmueble.');
        }
        $phone = $this->phone((string) Env::get('MERCADOLIBRE_CONTACT_PHONE', ''));
        $whatsapp = $this->phone((string) (Env::get('MERCADOLIBRE_CONTACT_WHATSAPP') ?: Env::get('MERCADOLIBRE_CONTACT_PHONE', '')));
        if ($phone === '' || $whatsapp === '') {
            throw new RuntimeException('Configura un telefono y WhatsApp colombianos de 10 digitos en Mercado Libre.');
        }
        $email = trim((string) Env::get('MERCADOLIBRE_CONTACT_EMAIL', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Configura MERCADOLIBRE_CONTACT_EMAIL.');
        }
        return [
            'title' => mb_substr($title, 0, (int) ($settings['max_title_length'] ?? 200)),
            'category_id' => $category['id'], 'price' => $price, 'currency_id' => 'COP',
            'available_quantity' => 1, 'buying_mode' => 'classified', 'condition' => 'not_specified',
            'listing_type_id' => $listingType, 'seller_custom_field' => (string) $property['reference_id'],
            'description' => ['plain_text' => mb_substr($description, 0, (int) ($settings['max_description_length'] ?? 50000))],
            'pictures' => array_slice(array_values($pictures), 0, (int) ($settings['max_pictures_per_item'] ?? 30)),
            'location' => $this->location($property['ubicacion'] ?? []),
            'attributes' => $this->attributes($property, (string) $category['id']),
            'seller_contact' => ['contact' => Env::get('MERCADOLIBRE_CONTACT_NAME', 'Go Cartagena'),
                'country_code' => '57', 'area_code' => '', 'phone' => $phone, 'country_code2' => '57',
                'phone2' => $whatsapp, 'email' => $email, 'other_info' => '', 'webpage' => ''],
        ];
    }

    private function category(array $property, string $offer): array
    {
        $type = self::normalize((string) ($property['tipo_inmueble'] ?? ''));
        $map = json_decode(Env::get('MERCADOLIBRE_CATEGORY_MAP', '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (!empty($map[$type . ':' . $offer])) {
            $category = $this->client->catalog('/categories/' . rawurlencode($map[$type . ':' . $offer]));
            if (!str_starts_with((string) ($category['id'] ?? ''), 'MCO')) {
                throw new RuntimeException('La categoria homologada debe pertenecer a Colombia (MCO).');
            }
            return $category;
        }
        $aliases = [
            'apartamento' => ['apartamentos'], 'apartaestudio' => ['apartaestudios','apartamentos'],
            'casa' => ['casas'], 'oficina' => ['oficinas'], 'local' => ['locales','locales comerciales'],
            'bodega' => ['bodegas'], 'lote' => ['lotes y terrenos','lotes','terrenos'],
            'finca' => ['fincas'], 'casa campestre' => ['casas campestres'], 'consultorio' => ['consultorios'],
            'edificio' => ['edificios'], 'parqueadero' => ['parqueaderos'],
        ];
        $root = $this->client->catalog('/categories/' . $this->rootCategory());
        $choice = $this->match($root['children_categories'] ?? [], $aliases[$type] ?? [$type], 'tipo ' . $type);
        $category = $this->client->catalog('/categories/' . $choice['id']);
        $choice = $this->match($category['children_categories'] ?? [], $offer === 'rent' ? ['arriendo','alquiler'] : ['venta'], 'operacion ' . $offer);
        $category = $this->client->catalog('/categories/' . $choice['id']);
        if (!empty($category['children_categories'])) {
            $choice = $this->match($category['children_categories'], ['propiedades individuales','inmuebles usados','usados','propiedades usadas'], 'subtipo individual');
            $category = $this->client->catalog('/categories/' . $choice['id']);
        }
        return $category;
    }

    private function location(array $location): array
    {
        $lat = filter_var($location['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($location['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) {
            throw new RuntimeException('Faltan coordenadas validas del inmueble para Mercado Libre.');
        }
        $country = $this->client->catalog('/classified_locations/countries/CO');
        $state = $this->match($country['states'] ?? [], [(string) ($location['departamento'] ?? '')], 'departamento');
        $stateData = $this->client->catalog('/classified_locations/states/' . rawurlencode($state['id']));
        $cityName = self::normalize((string) ($location['ciudad'] ?? ''));
        $cityNames = in_array($cityName, ['cartagena','cartagena de indias'], true) ? ['cartagena','cartagena de indias'] : [$cityName];
        $city = $this->match($stateData['cities'] ?? [], $cityNames, 'ciudad ' . $cityName);
        $cityData = $this->client->catalog('/classified_locations/cities/' . rawurlencode($city['id']));
        $barrio = self::normalize((string) ($location['barrio'] ?? ''));
        $map = json_decode(Env::get('MERCADOLIBRE_LOCATION_MAP', '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (!empty($map[$barrio])) {
            $neighborhood = $this->client->catalog('/classified_locations/neighborhoods/' . rawurlencode($map[$barrio]));
            if (($neighborhood['city']['id'] ?? '') !== $city['id']) {
                throw new RuntimeException('El barrio homologado no pertenece a la ciudad del inmueble.');
            }
        } else {
            $neighborhood = $this->match($cityData['neighborhoods'] ?? [], $barrio === 'morros' ? ['zona norte'] : [$barrio], 'barrio ' . $barrio . ' (homologa MERCADOLIBRE_LOCATION_MAP)');
        }
        $address = trim((string) ($location['direccion'] ?? ''));
        if ($address === '') {
            throw new RuntimeException('El inmueble no tiene direccion.');
        }
        return ['address_line' => $address, 'latitude' => $lat, 'longitude' => $lon,
            'country' => ['id' => 'CO'], 'state' => ['id' => $state['id']], 'city' => ['id' => $city['id']],
            'neighborhood' => ['id' => $neighborhood['id']]];
    }

    private function attributes(array $property, string $categoryId): array
    {
        $source = json_decode((string) ($property['source_payload'] ?? '{}'), true) ?: [];
        $values = ['PROPERTY_AGE' => self::propertyAge($property),
            'BEDROOMS' => $property['habitaciones'] ?? null, 'FULL_BATHROOMS' => $property['banos'] ?? null,
            'ROOMS' => $source['ambientes'] ?? $source['rooms'] ?? null,
            'PARKING_LOTS' => $property['parqueaderos'] ?? null, 'SOCIAL_STRATUM' => $property['estrato'] ?? null,
            'COVERED_AREA' => ($property['area_construida'] ?? 0) ?: ($property['area_privada'] ?? null),
            'TOTAL_AREA' => ($property['area_terreno'] ?? 0) ?: ($property['area_construida'] ?? 0) ?: ($property['area_privada'] ?? null)];
        $features = array_map(fn ($f) => self::normalize((string) ($f['valor'] ?? '')), $property['caracteristicas'] ?? []);
        $attributes = [];
        foreach ($this->client->catalog('/categories/' . $categoryId . '/attributes') as $definition) {
            $id = $definition['id'];
            $value = $source['mercadolibre_attributes'][$id] ?? $values[$id] ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new RuntimeException('El atributo ' . $id . ' debe ser un valor simple.');
            }
            if ($value !== null && $value !== '') {
                $unit = str_ends_with($id, '_AREA') ? ' m²' : '';
                if ($id === 'PROPERTY_AGE' && ($definition['value_type'] ?? '') === 'number_unit' && is_numeric($value)) {
                    $unit = ' ' . ($definition['default_unit'] ?? 'años');
                }
                $attributes[] = ['id' => $id, 'value_name' => (string) $value . $unit];
                continue;
            }
            $featureName = (string) preg_replace('/^(con|tiene) /', '', self::normalize((string) ($definition['name'] ?? '')));
            if (($definition['value_type'] ?? '') === 'boolean' && in_array($featureName, $features, true)) {
                foreach ($definition['values'] ?? [] as $option) {
                    if (in_array(self::normalize($option['name']), ['si','yes'], true)) {
                        $attributes[] = ['id' => $id, 'value_id' => $option['id']];
                        continue 2;
                    }
                }
            }
            if (!empty($definition['tags']['required']) && empty($definition['tags']['read_only']) && empty($definition['tags']['fixed'])) {
                if ($id === 'PROPERTY_AGE') throw new RuntimeException('Falta antiguedad: completa ano_construccion o edad_inmueble para publicar en Mercado Libre.');
                throw new RuntimeException('Falta homologar el atributo obligatorio ' . ($definition['name'] ?? $id) . ' (' . $id . ').');
            }
        }
        return $attributes;
    }

    private function match(array $options, array $names, string $label): array
    {
        foreach ($names as $name) {
            foreach ($options as $option) {
                if ($name !== '' && self::normalize((string) $option['name']) === self::normalize($name)) {
                    return $option;
                }
            }
        }
        throw new RuntimeException('No se encontro una coincidencia exacta para ' . $label . ' en Mercado Libre.');
    }

    private static function phone(string $value): string
    {
        $value = (string) preg_replace('/\D+/', '', $value);
        if (strlen($value) === 12 && str_starts_with($value, '57')) {
            $value = substr($value, 2);
        }
        return strlen($value) === 10 ? $value : '';
    }

    public static function propertyAge(array $property): ?int
    {
        $source = json_decode((string) ($property['source_payload'] ?? '{}'), true) ?: [];
        $age = $source['edad_inmueble'] ?? $source['antiguedad'] ?? null;
        if (is_scalar($age) && preg_match('/^\d{1,3}$/D', trim((string) $age))) return (int) $age;
        $year = filter_var($property['ano_construccion'] ?? null, FILTER_VALIDATE_INT);
        $current = (int) date('Y');
        if ($year !== false && $year >= 1800 && $year <= $current) return $current - $year;
        $override = $property['mercadolibre_property_age'] ?? null;
        if (is_scalar($override) && preg_match('/^\d{1,3}$/D', (string) $override)) return (int) $override;
        $fallback = trim((string) Env::get('MERCADOLIBRE_DEFAULT_PROPERTY_AGE', '8'));
        return preg_match('/^\d{1,3}$/D', $fallback) ? (int) $fallback : null;
    }
}
