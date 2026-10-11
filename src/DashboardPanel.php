<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\App;

/**
 * The card's own panel on the account dashboard (/account) - status,
 * quick actions, a short statistics summary. Nothing for an account with
 * no provider profile at all, the same "empty array, nothing shown"
 * convention every section hook (home, provider) already uses.
 */
final class DashboardPanel
{
    /** @return array<string, mixed> */
    public static function data(array $account, App $app): array
    {
        $provider = $app->providers->findByAccount((int) $account['id']);
        if ($provider === null) {
            return [];
        }

        $providerId = (int) $provider['id'];
        $organizations = new Organizations($app->db, $app);
        $membership = $organizations->forProvider($providerId);

        $plan = match (true) {
            $membership !== null && $membership['role'] === 'owner' => ['key' => 'card.dashboard.plan_owner', 'params' => []],
            $membership !== null => ['key' => 'card.dashboard.plan_member', 'params' => ['company' => $membership['organization']['company']]],
            $app->subscriptions->allows((int) $account['id'], 'card.feature.design') => ['key' => 'card.dashboard.plan_pro', 'params' => []],
            default => ['key' => 'card.dashboard.plan_free', 'params' => []],
        };

        $stats = null;
        if ($organizations->effectiveAllows((int) $account['id'], $providerId, 'card.feature.stats')) {
            $card = (new Cards($app->db))->findOrDefault($providerId);
            $events = new CardEvents($app->db);
            $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');
            $stats = [
                'views' => $card['view_count'],
                'since_yesterday' => $events->countSince($providerId, CardEvents::TYPE_VIEW, $yesterday),
                'busiest_weekday' => $events->busiestWeekday($providerId, CardEvents::TYPE_VIEW, 30),
            ];
        }

        return [
            'published' => $provider['status'] === 'approved',
            'card_path' => '/card/' . $provider['slug'],
            'plan_key' => $plan['key'],
            'plan_params' => $plan['params'],
            'stats' => $stats,
        ];
    }
}
