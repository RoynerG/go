<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\Response;
use App\Core\Url;
use App\Models\InmuebleRepository;
use App\Models\MercadolibreRepository;
use App\Services\MercadolibreClient;
use App\Services\MercadolibrePayloadBuilder;
use App\Services\MercadolibreSyncService;
use RuntimeException;
use Throwable;

final class MercadolibreController
{
    private function authorize(bool $csrf = true): bool
    {
        header('Cache-Control: no-store');
        if (!Auth::check()) {
            Response::json(['ok' => false, 'message' => 'Inicia sesion en el panel.'], 401);
            return false;
        }
        if ($csrf && !Auth::verifyCsrf($_POST['_token'] ?? null)) {
            Response::json(['ok' => false, 'message' => 'La sesion expiro. Actualiza el panel.'], 403);
            return false;
        }
        return true;
    }

    public function connect(): void
    {
        if (!$this->authorize()) return;
        try {
            $repo = new MercadolibreRepository();
            $repo->migrate();
            $state = bin2hex(random_bytes(32));
            $verifier = bin2hex(random_bytes(32));
            $_SESSION['ml_oauth'] = ['state' => $state, 'verifier' => $verifier, 'created' => time()];
            header('Location: ' . (new MercadolibreClient($repo))->authorizationUrl($state, $verifier));
        } catch (Throwable $e) {
            Response::view('panel/error', ['message' => $e->getMessage()]);
        }
    }

    public function callback(): void
    {
        if (!$this->authorize(false)) return;
        header('Referrer-Policy: no-referrer');
        $oauth = $_SESSION['ml_oauth'] ?? null;
        unset($_SESSION['ml_oauth']);
        if (!$oauth || time() - $oauth['created'] > 600 || !hash_equals($oauth['state'], (string) ($_GET['state'] ?? ''))) {
            Response::view('panel/error', ['message' => 'Autorizacion no valida o vencida. Inicia la conexion desde el panel.']);
            return;
        }
        try {
            if (empty($_GET['code']) || !empty($_GET['error'])) {
                throw new RuntimeException('La cuenta no concedio autorizacion.');
            }
            $repo = new MercadolibreRepository();
            $repo->migrate();
            (new MercadolibreClient($repo))->exchange((string) $_GET['code'], $oauth['verifier']);
            $_SESSION['panel_flash'] = ['type' => 'success', 'message' => 'Mercado Libre conectado.'];
            Url::redirect('/panel/inmuebles?portal=mercadolibre');
        } catch (Throwable $e) {
            Response::view('panel/error', ['message' => $e->getMessage()]);
        }
    }

    public function action(string $id, string $action): void
    {
        if (!$this->authorize()) return;
        try {
            $actions = ['publicar' => 'publish', 'actualizar' => 'update', 'despublicar' => 'pause', 'eliminar' => 'delete'];
            if (!isset($actions[$action])) throw new RuntimeException('Accion no valida.');
            $property = (new InmuebleRepository())->findFull((int) $id);
            if (!$property) throw new RuntimeException('Inmueble no encontrado.');
            if (in_array($action, ['publicar','actualizar'], true) && !MercadolibrePayloadBuilder::available($property)) {
                throw new RuntimeException('El inmueble no esta disponible.');
            }
            if (!Env::bool('MERCADOLIBRE_ENABLED') || !MercadolibreClient::configured()) {
                throw new RuntimeException('Configura y activa Mercado Libre en .env antes de publicar.');
            }
            if ($action === 'eliminar' && ($_POST['confirm_delete'] ?? '') !== '1') {
                throw new RuntimeException('Confirma la eliminacion definitiva.');
            }
            $repo = new MercadolibreRepository();
            if (!$repo->account()) throw new RuntimeException('Conecta la cuenta de Mercado Libre desde el panel.');
            $repo->ensure((int) $id, (string) $property['reference_id']);
            $repo->manual((int) $id, $actions[$action]);
            // Release the session so polling can display processing while the API request runs.
            session_write_close();
            $result = (new MercadolibreSyncService())->run(1, (int) $id);
            $ad = $repo->ad((int) $id);
            $ok = ($result['status'] ?? '') === 'busy' || ($ad['sync_status'] ?? '') !== 'failed';
            $message = $ad['last_error'] ?: (($ad['sync_status'] ?? '') === 'synced' ? 'Operacion confirmada en Mercado Libre.' : 'Operacion en cola de Mercado Libre.');
            Response::json(['ok' => $ok, 'type' => $ok ? 'success' : 'error', 'message' => $message,
                'card' => ['id' => (int) $id, 'mercadolibre' => $ad]]);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'type' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function process(): void
    {
        if (!$this->authorize()) return;
        session_write_close();
        try {
            $result = (new MercadolibreSyncService())->run(max(1, min(20, (int) Env::get('MERCADOLIBRE_CRON_LIMIT', '10'))));
            Response::json($result + ['message' => 'Mercado Libre: ' . ($result['success'] ?? 0) . ' confirmados, '
                . ($result['failed'] ?? 0) . ' errores, ' . ($result['waiting_quota'] ?? 0) . ' en espera de cupo.']);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function status(): void
    {
        if (!$this->authorize(false)) return;
        session_write_close();
        $repo = new MercadolibreRepository();
        $repo->migrate();
        $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))), 0, 20);
        $cards = array_values(array_filter(array_map(fn ($id) => $repo->ad($id), $ids)));
        Response::json(['ok' => true, 'cards' => $cards] + $repo->overview());
    }

    public function packs(): void
    {
        if (!$this->authorize()) return;
        session_write_close();
        try {
            $packs = (new MercadolibreSyncService())->packs();
            $safe = array_map(fn ($pack) => array_intersect_key($pack, array_flip([
                'description','status','date_expires','category_id','listing_details','remaining_listings'])), $packs);
            Response::json(['ok' => true, 'packs' => $safe]);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
