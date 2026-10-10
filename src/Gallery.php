<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Photos on a card, in the same shape as the core's own OfferImages: never
 * stored as uploaded, decoded and re-encoded fresh in two sizes, which
 * strips EXIF (including GPS) and rejects anything that is not really a
 * picture. Files live outside the web root and are served by
 * GalleryMediaController.
 */
final class Gallery
{
    public const MAX_IMAGES = 6;
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const LARGE_EDGE = 1600;
    private const THUMB_EDGE = 480;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /** @return list<array> ordered by position, then id */
    public function ofProvider(int $providerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_gallery WHERE provider_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $providerId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function add(int $providerId, array $upload): ?string
    {
        if (!self::available()) {
            return 'card.gallery.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'card.gallery.error.too_large'
                : 'card.gallery.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'card.gallery.error.too_large';
        }
        if (count($this->ofProvider($providerId)) >= self::MAX_IMAGES) {
            return 'card.gallery.error.too_many';
        }

        // What the file is is read from its content, never from the name
        // or the type the browser claims.
        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'card.gallery.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'card.gallery.error.dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'card.gallery.error.type';
        }

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        $dir = $this->uploadDir . '/' . $providerId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'card.gallery.error.storage';
        }

        $large = $this->resized($source, self::LARGE_EDGE);
        $thumb = $this->resized($source, self::THUMB_EDGE);
        $written = $this->write($large, "{$dir}/{$name}.{$extension}", $extension)
            && $this->write($thumb, "{$dir}/{$name}_thumb.{$extension}", $extension);
        $width = imagesx($large);
        $height = imagesy($large);
        imagedestroy($source);
        imagedestroy($large);
        imagedestroy($thumb);

        if (!$written) {
            @unlink("{$dir}/{$name}.{$extension}");
            @unlink("{$dir}/{$name}_thumb.{$extension}");

            return 'card.gallery.error.storage';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_card_gallery (provider_id, name, extension, width, height, position, created_at)
             VALUES (:provider, :name, :extension, :width, :height, :position, :now)'
        );
        $stmt->execute([
            'provider' => $providerId, 'name' => $name, 'extension' => $extension, 'width' => $width, 'height' => $height,
            'position' => count($this->ofProvider($providerId)), 'now' => Clock::now(),
        ]);

        return null;
    }

    public function delete(int $providerId, int $imageId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_gallery WHERE id = :id AND provider_id = :provider');
        $stmt->execute(['id' => $imageId, 'provider' => $providerId]);
        $image = $stmt->fetch();
        if (!$image) {
            return;
        }

        $delete = $this->db->prepare('DELETE FROM x_card_gallery WHERE id = :id');
        $delete->execute(['id' => $imageId]);
        $this->unlinkFiles($providerId, $image);
    }

    /** The stored file for a request, or null - only names this class created can match. */
    public function path(int $providerId, string $file): ?string
    {
        if (preg_match('/^[0-9a-f]{32}(_thumb)?\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }

        $path = $this->uploadDir . '/' . $providerId . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Paths (below /media) of an image row, for templates. */
    public static function urls(array $image): array
    {
        $base = '/media/card/' . $image['provider_id'] . '/' . $image['name'];

        return [
            'id' => (int) $image['id'],
            'large' => $base . '.' . $image['extension'],
            'thumb' => $base . '_thumb.' . $image['extension'],
            'width' => (int) $image['width'],
            'height' => (int) $image['height'],
        ];
    }

    private function unlinkFiles(int $providerId, array $image): void
    {
        $base = $this->uploadDir . '/' . $providerId . '/' . $image['name'];
        @unlink($base . '.' . $image['extension']);
        @unlink($base . '_thumb.' . $image['extension']);
    }

    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        return $extension === 'webp' ? imagewebp($image, $path, 82) : imagejpeg($image, $path, 85);
    }
}
