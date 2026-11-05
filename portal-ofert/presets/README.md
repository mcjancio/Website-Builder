# Presety — jak dostosować portal do dowolnych ofert

Silnik portalu **nie zna żadnej branży**. Wszystko, co odróżnia portal inwestycji deweloperskich od giełdy maszyn czy sprzedaży firm, jest w jednym pliku JSON — presecie. Kod się nie zmienia.

| Preset | Do czego |
|---|---|
| `szablon-nowego-portalu.json` | Punkt startowy dla nowego klienta |
| `inwestycje-deweloperskie.json` | Projekty deweloperskie szukające kapitału (wdrożenie referencyjne) |
| `sprzedaz-firm.json` | Firmy na sprzedaż / sukcesja |
| `maszyny-i-sprzet.json` | Używane maszyny — przykład bez NIP, z polami zależnymi od kategorii |

## Nowy portal w 5 krokach

1. `cp presets/szablon-nowego-portalu.json presets/moj-portal.json` i zmień `"preset": "moj-portal"`.
2. Uzupełnij sekcje (opis niżej). Sprawdź plik: `php bin/import-preset.php presets/moj-portal.json --validate`
3. Podejrzyj zmiany: `… --dry-run`
4. Zainstaluj: `php bin/install.php --preset=presets/moj-portal.json`
5. Dalsze zmiany kryteriów: edytuj plik i zaimportuj ponownie (albo — od etapu 5 — w panelu admina).

## Sekcje presetu

| Sekcja | Co ustawia | Przykład |
|---|---|---|
| `portal` | Nazwa, hasło, waluty, kolory (`theme.accent`, tryb jasny/ciemny), komunikat o treści dla zalogowanych | `"accent": "#E07A00"` |
| `registration` | Czy wymagany NIP, czy osoby prywatne, SMS, potwierdzenie e-mail | sprzedaż firm: NIP opcjonalny |
| `limits` | Długość krótkiego opisu, liczba zdjęć i załączników, domyślny poziom dostępu plików | `"short_desc_max": 287` |
| `access_levels` | Nazwy i opisy poziomów L1/L2/L3 | „Dopuszczony — po NDA” |
| `terms` | **Słownictwo interfejsu** — nadpisuje dowolny tekst z `lang/pl.php` | `"term.listing": "Inwestycja"` |
| `account_types` | Typy kont do wyboru przy rejestracji | deweloper / inwestor / doradca |
| `categories` | Drzewo kategorii (`parent` = slug rodzica) | Maszyny budowlane → Koparki |
| `fields` | Kryteria ofert: typ, grupa, wymagalność, poziom dostępu, filtr, widoczność na kafelku | patrz niżej |
| `pages` | Strony treści (Markdown), opcjonalnie w menu | Zasady współpracy, Kontakt |
| `ai` | Ustawienia asystenta AI (etap 7) | anonimizacja pól na L1 |

### Słownictwo (`terms`)

Neutralne domyślne: **Oferta / Oferty / Dodaj ofertę / Kategorie / Oferujący / Zainteresowany**. Preset może podmienić każdy klucz z `lang/pl.php` (np. `home.lead`, komunikaty). Nieznany klucz = błąd walidacji, więc literówka nie przejdzie po cichu.

### Pola (`fields`)

| Właściwość | Wartości |
|---|---|
| `key` | stały identyfikator (`a-z`, `0-9`, `_`) — **nie zmieniaj po starcie portalu**; etykietę możesz zmieniać dowolnie |
| `type` | `text`, `textarea`, `int`, `decimal`, `money` (z walutą), `percent`, `date`, `select`, `multiselect`, `bool`, `url`, `url_list`, `region` (województwa) |
| `options` | dla `select`/`multiselect`: `[["klucz", "Etykieta"], …]` |
| `access_level` | `1` publiczne, `2` zalogowani, `3` po nadaniu dostępu (np. NDA) |
| `filterable` + `filter_ui` | filtr na liście: `select`, `checkboxes`, `range` (od–do), `toggle` |
| `show_in_card` | pokaż na kafelku listy ofert |
| `required`, `group`, `help` | wymagalność, sekcja formularza, podpowiedź |
| `validation` | np. `{"min": 1950, "max": 2027}`, `{"allow_past_years": 5}` |
| `categories` | pole tylko w wybranych kategoriach (np. „Pojemność łyżki” tylko dla koparek); brak = wszystkie |

### Pola o specjalnym znaczeniu

- `region` (typ `region`) i `city` (typ `text`) — jeśli preset ma pola o **tych kluczach**, ich wartości trafiają też do kolumn oferty i służą do filtrów lokalizacji oraz adresów SEO. Nazwy (etykiety) mogą być dowolne.
- Pola `money` zapisują kwotę, walutę i równowartość w PLN (kurs średni NBP z dnia zapisu) — filtr „od–do” porównuje oferty w różnych walutach.

### Limity (`limits`)

| Klucz | Domyślnie | Znaczenie |
|---|---|---|
| `title_max` | 120 | długość tytułu |
| `short_desc_max` | 300 | krótki opis (publiczny) |
| `images_max`, `image_max_mb` | 20, 10 | zdjęcia (→ WebP 400/960/1600 px, bez EXIF) |
| `attachments_max`, `attachment_max_mb` | 25, 25 | dokumenty |
| `attachment_mime` | PDF | dozwolone typy (PDF, DOCX, XLSX, PPTX, JPG, PNG) |
| `attachment_default_level` | 3 | domyślny poziom dostępu nowego dokumentu |

### Zasady bezpieczeństwa importu

- Import można powtarzać — niczego nie dubluje i niczego nie usuwa.
- Usunięte z pliku pola/opcje/kategorie znikają z portalu dopiero z `--sync` — i są **ukrywane**, nie kasowane (stare oferty zachowują dane).
- Typu pola, które ma już wartości w ofertach, nie da się zmienić — dodaj nowe pole z innym `key`.
- `portal`, `terms`, strony treści itd. zmienione później w panelu nie są nadpisywane, chyba że użyjesz `--overwrite`.
