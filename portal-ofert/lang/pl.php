<?php

declare(strict_types=1);

/**
 * WSZYSTKIE teksty interfejsu. Kod i widoki używają wyłącznie __('klucz').
 * Parametry w nawiasach klamrowych: {field}, {arg}…
 * Test tests/LangTest sprawdza, czy każdy klucz użyty w kodzie istnieje w tym pliku.
 */

return [
    // Ogólne
    'app.default_name' => 'Portal Ofert',
    'app.skip_to_content' => 'Przejdź do treści',
    'app.footer_rights' => 'Wszelkie prawa zastrzeżone.',
    'app.menu' => 'Menu',
    'app.main_nav' => 'Nawigacja główna',

    // Strona główna (etap 1 — szkielet)
    'home.lead' => 'Projekty i oferty inwestycyjne dla zweryfikowanych firm i inwestorów.',
    'home.categories_title' => 'Rodzaje ofert',
    'home.coming_soon' => 'Portal jest w przygotowaniu. Lista ofert pojawi się wkrótce.',
    'home.no_categories' => 'Brak kategorii — zaimportuj preset: php bin/import-preset.php presets/<plik>.json',

    // Strony błędów
    'errors.404.title' => 'Nie znaleziono strony',
    'errors.404.text' => 'Ta strona nie istnieje albo została przeniesiona.',
    'errors.405.title' => 'Niedozwolona metoda',
    'errors.405.text' => 'Tej strony nie można otworzyć w ten sposób.',
    'errors.419.title' => 'Sesja wygasła',
    'errors.419.text' => 'Formularz był otwarty zbyt długo albo sesja wygasła. Odśwież stronę i spróbuj ponownie.',
    'errors.429.title' => 'Zbyt wiele prób',
    'errors.429.text' => 'Spróbuj ponownie za kilka minut.',
    'errors.500.title' => 'Błąd serwera',
    'errors.500.text' => 'Coś poszło nie tak po naszej stronie. Spróbuj ponownie za chwilę — jeśli problem się powtarza, napisz do nas.',
    'errors.back_home' => 'Wróć na stronę główną',

    // Walidacja
    'validation.required' => 'Pole „{field}” jest wymagane.',
    'validation.accepted' => 'Zaznacz „{field}”, aby kontynuować.',
    'validation.email' => 'Wpisz poprawny adres e-mail.',
    'validation.maxlength' => 'Pole „{field}” może mieć najwyżej {arg} znaków.',
    'validation.minlength' => 'Pole „{field}” musi mieć co najmniej {arg} znaków.',
    'validation.int' => 'Pole „{field}” musi być liczbą całkowitą.',
    'validation.decimal' => 'Pole „{field}” musi być liczbą.',
    'validation.min' => 'Wartość pola „{field}” musi wynosić co najmniej {arg}.',
    'validation.max' => 'Wartość pola „{field}” może wynosić najwyżej {arg}.',
    'validation.date' => 'Wpisz datę w formacie RRRR-MM-DD.',
    'validation.in' => 'Wybierz jedną z dostępnych opcji w polu „{field}”.',
    'validation.url' => 'Wpisz pełny adres strony zaczynający się od https://',
    'validation.phone_pl' => 'Wpisz poprawny numer telefonu, np. +48 600 100 200.',
    'validation.nip' => 'Numer NIP jest niepoprawny — sprawdź 10 cyfr.',
];
