-- Przegląd bezpieczeństwa przed startem.

-- Wersja sesji: zmiana hasła, „wyloguj wszędzie”, blokada, zmiana roli i reset 2FA unieważniają
-- WSZYSTKIE otwarte sesje użytkownika (nie tylko ciasteczka „Zapamiętaj mnie”).
ALTER TABLE users
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER locked_until,
  ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at;  -- ostatnio użyty kod 2FA (ochrona przed ponownym użyciem)

-- Ofertę wstrzymaną przez moderację autor nie może sam wznowić.
ALTER TABLE listings ADD COLUMN paused_by_staff TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured;
