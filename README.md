# Card

An extension for [Modulento](https://github.com/alex01at/modulento) that
turns an approved provider's profile into a shareable digital business
card: a full-page design at `/card/{slug}` (the provider's own slug, not a
new one), a QR code, a vCard download, click-tracked contact links, a
photo gallery, a plain display list of offerings, a booking button, and
view/click statistics.

Deliberately not an offer type: a card has nothing to order. It only ever
adds to a provider's own profile - name, address, contact details, headline
and description stay exactly where they already are, at
`/account/provider`. This extension adds the rest: company, category, logo,
social links, a design (`classic`, `modern`, `professional` or `playful`),
optional custom colours, opening hours, a photo gallery, an offerings list,
a booking link or request form, and counters.

## Free and Pro

Most of this is Pro-only, gated through Modulento's own **Subscriptions**
module rather than anything of this extension's own: build a plan under
**Administration → Subscriptions** and tick the boxes this extension adds
there (design & brand colour, custom colours, gallery, offerings, booking,
vCard, statistics). Switch the whole Subscriptions module off and every
card gets everything - the gate simply disappears, exactly like any other
extension's subscription feature.

A Free card still works: the provider's name, headline, description,
address and the contact methods already on the provider profile (phone,
e-mail) are always shown and always click-tracked.

## Installing

In Modulento, open **Administration → Packages**, enter
`alex01at/modulento-ext-card` and install. Then enable "Card" under
**Administration → Extensions**. An account turns its approved provider
profile into a card at **account → "Meine Visitenkarte"**
(`/account/card`).

By hand: unpack a release into `extensions/card/` of the installation and
enable the extension.

## Data

Tables of its own, all starting with `x_card_`: `x_card_profile` (the
extra fields, one row per provider), `x_card_gallery`, `x_card_offering`,
`x_card_event` (the time-series log behind the statistics trend chart - the
fast running totals for the stat tiles live as plain columns on
`x_card_profile` instead). A photo/logo lives outside the web root under
`var/uploads/card-gallery/` and `var/uploads/card-logo/`. Removing the
package leaves the tables and files in place.

## QR codes

Generated on the server with a small, dependency-free QR encoder of this
extension's own (`src/Qr/`) - extensions load without Composer, so a
package like `chillerlan/php-qrcode` is not an option. Byte mode only
(every payload here is a URL), version and error-correction level chosen
automatically to fit.

## Changing the look

The four designs in `templates/card/designs/` and their shared stylesheet
(`assets/card.css`) are this extension's own, not a site theme - the card
is meant to look the same everywhere a visitor opens it, independent of
which theme the rest of the site uses. A theme overrides one of these
templates the usual way, in `themes/<theme>/extensions/card/`.

## Development

```
ln -s /path/to/modulento-ext-card /path/to/modulento/extensions/card
cd /path/to/modulento && php tests/run.php
```

## Releasing

Set `version` in `extension.json`, commit, then tag and push:

```
git tag v0.1.0 && git push origin main v0.1.0
```

## Licence

GPL-3.0-or-later, see `LICENSE`.
