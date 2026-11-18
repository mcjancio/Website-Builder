# maciej-jankowski.com — wersja statyczna

Statyczna wersja strony (wcześniej WordPress + Elementor). Treść 1:1 z poprzedniej strony, bez buildów i bez PHP.

## Struktura

| Plik / katalog | Opis |
|---|---|
| `index.html` | Strona główna (biogram, LinkedIn, CTA do kalendarza) |
| `meeting/` | „Porozmawiajmy o współpracy” – kalendarz Zencal (slug `porozmawiajmy-o-wspolpracy`) |
| `kalendarz/` | Druga strona z kalendarzem Zencal (slug `lets-talk-about-your-business`), `noindex` jak dotąd poza menu |
| `meet/` | Przekierowanie `/meet` → `/meeting/` (dla hostingów bez `.htaccess`) |
| `styles.css` | Wspólne style |
| `fonts/` | Urbanist + Mulish (variable woff2, lokalnie – bez Google Fonts / RODO-friendly) |
| `img/` | Zdjęcie hero w AVIF/WebP/JPG (640/1024/1920), obraz OG, ikony |
| `.htaccess` | HTTPS + www, przekierowania starych adresów WP, nagłówki bezpieczeństwa, cache |
| `404.html`, `robots.txt`, `sitemap.xml` | Standard SEO |

## Wdrożenie na obecny hosting (FTP / menedżer plików)

1. Zrób kopię zapasową WordPressa (pliki + baza).
2. Usuń z katalogu domeny pliki WordPressa (`wp-admin`, `wp-content`, `wp-includes`, `wp-*.php`, `index.php`, `xmlrpc.php`, stary `.htaccess`).
3. Wgraj **zawartość** tego katalogu (łącznie z ukrytym `.htaccess`).
4. Sprawdź: `/`, `/meeting/`, `/meet`, `/kalendarz/`, nieistniejący adres (404), oraz kalendarz Zencal na telefonie.

## Wdrożenie na Hostilo.app

Wgraj cały katalog (lub ZIP). `.htaccess` jest tam ignorowany – przekierowanie `/meet` obsługuje `meet/index.html`.

## Edycja treści

Tekst biogramu jest w `index.html` w sekcji `<div class="bio">`. Link do LinkedIn występuje w `index.html` i w stopkach podstron.
