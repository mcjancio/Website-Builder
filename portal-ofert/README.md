# Portal Ofert — uniwersalny silnik portalu ofert B2B

Koncepcja następcy portalu **inwestycje.finansedewelopera.pl** (dziś WordPress + WooCommerce), przepisanego na **czysty PHP 8.2 + MySQL + HTML/CSS/vanilla JS** pod standardowy hosting **cPanel** — prostszy w utrzymaniu, odporny na „rozsypywanie się” po aktualizacjach wtyczek i używalny dla innych branż dzięki presetom.

| Plik | Zawartość |
|---|---|
| [`KONCEPCJA.md`](KONCEPCJA.md) | Wnioski z obecnego portalu, cele, 3 poziomy dostępu, role, procesy (rejestracja NIP+SMS, moderacja, zapytania), architektura, bezpieczeństwo, integracje, AI, plan wdrożenia |
| [`schema.sql`](schema.sql) | Schemat bazy MySQL/MariaDB — uniwersalny (pola ofert definiowane danymi, nie kodem) |
| [`presets/inwestycje-deweloperskie.json`](presets/inwestycje-deweloperskie.json) | Preset wdrożenia referencyjnego: kategorie, wszystkie kryteria ofert (typ, etap, kapitał, ROI, formy inwestycji, 10 form zabezpieczenia…), poziomy dostępu, teksty |
| [`presets/sprzedaz-firm.json`](presets/sprzedaz-firm.json) | Przykład innej branży na tym samym silniku |
| [`PROMPT-BUDOWY.md`](PROMPT-BUDOWY.md) | Prompt dla Claude do zbudowania kodu w 7 etapach (rdzeń → konta → oferty → front → panele → produkcja → AI) |

**Nowy portal dla innego klienta** = subdomena w cPanelu + `config.php` + nowy plik presetu + logo.
