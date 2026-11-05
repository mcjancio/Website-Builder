# Import z dotychczasowej strony WordPress

Skrypt `bin/import-wp.php` przenosi z WordPressa (także z WooCommerce i wtyczek katalogowych):

- **konta**: imię, nazwisko, telefon, firma i NIP, typ konta, data rejestracji. Hasła **nie** są przenoszone. Konto dostaje losowe hasło, a właściciel ustawia nowe linkiem z e-maila;
- **oferty**: tytuł, opisy (HTML jest zamieniany na prosty Markdown), kategoria, status, data publikacji, autor;
- **pola ofert**: dowolne pola własne (`postmeta`), atrybuty i taksonomie. Każda wartość przechodzi tę samą walidację co formularz;
- **zdjęcia**: obraz wyróżniający, galeria, opcjonalnie obrazy wklejone w treść. Zdjęcia są konwertowane do WebP jak przy zwykłym uploadzie;
- **dokumenty** (PDF/DOCX/XLSX) z wybranym poziomem dostępu;
- **przekierowania 301** ze starych adresów na nowe.

Kod nie zna starych nazw pól — wszystko opisuje **plik mapowania JSON**. Import można uruchamiać wielokrotnie: rekordy już przeniesione są pomijane (tabela `import_map`).

## Krok po kroku

1. **Dostęp do bazy WordPressa.** W cPanelu dodaj użytkownikowi bazy portalu uprawnienie *tylko do odczytu* (SELECT) do bazy WordPressa. Wpisz dane w `config.php → import_wp`:
   - `name`, `user`, `pass`, `prefix` — prefiks znajdziesz w `wp-config.php` jako `$table_prefix`;
   - `uploads` — pełna ścieżka do `wp-content/uploads`;
   - `old_url` — adres starej strony.

2. **Rozpoznanie** — sprawdź, co jest w bazie (nic nie zmienia):

   ```bash
   php bin/import-wp.php --discover
   php bin/import-wp.php --discover --post-type=product --write-mapping=storage/import/mapowanie.json
   ```

   Pierwsze polecenie pokazuje typy wpisów (np. `product`, `inwestycja`), role kont i pola kont. Drugie wypisuje pola i kategorie wybranego typu oraz zapisuje **szkielet mapowania** z listą znalezionych pól (`_znalezione_pola`) i kategorii.

3. **Uzupełnij mapowanie.** Wzór: [`import-wp-mapowanie.przyklad.json`](import-wp-mapowanie.przyklad.json). Opis sekcji jest niżej.

4. **Próba** — robi wszystko w transakcji wycofanej na końcu i nie kopiuje plików (sprawdza tylko, czy istnieją):

   ```bash
   php bin/import-wp.php --mapping=storage/import/mapowanie.json --dry-run
   ```

   Przejrzyj raport `storage/import/raport-wp-…-proba.md`:
   - **wartości bez odpowiednika w opcjach** — dopisz je do `values` w mapowaniu albo dodaj opcję w panelu (Kryteria);
   - **pola, których nie przeniesiono** — zdecyduj, czy są potrzebne;
   - **kategorie bez odpowiednika** — uzupełnij `category.map`;
   - **odrzucone wartości i brakujące pliki**.

   Powtarzaj, aż raport będzie akceptowalny.

5. **Import** (najlepiej najpierw na stagingu):

   ```bash
   php bin/import-wp.php --mapping=storage/import/mapowanie.json --owner=admin@twojadomena.pl
   ```

   `--owner` wskazuje konto, które przejmie oferty, których autor nie był importowany (np. administrator starej strony). Domyślnie jest to pierwszy administrator portalu.

6. **Przekierowania 301.** Wklej reguły z `storage/import/przekierowania-wp.htaccess` do `public/.htaccess` zaraz po `RewriteEngine On`. Jeśli stara strona zostaje pod inną domeną, wklej je do `.htaccess` starej domeny i przed `/oferta/` dopisz pełny adres nowego portalu.

7. **Start i zaproszenia.** Po przełączeniu domeny wyślij właścicielom kont link do ustawienia hasła (ważny 14 dni; każde konto dostaje go tylko raz):

   ```bash
   php bin/import-wp.php --send-invites --limit=200
   ```

   Przy dużej liczbie kont wysyłaj partiami (np. 200 dziennie), żeby nie trafić do spamu.

## Dzień przełączenia (zalecana kolejność)

1. Na starej stronie wyłącz rejestrację i dodawanie ofert (tryb tylko do odczytu).
2. Wykonaj kopię: `php bin/backup.php`.
3. Uruchom import jeszcze raz — przeniesie tylko to, co doszło od ostatniego razu.
4. Wklej przekierowania i przełącz domenę.
5. Sprawdź kilka starych adresów (powinny dać 301 na nowe), `/health` i panel `/admin/oferty`.
6. Wyślij zaproszenia (`--send-invites`).

## Plik mapowania — opis

| Sekcja | Znaczenie |
|---|---|
| `post_type` | Typ wpisu z ofertami, np. `product` (WooCommerce) |
| `statuses` | Status WP → status w portalu. Domyślnie: `publish` → `published`, `draft` → `draft`, `pending` → `pending`, `private` → `paused`. Pozostałe (np. `trash`) są pomijane |
| `category` | `taxonomy` (np. `product_cat`), `map`: slug lub nazwa kategorii WP → slug kategorii portalu, `default`: kategoria, gdy brak dopasowania |
| `core` | Pola stałe: `title`, `short_desc`, `full_desc_md`, `confidential_md`. Źródło: `post_title`, `post_content`, `post_excerpt` albo `meta:klucz`. Pusty krótki opis zostanie utworzony z początku pełnego opisu |
| `fields` | Klucz pola portalu → źródło: `"meta:klucz"`, `"taxonomy:nazwa"` albo obiekt `{"from": …, "values": {…}, "currency": "PLN", "currency_from": "meta:waluta"}`. Opcje pól wyboru są dopasowywane po kluczu albo etykiecie (bez względu na wielkość liter i polskie znaki). `values` tłumaczy inne zapisy |
| `images` | `featured` (obraz wyróżniający), `gallery_meta` (np. `_product_image_gallery`), `from_content` (obrazy z treści; zamiast miniatur `-300x200` brany jest oryginał) |
| `attachments` | Lista `{"from": "meta:klucz", "label": "Teaser", "access_level": 1–3}`. Wartość może być ID z biblioteki mediów, adresem URL z `/wp-content/uploads/` albo listą |
| `users` | `roles` (które role importować; puste = wszystkie), `skip_admins`, `meta` (skąd imię, nazwisko, `phone`, `company_name`, `nip`), `account_type` (`from`: `meta:klucz` albo `role`, `values`, `default`) |
| `redirects` | `paths`: wzory starych adresów z `{slug}`, np. `/inwestycje/{slug}/`; `query`: parametry typu `?p=123` / `?product=slug` |

## Co warto wiedzieć

- **Konto o tym samym e-mailu** co istniejące w portalu nie jest dublowane — oferty trafiają do istniejącego konta.
- **Telefon** jest normalizowany do formatu +48…. Telefon niepoprawny albo już użyty przez inne konto zostaje pusty (patrz raport); właściciel uzupełni go w profilu.
- **E-mail importowanych kont jest uznany za potwierdzony** (działał na starej stronie). Zmianę hasła i tak trzeba potwierdzić linkiem z tej skrzynki.
- **Limity z presetu obowiązują** (liczba i rozmiar zdjęć, typy dokumentów). To, co ich nie spełnia, trafia do raportu jako „odrzucone pliki”.
- **Raporty** i plik przekierowań leżą w `storage/import/` (poza Gitem i poza `public_html`).
