<?php

declare(strict_types=1);

return [
    // Subscription feature labels (Administration → Abonnements, Pläne)
    'card.subscription.design' => 'Visitenkarte: Design-Vorlagen & Markenfarbe',
    'card.subscription.colors' => 'Visitenkarte: eigene Farben',
    'card.subscription.gallery' => 'Visitenkarte: Bildergalerie',
    'card.subscription.offerings' => 'Visitenkarte: Angebotsliste',
    'card.subscription.booking' => 'Visitenkarte: Terminbuchung',
    'card.subscription.vcard' => 'Visitenkarte: vCard-Download',
    'card.subscription.stats' => 'Visitenkarte: Statistiken',

    // Navigation
    'card.nav.my_card' => 'Meine Visitenkarte',
    'card.error.needs_provider' => 'Lege zuerst ein Anbieterprofil an - die Visitenkarte baut darauf auf.',
    'card.feature.locked' => 'Das ist in deinem aktuellen Plan nicht enthalten.',

    // Editor
    'card.edit.saved' => 'Gespeichert.',
    'card.edit.error.url' => 'Einer der Links ist keine gültige Adresse.',
    'card.edit.error.color' => 'Eine der Farben ist ungültig.',

    // Logo
    'card.logo.uploaded' => 'Logo hochgeladen.',
    'card.logo.removed' => 'Logo entfernt.',
    'card.logo.error.unavailable' => 'Bildverarbeitung ist auf diesem Server nicht verfügbar.',
    'card.logo.error.upload' => 'Das Logo konnte nicht hochgeladen werden.',
    'card.logo.error.too_large' => 'Das Logo ist zu groß (maximal 8 MB).',
    'card.logo.error.type' => 'Dieser Dateityp wird nicht unterstützt.',
    'card.logo.error.dimensions' => 'Das Bild ist zu groß oder zu klein.',
    'card.logo.error.storage' => 'Das Logo konnte nicht gespeichert werden.',

    // Gallery
    'card.gallery.added' => 'Bild hinzugefügt.',
    'card.gallery.removed' => 'Bild entfernt.',
    'card.gallery.error.unavailable' => 'Bildverarbeitung ist auf diesem Server nicht verfügbar.',
    'card.gallery.error.upload' => 'Das Bild konnte nicht hochgeladen werden.',
    'card.gallery.error.too_large' => 'Das Bild ist zu groß (maximal 8 MB).',
    'card.gallery.error.too_many' => 'Du hast die maximale Anzahl Bilder erreicht.',
    'card.gallery.error.type' => 'Dieser Dateityp wird nicht unterstützt.',
    'card.gallery.error.dimensions' => 'Das Bild ist zu groß oder zu klein.',
    'card.gallery.error.storage' => 'Das Bild konnte nicht gespeichert werden.',

    // Offerings
    'card.offering.added' => 'Eintrag hinzugefügt.',
    'card.offering.removed' => 'Eintrag entfernt.',
    'card.offering.error.title' => 'Gib einen Titel an (maximal 150 Zeichen).',
    'card.offering.error.description' => 'Die Beschreibung ist zu lang (maximal 500 Zeichen).',
    'card.offering.error.price' => 'Der Preis ist zu lang (maximal 50 Zeichen).',
    'card.offering.error.too_many' => 'Du hast die maximale Anzahl Einträge erreicht.',

    // Booking request form
    'card.booking.sent' => 'Deine Anfrage wurde gesendet.',
    'card.booking.error.name' => 'Gib deinen Namen an.',
    'card.booking.error.email' => 'Gib eine gültige E-Mail-Adresse an.',
    'card.booking.error.preferred_time' => 'Gib an, wann es dir passt.',
    'card.booking.title' => 'Termin anfragen bei {name}',
    'card.booking.back' => 'Zurück zur Karte von {name}',
    'card.booking.submit' => 'Anfrage senden',
    'card.booking.field.name' => 'Name',
    'card.booking.field.email' => 'E-Mail',
    'card.booking.field.preferred_time' => 'Wunschtermin',
    'card.booking.field.preferred_time_placeholder' => 'z. B. Dienstagnachmittag',
    'card.booking.field.message' => 'Nachricht',

    // Booking e-mail to the card owner
    'card.mail.booking.subject' => 'Terminanfrage von {name}',
    'card.mail.booking.intro' => 'Über deine Visitenkarte ist eine Terminanfrage für {provider} eingegangen:',

    // Public card page
    'card.detail.save_contact' => 'Kontakt speichern',
    'card.detail.book' => 'Termin anfragen',
    'card.detail.opening_hours' => 'Öffnungszeiten',
    'card.detail.powered_by' => 'Erstellt mit {site}',
    'card.teaser.view' => 'Volle Visitenkarte ansehen',

    // Design names
    'card.design.classic' => 'Klassisch',
    'card.design.modern' => 'Modern',
    'card.design.professional' => 'Professionell',
    'card.design.playful' => 'Verspielt',

    // Contact/click type labels (also used for the statistics table)
    'card.contact.phone' => 'Telefon',
    'card.contact.email' => 'E-Mail',
    'card.contact.website' => 'Website',
    'card.contact.address' => 'Adresse',
    'card.contact.linkedin' => 'LinkedIn',
    'card.contact.instagram' => 'Instagram',
    'card.contact.facebook' => 'Facebook',
    'card.contact.youtube' => 'YouTube',
    'card.contact.booking' => 'Terminbuchung',
    'card.contact.whatsapp' => 'WhatsApp',
    'card.contact.snapchat' => 'Snapchat',

    // Weekdays (ISO: 1 Montag .. 7 Sonntag)
    'card.weekday.1' => 'Montag',
    'card.weekday.2' => 'Dienstag',
    'card.weekday.3' => 'Mittwoch',
    'card.weekday.4' => 'Donnerstag',
    'card.weekday.5' => 'Freitag',
    'card.weekday.6' => 'Samstag',
    'card.weekday.7' => 'Sonntag',

    // Editor page
    'card.edit.title' => 'Meine Visitenkarte',
    'card.edit.intro' => 'Name, Adresse und Kontaktdaten kommen von deinem Anbieterprofil.',
    'card.edit.provider_link' => 'Anbieterprofil bearbeiten',
    'card.edit.preview' => 'Karte ansehen',
    'card.edit.save' => 'Speichern',
    'card.edit.pro_only' => 'Nur mit Pro',
    'card.edit.company' => 'Firma / Arbeitgeber',
    'card.edit.category' => 'Branche',
    'card.edit.category_none' => 'Keine Angabe',
    'card.edit.section_contact' => 'Kontakt',
    'card.edit.contact_hint' => 'Telefon, E-Mail und Adresse kommen vom Anbieterprofil.',
    'card.edit.whatsapp' => 'WhatsApp-Nummer',
    'card.edit.website' => 'Website',
    'card.edit.section_social' => 'Soziale Netzwerke',
    'card.edit.opening_hours' => 'Öffnungszeiten',
    'card.edit.opening_hours_placeholder' => 'z. B. Mo–Fr 9–17 Uhr',
    'card.edit.section_design' => 'Design',
    'card.edit.section_colors' => 'Eigene Farben',
    'card.edit.use_custom_colors' => 'Eigene Farben verwenden',
    'card.edit.color_background' => 'Hintergrund',
    'card.edit.color_header' => 'Kopfbereich',
    'card.edit.color_content' => 'Akzentfarbe',
    'card.edit.color_footer' => 'Fußbereich',
    'card.edit.section_booking' => 'Terminbuchung',
    'card.edit.booking_none' => 'Keine Terminbuchung',
    'card.edit.booking_link' => 'Externer Link (z. B. Calendly)',
    'card.edit.booking_request' => 'Anfrageformular auf der Karte',
    'card.edit.booking_request_hint' => 'Besucher senden dir eine Anfrage per E-Mail - keine eigene Terminverwaltung.',
    'card.edit.section_logo' => 'Logo',
    'card.edit.logo_upload' => 'Logo auswählen',
    'card.edit.logo_hint' => 'JPG, PNG oder WebP, maximal 8 MB.',
    'card.edit.logo_save' => 'Hochladen',
    'card.edit.logo_remove' => 'Logo entfernen',
    'card.edit.section_gallery' => 'Bildergalerie',
    'card.edit.gallery_add' => 'Bild auswählen',
    'card.edit.gallery_hint' => 'Bis zu {max} Bilder, maximal 8 MB je Bild.',
    'card.edit.gallery_upload' => 'Hochladen',
    'card.edit.gallery_remove' => 'Entfernen',
    'card.edit.section_offerings' => 'Angebote',
    'card.edit.offering_title' => 'Titel',
    'card.edit.offering_price' => 'Preis (frei formulierbar)',
    'card.edit.offering_description' => 'Beschreibung',
    'card.edit.offering_add' => 'Hinzufügen',
    'card.edit.offering_remove' => 'Entfernen',
    'card.edit.stats_link' => 'Statistiken ansehen',

    // Stats page
    'card.stats.title' => 'Statistiken',
    'card.stats.views' => 'Aufrufe insgesamt',
    'card.stats.since_yesterday' => 'Aufrufe seit gestern',
    'card.stats.busiest_day' => 'Meistbesuchter Wochentag',
    'card.stats.trend' => 'Aufrufe der letzten 30 Tage',
    'card.stats.clicks' => 'Klicks',
    'card.stats.link_type' => 'Kontaktart',
];
