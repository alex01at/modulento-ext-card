<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * The card-specific half of a provider's business card - everything the
 * core provider record does not already have. Name, address, contact
 * details, headline and description stay on provider/provider_translation
 * and are read from there directly; this class only ever touches
 * x_card_profile.
 */
final class Cards
{
    public const AVAILABLE_DESIGNS = ['classic', 'modern', 'professional', 'playful'];
    public const BOOKING_MODES = ['link', 'request'];

    /** link type => counter column. Only these exact columns can ever be reached from incrementClick(). */
    public const CLICK_COLUMNS = [
        'phone' => 'clicks_phone',
        'email' => 'clicks_email',
        'website' => 'clicks_website',
        'address' => 'clicks_address',
        'linkedin' => 'clicks_linkedin',
        'instagram' => 'clicks_instagram',
        'facebook' => 'clicks_facebook',
        'youtube' => 'clicks_youtube',
        'booking' => 'clicks_booking',
        'whatsapp' => 'clicks_whatsapp',
        'snapchat' => 'clicks_snapchat',
    ];

    private const DEFAULTS = [
        'id' => null, 'company' => null, 'category_id' => null, 'logo_name' => null, 'logo_extension' => null,
        'whatsapp' => null, 'website_url' => null, 'linkedin_url' => null, 'instagram_url' => null, 'facebook_url' => null,
        'youtube_url' => null, 'snapchat_url' => null, 'booking_url' => null, 'booking_mode' => null,
        'opening_hours' => null, 'design' => 'classic', 'use_custom_colors' => false,
        'color_background' => null, 'color_header' => null, 'color_content' => null, 'color_footer' => null,
        'view_count' => 0, 'clicks_phone' => 0, 'clicks_email' => 0, 'clicks_website' => 0, 'clicks_address' => 0,
        'clicks_linkedin' => 0, 'clicks_instagram' => 0, 'clicks_facebook' => 0, 'clicks_youtube' => 0,
        'clicks_booking' => 0, 'clicks_whatsapp' => 0, 'clicks_snapchat' => 0,
    ];

    public function __construct(private PDO $db)
    {
    }

    /** The stored profile, or null before the owner has ever saved one. */
    public function find(int $providerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_profile WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);
        $row = $stmt->fetch();

        return $row !== false ? $this->cast($row) : null;
    }

    /** The profile with sensible defaults filled in where none was ever saved - a provider page must always render. */
    public function findOrDefault(int $providerId): array
    {
        return $this->find($providerId) ?? (['provider_id' => $providerId] + self::DEFAULTS);
    }

    /**
     * Checks and tidies what was typed into the card editor.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: string[]}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $values = [
            'company' => mb_substr(trim((string) ($input['company'] ?? '')), 0, 150),
            'whatsapp' => mb_substr(trim((string) ($input['whatsapp'] ?? '')), 0, 30),
            'opening_hours' => mb_substr(trim(str_replace("\r\n", "\n", (string) ($input['opening_hours'] ?? ''))), 0, 2000),
        ];

        $values['category_id'] = null;
        $categoryId = (string) ($input['category_id'] ?? '');
        if ($categoryId !== '' && ctype_digit($categoryId)) {
            $stmt = $this->db->prepare('SELECT 1 FROM category WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);
            $values['category_id'] = $stmt->fetchColumn() !== false ? (int) $categoryId : null;
        }

        // A website is commonly typed without a scheme ("example.com") -
        // the others are profile links, always a full address by nature.
        $websiteUrl = trim((string) ($input['website_url'] ?? ''));
        if ($websiteUrl !== '' && !preg_match('#^https?://#i', $websiteUrl)) {
            $websiteUrl = 'https://' . $websiteUrl;
        }
        if ($websiteUrl !== '' && filter_var($websiteUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'card.edit.error.url';
            $websiteUrl = '';
        }
        $values['website_url'] = $websiteUrl !== '' ? mb_substr($websiteUrl, 0, 255) : null;

        foreach (['linkedin_url', 'instagram_url', 'facebook_url', 'youtube_url', 'snapchat_url'] as $field) {
            $url = trim((string) ($input[$field] ?? ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors[] = 'card.edit.error.url';
                $url = '';
            }
            $values[$field] = $url !== '' ? mb_substr($url, 0, 255) : null;
        }

        $design = (string) ($input['design'] ?? 'classic');
        $values['design'] = in_array($design, self::AVAILABLE_DESIGNS, true) ? $design : 'classic';

        $values['use_custom_colors'] = isset($input['use_custom_colors']);
        foreach (['color_background', 'color_header', 'color_content', 'color_footer'] as $field) {
            $color = trim((string) ($input[$field] ?? ''));
            if ($color !== '' && !self::isValidHexColor($color)) {
                $errors[] = 'card.edit.error.color';
                $color = '';
            }
            $values[$field] = $color !== '' ? $color : null;
        }

        $bookingUrl = trim((string) ($input['booking_url'] ?? ''));
        if ($bookingUrl !== '' && filter_var($bookingUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'card.edit.error.url';
            $bookingUrl = '';
        }
        $bookingMode = (string) ($input['booking_mode'] ?? '');
        $bookingMode = in_array($bookingMode, self::BOOKING_MODES, true) ? $bookingMode : null;
        if ($bookingMode === 'link' && $bookingUrl === '') {
            // An external link was chosen but left blank - same as no booking button at all.
            $bookingMode = null;
        }
        if ($bookingMode === 'request') {
            // The request form needs no external URL.
            $bookingUrl = '';
        }
        $values['booking_mode'] = $bookingMode;
        $values['booking_url'] = $bookingUrl !== '' ? mb_substr($bookingUrl, 0, 255) : null;

        return ['values' => $values, 'errors' => array_values(array_unique($errors))];
    }

    public function save(int $providerId, array $values): void
    {
        $existing = $this->find($providerId);
        $now = Clock::now();

        $columns = $values + ['updated_at' => $now];

        if ($existing === null) {
            $columns += ['provider_id' => $providerId, 'created_at' => $now];
            $stmt = $this->db->prepare(
                'INSERT INTO x_card_profile (' . implode(', ', array_keys($columns)) . ')
                 VALUES (:' . implode(', :', array_keys($columns)) . ')'
            );
        } else {
            $assignments = implode(', ', array_map(fn (string $c) => "{$c} = :{$c}", array_keys($columns)));
            $stmt = $this->db->prepare("UPDATE x_card_profile SET {$assignments} WHERE provider_id = :provider_id");
            $columns['provider_id'] = $providerId;
        }

        $stmt->execute($this->bindable($columns));
    }

    /**
     * The card's own company logo - separate from the account's personal
     * photo (core's Avatars). Same decode-and-re-encode-fresh pattern as
     * every other image upload in Modulento, cropped to a square like an
     * avatar (a logo sits in the same round/square slot on every design).
     *
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function setLogo(int $providerId, array $upload, string $uploadDir): ?string
    {
        if (!Gallery::available()) {
            return 'card.logo.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'card.logo.error.too_large'
                : 'card.logo.error.upload';
        }
        if (filesize($upload['tmp_name']) > Gallery::MAX_BYTES) {
            return 'card.logo.error.too_large';
        }

        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return 'card.logo.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            return 'card.logo.error.dimensions';
        }
        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'card.logo.error.type';
        }

        $edge = 512;
        $side = min(imagesx($source), imagesy($source));
        $target = imagecreatetruecolor($edge, $edge);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), $edge, $edge, $side, $side);

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return 'card.logo.error.storage';
        }
        $path = "{$uploadDir}/{$name}.{$extension}";
        $written = $extension === 'webp' ? imagewebp($target, $path, 82) : imagejpeg($target, $path, 85);
        imagedestroy($source);
        imagedestroy($target);
        if (!$written) {
            @unlink($path);

            return 'card.logo.error.storage';
        }

        $this->removeLogo($providerId, $uploadDir);
        $this->ensureRow($providerId);
        $stmt = $this->db->prepare('UPDATE x_card_profile SET logo_name = :name, logo_extension = :extension, updated_at = :now WHERE provider_id = :id');
        $stmt->execute(['name' => $name, 'extension' => $extension, 'now' => Clock::now(), 'id' => $providerId]);

        return null;
    }

    public function removeLogo(int $providerId, string $uploadDir): void
    {
        $profile = $this->find($providerId);
        if ($profile === null || $profile['logo_name'] === null) {
            return;
        }

        @unlink("{$uploadDir}/{$profile['logo_name']}.{$profile['logo_extension']}");
        $stmt = $this->db->prepare('UPDATE x_card_profile SET logo_name = NULL, logo_extension = NULL WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);
    }

    /** The stored logo file for a request, or null - only names this class created can match. */
    public function logoPath(string $uploadDir, string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }
        $path = $uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    public function incrementView(int $providerId): void
    {
        $this->ensureRow($providerId);
        $stmt = $this->db->prepare('UPDATE x_card_profile SET view_count = view_count + 1 WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);
    }

    public function incrementClick(int $providerId, string $type): void
    {
        $column = self::CLICK_COLUMNS[$type] ?? null;
        if ($column === null) {
            return;
        }

        $this->ensureRow($providerId);
        $stmt = $this->db->prepare("UPDATE x_card_profile SET {$column} = {$column} + 1 WHERE provider_id = :id");
        $stmt->execute(['id' => $providerId]);
    }

    public static function isValidHexColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * Joins street/postal_code/city/country (already structured on the
     * provider) into one display/search line, e.g. "Musterstraße 1, 1010
     * Wien, Österreich" - used on the card face, the Google Maps query and
     * the vCard.
     */
    public static function formatAddress(array $provider, callable $countryName): ?string
    {
        $street = trim((string) ($provider['street'] ?? ''));
        $postalCode = trim((string) ($provider['postal_code'] ?? ''));
        $city = trim((string) ($provider['city'] ?? ''));
        $country = $provider['country'] ?? '';
        $countryLabel = $country !== '' ? trim((string) $countryName($country)) : '';

        $cityLine = trim($postalCode . ' ' . $city);
        $parts = array_filter([$street, $cityLine, $countryLabel], static fn (string $p) => $p !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /** A profile row always exists once something needs to increment a counter on it. */
    private function ensureRow(int $providerId): void
    {
        if ($this->find($providerId) !== null) {
            return;
        }

        $now = Clock::now();
        $stmt = $this->db->prepare('INSERT IGNORE INTO x_card_profile (provider_id, created_at, updated_at) VALUES (:id, :now, :now2)');
        $stmt->execute(['id' => $providerId, 'now' => $now, 'now2' => $now]);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['provider_id'] = (int) $row['provider_id'];
        $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['use_custom_colors'] = (bool) $row['use_custom_colors'];
        foreach (array_keys(self::CLICK_COLUMNS) as $type) {
            $row[self::CLICK_COLUMNS[$type]] = (int) $row[self::CLICK_COLUMNS[$type]];
        }
        $row['view_count'] = (int) $row['view_count'];

        return $row;
    }

    private function bindable(array $columns): array
    {
        if (array_key_exists('use_custom_colors', $columns)) {
            $columns['use_custom_colors'] = $columns['use_custom_colors'] ? 1 : 0;
        }

        return $columns;
    }
}
