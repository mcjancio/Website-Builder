-- Etap 7: asystent AI (wyłączony domyślnie: preset/ustawienia ai.enabled).

-- Każde wywołanie modelu: koszt i wynik — podstawa miesięcznego budżetu (tabela z 001, tu rozbudowana).
ALTER TABLE ai_usage
  MODIFY feature VARCHAR(30) NOT NULL,                -- extract | rewrite | hints
  MODIFY input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  MODIFY output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  MODIFY cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
  ADD COLUMN listing_id INT UNSIGNED NULL AFTER user_id,
  ADD COLUMN status ENUM('ok','error','refused') NOT NULL DEFAULT 'ok' AFTER cost_usd,
  ADD COLUMN error VARCHAR(500) NULL AFTER status,
  ADD COLUMN duration_ms INT UNSIGNED NULL AFTER error,
  ADD KEY idx_listing (listing_id);

-- Zlecenia dla asystenta (wykonywane w tle przez kolejkę) i ich wyniki.
CREATE TABLE ai_tasks (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind          VARCHAR(20) NOT NULL,       -- extract | rewrite | hints
  listing_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,
  attachment_id INT UNSIGNED NULL,          -- extract: dokument źródłowy
  input         MEDIUMTEXT NULL,            -- rewrite: notatki autora
  status        ENUM('queued','running','done','failed','applied') NOT NULL DEFAULT 'queued',
  result        JSON NULL,
  error         VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   DATETIME NULL,
  KEY idx_listing_kind (listing_id, kind, id),
  CONSTRAINT fk_ait_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- Pola wypełnione przez AI, których autor jeszcze nie sprawdził (znacznik „do sprawdzenia” w kreatorze).
ALTER TABLE listings ADD COLUMN ai_review JSON NULL AFTER ai_extracted;
