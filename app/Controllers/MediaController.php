<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\InmuebleRepository;

final class MediaController
{
    public function fincaraizImage(string $id): void
    {
        $mediaId = (int) preg_replace('/[^0-9]/', '', $id);
        if ($mediaId <= 0) {
            $this->notFound();
            return;
        }

        $media = (new InmuebleRepository())->mediaById($mediaId);
        if (!$media || !in_array((string) ($media['tipo'] ?? ''), ['portada', 'galeria'], true)) {
            $this->notFound();
            return;
        }

        $sourceUrl = trim((string) ($media['url'] ?? ''));
        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $sourceUrl)) {
            $this->notFound();
            return;
        }

        $cachePath = dirname(__DIR__, 2) . '/storage/fincaraiz-images/' . $mediaId . '.jpg';
        if (is_file($cachePath)) {
            $this->sendJpeg($cachePath);
            return;
        }

        $raw = $this->download($sourceUrl);
        if ($raw === null) {
            $this->notFound();
            return;
        }

        if (!function_exists('imagecreatefromstring')) {
            $this->serveOriginalIfJpeg($sourceUrl, $raw);
            return;
        }

        $image = @imagecreatefromstring($raw);
        if (!$image) {
            $this->serveOriginalIfJpeg($sourceUrl, $raw);
            return;
        }

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $jpeg = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($jpeg, 255, 255, 255);
        imagefilledrectangle($jpeg, 0, 0, imagesx($image), imagesy($image), $white);
        imagecopy($jpeg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagejpeg($jpeg, $cachePath, 88);
        imagedestroy($jpeg);
        imagedestroy($image);

        if (!is_file($cachePath)) {
            $this->notFound();
            return;
        }

        $this->sendJpeg($cachePath);
    }

    private function download(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            $raw = @file_get_contents($url);
            return is_string($raw) && $raw !== '' ? $raw : null;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'PortalesGo/1.0',
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return is_string($raw) && $raw !== '' && $status >= 200 && $status < 300 ? $raw : null;
    }

    private function serveOriginalIfJpeg(string $sourceUrl, string $raw): void
    {
        $path = strtolower((string) parse_url($sourceUrl, PHP_URL_PATH));
        if (!preg_match('~\.jpe?g$~', $path)) {
            http_response_code(415);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'La imagen no se pudo convertir a JPG.';
            return;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=2592000, immutable');
        echo $raw;
    }

    private function sendJpeg(string $path): void
    {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=2592000, immutable');
        readfile($path);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Imagen no encontrada.';
    }
}
