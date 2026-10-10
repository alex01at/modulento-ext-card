<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * A plain display list on the card - services or products with a title, an
 * optional description and an optional free-text price. Never orderable:
 * there is no cart, no checkout, nothing linking this to the core's
 * catalogue at all.
 */
final class Offerings
{
    public const MAX_OFFERINGS = 10;

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array> ordered by position, then id */
    public function ofProvider(int $providerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_offering WHERE provider_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $providerId]);

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_card_offering WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return string|null language key of the problem, null on success */
    public function add(int $providerId, string $title, string $description, string $price): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return 'card.offering.error.title';
        }
        if (mb_strlen($title) > 150) {
            return 'card.offering.error.title';
        }
        $description = trim($description);
        if (mb_strlen($description) > 500) {
            return 'card.offering.error.description';
        }
        $price = trim($price);
        if (mb_strlen($price) > 50) {
            return 'card.offering.error.price';
        }
        if (count($this->ofProvider($providerId)) >= self::MAX_OFFERINGS) {
            return 'card.offering.error.too_many';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_card_offering (provider_id, title, description, price, position, created_at)
             VALUES (:provider, :title, :description, :price, :position, :now)'
        );
        $stmt->execute([
            'provider' => $providerId,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'price' => $price !== '' ? $price : null,
            'position' => count($this->ofProvider($providerId)),
            'now' => Clock::now(),
        ]);

        return null;
    }

    public function delete(int $providerId, int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM x_card_offering WHERE id = :id AND provider_id = :provider');
        $stmt->execute(['id' => $id, 'provider' => $providerId]);
    }
}
