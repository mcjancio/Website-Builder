-- Etap 6: import z dotychczasowej strony (WordPress) — mapa „stary ID → nowy ID”.
-- Dzięki niej import można uruchamiać wielokrotnie (już przeniesione rekordy są pomijane),
-- a przekierowania 301 i raport wskazują dokładnie, co skąd pochodzi.

CREATE TABLE import_map (
  source       VARCHAR(20) NOT NULL,      -- np. wp
  entity       VARCHAR(20) NOT NULL,      -- user | listing | media | attachment
  source_id    BIGINT UNSIGNED NOT NULL,
  target_id    INT UNSIGNED NOT NULL,
  old_path     VARCHAR(500) NULL,         -- dawny adres (do przekierowań)
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (source, entity, source_id),
  KEY idx_target (entity, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- Konta przeniesione ze starej strony mogą nie mieć telefonu (uzupełnią go w profilu).
ALTER TABLE users MODIFY phone_e164 VARCHAR(16) NULL;
