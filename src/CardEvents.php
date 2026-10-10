<?php

declare(strict_types=1);

namespace Modulento\Card;

use DateTimeImmutable;
use DateTimeZone;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * A time-series log of page views and tracked link clicks, next to the
 * cheap running totals kept as plain columns on x_card_profile. Only this
 * log is read for the Pro trend chart, which needs day-by-day numbers;
 * everything else (the stat tiles) reads the fast columns instead.
 */
final class CardEvents
{
    public const TYPE_VIEW = 'view';

    public function __construct(private PDO $db)
    {
    }

    public function record(int $providerId, string $type): void
    {
        $stmt = $this->db->prepare('INSERT INTO x_card_event (provider_id, type, created_at) VALUES (:provider, :type, :now)');
        $stmt->execute(['provider' => $providerId, 'type' => $type, 'now' => Clock::now()]);
    }

    /**
     * Zero-filled counts for each of the trailing $days days, oldest first -
     * SQL only returns a row for a day that actually had an event, so the
     * gaps are filled in here.
     *
     * @return list<array{date: string, count: int}>
     */
    public function dailyCounts(int $providerId, string $type, int $days): array
    {
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);
        $since = $today->modify('-' . ($days - 1) . ' days');

        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS n FROM x_card_event
             WHERE provider_id = :provider AND type = :type AND created_at >= :since
             GROUP BY DATE(created_at)"
        );
        $stmt->execute(['provider' => $providerId, 'type' => $type, 'since' => $since->format('Y-m-d 00:00:00')]);
        $byDay = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days")->format('Y-m-d');
            $result[] = ['date' => $date, 'count' => (int) ($byDay[$date] ?? 0)];
        }

        return $result;
    }

    public function countSince(int $providerId, string $type, string $sinceDateTime): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM x_card_event WHERE provider_id = :provider AND type = :type AND created_at >= :since'
        );
        $stmt->execute(['provider' => $providerId, 'type' => $type, 'since' => $sinceDateTime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * ISO weekday (1 Monday .. 7 Sunday) with the most events of this type
     * in the trailing $days days, or null without any. Grouped in PHP
     * rather than with a WEEKDAY()-style SQL function - MySQL and SQLite
     * (the test suite) don't share one, and the row count here is always
     * small (one provider's events over a month).
     */
    public function busiestWeekday(int $providerId, string $type, int $days): ?int
    {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT created_at FROM x_card_event WHERE provider_id = :provider AND type = :type AND created_at >= :since'
        );
        $stmt->execute(['provider' => $providerId, 'type' => $type, 'since' => $since]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $createdAt) {
            $weekday = (int) (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format('N');
            $counts[$weekday] = ($counts[$weekday] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return array_key_first($counts);
    }
}
