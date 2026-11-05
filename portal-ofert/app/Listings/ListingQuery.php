<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;

/**
 * Wyszukiwanie opublikowanych ofert z filtrami generowanymi z presetu.
 *
 * Parametry URL (czytelne, do linków w kampaniach):
 *   ?kategoria=slug&q=tekst&sort=najnowsze&strona=2
 *   ?<klucz>=opcja                (select, region)
 *   ?<klucz>[]=a&<klucz>[]=b      (checkboxes — dowolna z zaznaczonych)
 *   ?<klucz>_od=1000&<klucz>_do=5000   (range; kwoty w PLN)
 *   ?<klucz>=1                    (toggle)
 *
 * Bezpieczeństwo informacji: filtrować można tylko po polach, które użytkownik widzi
 * (gość — poziom 1, zalogowany — do 2). Inaczej filtr zdradzałby ukryte wartości.
 * Nieznane / niepoprawne parametry są ignorowane (nigdy błąd 500 z linku z kampanii).
 */
final class ListingQuery
{
    public const PER_PAGE = 12;
    public const SORTS = ['najnowsze', 'najstarsze'];

    /** @return array<string,array> pola, po których ten użytkownik może filtrować */
    public static function filterFields(int $maxLevel): array
    {
        return array_filter(Fields::all(), static fn($f) => $f['filterable'] && $f['filter_ui'] !== 'none'
            && $f['access_level'] <= min($maxLevel, 2));
    }

    /**
     * Sortowanie po polach liczbowych z filtrem zakresu, które są też na kafelku (show_in_card) —
     * czyli tych, które użytkownik widzi na liście (np. kapitał, cena, ROI).
     */
    public static function sortOptions(int $maxLevel): array
    {
        $opts = ['najnowsze' => null, 'najstarsze' => null];
        foreach (self::filterFields($maxLevel) as $key => $f) {
            if ($f['filter_ui'] === 'range' && $f['show_in_card'] && in_array($f['type'], ['money', 'int', 'decimal', 'percent'], true)) {
                $opts[$key . '_rosnaco'] = $f;
                $opts[$key . '_malejaco'] = $f;
            }
        }
        return $opts;
    }

    /**
     * @return array{items:array[],total:int,page:int,pages:int,applied:array<string,mixed>}
     */
    public static function search(array $params, int $maxLevel, int $perPage = self::PER_PAGE): array
    {
        $where = ["l.status = 'published'"];
        $args = [];
        $applied = [];

        // Kategoria (z podkategoriami)
        $slug = self::str($params['kategoria'] ?? '');
        if ($slug !== '') {
            $cat = DB::fetch('SELECT id FROM categories WHERE slug = ? AND is_active = 1', [$slug]);
            if ($cat !== null) {
                $ids = self::categoryWithChildren((int) $cat['id']);
                $where[] = 'l.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($args, ...$ids);
                $applied['kategoria'] = $slug;
            }
        }

        // Tekst (FULLTEXT dla słów ≥ 3 znaków, inaczej LIKE)
        $q = mb_substr(self::str($params['q'] ?? ''), 0, 100);
        if ($q !== '') {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $q) ?: [], static fn($w) => mb_strlen($w) >= 3));
            if ($words !== []) {
                $where[] = 'MATCH(l.title, l.short_desc) AGAINST (? IN BOOLEAN MODE)';
                $args[] = implode(' ', array_map(static fn($w) => '+' . $w . '*', $words));
            } else {
                $where[] = '(l.title LIKE ? OR l.short_desc LIKE ?)';
                $like = '%' . addcslashes($q, '%_\\') . '%';
                array_push($args, $like, $like);
            }
            $applied['q'] = $q;
        }

        // Filtry z presetu
        foreach (self::filterFields($maxLevel) as $key => $f) {
            $fid = $f['id'];
            switch ($f['filter_ui']) {
                case 'select':
                case 'checkboxes':
                    $wanted = self::optionIds($f, $params[$key] ?? null);
                    if ($wanted === []) {
                        break;
                    }
                    $in = implode(',', array_fill(0, count($wanted), '?'));
                    $where[] = $f['type'] === 'multiselect'
                        ? "EXISTS (SELECT 1 FROM listing_value_options o WHERE o.listing_id = l.id AND o.field_id = ? AND o.option_id IN ({$in}))"
                        : "EXISTS (SELECT 1 FROM listing_values v WHERE v.listing_id = l.id AND v.field_id = ? AND v.v_option_id IN ({$in}))";
                    array_push($args, $fid, ...array_values($wanted));
                    $applied[$key] = array_keys($wanted);
                    break;

                case 'range':
                    $col = match ($f['type']) {
                        'money' => 'v_dec_pln',
                        'int' => 'v_int',
                        'decimal', 'percent' => 'v_dec',
                        'date' => 'v_date',
                        default => null,
                    };
                    if ($col === null) {
                        break;
                    }
                    foreach (['od' => '>=', 'do' => '<='] as $suffix => $op) {
                        $raw = self::str($params[$key . '_' . $suffix] ?? '');
                        $num = $col === 'v_date' ? self::date($raw) : self::number($raw);
                        if ($num === null) {
                            continue;
                        }
                        $where[] = "EXISTS (SELECT 1 FROM listing_values v WHERE v.listing_id = l.id AND v.field_id = ? AND v.{$col} {$op} ?)";
                        array_push($args, $fid, $num);
                        $applied[$key . '_' . $suffix] = $num;
                    }
                    break;

                case 'toggle':
                    if (in_array(self::str($params[$key] ?? ''), ['1', 'on', 'tak'], true)) {
                        $where[] = 'EXISTS (SELECT 1 FROM listing_values v WHERE v.listing_id = l.id AND v.field_id = ? AND v.v_int = 1)';
                        $args[] = $fid;
                        $applied[$key] = '1';
                    }
                    break;
            }
        }

        // Sortowanie
        $sortKey = self::str($params['sort'] ?? 'najnowsze');
        $sorts = self::sortOptions($maxLevel);
        $order = 'l.is_featured DESC, l.published_at DESC, l.id DESC';
        $join = '';
        if ($sortKey === 'najstarsze') {
            $order = 'l.published_at ASC, l.id ASC';
            $applied['sort'] = $sortKey;
        } elseif (isset($sorts[$sortKey]) && $sorts[$sortKey] !== null) {
            $f = $sorts[$sortKey];
            $col = match ($f['type']) { 'money' => 'v_dec_pln', 'int' => 'v_int', default => 'v_dec' };
            $dir = str_ends_with($sortKey, '_rosnaco') ? 'ASC' : 'DESC';
            $join = 'LEFT JOIN listing_values sv ON sv.listing_id = l.id AND sv.field_id = ' . (int) $f['id'];
            // Oferty bez wartości zawsze na końcu.
            $order = "sv.{$col} IS NULL, sv.{$col} {$dir}, l.id DESC";
            $applied['sort'] = $sortKey;
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM listings l WHERE {$sqlWhere}", $args);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, (int) self::str($params['strona'] ?? '1')));
        $offset = ($page - 1) * $perPage;

        $rows = DB::fetchAll(
            "SELECT l.id, l.slug, l.title, l.short_desc, l.region, l.city, l.category_id, l.is_featured, l.published_at, l.user_id, l.status,
                    c.name AS category_name,
                    (SELECT m.id FROM media m WHERE m.listing_id = l.id AND m.access_level = 1 AND m.state IN ('live', 'pending_remove')
                      ORDER BY m.is_cover DESC, m.sort_order LIMIT 1) AS cover_id
             FROM listings l JOIN categories c ON c.id = l.category_id {$join}
             WHERE {$sqlWhere} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
            $args
        );
        $values = ValueStore::loadMany(array_map(static fn($r) => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['values'] = $values[(int) $r['id']] ?? [];
        }
        unset($r);

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'applied' => $applied];
    }

    /** Najnowsze oferty na stronę główną. */
    public static function latest(int $limit, int $maxLevel): array
    {
        return self::search([], $maxLevel, $limit)['items'];
    }

    /** @return array<string,int> klucz opcji => id (tylko istniejące) */
    private static function optionIds(array $f, mixed $raw): array
    {
        $keys = is_array($raw) ? $raw : (is_scalar($raw) && $raw !== '' ? explode(',', (string) $raw) : []);
        $out = [];
        foreach (array_slice($keys, 0, 30) as $k) {
            $k = (string) $k;
            if (isset($f['options'][$k])) {
                $out[$k] = $f['options'][$k]['id'];
            }
        }
        return $out;
    }

    private static function categoryWithChildren(int $id): array
    {
        $ids = [$id];
        $frontier = [$id];
        for ($depth = 0; $depth < 5 && $frontier !== []; $depth++) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $frontier = array_map('intval', array_column(DB::fetchAll("SELECT id FROM categories WHERE parent_id IN ({$in}) AND is_active = 1", $frontier), 'id'));
            $ids = array_merge($ids, $frontier);
        }
        return array_values(array_unique($ids));
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** „1 000 000”, „1,5 mln” nie — tylko liczby; nieczytelne = brak filtra. */
    private static function number(string $s): ?float
    {
        $s = (string) preg_replace('/[\s\x{00A0}]|zł|pln|%/iu', '', $s);
        $s = str_replace(',', '.', $s);
        return $s !== '' && is_numeric($s) ? (float) $s : null;
    }

    private static function date(string $s): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s ? $s : null;
    }

    /** Maksymalny poziom treści dla listy (gość 1, zalogowany z aktywnym kontem 2). */
    public static function levelFor(?array $user): int
    {
        return $user !== null && \App\Http\Middleware\RequireVerifiedEmail::isVerifiedOrInGrace($user) ? 2 : 1;
    }
}
