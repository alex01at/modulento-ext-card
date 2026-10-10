-- A Firma (team) organisation: one owning provider manages any number of
-- member providers, each with its own full card. Branding (company name,
-- logo, design, colour preset) lives here, never copied onto a member's own
-- x_card_profile row - it is resolved live at render time by
-- Organizations::applyBranding(), so a change by the owner takes effect on
-- every member's card immediately. color_preset is one of
-- Organizations::COLOR_PRESETS' keys, resolved to concrete hex values in
-- code rather than stored as hex here - a later change to a preset's own
-- colours then reaches every organisation that picked it.

CREATE TABLE x_card_organization (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_provider_id INT UNSIGNED NOT NULL,
    company VARCHAR(150) NOT NULL,
    logo_name CHAR(32) NULL,
    logo_extension VARCHAR(5) NULL,
    design VARCHAR(20) NOT NULL DEFAULT 'classic',
    color_preset VARCHAR(20) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_card_organization_owner (owner_provider_id),
    CONSTRAINT fk_x_card_organization_owner FOREIGN KEY (owner_provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per member (the owner included, as the first "owner"-role row).
-- workplace is the one optional field a member's own card can still set
-- itself (replacing a full address, which a member is never asked to fill
-- in - see Organizations::invite()).
CREATE TABLE x_card_organization_member (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'employee') NOT NULL,
    workplace VARCHAR(150) NULL,
    joined_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_card_organization_member_provider (provider_id),
    KEY idx_x_card_organization_member_org (organization_id),
    CONSTRAINT fk_x_card_organization_member_org FOREIGN KEY (organization_id) REFERENCES x_card_organization (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_card_organization_member_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
