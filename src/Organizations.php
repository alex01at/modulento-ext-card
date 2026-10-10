<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Account\Tokens;
use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * A Firma (team) organisation: one owning provider, any number of member
 * providers, each with its own full card. Branding lives only here - see
 * applyBranding(), the single place it is resolved onto a member's card,
 * always live, never copied into x_card_profile.
 */
final class Organizations
{
    public const MAX_COMPANY_LENGTH = 150;
    /** A sane upper bound against runaway invites, not a billing limit - that lives in the plan's min_quantity/Stripe. */
    public const MAX_MEMBERS = 200;

    /**
     * Curated, legible palettes - never a free hex choice (that would risk
     * illegible button/fab text, which is always a fixed light colour in
     * every design, see card.css). Footer/accent-2 is left out on purpose:
     * applyBranding() falls back to the header colour for it, one fewer
     * value to keep in step if a preset's look is ever refined.
     */
    public const COLOR_PRESETS = [
        'graphite' => ['background' => '#f5f6fa', 'content' => '#1f2430', 'header' => '#334155'],
        'bordeaux' => ['background' => '#fdf2f4', 'content' => '#1f2430', 'header' => '#7a1f3d'],
        'forest' => ['background' => '#f3f8f4', 'content' => '#1f2430', 'header' => '#1f5c3f'],
    ];

    public function __construct(private PDO $db, private App $app)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_organization WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $this->cast($row) : null;
    }

    public function ofOwner(int $ownerProviderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_organization WHERE owner_provider_id = :id');
        $stmt->execute(['id' => $ownerProviderId]);
        $row = $stmt->fetch();

        return $row !== false ? $this->cast($row) : null;
    }

    /** The membership row for a provider (owner or employee), or null outside any organisation. */
    public function memberOf(int $providerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_organization_member WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);
        $row = $stmt->fetch();

        return $row !== false ? [
            'id' => (int) $row['id'],
            'organization_id' => (int) $row['organization_id'],
            'provider_id' => (int) $row['provider_id'],
            'role' => $row['role'],
            'workplace' => $row['workplace'],
            'joined_at' => $row['joined_at'],
        ] : null;
    }

    /**
     * What a card renders with: the membership (for role/workplace) and the
     * organisation's current branding in one call - the shape every render
     * site (CardController, AccountCardController) actually wants.
     */
    public function forProvider(int $providerId): ?array
    {
        $membership = $this->memberOf($providerId);

        return $membership !== null ? $membership + ['organization' => $this->find($membership['organization_id'])] : null;
    }

    /** @return list<array<string, mixed>> every member, owner first, then by join date */
    public function members(int $organizationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.provider_id, m.role, m.workplace, m.joined_at, p.name, p.slug, a.email
             FROM x_card_organization_member m
             JOIN provider p ON p.id = m.provider_id
             JOIN account a ON a.id = p.account_id
             WHERE m.organization_id = :id
             ORDER BY (m.role = 'owner') DESC, m.joined_at"
        );
        $stmt->execute(['id' => $organizationId]);

        return array_map(fn (array $row) => [
            'provider_id' => (int) $row['provider_id'],
            'role' => $row['role'],
            'workplace' => $row['workplace'],
            'joined_at' => $row['joined_at'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'email' => $row['email'],
        ], $stmt->fetchAll());
    }

    /**
     * Founds an organisation for an account that already has its own
     * provider/card - that card becomes the first member, as the owner.
     *
     * @return int|string the new organisation's id, or a language key of the problem
     */
    public function create(int $ownerProviderId, string $company): int|string
    {
        $company = trim($company);
        if ($company === '' || mb_strlen($company) > self::MAX_COMPANY_LENGTH) {
            return 'card.team.error.company';
        }
        if ($this->ofOwner($ownerProviderId) !== null || $this->memberOf($ownerProviderId) !== null) {
            return 'card.team.error.already_in_one';
        }

        $now = Clock::now();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO x_card_organization (owner_provider_id, company, design, created_at, updated_at) VALUES (:owner, :company, :design, :now, :now2)');
            $stmt->execute(['owner' => $ownerProviderId, 'company' => $company, 'design' => Cards::AVAILABLE_DESIGNS[0], 'now' => $now, 'now2' => $now]);
            $id = (int) $this->db->lastInsertId();

            $this->db->prepare("INSERT INTO x_card_organization_member (organization_id, provider_id, role, joined_at) VALUES (:org, :provider, 'owner', :now)")
                ->execute(['org' => $id, 'provider' => $ownerProviderId, 'now' => $now]);
            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Invites a new employee by e-mail: a fresh account (no password of its
     * own - the mail carries a set-password link, exactly the pattern
     * AdminAccountController::create() already uses for the same reason),
     * a minimal provider row approved right away (the owner already vouches
     * for it - no separate moderation queue), and the membership row.
     * Afterwards the organisation's billed seat count is synced to Stripe.
     *
     * @return string|null a language key of the problem, null on success
     */
    public function invite(int $organizationId, string $email, string $locale): ?string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'card.team.error.email';
        }
        if (count($this->members($organizationId)) >= self::MAX_MEMBERS) {
            return 'card.team.error.full';
        }

        $app = $this->app;
        if ($app->accounts->findByEmail($email) !== null) {
            return 'card.team.error.taken';
        }

        $org = $this->find($organizationId);
        if ($org === null) {
            return 'card.team.error.unknown';
        }

        $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
        $accountId = $app->accounts->create($email, bin2hex(random_bytes(24)), $locale, true);

        $texts = array_fill_keys($app->locales->enabled(), ['headline' => '', 'description' => '']);
        $saved = $app->providers->save($accountId, [
            'type' => 'private', 'name' => $name, 'legal_name' => $name,
            'street' => '', 'postal_code' => '', 'city' => '', 'country' => 'DE',
            'contact_email' => '', 'phone' => '', 'vat_id' => '', 'tax_id' => '', 'company_register' => '',
            'self_certified' => false,
        ], $texts);
        $app->providers->setStatus($saved['id'], 'approved', null, null);

        $now = Clock::now();
        $this->db->prepare("INSERT INTO x_card_organization_member (organization_id, provider_id, role, joined_at) VALUES (:org, :provider, 'employee', :now)")
            ->execute(['org' => $organizationId, 'provider' => $saved['id'], 'now' => $now]);

        $token = $app->tokens->create($accountId, Tokens::RESET_PASSWORD, 7 * 86400);
        $app->mailer->send($email, '@card/emails/team_invite.txt.twig', [
            'company' => $org['company'],
            'link' => $app->url('/reset-password/' . $token, $locale, true),
        ], $locale);

        $this->syncSeats($organizationId);

        return null;
    }

    /** A member's own optional "where I work" line - the one personal field a member's own card editor still offers. */
    public function setWorkplace(int $providerId, ?string $workplace): void
    {
        $this->db->prepare('UPDATE x_card_organization_member SET workplace = :workplace WHERE provider_id = :provider')
            ->execute(['workplace' => $workplace, 'provider' => $providerId]);
    }

    /** Removes a member; its card stays as a normal, now organisation-less card (falls back to Free). */
    public function remove(int $organizationId, int $providerId): void
    {
        $this->db->prepare("DELETE FROM x_card_organization_member WHERE organization_id = :org AND provider_id = :provider AND role = 'employee'")
            ->execute(['org' => $organizationId, 'provider' => $providerId]);

        $this->syncSeats($organizationId);
    }

    /** @param array{company?: string, design?: string, color_preset?: string|null} $values */
    public function saveBranding(int $organizationId, array $values): void
    {
        $company = trim((string) ($values['company'] ?? ''));
        if ($company === '' || mb_strlen($company) > self::MAX_COMPANY_LENGTH) {
            return;
        }
        $design = in_array($values['design'] ?? null, Cards::AVAILABLE_DESIGNS, true) ? $values['design'] : Cards::AVAILABLE_DESIGNS[0];
        $preset = array_key_exists($values['color_preset'] ?? null, self::COLOR_PRESETS) ? $values['color_preset'] : null;

        $this->db->prepare('UPDATE x_card_organization SET company = :company, design = :design, color_preset = :preset, updated_at = :now WHERE id = :id')
            ->execute(['company' => $company, 'design' => $design, 'preset' => $preset, 'now' => Clock::now(), 'id' => $organizationId]);
    }

    /**
     * The organisation's own logo - same decode/re-encode/square-crop
     * pattern as Cards::setLogo(), and deliberately the very same upload
     * directory and /media/card-logo/{file} route (random 32-hex names
     * make a collision practically impossible) - one logo-serving route is
     * enough, an organisation's logo is just another file in it.
     *
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function setLogo(int $organizationId, array $upload, string $uploadDir): ?string
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

        $this->removeLogo($organizationId, $uploadDir);
        $stmt = $this->db->prepare('UPDATE x_card_organization SET logo_name = :name, logo_extension = :extension, updated_at = :now WHERE id = :id');
        $stmt->execute(['name' => $name, 'extension' => $extension, 'now' => Clock::now(), 'id' => $organizationId]);

        return null;
    }

    public function removeLogo(int $organizationId, string $uploadDir): void
    {
        $org = $this->find($organizationId);
        if ($org === null || $org['logo_name'] === null) {
            return;
        }

        @unlink("{$uploadDir}/{$org['logo_name']}.{$org['logo_extension']}");
        $this->db->prepare('UPDATE x_card_organization SET logo_name = NULL, logo_extension = NULL WHERE id = :id')->execute(['id' => $organizationId]);
    }

    /** The stored org logo file for a request, or null - only names this class created can match. */
    public function logoPath(string $uploadDir, string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }
        $path = $uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Whether the account effectively has a Pro-tier card feature: its own
     * subscription, or - being a member of an organisation - the owner's.
     * The single choke-point every Pro-only card feature check goes
     * through, so a Firma seat carries full Pro access, not just branding,
     * for every member - founding/owning a team itself is the one
     * exception, deliberately checked against the account's own
     * subscription directly (see TeamController), since a member cannot
     * found a nested team by inheriting the owner's plan.
     */
    public function effectiveAllows(int $accountId, int $providerId, string $feature): bool
    {
        if ($this->app->subscriptions->allows($accountId, $feature)) {
            return true;
        }

        $membership = $this->memberOf($providerId);
        $organization = $membership !== null ? $this->find($membership['organization_id']) : null;
        if ($organization === null) {
            return false;
        }

        $ownerAccountId = (int) $this->db->query('SELECT account_id FROM provider WHERE id = ' . (int) $organization['owner_provider_id'])->fetchColumn();

        return $ownerAccountId !== 0 && $this->app->subscriptions->allows($ownerAccountId, $feature);
    }

    /**
     * The one place an organisation's branding is laid over a member's own
     * card - called fresh on every render, never written back to
     * x_card_profile. Without an organisation, $card comes back untouched.
     */
    public function applyBranding(array $card, ?array $org): array
    {
        if ($org === null) {
            return $card;
        }

        $colors = $org['color_preset'] !== null ? self::COLOR_PRESETS[$org['color_preset']] : null;

        return array_merge($card, [
            'company' => $org['company'],
            'logo_name' => $org['logo_name'],
            'logo_extension' => $org['logo_extension'],
            'design' => $org['design'],
            'use_custom_colors' => $colors !== null,
            'color_background' => $colors['background'] ?? null,
            'color_header' => $colors['header'] ?? null,
            'color_content' => $colors['content'] ?? null,
            'color_footer' => $colors['header'] ?? null,
        ]);
    }

    /** Pushes the organisation's current member count to Subscriptions as the billed seat count - a no-op below the plan's own floor or without a per-seat plan. */
    private function syncSeats(int $organizationId): void
    {
        $org = $this->find($organizationId);
        if ($org === null) {
            return;
        }
        $ownerAccountId = (int) $this->db->query('SELECT account_id FROM provider WHERE id = ' . (int) $org['owner_provider_id'])->fetchColumn();
        if ($ownerAccountId === 0) {
            return;
        }

        try {
            $this->app->subscriptionBilling->setQuantity($ownerAccountId, count($this->members($organizationId)));
        } catch (\InvalidArgumentException) {
            // No active subscription (e.g. a trial not yet started, or the
            // plan was cancelled) - nothing to sync, the next real one starts fresh.
        }
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['owner_provider_id'] = (int) $row['owner_provider_id'];

        return $row;
    }
}
