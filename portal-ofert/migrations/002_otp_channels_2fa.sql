-- =====================================================================
-- Portal Ofert — migracja 002: weryfikacja telefonu wieloma kanałami + 2FA
--   * sms_codes → otp_codes: kod może iść WhatsAppem, SMS-em albo (tryb testowy) do logu
--   * zapamiętany status doręczenia z webhooka WhatsApp → automatyczne przejście na SMS
--   * kod zaszyfrowany (code_enc) na czas ważności, żeby SMS zapasowy niósł TEN SAM kod
--   * 2FA: data włączenia i kody awaryjne
-- =====================================================================

RENAME TABLE sms_codes TO otp_codes;

ALTER TABLE otp_codes
  ADD COLUMN channel ENUM('whatsapp','sms','log') NOT NULL DEFAULT 'sms' AFTER purpose,
  ADD COLUMN code_enc VARBINARY(255) NULL AFTER code_hash,          -- czyszczony po weryfikacji/wygaśnięciu
  ADD COLUMN delivery_status VARCHAR(20) NULL AFTER provider_id,    -- sent / delivered / read / failed
  ADD COLUMN delivery_error VARCHAR(255) NULL AFTER delivery_status,
  ADD COLUMN fallback_sent_at DATETIME NULL AFTER delivery_error,
  ADD KEY idx_provider (provider_id),
  ADD KEY idx_phone_purpose (phone_e164, purpose, created_at);

ALTER TABLE users
  ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
  ADD COLUMN recovery_codes JSON NULL AFTER totp_enabled_at;       -- hashe jednorazowych kodów awaryjnych
