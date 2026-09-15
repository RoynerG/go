<?php
/**
 * Plugin Name: Portales Go Sync
 * Description: Sincroniza los formularios JetFormBuilder de inmuebles con el MVC Portales Go.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PG_MVC_URL')) {
    define('PG_MVC_URL', 'https://gocartagenarealestate.com/portales-go/public');
}

if (!defined('PG_MVC_SECRET')) {
    define('PG_MVC_SECRET', getenv('PG_MVC_SECRET') ?: 'CAMBIAR_PG_MVC_SECRET');
}

add_action('jet-form-builder/custom-action/sync_inmueble_cct', 'pg_sync_inmueble_cct', 10, 1);
add_action('jet-form-builder/custom-action/despublicar_inmueble_cct', 'pg_despublicar_inmueble_cct', 10, 1);
add_action('admin_post_pg_sync_all_inmuebles', 'pg_sync_all_inmuebles');
add_action('admin_post_nopriv_pg_sync_all_inmuebles', 'pg_sync_all_inmuebles');

if (!function_exists('pg_sync_inmueble_cct')) {
    function pg_sync_inmueble_cct(array $request): void
    {
        $postId = (int) ($request['inserted_post_id'] ?? $request['id_inmueble'] ?? 0);
        if ($postId <= 0) {
            return;
        }

        $payload = pg_build_inmueble_payload($postId, $request);
        $reference = $payload['reference_id'];
        $isNew = !empty($request['inserted_post_id']) && empty($request['id_inmueble']);
        $method = $isNew ? 'POST' : 'PUT';
        $path = $method === 'POST' ? '/api/inmuebles' : '/api/inmuebles/' . rawurlencode($reference);

        pg_mvc_request($method, $path, $payload);
    }
}

if (!function_exists('pg_despublicar_inmueble_cct')) {
    function pg_despublicar_inmueble_cct(array $request): void
    {
        $postId = (int) ($request['id_inmueble'] ?? 0);
        if ($postId <= 0) {
            return;
        }

        $reference = pg_reference_from_request($request, $postId);
        pg_mvc_request('POST', '/api/inmuebles/' . rawurlencode($reference) . '/despublicar', [
            'reference_id' => $reference,
            'id_inmueble' => $postId,
            'estado' => 'No disponible',
            'publicar_proppit' => 'No',
            'publicar_fincaraiz' => 'No',
            'razon_despublicacion' => $request['razon-despublicacion'] ?? null,
        ]);
    }
}

if (!function_exists('pg_build_inmueble_payload')) {
    function pg_build_inmueble_payload(int $postId, array $request): array
    {
        $post = get_post($postId);
        $meta = function (string $key, $default = null) use ($postId, $request) {
            $value = get_post_meta($postId, $key, true);
            if ($value !== '' && $value !== null && $value !== false) {
                return $value;
            }
            return $request[$key] ?? $default;
        };

        $barrio = pg_term_name($request['barrio'] ?? null, 'barrio', $postId);
        $barrioData = pg_barrio_catalog_data($barrio);
        $estado = $meta('estado', 'Disponible');
        $publish = pg_publish_value($meta('publicar_proppit', 'Si'), $estado);

        return [
            'wp_post_id' => $postId,
            'id_inmueble' => $postId,
            'reference_id' => (string) $postId,
            'titulo' => $request['titulo'] ?? ($post ? $post->post_title : ''),
            'texto_corto' => $request['texto-corto'] ?? ($post ? $post->post_excerpt : ''),
            'descripcion' => $request['descripcion'] ?? ($post ? $post->post_content : ''),
            'tipo_inmueble' => pg_term_name($request['tipo_inmueble'] ?? null, 'tipo-inmueble', $postId),
            'categoria' => pg_term_name($request['categoria'] ?? null, 'categoria', $postId),
            'destinacion' => pg_term_name($request['destinacion'] ?? null, 'destinacion', $postId),
            'estado' => $estado,
            'precio_venta' => $meta('precio_venta'),
            'precio_arriendo' => $meta('precio_arriendo'),
            'precio_iva' => $meta('precio_iva'),
            'precio_admin' => $meta('precio_admin', $request['precio_administracion'] ?? null),
            'area_construida' => $meta('area_construida'),
            'area_privada' => $meta('area_privada'),
            'area_terreno' => $meta('area_terreno'),
            'habitaciones' => $meta('habitaciones', 0),
            'banos' => $meta('banos', 0),
            'pisos' => $meta('pisos'),
            'estrato' => $meta('estrato'),
            'parqueaderos' => $meta('parqueaderos', 0),
            'depositos' => $meta('depositos', 0),
            'ano_construccion' => $meta('ano_construccion'),
            'direccion' => $meta('direccion'),
            'barrio' => $barrio,
            'ciudad' => $meta('ciudad', $barrioData['ciudad'] ?? 'Cartagena'),
            'departamento' => $meta('departamento', $barrioData['departamento'] ?? 'Bolivar'),
            'pais' => $meta('pais', 'CO'),
            'codigo_postal' => $meta('codigo_postal', $barrioData['codigo_postal'] ?? null),
            'latitud' => $meta('latitud', $barrioData['latitud'] ?? null),
            'longitud' => $meta('longitud', $barrioData['longitud'] ?? null),
            'visibilidad_proppit' => $meta('visibilidad_proppit', 'approximate'),
            'publicar_proppit' => $publish,
            'publicar_fincaraiz' => pg_publish_value($meta('publicar_fincaraiz', $publish), $estado),
            'isBoosted' => $meta('isBoosted', $meta('is_boosted', 'Si')),
            'isExclusive' => $meta('isExclusive', $meta('is_exclusive', $request['exclusivo'] ?? 'No')),
            'nombre_propietario' => $meta('nombre_propietario'),
            'celular_propietario' => $meta('celular_propietario'),
            'correo_propietario' => $meta('correo_propietario'),
            'nombre_consultor' => $meta('nombre_consultor'),
            'celular_consultor' => $meta('celular_consultor'),
            'correo_consultor' => $meta('correo_consultor'),
            'internas' => pg_list_value($meta('caracteristicas_internas', $request['internas'] ?? [])),
            'externas' => pg_list_value($meta('caracteristicas_externas', $request['externas'] ?? [])),
            'servicios' => pg_list_value($meta('caracteristicas_servicios', $meta('servicios', $request['servicios'] ?? $request['instalaciones'] ?? []))),
            'instalaciones' => pg_list_value($meta('caracteristicas_instalaciones', $meta('instalaciones', $request['instalaciones'] ?? $request['amenities'] ?? []))),
            'cerca_de' => pg_list_value($meta('caracteristicas_cerca_de', $meta('cerca_de', $request['cerca'] ?? $request['nearbyLocations'] ?? []))),
            'portada_url' => pg_attachment_url($meta('_thumbnail_id', $request['portada'] ?? null)),
            'galeria_urls' => pg_attachment_urls($meta('galeria', $request['galeria'] ?? [])),
            'planos_urls' => pg_attachment_urls($meta('planos', $request['planos'] ?? [])),
            'tour_url' => $meta('virtual-tour', $meta('virtual_tour', $request['virtual-tour'] ?? null)),
        ];
    }
}

if (!function_exists('pg_sync_all_inmuebles')) {
    function pg_sync_all_inmuebles(): void
    {
        if (!pg_bulk_sync_allowed()) {
            status_header(403);
            wp_send_json(['ok' => false, 'error' => 'No autorizado'], 403);
        }

        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $dryRun = isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';

        $query = new WP_Query([
            'post_type' => 'inmuebles',
            'post_status' => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        $results = [];
        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $payload = pg_build_inmueble_payload($postId, ['id_inmueble' => $postId]);
            $reference = $payload['reference_id'];

            if ($dryRun) {
                $results[] = [
                    'id' => $postId,
                    'reference_id' => $reference,
                    'estado' => $payload['estado'] ?? null,
                    'publicar_proppit' => $payload['publicar_proppit'] ?? null,
                    'publicar_fincaraiz' => $payload['publicar_fincaraiz'] ?? null,
                    'galeria' => count($payload['galeria_urls'] ?? []),
                ];
                continue;
            }

            $response = pg_mvc_request('PUT', '/api/inmuebles/' . rawurlencode($reference), $payload);
            $results[] = [
                'id' => $postId,
                'reference_id' => $reference,
                'ok' => !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400,
                'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                'error' => is_wp_error($response) ? $response->get_error_message() : null,
            ];
        }

        $processed = count($query->posts);
        $total = (int) $query->found_posts;
        $nextOffset = $offset + $processed;

        wp_send_json([
            'ok' => true,
            'dry_run' => $dryRun,
            'limit' => $limit,
            'offset' => $offset,
            'processed' => $processed,
            'total' => $total,
            'next_offset' => $nextOffset < $total ? $nextOffset : null,
            'done' => $nextOffset >= $total,
            'results' => $results,
        ]);
    }
}

if (!function_exists('pg_reference_from_request')) {
    function pg_reference_from_request(array $request, int $postId): string
    {
        $reference = pg_clean_reference_id($request['codigo_completo'] ?? '');
        if ($reference === '0') {
            $reference = pg_clean_reference_id($request['reference_id'] ?? '');
        }
        if ($reference === '0') {
            $reference = (string) $postId;
        }
        return $reference;
    }
}

if (!function_exists('pg_bulk_sync_allowed')) {
    function pg_bulk_sync_allowed(): bool
    {
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        $secret = (string) ($_GET['secret'] ?? '');
        return $secret !== '' && hash_equals((string) PG_MVC_SECRET, $secret);
    }
}

if (!function_exists('pg_clean_reference_id')) {
    function pg_clean_reference_id($value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $value = preg_replace('/^INM-/i', '', $value);
        return $value !== '' ? $value : '0';
    }
}

if (!function_exists('pg_mvc_request')) {
    function pg_mvc_request(string $method, string $path, array $payload)
    {
        return wp_remote_request(rtrim((string) PG_MVC_URL, '/') . $path, [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Api-Key' => (string) PG_MVC_SECRET,
            ],
            'body' => wp_json_encode($payload),
        ]);
    }
}

if (!function_exists('pg_publish_value')) {
    function pg_publish_value($value, $estado = null): string
    {
        $estado = strtolower(trim((string) $estado));
        if (in_array($estado, ['0', 'no disponible', 'no_disponible', 'deleted'], true)) {
            return 'No';
        }

        $value = strtolower(trim((string) $value));
        if (in_array($value, ['0', 'no', 'false', 'off', 'no disponible', 'no_disponible'], true)) {
            return 'No';
        }
        return 'Si';
    }
}

if (!function_exists('pg_term_name')) {
    function pg_term_name($value, string $taxonomy, int $postId): ?string
    {
        if ($value && is_numeric($value)) {
            $term = get_term((int) $value, $taxonomy);
            return $term && !is_wp_error($term) ? $term->name : null;
        }

        $terms = get_the_terms($postId, $taxonomy);
        if ($terms && !is_wp_error($terms)) {
            return $terms[0]->name;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}

if (!function_exists('pg_barrio_catalog_data')) {
    function pg_barrio_catalog_data(?string $barrio): ?array
    {
        if (!$barrio) {
            return null;
        }

        global $wpdb;
        $norm = pg_normalize_barrio($barrio);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT barrio_nombre, ciudad, departamento, pais, codigo_postal, latitud, longitud, fincaraiz_location_id
                 FROM app_barrios_catalog
                 WHERE barrio_norm = %s AND activo = 1
                 LIMIT 1',
                $norm
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('pg_normalize_barrio')) {
    function pg_normalize_barrio(string $value): string
    {
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return [
            'morros' => 'morros',
            'barcelona' => 'barcelona',
            'chambacu' => 'chambacu',
            'chambacú' => 'chambacu',
            'anillo vial' => 'anillovial',
            'anillo vial cartagena' => 'anillovial',
        ][$value] ?? $value;
    }
}

if (!function_exists('pg_attachment_url')) {
    function pg_attachment_url($value): ?string
    {
        $items = pg_list_value($value);
        $first = $items[0] ?? null;
        if (!$first) {
            return null;
        }
        if (filter_var($first, FILTER_VALIDATE_URL)) {
            return $first;
        }
        $url = wp_get_attachment_url((int) $first);
        return $url ?: null;
    }
}

if (!function_exists('pg_attachment_urls')) {
    function pg_attachment_urls($value): array
    {
        $urls = [];
        foreach (pg_list_value($value) as $item) {
            if (filter_var($item, FILTER_VALIDATE_URL)) {
                $urls[] = $item;
                continue;
            }
            $url = wp_get_attachment_url((int) $item);
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}

if (!function_exists('pg_list_value')) {
    function pg_list_value($value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return pg_list_value($decoded);
            }
            $unserialized = @unserialize($trimmed, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                return pg_list_value($unserialized);
            }
            if (strpos($trimmed, ',') !== false) {
                return array_values(array_filter(array_map('trim', explode(',', $trimmed))));
            }
        }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $items = array_merge($items, pg_list_value($item));
                    continue;
                }
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $items[] = trim((string) $item);
                }
            }
            return array_values(array_unique($items));
        }
        return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
    }
}
