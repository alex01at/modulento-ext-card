<?php

declare(strict_types=1);

namespace Modulento\Card;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/**
 * "Mein Team": founding a Firma organisation, its branding (company name,
 * logo, design, a curated colour preset - never a free colour choice, see
 * Organizations::COLOR_PRESETS), and its member list. Only the owner can
 * change any of this; a member sees a read-only summary instead (their own
 * card is edited at the normal /account/card, just with company/design/
 * colours locked, see AccountCardController).
 */
final class TeamController extends Controller
{
    /** Public page explaining the Firma/Team offer, with the real, currently active plan(s) that grant it. */
    public function landing(array $params): void
    {
        $plans = array_values(array_filter(
            $this->app->subscriptions->activePlans(),
            fn (array $plan) => in_array('card.feature.team', $plan['features'], true)
        ));

        $this->render('@card/firma.twig', [
            'plans' => array_map(fn (array $plan) => $plan + ['min_total_cents' => $plan['price_cents'] * $plan['min_quantity']], $plans),
            'signed_in' => $this->app->auth->account() !== null,
        ]);
    }

    public function index(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $membership = $this->organizations()->forProvider((int) $provider['id']);
        if ($membership !== null) {
            $organization = $membership['organization'];
            if ($membership['role'] === 'owner') {
                $this->render('@card/account/team.twig', [
                    'organization' => $organization,
                    'members' => $this->organizations()->members((int) $organization['id']),
                    'color_presets' => Organizations::COLOR_PRESETS,
                    'designs' => Cards::AVAILABLE_DESIGNS,
                ]);
            } else {
                $this->render('@card/account/team_member.twig', ['organization' => $organization]);
            }

            return;
        }

        if (!$this->app->subscriptions->allows((int) $provider['account_id'], 'card.feature.team')) {
            $this->render('@card/account/team_locked.twig', []);

            return;
        }

        $this->render('@card/account/team_found.twig', []);
    }

    public function found(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }
        if (!$this->app->subscriptions->allows((int) $provider['account_id'], 'card.feature.team')) {
            Session::flash('error', $this->trans('card.feature.locked'));
            $this->redirect('/account/team');

            return;
        }

        $result = $this->organizations()->create((int) $provider['id'], (string) ($_POST['company'] ?? ''));
        if (is_string($result)) {
            Session::flash('error', $this->trans($result));
        } else {
            Session::flash('success', $this->trans('card.team.founded'));
        }
        $this->redirect('/account/team');
    }

    public function saveBranding(array $params): void
    {
        $organization = $this->ownedOrganization();
        if ($organization === null) {
            return;
        }

        $this->organizations()->saveBranding((int) $organization['id'], [
            'company' => (string) ($_POST['company'] ?? ''),
            'design' => (string) ($_POST['design'] ?? ''),
            'color_preset' => ($_POST['color_preset'] ?? '') !== '' ? (string) $_POST['color_preset'] : null,
        ]);
        Session::flash('success', $this->trans('card.team.branding_saved'));
        $this->redirect('/account/team');
    }

    public function uploadLogo(array $params): void
    {
        $organization = $this->ownedOrganization();
        if ($organization === null) {
            return;
        }

        $error = $this->organizations()->setLogo((int) $organization['id'], $_FILES['logo'] ?? [], $this->uploadDir());
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'card.logo.uploaded'));
        $this->redirect('/account/team');
    }

    public function deleteLogo(array $params): void
    {
        $organization = $this->ownedOrganization();
        if ($organization === null) {
            return;
        }

        $this->organizations()->removeLogo((int) $organization['id'], $this->uploadDir());
        Session::flash('success', $this->trans('card.logo.removed'));
        $this->redirect('/account/team');
    }

    public function invite(array $params): void
    {
        $organization = $this->ownedOrganization();
        if ($organization === null) {
            return;
        }

        $error = $this->organizations()->invite((int) $organization['id'], (string) ($_POST['email'] ?? ''), $this->app->translator->locale());
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'card.team.invited'));
        $this->redirect('/account/team');
    }

    public function remove(array $params): void
    {
        $organization = $this->ownedOrganization();
        if ($organization === null) {
            return;
        }

        $memberProviderId = (int) $params['providerId'];
        if ($memberProviderId === (int) $organization['owner_provider_id']) {
            Session::flash('error', $this->trans('card.team.error.remove_owner'));
            $this->redirect('/account/team');

            return;
        }

        $this->organizations()->remove((int) $organization['id'], $memberProviderId);
        Session::flash('success', $this->trans('card.team.removed'));
        $this->redirect('/account/team');
    }

    private function organizations(): Organizations
    {
        return new Organizations($this->app->db, $this->app);
    }

    /** The logged-in account's own provider; redirects to create one first, same as AccountCardController. */
    private function provider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            Session::flash('error', $this->trans('card.error.needs_provider'));
            $this->redirect('/account/provider');
        }

        return $provider;
    }

    /** The organisation this account owns, or null (with a flash and redirect already issued) if it owns none. */
    private function ownedOrganization(): ?array
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }

        $organization = $this->organizations()->ofOwner((int) $provider['id']);
        if ($organization === null) {
            Session::flash('error', $this->trans('card.team.error.not_owner'));
            $this->redirect('/account/team');

            return null;
        }

        return $organization;
    }

    private function uploadDir(): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/card-logo';
    }
}
