-- A digital business card: one row per provider, holding everything the
-- core provider record does not already have (name/address/contact/headline/
-- description come straight from `provider`/`provider_translation`). Free
-- vs. Pro is decided by the subscription feature checks in the extension's
-- own code, not by anything in this schema.

CREATE TABLE x_card_profile (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    company VARCHAR(150) NULL,
    category_id INT UNSIGNED NULL,
    logo_name CHAR(32) NULL,
    logo_extension VARCHAR(5) NULL,
    whatsapp VARCHAR(30) NULL,
    website_url VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    instagram_url VARCHAR(255) NULL,
    facebook_url VARCHAR(255) NULL,
    youtube_url VARCHAR(255) NULL,
    snapchat_url VARCHAR(255) NULL,
    booking_url VARCHAR(255) NULL,
    booking_mode ENUM('link', 'request') NULL,
    opening_hours TEXT NULL,
    design VARCHAR(20) NOT NULL DEFAULT 'classic',
    use_custom_colors TINYINT(1) NOT NULL DEFAULT 0,
    color_background CHAR(7) NULL,
    color_header CHAR(7) NULL,
    color_content CHAR(7) NULL,
    color_footer CHAR(7) NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_phone INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_email INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_website INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_address INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_linkedin INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_instagram INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_facebook INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_youtube INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_booking INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_whatsapp INT UNSIGNED NOT NULL DEFAULT 0,
    clicks_snapchat INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_card_profile_provider (provider_id),
    KEY idx_x_card_profile_category (category_id),
    CONSTRAINT fk_x_card_profile_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_card_profile_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Up to GalleryService::MAX_IMAGES photos, shown on the public card. Mirrors
-- the core's own offer_image table (two sizes, random name, outside the web root).
CREATE TABLE x_card_gallery (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    name CHAR(32) NOT NULL,
    extension VARCHAR(5) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_x_card_gallery_provider (provider_id, position),
    CONSTRAINT fk_x_card_gallery_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A plain display list (services/products), never an orderable catalogue -
-- price is free text on purpose, there is no checkout behind this at all.
CREATE TABLE x_card_offering (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    price VARCHAR(50) NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_x_card_offering_provider (provider_id, position),
    CONSTRAINT fk_x_card_offering_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per page view or tracked link click, purely for the Pro trend
-- chart (day-by-day). The cheap running totals for the stat tiles live as
-- plain columns on x_card_profile instead - this table is never read for
-- those, only for the dated breakdown.
CREATE TABLE x_card_event (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_x_card_event_provider (provider_id, type, created_at),
    CONSTRAINT fk_x_card_event_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
