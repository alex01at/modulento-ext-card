<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Card\Qr\QrEncoder;
use Modulento\Card\Qr\QrRenderer;
use Modulento\Core\App;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Provider\Providers;
use Modulento\Core\Support\Session;

/**
 * The public side of a card: the full-page design at /card/{slug}, its QR
 * code, the vCard download, the tracked /go/{slug}/{type} redirect every
 * clickable link on the card goes through, and the booking request form
 * (booking_mode "request" only - "link" just points straight out through
 * the same /go/ redirect).
 */
final class CardController extends Controller
{
    public function show(array $params): void
    {
        $app = $this->app;
        $provider = $app->providers->findPublicBySlug($params['slug']);
        if ($provider === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $cards = new Cards($app->db);
        $card = $cards->findOrDefault((int) $provider['id']);
        $cards->incrementView((int) $provider['id']);
        (new CardEvents($app->db))->record((int) $provider['id'], CardEvents::TYPE_VIEW);

        $locale = $app->translator->locale();
        $design = in_array($card['design'], Cards::AVAILABLE_DESIGNS, true) ? $card['design'] : 'classic';

        $this->render('@card/designs/' . $design . '.twig', $this->cardView($provider, $card, $locale));
    }

    public function qrPng(array $params): void
    {
        $provider = $this->app->providers->findPublicBySlug($params['slug']);
        if ($provider === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $this->renderQr($provider, 'png');
    }

    public function qrSvg(array $params): void
    {
        $provider = $this->app->providers->findPublicBySlug($params['slug']);
        if ($provider === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $this->renderQr($provider, 'svg');
    }

    /** The logged-in owner's own QR, even while the card is not yet approved/public. */
    public function ownQrPng(array $params): void
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $this->renderQr($provider, 'png');
    }

    public function ownQrSvg(array $params): void
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $this->renderQr($provider, 'svg');
    }

    /**
     * Every clickable contact method on the card points here instead of a
     * direct tel:/mailto:/url - a plain server-side redirect that counts
     * the click first, no client-side beacon needed.
     */
    public function trackClick(array $params): void
    {
        $app = $this->app;
        $provider = $app->providers->findPublicBySlug($params['slug']);
        $card = $provider !== null ? (new Cards($app->db))->findOrDefault((int) $provider['id']) : null;
        $target = $provider !== null && $card !== null ? $this->linkTarget($provider, $card, $params['type']) : null;

        if ($target === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        (new Cards($app->db))->incrementClick((int) $provider['id'], $params['type']);
        (new CardEvents($app->db))->record((int) $provider['id'], $params['type']);
        header('Location: ' . $target);
    }

    public function vcard(array $params): void
    {
        $app = $this->app;
        $provider = $app->providers->findPublicBySlug($params['slug']);
        $card = $provider !== null ? (new Cards($app->db))->findOrDefault((int) $provider['id']) : null;

        if ($provider === null || $card === null || !$app->subscriptions->allows((int) $provider['account_id'], 'card.feature.vcard')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $logoUrl = $this->avatarUrl($app, $provider, $card);
        $vcard = $this->buildVCard($provider, $card, $logoUrl !== null ? $app->url($logoUrl, null, true) : null);

        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $provider['slug'] . '.vcf"');
        header('Content-Length: ' . strlen($vcard));
        echo $vcard;
    }

    public function bookingShow(array $params): void
    {
        $app = $this->app;
        $provider = $this->eligibleForBooking($params['slug']);
        if ($provider === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        // An anti-bot timing trap: a real visitor needs at least a few
        // seconds between loading the form and submitting it.
        Session::set('_card_booking_rendered_at', time());
        $this->render('@card/booking_request.twig', ['provider' => $provider]);
    }

    public function bookingSubmit(array $params): void
    {
        $app = $this->app;
        $provider = $this->eligibleForBooking($params['slug']);
        if ($provider === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $renderedAt = (int) Session::get('_card_booking_rendered_at', 0);
        $isBot = trim((string) ($_POST['website'] ?? '')) !== ''
            || ($_POST['js_ok'] ?? '') !== '1'
            || $renderedAt === 0 || time() - $renderedAt < 3;

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $preferredTime = trim((string) ($_POST['preferred_time'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'card.booking.error.name';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'card.booking.error.email';
        }
        if ($preferredTime === '') {
            $errors[] = 'card.booking.error.preferred_time';
        }

        if (!$isBot && $errors === []) {
            $to = $provider['contact_email'] ?: $provider['account_email'];
            $app->mailer->send($to, '@card/emails/booking_request.txt.twig', [
                'provider_name' => $provider['name'],
                'name' => $name,
                'email' => $email,
                'preferred_time' => $preferredTime,
                'message' => $message,
            ], $app->translator->locale());
        }

        // The same success message whether this was real or caught by a
        // bot trap - no tell given back to whoever (or whatever) is probing.
        Session::flash('success', $this->trans('card.booking.sent'));
        $this->redirect('/card/' . $provider['slug']);
    }

    /** @return array<string, mixed> everything a design template needs */
    private function cardView(array $provider, array $card, string $locale): array
    {
        $app = $this->app;
        $isPro = $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.design');
        $text = Providers::text($provider, $locale, $app->locales->default()) ?? ['headline' => '', 'description' => ''];

        $categoryName = null;
        if ($card['category_id'] !== null) {
            $category = $app->categories->find((int) $card['category_id']);
            $categoryName = $category !== null ? ($category['translations'][$locale]['name'] ?? $category['translations'][$app->locales->default()]['name'] ?? null) : null;
        }

        $gallery = ($isPro && $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.gallery'))
            ? array_map(fn (array $i) => Gallery::urls($i), (new Gallery($app->db, $this->galleryDir()))->ofProvider((int) $provider['id']))
            : [];
        $offerings = ($isPro && $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.offerings'))
            ? (new Offerings($app->db))->ofProvider((int) $provider['id'])
            : [];

        $avatarUrl = $this->avatarUrl($app, $provider, $card);
        $address = Cards::formatAddress($provider, fn (string $code) => $this->trans('core.country.' . $code));

        return [
            'provider' => $provider,
            'card' => $card,
            'headline' => $text['headline'],
            'description' => $text['description'],
            'is_pro' => $isPro,
            'can_vcard' => $isPro && $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.vcard'),
            'can_booking' => $isPro && $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.booking') && $card['booking_mode'] !== null,
            'avatar_url' => $avatarUrl,
            'category_name' => $categoryName,
            'address' => $address,
            'gallery_images' => $gallery,
            'offerings' => $offerings,
            'meta_description' => $text['headline'] !== '' ? $text['headline'] : $text['description'],
            'og_image_url' => $avatarUrl !== null ? $app->url($avatarUrl, null, true) : null,
            'structured_data_json' => $this->structuredData($provider, $card, $text, $address, $avatarUrl),
        ];
    }

    /**
     * schema.org Person markup for the card. JSON_UNESCAPED_UNICODE only -
     * deliberately NOT JSON_UNESCAPED_SLASHES, since this is echoed with
     * |raw inside a <script> tag: an unescaped "</" in a text field could
     * close the script element early and inject markup.
     */
    private function structuredData(array $provider, array $card, array $text, ?string $address, ?string $avatarUrl): string
    {
        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $provider['name'],
            'jobTitle' => $text['headline'] !== '' ? $text['headline'] : null,
            'worksFor' => $card['company'] !== null ? ['@type' => 'Organization', 'name' => $card['company']] : null,
            'email' => $provider['contact_email'],
            'telephone' => $provider['phone'],
            'address' => $address,
            'image' => $avatarUrl !== null ? $this->app->url($avatarUrl, null, true) : null,
            'url' => $this->app->url('/card/' . $provider['slug'], null, true),
        ], fn ($value) => $value !== null);

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function avatarUrl(App $app, array $provider, array $card): ?string
    {
        if ($card['logo_name'] !== null) {
            return '/media/card-logo/' . $card['logo_name'] . '.' . $card['logo_extension'];
        }

        return $app->avatars->url((int) $provider['account_id']);
    }

    private function renderQr(array $provider, string $format): void
    {
        $url = $this->app->url('/card/' . $provider['slug'], null, true);
        $matrix = QrEncoder::encode($url);

        if ($format === 'svg') {
            header('Content-Type: image/svg+xml');
            $body = QrRenderer::toSvg($matrix);
        } else {
            header('Content-Type: image/png');
            $body = QrRenderer::toPng($matrix);
        }
        if (isset($_GET['download'])) {
            header('Content-Disposition: attachment; filename="' . $provider['slug'] . '-qrcode.' . $format . '"');
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function linkTarget(array $provider, array $card, string $type): ?string
    {
        $app = $this->app;

        return match ($type) {
            'phone' => $provider['phone'] !== null && $provider['phone'] !== '' ? 'tel:' . $provider['phone'] : null,
            'whatsapp' => $card['whatsapp'] !== null && $card['whatsapp'] !== '' ? 'https://wa.me/' . preg_replace('/\D/', '', $card['whatsapp']) : null,
            'email' => $provider['contact_email'] !== null && $provider['contact_email'] !== '' ? 'mailto:' . $provider['contact_email'] : null,
            'website' => $card['website_url'],
            'address' => ($addr = Cards::formatAddress($provider, fn (string $code) => $this->trans('core.country.' . $code))) !== null
                ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr)
                : null,
            'linkedin' => $card['linkedin_url'],
            'instagram' => $card['instagram_url'],
            'facebook' => $card['facebook_url'],
            'youtube' => $card['youtube_url'],
            'snapchat' => $card['snapchat_url'],
            'booking' => $app->subscriptions->allows((int) $provider['account_id'], 'card.feature.booking') ? $card['booking_url'] : null,
            default => null,
        };
    }

    private function buildVCard(array $provider, array $card, ?string $logoUrl): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $name = $this->escapeVCardValue($provider['name']);
        $lines[] = 'FN:' . $name;
        $lines[] = 'N:' . $name . ';;;;';

        $text = Providers::text($provider, $this->app->translator->locale(), $this->app->locales->default());
        if (($text['headline'] ?? '') !== '') {
            $lines[] = 'TITLE:' . $this->escapeVCardValue($text['headline']);
        }
        if (($card['company'] ?? null) !== null) {
            $lines[] = 'ORG:' . $this->escapeVCardValue($card['company']);
        }
        if (($provider['phone'] ?? null) !== null) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:' . $this->escapeVCardValue($provider['phone']);
        }
        if (($provider['contact_email'] ?? null) !== null) {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $this->escapeVCardValue($provider['contact_email']);
        }
        $address = Cards::formatAddress($provider, fn (string $code) => $this->trans('core.country.' . $code));
        if ($address !== null) {
            $lines[] = 'ADR;TYPE=WORK:;;' . $this->escapeVCardValue($provider['street'] ?? '') . ';'
                . $this->escapeVCardValue($provider['city'] ?? '') . ';;'
                . $this->escapeVCardValue($provider['postal_code'] ?? '') . ';'
                . $this->escapeVCardValue($this->trans('core.country.' . ($provider['country'] ?? '')));
        }
        if (($text['description'] ?? '') !== '') {
            $lines[] = 'NOTE:' . $this->escapeVCardValue($text['description']);
        }
        if ($logoUrl !== null) {
            $lines[] = 'PHOTO;VALUE=uri:' . $logoUrl;
        }
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escapeVCardValue(string $value): string
    {
        $value = str_replace(['\\', ',', ';'], ['\\\\', '\\,', '\\;'], $value);

        return str_replace(["\r\n", "\n", "\r"], '\n', $value);
    }

    private function eligibleForBooking(string $slug): ?array
    {
        $provider = $this->app->providers->findPublicBySlug($slug);
        if ($provider === null) {
            return null;
        }

        $card = (new Cards($this->app->db))->findOrDefault((int) $provider['id']);
        $allowed = $this->app->subscriptions->allows((int) $provider['account_id'], 'card.feature.booking');

        return $allowed && $card['booking_mode'] === 'request' ? $provider : null;
    }

    private function galleryDir(): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/card-gallery';
    }
}
