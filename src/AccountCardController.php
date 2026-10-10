<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/**
 * The owner's own editor: everything about the card that is not already
 * the provider's own name/address/contact/headline/description (edited at
 * /account/provider, untouched by this extension).
 */
final class AccountCardController extends Controller
{
    public function edit(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $this->renderForm($provider, (new Cards($this->app->db))->findOrDefault((int) $provider['id']), []);
    }

    public function save(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $cards = new Cards($this->app->db);
        $result = $cards->validate($_POST);

        if (!$this->features((int) $provider['account_id'])['design']) {
            $result['values']['design'] = 'classic';
        }
        if (!$this->features((int) $provider['account_id'])['colors']) {
            $result['values']['use_custom_colors'] = false;
        }
        if (!$this->features((int) $provider['account_id'])['booking']) {
            $result['values']['booking_mode'] = null;
            $result['values']['booking_url'] = null;
        }

        if ($result['errors'] !== []) {
            $typed = $result['values'] + (new Cards($this->app->db))->findOrDefault((int) $provider['id']);
            $this->renderForm($provider, $typed, array_map(fn (string $k) => $this->trans($k), $result['errors']));
            return;
        }

        $cards->save((int) $provider['id'], $result['values']);
        Session::flash('success', $this->trans('card.edit.saved'));
        $this->redirect('/account/card');
    }

    public function uploadLogo(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $error = (new Cards($this->app->db))->setLogo((int) $provider['id'], $_FILES['logo'] ?? [], $this->uploadDir());
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'card.logo.uploaded'));
        $this->redirect('/account/card');
    }

    public function deleteLogo(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        (new Cards($this->app->db))->removeLogo((int) $provider['id'], $this->uploadDir());
        Session::flash('success', $this->trans('card.logo.removed'));
        $this->redirect('/account/card');
    }

    public function addGalleryImage(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }
        if (!$this->features((int) $provider['account_id'])['gallery']) {
            Session::flash('error', $this->trans('card.feature.locked'));
            $this->redirect('/account/card');
            return;
        }

        $gallery = new Gallery($this->app->db, $this->uploadDir('card-gallery'));
        $error = $gallery->add((int) $provider['id'], $_FILES['image'] ?? []);
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'card.gallery.added'));
        $this->redirect($this->safeReturn('/account/card'));
    }

    public function deleteGalleryImage(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        (new Gallery($this->app->db, $this->uploadDir('card-gallery')))->delete((int) $provider['id'], (int) $params['id']);
        Session::flash('success', $this->trans('card.gallery.removed'));
        $this->redirect($this->safeReturn('/account/card'));
    }

    public function addOffering(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }
        if (!$this->features((int) $provider['account_id'])['offerings']) {
            Session::flash('error', $this->trans('card.feature.locked'));
            $this->redirect('/account/card');
            return;
        }

        $error = (new Offerings($this->app->db))->add(
            (int) $provider['id'],
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (string) ($_POST['price'] ?? '')
        );
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'card.offering.added'));
        $this->redirect($this->safeReturn('/account/card'));
    }

    public function deleteOffering(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        (new Offerings($this->app->db))->delete((int) $provider['id'], (int) $params['id']);
        Session::flash('success', $this->trans('card.offering.removed'));
        $this->redirect($this->safeReturn('/account/card'));
    }

    public function stats(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }
        if (!$this->features((int) $provider['account_id'])['stats']) {
            $this->render('@card/account/stats_locked.twig', []);
            return;
        }

        $card = (new Cards($this->app->db))->findOrDefault((int) $provider['id']);
        $events = new CardEvents($this->app->db);
        $trendDays = 30;
        $trend = $events->dailyCounts((int) $provider['id'], CardEvents::TYPE_VIEW, $trendDays);
        $maxDaily = max(1, max(array_column($trend, 'count')));
        $trend = array_map(fn (array $day) => $day + ['percent' => max(3, (int) round($day['count'] / $maxDaily * 100))], $trend);

        $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');

        $this->render('@card/account/stats.twig', [
            'provider' => $provider,
            'card' => $card,
            'trend' => $trend,
            'views_since_yesterday' => $events->countSince((int) $provider['id'], CardEvents::TYPE_VIEW, $yesterday),
            'busiest_weekday' => $events->busiestWeekday((int) $provider['id'], CardEvents::TYPE_VIEW, $trendDays),
            'click_columns' => Cards::CLICK_COLUMNS,
        ]);
    }

    /** @var array<int, array<string, bool>> */
    private array $featuresCache = [];

    /**
     * Every Pro feature key, resolved once per controller instance - an
     * instance property, deliberately not a function-static: a `static`
     * local variable is shared by every call to this method for the whole
     * PHP process, which under PHP-FPM (worker processes reused across
     * many requests) would keep answering with whichever account's plan
     * happened to be checked first, forever, for every other account that
     * worker later serves.
     *
     * @return array<string, bool>
     */
    private function features(int $accountId): array
    {
        if (isset($this->featuresCache[$accountId])) {
            return $this->featuresCache[$accountId];
        }

        $subscriptions = $this->app->subscriptions;

        return $this->featuresCache[$accountId] = [
            'design' => $subscriptions->allows($accountId, 'card.feature.design'),
            'colors' => $subscriptions->allows($accountId, 'card.feature.colors'),
            'gallery' => $subscriptions->allows($accountId, 'card.feature.gallery'),
            'offerings' => $subscriptions->allows($accountId, 'card.feature.offerings'),
            'booking' => $subscriptions->allows($accountId, 'card.feature.booking'),
            'vcard' => $subscriptions->allows($accountId, 'card.feature.vcard'),
            'stats' => $subscriptions->allows($accountId, 'card.feature.stats'),
        ];
    }

    private function renderForm(array $provider, array $card, array $errors): void
    {
        $gallery = new Gallery($this->app->db, $this->uploadDir('card-gallery'));
        $this->render('@card/account/edit.twig', [
            'provider' => $provider,
            'card' => $card,
            'errors' => $errors,
            'features' => $this->features((int) $provider['account_id']),
            'categories' => $this->app->categories->all(),
            'gallery_images' => array_map(fn (array $i) => Gallery::urls($i), $gallery->ofProvider((int) $provider['id'])),
            'gallery_max' => Gallery::MAX_IMAGES,
            'offerings' => (new Offerings($this->app->db))->ofProvider((int) $provider['id']),
            'offerings_max' => Offerings::MAX_OFFERINGS,
        ]);
    }

    /** The logged-in account's own provider profile; a card needs one to belong to. */
    private function provider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            Session::flash('error', $this->trans('card.error.needs_provider'));
            $this->redirect('/account/provider');
        }

        return $provider;
    }

    private function uploadDir(string $sub = 'card-logo'): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/' . $sub;
    }
}
