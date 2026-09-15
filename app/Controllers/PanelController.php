<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Url;
use App\Models\InmuebleRepository;
use App\Services\FincaraizSyncService;
use App\Services\ProppitSyncService;
use Throwable;

final class PanelController
{
    public function index(): void
    {
        $this->renderListPage('inmuebles');
    }

    public function queuePage(): void
    {
        $this->renderPanelPage('cola-cron');
    }

    public function logsPage(): void
    {
        $this->renderPanelPage('logs');
    }

    public function automationPage(): void
    {
        $this->renderPanelPage('automatizacion');
    }

    public function statesPage(): void
    {
        $this->renderPanelPage('estados');
    }

    public function publishedPage(): void
    {
        $this->renderListPage('publicados', $this->portalStatusFilters('publicados'));
    }

    public function deletedPage(): void
    {
        $this->renderListPage('eliminados', $this->portalStatusFilters('eliminados'));
    }

    public function errorsPage(): void
    {
        $this->renderListPage('errores', $this->portalStatusFilters('errores'));
    }

    private function portalStatusFilters(string $page): array
    {
        $portal = trim((string) ($_GET['portal'] ?? 'proppit'));
        if ($portal === 'fincaraiz') {
            return match ($page) {
                'publicados' => ['fincaraiz_estado' => 'active'],
                'eliminados' => ['fincaraiz_estado' => 'disabled', 'fincaraiz_action' => 'pause'],
                'errores' => ['fincaraiz_estado' => 'error'],
                default => [],
            };
        }

        return match ($page) {
            'publicados' => ['proppit_estado' => 'published'],
            'eliminados' => ['proppit_estado' => 'deleted', 'proppit_action' => 'delete'],
            'errores' => ['proppit_estado' => 'error'],
            default => [],
        };
    }

    private function renderListPage(string $activePage, array $forcedFilters = []): void
    {
        if (!Auth::check()) {
            Url::redirect('/panel/login');
        }

        try {
            $repository = new InmuebleRepository();
            $filters = array_merge($this->filters(), $forcedFilters);
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 20;
            $total = $repository->panelCount($filters);
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $rows = $repository->panelRows($filters, $page, $perPage);
            Response::view('panel/inmuebles', [
                'rows' => $rows,
                'filters' => $filters,
                'options' => $repository->filterOptions(),
                'operation' => $repository->operationSummary(),
                'activePage' => $activePage,
                'flash' => $this->pullFlash(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                ],
            ]);
        } catch (Throwable $exception) {
            http_response_code(500);
            Response::view('panel/error', [
                'message' => Env::bool('APP_DEBUG', false)
                    ? $exception->getMessage()
                    : 'No se pudo cargar el panel. Revisa la configuracion de base de datos y las migraciones.',
            ]);
        }
    }

    private function renderPanelPage(string $activePage): void
    {
        if (!Auth::check()) {
            Url::redirect('/panel/login');
        }

        try {
            $repository = new InmuebleRepository();
            Response::view('panel/inmuebles', [
                'rows' => [],
                'filters' => [],
                'options' => $repository->filterOptions(),
                'operation' => $repository->operationSummary(),
                'activePage' => $activePage,
                'flash' => $this->pullFlash(),
                'pagination' => [
                    'page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'total_pages' => 1,
                ],
            ]);
        } catch (Throwable $exception) {
            http_response_code(500);
            Response::view('panel/error', [
                'message' => Env::bool('APP_DEBUG', false)
                    ? $exception->getMessage()
                    : 'No se pudo cargar el panel. Revisa la configuracion de base de datos y las migraciones.',
            ]);
        }
    }

    public function show(string $id): void
    {
        if (!Auth::check()) {
            Url::redirect('/panel/login');
        }

        $inmueble = (new InmuebleRepository())->findFull((int) $id);
        if (!$inmueble) {
            http_response_code(404);
            Response::view('panel/error', ['message' => 'Inmueble no encontrado.']);
            return;
        }

        Response::view('panel/ficha', ['inmueble' => $inmueble, 'flash' => $this->pullFlash()]);
    }

    public function publish(string $id): void
    {
        $this->handleProppitAction((int) $id, 'publish');
    }

    public function updateInProppit(string $id): void
    {
        $this->handleProppitAction((int) $id, 'update');
    }

    public function unpublishInProppit(string $id): void
    {
        $this->handleProppitAction((int) $id, 'delete');
    }

    public function publishInFincaraiz(string $id): void
    {
        $this->handleFincaraizAction((int) $id, 'publish');
    }

    public function updateInFincaraiz(string $id): void
    {
        $this->handleFincaraizAction((int) $id, 'update');
    }

    public function unpublishInFincaraiz(string $id): void
    {
        $this->handleFincaraizAction((int) $id, 'pause');
    }

    public function verifyInFincaraiz(string $id): void
    {
        $this->handleFincaraizAction((int) $id, 'verify');
    }

    public function toggleBoosted(string $id): void
    {
        $this->handleFlagToggle((int) $id, 'is_boosted', 'Destacado actualizado.');
    }

    public function toggleExclusive(string $id): void
    {
        $this->handleFlagToggle((int) $id, 'is_exclusive', 'Exclusivo actualizado.');
    }

    public function processQueue(): void
    {
        if (!Auth::check()) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Sesion vencida. Inicia sesion otra vez.'], 401);
                return;
            }
            Url::redirect('/panel/login');
        }

        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Token de seguridad invalido. Recarga el panel.'], 419);
                return;
            }
            Url::redirect('/panel/inmuebles');
        }

        $type = 'success';
        $message = 'Cola procesada correctamente.';
        $payload = [];

        try {
            $repository = new InmuebleRepository();
            $audit = $repository->refreshProppitQueueFromInmuebles();
            $limit = max(1, min(50, (int) ($_POST['limit'] ?? 10)));
            $sync = (new ProppitSyncService())->run($limit);
            if (($sync['failed'] ?? 0) > 0) {
                $type = 'warning';
                $message = 'La cola se proceso, pero algunos inmuebles fallaron. Revisa los logs.';
            }
            $cards = array_values(array_filter(array_map(
                fn (int $inmuebleId): ?array => $this->cardState($inmuebleId),
                array_unique(array_map('intval', $sync['inmueble_ids'] ?? []))
            )));
            $payload = [
                'audit' => $audit,
                'sync' => $sync,
                'cards' => $cards,
                'operation' => $repository->operationSummary(),
            ];
        } catch (Throwable $exception) {
            $type = 'error';
            $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'No se pudo procesar la cola de Proppit.';
        }

        if ($this->wantsJson()) {
            Response::json(array_merge([
                'ok' => $type !== 'error',
                'type' => $type,
                'message' => $message,
            ], $payload), $type === 'error' ? 422 : 200);
            return;
        }

        $this->flash($type, $message);
        $this->redirectBack();
    }

    public function processFincaraizQueue(): void
    {
        if (!Auth::check()) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Sesion vencida. Inicia sesion otra vez.'], 401);
                return;
            }
            Url::redirect('/panel/login');
        }

        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Token de seguridad invalido. Recarga el panel.'], 419);
                return;
            }
            Url::redirect('/panel/inmuebles');
        }

        $type = 'success';
        $message = 'Cola de Finca Raiz procesada correctamente.';
        $payload = [];

        try {
            $repository = new InmuebleRepository();
            $audit = $repository->refreshFincaraizQueueFromInmuebles();
            $limit = max(1, min(50, (int) ($_POST['limit'] ?? 10)));
            $sync = (new FincaraizSyncService())->run($limit);
            if (($sync['failed'] ?? 0) > 0) {
                $type = 'warning';
                $message = 'La cola se proceso, pero algunos inmuebles fallaron. Revisa los logs de Finca Raiz.';
            } elseif (($sync['queued'] ?? 0) > 0) {
                $message = 'Finca Raiz recibio acciones y quedaron tareas pendientes de verificacion.';
            }
            $cards = array_values(array_filter(array_map(
                fn (int $inmuebleId): ?array => $this->cardState($inmuebleId),
                array_unique(array_map('intval', $sync['inmueble_ids'] ?? []))
            )));
            $payload = [
                'audit' => $audit,
                'sync' => $sync,
                'cards' => $cards,
                'operation' => $repository->operationSummary(),
            ];
        } catch (Throwable $exception) {
            $type = 'error';
            $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'No se pudo procesar la cola de Finca Raiz.';
        }

        if ($this->wantsJson()) {
            Response::json(array_merge([
                'ok' => $type !== 'error',
                'type' => $type,
                'message' => $message,
            ], $payload), $type === 'error' ? 422 : 200);
            return;
        }

        $this->flash($type, $message);
        $this->redirectBack();
    }

    private function handleProppitAction(int $id, string $action): void
    {
        if (!Auth::check()) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Sesion vencida. Inicia sesion otra vez.'], 401);
                return;
            }
            Url::redirect('/panel/login');
        }

        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Token de seguridad invalido. Recarga el panel.'], 419);
                return;
            }
            Url::redirect('/panel/inmuebles');
        }

        $type = 'warning';
        $message = 'La accion quedo preparada, pero no se proceso ningun registro.';

        try {
            $repository = new InmuebleRepository();
            $queued = $repository->queueProppitAction($id, $action);
            if (!$queued) {
                $type = 'error';
                $message = 'No se pudo preparar la accion. Revisa si el inmueble existe o esta no disponible.';
                $this->finishAction($type, $message, $id);
                return;
            }

            $result = (new ProppitSyncService())->runInmueble($id);
            if (($result['failed'] ?? 0) > 0) {
                $type = 'error';
                $message = 'Proppit rechazo la accion. Abre la ficha o revisa el error de la tarjeta.';
            } elseif (($result['synced'] ?? 0) > 0) {
                $labels = ['publish' => 'publicado', 'update' => 'actualizado', 'delete' => 'despublicado'];
                $type = 'success';
                $message = 'Inmueble ' . ($labels[$action] ?? 'sincronizado') . ' correctamente en Proppit.';
            }
        } catch (Throwable $exception) {
            $type = 'error';
            $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'No se pudo conectar con Proppit.';
        }

        $this->finishAction($type, $message, $id);
    }

    private function handleFincaraizAction(int $id, string $action): void
    {
        if (!Auth::check()) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Sesion vencida. Inicia sesion otra vez.'], 401);
                return;
            }
            Url::redirect('/panel/login');
        }

        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Token de seguridad invalido. Recarga el panel.'], 419);
                return;
            }
            Url::redirect('/panel/inmuebles');
        }

        $type = 'warning';
        $message = 'La accion quedo preparada, pero no se proceso ningun registro.';

        try {
            $repository = new InmuebleRepository();
            $queued = $repository->queueFincaraizAction($id, $action);
            if (!$queued) {
                $type = 'error';
                $message = 'No se pudo preparar la accion para Finca Raiz. Revisa si el inmueble existe o esta no disponible.';
                $this->finishAction($type, $message, $id);
                return;
            }

            $result = (new FincaraizSyncService())->runInmueble($id);
            if (($result['failed'] ?? 0) > 0) {
                $type = 'error';
                $message = 'Finca Raiz rechazo la accion. Abre la ficha o revisa el error de la tarjeta.';
            } elseif (($result['synced'] ?? 0) > 0 || ($result['queued'] ?? 0) > 0) {
                $labels = ['publish' => 'publicado', 'update' => 'actualizado', 'pause' => 'despublicado', 'verify' => 'verificado'];
                $type = 'success';
                $message = 'Inmueble ' . ($labels[$action] ?? 'sincronizado') . ' en Finca Raiz.';
            }
        } catch (Throwable $exception) {
            $type = 'error';
            $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'No se pudo conectar con Finca Raiz.';
        }

        $this->finishAction($type, $message, $id);
    }

    private function handleFlagToggle(int $id, string $flag, string $message): void
    {
        if (!Auth::check()) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Sesion vencida. Inicia sesion otra vez.'], 401);
                return;
            }
            Url::redirect('/panel/login');
        }

        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'type' => 'error', 'message' => 'Token de seguridad invalido. Recarga el panel.'], 419);
                return;
            }
            Url::redirect('/panel/inmuebles');
        }

        $type = 'error';
        try {
            $ok = (new InmuebleRepository())->toggleProppitFlag($id, $flag);
            $type = $ok ? 'success' : 'error';
            $message = $ok ? $message : 'No se pudo actualizar esa opcion.';
        } catch (Throwable $exception) {
            $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'No se pudo actualizar esa opcion.';
        }

        $this->finishAction($type, $message, $id);
    }

    private function filters(): array
    {
        return [
            'codigo' => trim((string) ($_GET['codigo'] ?? '')),
            'direccion' => trim((string) ($_GET['direccion'] ?? '')),
            'tipo_inmueble' => trim((string) ($_GET['tipo_inmueble'] ?? '')),
            'categoria' => trim((string) ($_GET['categoria'] ?? '')),
            'destinacion' => trim((string) ($_GET['destinacion'] ?? '')),
            'barrio' => trim((string) ($_GET['barrio'] ?? '')),
            'marcado' => trim((string) ($_GET['marcado'] ?? '')),
            'proppit_estado' => trim((string) ($_GET['proppit_estado'] ?? '')),
            'fincaraiz_estado' => trim((string) ($_GET['fincaraiz_estado'] ?? '')),
            'portal' => trim((string) ($_GET['portal'] ?? '')),
        ];
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['panel_flash'] = ['type' => $type, 'message' => $message];
    }

    private function pullFlash(): ?array
    {
        $flash = $_SESSION['panel_flash'] ?? null;
        unset($_SESSION['panel_flash']);
        return is_array($flash) ? $flash : null;
    }

    private function redirectBack(): void
    {
        $redirect = (string) ($_POST['_redirect'] ?? Url::to('/panel/inmuebles'));
        if ($redirect === '' || str_starts_with($redirect, 'http') || !str_starts_with($redirect, '/')) {
            $redirect = Url::to('/panel/inmuebles');
        }

        header('Location: ' . $redirect);
        exit;
    }

    private function finishAction(string $type, string $message, int $id): void
    {
        if ($this->wantsJson()) {
            Response::json([
                'ok' => $type === 'success',
                'type' => $type,
                'message' => $message,
                'card' => $this->cardState($id),
            ], $type === 'error' ? 422 : 200);
            return;
        }

        $this->flash($type, $message);
        $this->redirectBack();
    }

    private function cardState(int $id): ?array
    {
        $inmueble = (new InmuebleRepository())->findFull($id);
        if (!$inmueble) {
            return null;
        }

        $proppit = $inmueble['proppit'] ?? [];
        $fincaraiz = $inmueble['fincaraiz'] ?? [];
        $isBoosted = (int) ($inmueble['is_boosted'] ?? 0) === 1;
        $isExclusive = (int) ($inmueble['is_exclusive'] ?? 0) === 1;

        return [
            'id' => (int) $inmueble['id'],
            'publicar_proppit' => (int) ($inmueble['publicar_proppit'] ?? 0),
            'marked' => ((int) ($inmueble['publicar_proppit'] ?? 0) === 1) ? 'Marcado en portal' : 'No marcado en portal',
            'estado' => (string) ($inmueble['estado'] ?? ''),
            'desired_action' => (string) ($proppit['desired_action'] ?? ''),
            'sync_status' => (string) ($proppit['sync_status'] ?? 'pending'),
            'remote_status' => (string) ($proppit['remote_status'] ?? 'not_sent'),
            'last_error' => (string) ($proppit['last_error'] ?? ''),
            'publicar_fincaraiz' => (int) ($inmueble['publicar_fincaraiz'] ?? 0),
            'fincaraiz_desired_action' => (string) ($fincaraiz['desired_action'] ?? ''),
            'fincaraiz_sync_status' => (string) ($fincaraiz['sync_status'] ?? 'pending'),
            'fincaraiz_remote_status' => (string) ($fincaraiz['remote_status'] ?? 'not_sent'),
            'fincaraiz_last_error' => (string) ($fincaraiz['last_error'] ?? ''),
            'is_boosted' => $isBoosted,
            'is_exclusive' => $isExclusive,
            'boosted' => $isBoosted ? 'Destacado activo' : 'Destacado inactivo',
            'exclusive' => $isExclusive ? 'Exclusivo activo' : 'Exclusivo inactivo',
            'boosted_button' => $isBoosted ? 'DESTACADO ON' : 'DESTACADO OFF',
            'exclusive_button' => $isExclusive ? 'EXCLUSIVO ON' : 'EXCLUSIVO OFF',
        ];
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        return stripos($accept, 'application/json') !== false || strtolower($requestedWith) === 'xmlhttprequest';
    }
}
