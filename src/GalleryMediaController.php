<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Controller\Controller;

/**
 * Serves gallery photos and the card logo from outside the web root -
 * same shape as the core's own MediaController, names are random and
 * never reused, so every response is cached forever.
 */
final class GalleryMediaController extends Controller
{
    private const TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function gallery(array $params): void
    {
        $gallery = new Gallery($this->app->db, $this->uploadDir('card-gallery'));
        $this->send($gallery->path((int) $params['id'], $params['file']));
    }

    public function logo(array $params): void
    {
        $cards = new Cards($this->app->db);
        $this->send($cards->logoPath($this->uploadDir('card-logo'), $params['file']));
    }

    private function uploadDir(string $sub): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/' . $sub;
    }

    private function send(?string $file): void
    {
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        header('Content-Type: ' . self::TYPES[pathinfo($file, PATHINFO_EXTENSION)]);
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}
