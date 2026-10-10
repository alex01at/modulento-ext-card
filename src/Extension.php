<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Turns an approved provider's profile into a shareable digital business
 * card: a full-page design at /card/{slug} (the provider's own slug, no
 * new one of its own), a QR code, a vCard download, tracked contact links,
 * a photo gallery, a plain display list of offerings, and a booking
 * button. Deliberately not an offer type - there is nothing to order here,
 * this only ever adds to a provider's own profile.
 *
 * Most of it is Pro-only, gated through the core's own Subscriptions
 * module (subscriptionFeature() below) rather than a module or a plan of
 * this extension's own - an operator builds a "Pro" plan under
 * Administration → Subscriptions and ticks the card.feature.* boxes,
 * exactly like any other extension's subscription feature.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->subscriptionFeature('card.feature.design', 'card.subscription.design');
        $registrar->subscriptionFeature('card.feature.colors', 'card.subscription.colors');
        $registrar->subscriptionFeature('card.feature.gallery', 'card.subscription.gallery');
        $registrar->subscriptionFeature('card.feature.offerings', 'card.subscription.offerings');
        $registrar->subscriptionFeature('card.feature.booking', 'card.subscription.booking');
        $registrar->subscriptionFeature('card.feature.vcard', 'card.subscription.vcard');
        $registrar->subscriptionFeature('card.feature.stats', 'card.subscription.stats');
        $registrar->subscriptionFeature('card.feature.team', 'card.subscription.team');

        $registrar->routes(function (Router $router): void {
            $router->get('/card/{slug}', [CardController::class, 'show'], Router::PUBLIC);
            $router->get('/card/{slug}/qr.png', [CardController::class, 'qrPng'], Router::PUBLIC);
            $router->get('/card/{slug}/qr.svg', [CardController::class, 'qrSvg'], Router::PUBLIC);
            $router->get('/card/{slug}/vcard.vcf', [CardController::class, 'vcard'], Router::PUBLIC);
            $router->get('/card/{slug}/termin', [CardController::class, 'bookingShow'], Router::PUBLIC);
            $router->post('/card/{slug}/termin', [CardController::class, 'bookingSubmit'], Router::PUBLIC);
            $router->get('/go/{slug}/{type}', [CardController::class, 'trackClick'], Router::PUBLIC);

            $router->get('/media/card/{id}/{file}', [GalleryMediaController::class, 'gallery'], Router::PUBLIC);
            $router->get('/media/card-logo/{file}', [GalleryMediaController::class, 'logo'], Router::PUBLIC);

            $router->get('/account/card', [AccountCardController::class, 'edit']);
            $router->post('/account/card', [AccountCardController::class, 'save']);
            $router->get('/account/card/qr.png', [CardController::class, 'ownQrPng']);
            $router->get('/account/card/qr.svg', [CardController::class, 'ownQrSvg']);
            $router->post('/account/card/logo', [AccountCardController::class, 'uploadLogo']);
            $router->post('/account/card/logo/delete', [AccountCardController::class, 'deleteLogo']);
            $router->post('/account/card/gallery/add', [AccountCardController::class, 'addGalleryImage']);
            $router->post('/account/card/gallery/{id}/delete', [AccountCardController::class, 'deleteGalleryImage']);
            $router->post('/account/card/offerings/add', [AccountCardController::class, 'addOffering']);
            $router->post('/account/card/offerings/{id}/delete', [AccountCardController::class, 'deleteOffering']);
            $router->get('/account/card/stats', [AccountCardController::class, 'stats']);

            $router->get('/account/team', [TeamController::class, 'index']);
            $router->post('/account/team', [TeamController::class, 'found']);
            $router->post('/account/team/branding', [TeamController::class, 'saveBranding']);
            $router->post('/account/team/logo', [TeamController::class, 'uploadLogo']);
            $router->post('/account/team/logo/delete', [TeamController::class, 'deleteLogo']);
            $router->post('/account/team/invite', [TeamController::class, 'invite']);
            $router->post('/account/team/{providerId}/remove', [TeamController::class, 'remove']);
        });

        $registrar->accountLink('card.nav.my_card', '/account/card');
        $registrar->accountLink('card.nav.my_team', '/account/team');

        // A small teaser on the standard marketplace profile page, linking
        // to the full card - additive, /providers/{slug} itself is
        // untouched. Returns [] (nothing shown) for a provider who has
        // never saved a card-specific thing, same convention as every
        // provider section.
        $registrar->providerSection('@card/provider_section.twig', fn (array $provider, App $app): array => ['provider' => $provider]);
    }
}
