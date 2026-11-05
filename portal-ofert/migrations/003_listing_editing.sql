-- =====================================================================
-- Portal Ofert — migracja 003: edycja ofert z moderacją
--   * rewizja może być szkicem (autor jeszcze edytuje) zanim trafi do moderacji
--   * zdjęcia i załączniki dodane/usunięte w OPUBLIKOWANEJ ofercie czekają na akceptację
--   * data wysłania do moderacji (kolejka moderatora wg czasu)
-- =====================================================================

ALTER TABLE listing_revisions
  MODIFY status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  ADD COLUMN submitted_at DATETIME NULL AFTER status;

ALTER TABLE listings
  ADD COLUMN submitted_at DATETIME NULL AFTER ai_extracted;

ALTER TABLE media
  ADD COLUMN state ENUM('live','pending_add','pending_remove') NOT NULL DEFAULT 'live' AFTER access_level,
  ADD COLUMN size_bytes INT UNSIGNED NULL AFTER height;

ALTER TABLE attachments
  ADD COLUMN state ENUM('live','pending_add','pending_remove') NOT NULL DEFAULT 'live' AFTER access_level;
