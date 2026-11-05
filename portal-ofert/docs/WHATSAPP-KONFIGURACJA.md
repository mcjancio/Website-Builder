# Kody weryfikacyjne przez WhatsApp — konfiguracja konta (dla właściciela portalu)

Portal wysyła kod weryfikacyjny **najpierw przez WhatsApp**, a gdy wiadomość nie dotrze (np. numer bez WhatsAppa), **automatycznie SMS-em z tym samym kodem**. Tak samo robią Allegro czy Amazon.

Do uruchomienia WhatsAppa potrzebne jest konto **WhatsApp Business Platform (Cloud API)** od Meta. Formalności trwają zwykle **od kilku dni do ok. 2 tygodni**, więc warto zacząć wcześniej. Do tego czasu portal działa na samych SMS-ach.

> Interfejs Meta często zmienia nazwy przycisków i układ menu. Poniższe kroki opisują stan z listopada 2026 — jeśli coś wygląda inaczej, szukaj tych samych pojęć (Business portfolio, WhatsApp Manager, System User, Authentication template).

## Czego potrzebujesz przed startem

- Dokumenty firmy do weryfikacji (KRS/CEIDG, NIP, adres strony www w domenie firmy, e-mail w tej domenie).
- **Osobny numer telefonu** dla portalu. Nie może być jednocześnie używany w zwykłej aplikacji WhatsApp ani WhatsApp Business na telefonie (można go wcześniej z niej usunąć). Może to być numer stacjonarny — weryfikacja przyjdzie połączeniem głosowym.
- Karta płatnicza (Meta rozlicza wiadomości według cennika dla Polski — sprawdź aktualne stawki w dokumentacji Meta: „Pricing on the WhatsApp Business Platform”, kategoria *Authentication*).

## Krok 1. Portfolio biznesowe i weryfikacja firmy

1. Wejdź na **business.facebook.com** i utwórz *Business portfolio* (dawniej Business Manager) na dane spółki.
2. *Ustawienia → Informacje o firmie / Security Center* → **Rozpocznij weryfikację** i prześlij dokumenty.

## Krok 2. Aplikacja i WhatsApp

1. Wejdź na **developers.facebook.com** → *Moje aplikacje* → **Utwórz aplikację** → typ *Business* (lub przypadek użycia „Connect with customers through WhatsApp”), przypisz do portfolio z kroku 1.
2. W aplikacji dodaj produkt **WhatsApp** i przejdź do *API Setup*.
3. **Dodaj numer telefonu** (krok „Add phone number”): nazwa wyświetlana (np. nazwa portalu — Meta ją zatwierdza), kategoria, weryfikacja numeru kodem SMS/połączeniem.
4. Zanotuj **Phone number ID** (nie sam numer!) — to wartość `whatsapp.phone_number_id`.
5. W *WhatsApp Manager → Ustawienia płatności* dodaj metodę płatności.

## Krok 3. Szablon wiadomości z kodem

1. *WhatsApp Manager → Szablony wiadomości → Utwórz szablon*.
2. Kategoria: **Authentication** (uwierzytelnianie). Nazwa np. `kod_weryfikacyjny`, język: **polski (pl)**.
3. Typ dostarczenia kodu: **Copy code** („Kopiuj kod”). Opcjonalnie zaznacz dopisek bezpieczeństwa i czas ważności (portal: 10 minut).
4. Wyślij do zatwierdzenia (zwykle kilka minut). Nazwa szablonu → `whatsapp.template`, język → `whatsapp.language`.

Treść szablonów uwierzytelniających ustala Meta („*Twój kod weryfikacyjny to 123456*”). Nie da się dopisać reklamy ani linku — to celowe zabezpieczenie przed nadużyciami.

## Krok 4. Stały token dostępu

Tymczasowy token z *API Setup* wygasa po 24 h — portal potrzebuje stałego:

1. *business.facebook.com → Ustawienia → Użytkownicy → Użytkownicy systemowi* → **Dodaj** (rola: Admin).
2. **Przypisz zasoby**: aplikację z kroku 2 (pełna kontrola) i konto WhatsApp (pełna kontrola).
3. **Wygeneruj token**: wybierz aplikację, ważność **„Nigdy”**, uprawnienia `whatsapp_business_messaging` i `whatsapp_business_management`.
4. Token → `whatsapp.access_token`. **Traktuj jak hasło**: nie wysyłaj mailem ani komunikatorem; wpisz bezpośrednio do `config.php` na serwerze (lub przekaż przez menedżer haseł).

## Krok 5. Webhook (statusy doręczenia → automatyczny SMS)

1. W aplikacji: *App settings → Basic* → skopiuj **App Secret** → `whatsapp.app_secret`.
2. Wymyśl losowy ciąg (np. 32 znaki) → `whatsapp.verify_token`.
3. Wpisz oba do `config.php` na serwerze (portal musi już działać pod HTTPS).
4. *WhatsApp → Configuration → Webhook → Edit*:
   - Callback URL: `https://<adres-portalu>/webhook/whatsapp`
   - Verify token: ten sam ciąg co `whatsapp.verify_token`
   - **Verify and save** — portal odpowie automatycznie.
5. W *Webhook fields* zasubskrybuj **messages** (statusy wiadomości przychodzą w tym polu).

## Krok 6. Wpis do config.php i test

```php
'whatsapp' => [
    'phone_number_id' => '123456789012345',
    'access_token'    => 'EAAG…',            // stały token użytkownika systemowego
    'template'        => 'kod_weryfikacyjny',
    'language'        => 'pl',
    'graph_version'   => 'v23.0',
    'app_secret'      => '…',
    'verify_token'    => '…',
],
'sms' => [
    'provider' => 'smsapi',
    'token'    => '…',                       // SMSAPI → Ustawienia API → Tokeny
    'sender'   => 'NazwaNadawcy',            // zatwierdzona w SMSAPI
],
```

Test: `php bin/install.php --check` pokaże „kanał weryfikacji telefonu: whatsapp → sms”. Potem zarejestruj konto testowe:
- na numer z WhatsAppem → kod przychodzi na WhatsApp,
- na numer bez WhatsAppa (np. stacjonarny) → po kilku sekundach przychodzi SMS z tym samym kodem.

## Co się dzieje, gdy…

| Sytuacja | Zachowanie portalu |
|---|---|
| Meta odrzuci wysyłkę od razu (np. szablon niezatwierdzony, brak płatności) | Kod idzie od razu SMS-em; błąd w `storage/logs` |
| Numer nie ma WhatsAppa | Meta zgłasza „nie doręczono” webhookiem → SMS z tym samym kodem (zwykle w ciągu kilku sekund) |
| Użytkownik nie widzi wiadomości | Po 30 s może kliknąć „Nie mam WhatsAppa — wyślij SMS” |
| WhatsApp nie skonfigurowany | Portal od razu używa SMS-ów |
| Chcesz wyłączyć WhatsApp | Usuń `phone_number_id` w `config.php` albo ustaw `otp.channels => ['sms']` |
