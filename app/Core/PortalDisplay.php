<?php

declare(strict_types=1);

namespace App\Core;

final class PortalDisplay
{
    public static function action(?string $value): string
    {
        return [
            'publish'=>'Publicar', 'create_ad'=>'Publicar', 'create'=>'Publicar',
            'update'=>'Actualizar', 'update_ad'=>'Actualizar',
            'delete'=>'Eliminar', 'delete_ad'=>'Despublicar', 'pause'=>'Pausar',
            'activate'=>'Activar', 'verify'=>'Verificar', 'sync_error'=>'Validacion del inmueble',
        ][$value ?? ''] ?? (string) $value;
    }

    public static function state(?string $value): string
    {
        return [
            'pending' => 'En espera', 'processing' => 'Procesando', 'synced' => 'Confirmado',
            'failed' => 'Con error', 'error' => 'Con error', 'published' => 'Publicado',
            'active' => 'Publicado', 'disabled' => 'Despublicado', 'deleted' => 'Eliminado',
            'paused' => 'Pausado', 'closed' => 'Finalizado', 'not_sent' => 'Sin publicar',
            'not_yet_active' => 'Pendiente de activacion', 'under_review' => 'En revision',
            '' => 'Sin actividad',
        ][$value ?? ''] ?? (string) $value;
    }
}
