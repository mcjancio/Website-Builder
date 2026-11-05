-- =====================================================================
-- Portal Ofert — migracja 001: schemat bazy danych v1.0
-- Uruchamiana przez: php bin/migrate.php (wpis w tabeli migrations dodaje migrator)
-- MySQL 8.0+ / MariaDB 10.6+ · utf8mb4 · InnoDB
-- Uniwersalny: branża definiowana presetem (categories + field_definitions)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';   -- czasy w UTC, konwersja do Europe/Warsaw w widokach

-- ---------------------------------------------------------------------
-- Konfiguracja portalu
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  `key`        VARCHAR(100) PRIMARY KEY,
  `value`      JSON NOT NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE pages (                      -- Zasady współpracy, Regulamin, Polityka prywatności…
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(120) NOT NULL UNIQUE,
  title        VARCHAR(200) NOT NULL,
  body_md      MEDIUMTEXT NOT NULL,       -- Markdown, renderowany bez surowego HTML
  version      INT UNSIGNED NOT NULL DEFAULT 1,  -- ważne dla zgód (regulamin v3)
  in_menu      TINYINT(1) NOT NULL DEFAULT 0,
  menu_order   SMALLINT NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------------------------------------------------------------------
-- Użytkownicy, firmy, uwierzytelnianie
-- ---------------------------------------------------------------------
CREATE TABLE companies (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nip          CHAR(10) NULL UNIQUE,      -- NULL = osoba prywatna (jeśli dopuszczona)
  name         VARCHAR(255) NOT NULL,
  regon        VARCHAR(14) NULL,
  krs          VARCHAR(10) NULL,
  address      VARCHAR(255) NULL,
  city         VARCHAR(120) NULL,
  postal_code  VARCHAR(10) NULL,
  vat_status   VARCHAR(30) NULL,          -- z API MF: Czynny / Zwolniony / brak
  source       ENUM('mf_api','manual') NOT NULL DEFAULT 'manual',
  verified_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE users (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id          INT UNSIGNED NULL,
  email               VARCHAR(190) NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NOT NULL,
  first_name          VARCHAR(100) NOT NULL,
  last_name           VARCHAR(100) NOT NULL,
  phone_e164          VARCHAR(16) NOT NULL,              -- zawsze +48XXXXXXXXX
  role                ENUM('admin','moderator','member') NOT NULL DEFAULT 'member',
  is_investor         TINYINT(1) NOT NULL DEFAULT 0,
  status              ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
  email_verified_at   DATETIME NULL,
  phone_verified_at   DATETIME NULL,
  totp_secret         VARBINARY(255) NULL,               -- szyfrowane kluczem z config.php
  failed_logins       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME NULL,
  last_login_at       DATETIME NULL,
  investor_prefs      JSON NULL,                         -- v2: preferencje do dopasowań
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_phone (phone_e164),
  KEY idx_role (role),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE user_tokens (                -- remember-me, reset hasła, weryfikacja e-mail
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  type         ENUM('remember','password_reset','email_verify') NOT NULL,
  selector     CHAR(24) NOT NULL UNIQUE,
  token_hash   CHAR(64) NOT NULL,         -- SHA-256 walidatora; sam token tylko u użytkownika
  expires_at   DATETIME NOT NULL,
  used_at      DATETIME NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, type),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE sms_codes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_e164   VARCHAR(16) NOT NULL,
  purpose      ENUM('register','login','phone_change') NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- maks. 5
  expires_at   DATETIME NOT NULL,                    -- +10 min
  verified_at  DATETIME NULL,
  ip           VARBINARY(16) NOT NULL,
  provider_id  VARCHAR(64) NULL,                     -- ID wiadomości w SMSAPI
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_phone_created (phone_e164, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE consents (                   -- RODO: dowód zgody
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  email        VARCHAR(190) NOT NULL,
  type         VARCHAR(50) NOT NULL,      -- terms, privacy, marketing, nda:{listing_id}
  page_version INT UNSIGNED NULL,
  granted      TINYINT(1) NOT NULL,
  ip           VARBINARY(16) NOT NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------------------------------------------------------------------
-- Preset: kategorie i definicje pól (edytowalne w panelu admina)
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id    INT UNSIGNED NULL,
  slug         VARCHAR(120) NOT NULL UNIQUE,
  name         VARCHAR(150) NOT NULL,
  icon         VARCHAR(50) NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE field_definitions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(64) NOT NULL UNIQUE,  -- stały identyfikator (np. capital_needed); nie zmieniać po starcie
  label        VARCHAR(150) NOT NULL,        -- etykieta (można zmieniać)
  help         VARCHAR(300) NULL,
  type         ENUM('text','textarea','int','decimal','money','percent','date',
                    'select','multiselect','bool','url','url_list','region') NOT NULL,
  group_name   VARCHAR(80) NOT NULL DEFAULT 'Parametry',
  required     TINYINT(1) NOT NULL DEFAULT 0,
  access_level TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- 1 publiczne, 2 zalogowani, 3 po NDA
  filterable   TINYINT(1) NOT NULL DEFAULT 0,
  filter_ui    ENUM('none','select','checkboxes','range','toggle') NOT NULL DEFAULT 'none',
  show_in_card TINYINT(1) NOT NULL DEFAULT 0,          -- widoczne na kafelku listy
  validation   JSON NULL,                              -- {min, max, maxlength, pattern, allow_past_years}
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1           -- ukrycie zamiast usunięcia
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE field_categories (           -- w których kategoriach pole występuje (brak wpisów = wszystkie)
  field_id     INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (field_id, category_id),
  CONSTRAINT fk_fc_field FOREIGN KEY (field_id) REFERENCES field_definitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_fc_cat   FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE field_options (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_id     INT UNSIGNED NOT NULL,
  `key`        VARCHAR(64) NOT NULL,
  label        VARCHAR(200) NOT NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_field_key (field_id, `key`),
  CONSTRAINT fk_opt_field FOREIGN KEY (field_id) REFERENCES field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------------------------------------------------------------------
-- Oferty
-- ---------------------------------------------------------------------
CREATE TABLE listings (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  category_id     INT UNSIGNED NOT NULL,
  slug            VARCHAR(200) NOT NULL,
  title           VARCHAR(200) NOT NULL,
  short_desc      VARCHAR(400) NOT NULL,            -- L1, limit z presetu (np. 287 znaków)
  full_desc_md    MEDIUMTEXT NULL,                  -- L2
  confidential_md MEDIUMTEXT NULL,                  -- L3 (np. nazwa dewelopera, dokładny adres)
  region          VARCHAR(40) NULL,                 -- województwo (do filtrów i SEO)
  city            VARCHAR(120) NULL,
  lat             DECIMAL(9,6) NULL,
  lng             DECIMAL(9,6) NULL,
  status          ENUM('draft','pending','published','paused','sold','archived','rejected')
                  NOT NULL DEFAULT 'draft',
  is_featured     TINYINT(1) NOT NULL DEFAULT 0,
  featured_until  DATETIME NULL,
  l3_self_grant   TINYINT(1) NOT NULL DEFAULT 0,    -- czy autor sam nadaje dostęp L3
  moderation_note VARCHAR(500) NULL,
  ai_extracted    TINYINT(1) NOT NULL DEFAULT 0,    -- wypełnione przez asystenta AI
  published_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY idx_status_pub (status, published_at),
  KEY idx_cat_status (category_id, status),
  KEY idx_region (region),
  FULLTEXT KEY ft_search (title, short_desc),
  CONSTRAINT fk_list_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_list_cat  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE listing_values (             -- wartości pól presetu; typowane kolumny = szybkie filtry
  listing_id   INT UNSIGNED NOT NULL,
  field_id     INT UNSIGNED NOT NULL,
  v_int        BIGINT NULL,
  v_dec        DECIMAL(18,4) NULL,        -- kwoty, procenty
  v_text       VARCHAR(1000) NULL,
  v_date       DATE NULL,
  v_option_id  INT UNSIGNED NULL,         -- dla select
  currency     CHAR(3) NULL,              -- dla money: PLN/EUR/USD/GBP
  v_dec_pln    DECIMAL(18,2) NULL,        -- kwota przeliczona na PLN (kurs NBP z dnia zapisu) — filtr „od–do”
  PRIMARY KEY (listing_id, field_id),
  KEY idx_field_int (field_id, v_int),
  KEY idx_field_dec (field_id, v_dec),
  KEY idx_field_pln (field_id, v_dec_pln),
  KEY idx_field_date (field_id, v_date),
  KEY idx_field_opt (field_id, v_option_id),
  CONSTRAINT fk_lv_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_lv_field   FOREIGN KEY (field_id) REFERENCES field_definitions(id),
  CONSTRAINT fk_lv_opt     FOREIGN KEY (v_option_id) REFERENCES field_options(id)
) ENGINE=InnoDB;

CREATE TABLE listing_value_options (      -- multiselect (np. formy zabezpieczenia)
  listing_id   INT UNSIGNED NOT NULL,
  field_id     INT UNSIGNED NOT NULL,
  option_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (listing_id, field_id, option_id),
  KEY idx_field_option (field_id, option_id),
  CONSTRAINT fk_lvo_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_lvo_option  FOREIGN KEY (option_id) REFERENCES field_options(id)
) ENGINE=InnoDB;

CREATE TABLE listing_revisions (          -- edycja opublikowanej oferty czeka na akceptację
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  payload      JSON NOT NULL,             -- pełny stan oferty po zmianie (do diffu)
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer_id  INT UNSIGNED NULL,
  review_note  VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at  DATETIME NULL,
  KEY idx_listing_status (listing_id, status),
  CONSTRAINT fk_rev_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE media (                      -- zdjęcia (WebP w 3 rozmiarach)
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id   INT UNSIGNED NOT NULL,
  path_base    VARCHAR(255) NOT NULL,     -- storage/uploads/img/ab/cd/<uuid> (+ _400/_960/_1600.webp)
  alt          VARCHAR(200) NULL,
  access_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_cover     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  width        SMALLINT UNSIGNED NULL,
  height       SMALLINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_listing (listing_id, sort_order),
  CONSTRAINT fk_media_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE attachments (                -- PDF/DOCX/XLSX, do 25 na ofertę
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id    INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(255) NOT NULL,    -- poza public_html
  mime          VARCHAR(100) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) NOT NULL,
  label         VARCHAR(200) NULL,        -- np. „Teaser”, „Model finansowy”, „Decyzja WZ”
  access_level  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_listing (listing_id, sort_order),
  CONSTRAINT fk_att_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE access_grants (              -- poziom L3 per użytkownik × oferta
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  status       ENUM('requested','granted','denied','revoked') NOT NULL DEFAULT 'requested',
  message      VARCHAR(1000) NULL,        -- uzasadnienie prośby
  nda_consent_id BIGINT UNSIGNED NULL,    -- v2: akceptacja NDA online
  decided_by   INT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at   DATETIME NULL,
  expires_at   DATETIME NULL,
  UNIQUE KEY uq_listing_user (listing_id, user_id),
  KEY idx_status (status),
  CONSTRAINT fk_ag_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ag_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE downloads (                  -- kto pobrał który dokument (wymóg dla L3)
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attachment_id INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,
  ip            VARBINARY(16) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_att (attachment_id, created_at),
  CONSTRAINT fk_dl_att FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Leady, aktywność użytkowników
-- ---------------------------------------------------------------------
CREATE TABLE inquiries (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id   INT UNSIGNED NULL,         -- NULL = formularz kontaktowy ogólny
  user_id      INT UNSIGNED NULL,
  name         VARCHAR(150) NOT NULL,
  email        VARCHAR(190) NOT NULL,
  phone_e164   VARCHAR(16) NULL,
  message      TEXT NOT NULL,
  consent_id   BIGINT UNSIGNED NOT NULL,  -- zgoda wymagana (sprawdzana na serwerze)
  status       ENUM('new','in_progress','closed','spam') NOT NULL DEFAULT 'new',
  assigned_to  INT UNSIGNED NULL,
  admin_note   TEXT NULL,
  utm          JSON NULL,                 -- źródło kampanii
  ip           VARBINARY(16) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status_created (status, created_at),
  KEY idx_listing (listing_id),
  CONSTRAINT fk_inq_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE favorites (
  user_id      INT UNSIGNED NOT NULL,
  listing_id   INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, listing_id),
  CONSTRAINT fk_fav_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE saved_searches (             -- alert e-mail o nowych pasujących ofertach
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  name         VARCHAR(150) NOT NULL,
  query        JSON NOT NULL,             -- te same parametry co URL filtrów
  frequency    ENUM('off','daily','weekly') NOT NULL DEFAULT 'weekly',
  last_sent_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE listing_stats (              -- agregaty dzienne (bez zapisywania każdego wejścia)
  listing_id   INT UNSIGNED NOT NULL,
  day          DATE NOT NULL,
  views_public INT UNSIGNED NOT NULL DEFAULT 0,
  views_member INT UNSIGNED NOT NULL DEFAULT 0,
  inquiries    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (listing_id, day),
  CONSTRAINT fk_stats_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE listing_member_views (       -- który zalogowany użytkownik oglądał ofertę (lead intelligence)
  listing_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  first_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  views        INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (listing_id, user_id),
  CONSTRAINT fk_lmv_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_lmv_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Infrastruktura: kolejka, limity, dziennik
-- ---------------------------------------------------------------------
CREATE TABLE jobs (                       -- przetwarzane przez bin/worker.php z crona co minutę
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         VARCHAR(60) NOT NULL,      -- mail, sms, getresponse.upsert, webhook, ai.extract, alerts.send
  payload      JSON NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- backoff: 1, 5, 15, 60 min
  reserved_at  DATETIME NULL,
  done_at      DATETIME NULL,
  last_error   TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pending (done_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE rate_limits (
  bucket       VARCHAR(120) PRIMARY KEY,  -- np. login:ip:1.2.3.4, sms:phone:+48…
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(80) NOT NULL,      -- login.ok, login.fail, listing.approve, access.grant, file.download, role.change…
  entity_type  VARCHAR(40) NULL,
  entity_id    INT UNSIGNED NULL,
  details      JSON NULL,
  ip           VARBINARY(16) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity_type, entity_id),
  KEY idx_user_created (user_id, created_at),
  KEY idx_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE ai_usage (                   -- kontrola kosztów modułu AI
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  feature       VARCHAR(40) NOT NULL,     -- extract_pdf, rewrite_desc, score
  model         VARCHAR(60) NOT NULL,
  input_tokens  INT UNSIGNED NOT NULL,
  output_tokens INT UNSIGNED NOT NULL,
  cost_usd      DECIMAL(10,5) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB;

-- Tabelę `migrations` tworzy sam migrator (App\Core\Migrator) przed pierwszą migracją.

