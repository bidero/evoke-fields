<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — tłumaczenia wartości pól (1.70.0).
 *
 * JĘZYKI PRZYCHODZĄ Z ZEWNĄTRZ. Fields nie zna żadnej wtyczki wielojęzycznej:
 * listę języków i bieżący język podaje filtr (w praktyce Evoke ONE, gdy jego
 * moduł Tłumaczeń jest włączony):
 *
 *   evk_fields_jezyki           [ 'en' => 'English', 'de' => 'Deutsch' ] —
 *                               pusta tablica = funkcja wyłączona, panel i front
 *                               bez żadnych zmian;
 *   evk_fields_biezacy_jezyk    kod języka strony, którą właśnie składamy;
 *   evk_fields_jezyk_podstawowy język wpisywany w zwykłych polach ('pl');
 *   evk_fields_url_jezyka       adres z pola Link → adres w danym języku
 *                               (wewnętrzny na wersję językową, zewnętrzny bez
 *                               zmian — to wie tylko wtyczka od języków).
 *
 * CO SIĘ TŁUMACZY: Tekst, Tekst wielowierszowy, Edytor WYSIWYG i etykieta
 * w polu Link — także jako podpola repeaterów, na każdym poziomie. Pole
 * z zaznaczonym „Nie tłumacz" (kreator pola) zostaje takie samo we wszystkich
 * językach. Adres w polu Link idzie przez `evk_fields_url_jezyka` zawsze.
 *
 * GDZIE: wpisy i typy treści, termy taksonomii, strony ustawień. Profil
 * użytkownika i media — bez pól języków (tam tylko adres Linku się zmienia).
 *
 * ZAPIS obok oryginału, pod kluczem `evk_tl_{język}__{klucz}` — tak samo jak
 * pola języków w elementach Bricksa (Evoke ONE):
 *   · grupa pojedyncza na wpisie/termie — osobna meta;
 *   · wiersz repeatera — klucz w wierszu (przenosi się razem z wierszem);
 *   · strona ustawień — klucz w tablicy opcji grupy.
 * Obok leży `…__zrodlo`: skrót tekstu podstawowego, z którego powstało
 * tłumaczenie. Inny niż skrót bieżącego tekstu = „Do sprawdzenia".
 *
 * ODCZYT (tagi {evk_field_…}, pętle, {evk_opt_…}, evk_get_field()): w języku
 * innym niż podstawowy — tłumaczenie, a gdy puste: tekst podstawowy (słownik
 * wtyczki od języków może go jeszcze przetłumaczyć na stronie). Pusty tekst
 * podstawowy zostaje pusty także w innych językach — wyczyszczenie pola
 * wyłącza je wszędzie. W builderze Bricksa i w panelu — zawsze tekst podstawowy.
 * Surowe dane (evk_rows(), evk_get_option_field()) zostają surowe: bliźniaki
 * leżą w wierszu i w tablicy opcji pod swoimi kluczami.
 */

// =========================================================================
// JĘZYKI I KLUCZE
// =========================================================================

/** Język wpisywany w zwykłych polach. */
function evk_rep_tl_default(): string {
    $kod = sanitize_key((string) apply_filters('evk_fields_jezyk_podstawowy', 'pl'));
    return $kod !== '' ? $kod : 'pl';
}

/**
 * Języki tłumaczeń: kod => nazwa, BEZ podstawowego. Pusta = funkcja wyłączona.
 * Bez pamięci podręcznej: filtr jest tani, a wtyczka od języków może go
 * powiesić później niż pierwsze wywołanie.
 *
 * @return array<string,string>
 */
function evk_rep_tl_langs(): array {
    $out  = [];
    $baza = evk_rep_tl_default();
    foreach ((array) apply_filters('evk_fields_jezyki', []) as $kod => $nazwa) {
        $kod = sanitize_key((string) $kod);
        if ($kod === '' || $kod === $baza) continue;
        $out[$kod] = is_string($nazwa) && trim($nazwa) !== '' ? trim($nazwa) : strtoupper($kod);
    }
    return $out;
}

/** Typy pól, których wartość się tłumaczy (w Linku: etykieta). */
function evk_rep_tl_types(): array {
    return ['text', 'textarea', 'wysiwyg', 'link'];
}

/**
 * Czy typ pola ma wersje językowe — bez patrzenia na „Nie tłumacz". Zapis
 * trzyma się TEGO warunku: przełączenie „Nie tłumacz" chowa tłumaczenia,
 * ale ich nie kasuje, więc odznaczenie przywraca je w całości.
 */
function evk_rep_tl_type_on(array $field): bool {
    return in_array($field['type'] ?? 'text', evk_rep_tl_types(), true);
}

/** Czy wartość tego pola ma wersje językowe (panel i odczyt). */
function evk_rep_tl_field_on(array $field): bool {
    return evk_rep_tl_type_on($field) && empty($field['no_translate']);
}

/** Czy lista pól (rekurencyjnie, z podpolami repeaterów) ma choć jedno pole tłumaczalne. */
function evk_rep_tl_fields_have(array $fields): bool {
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        if (($f['type'] ?? '') === 'repeater') {
            if (evk_rep_tl_fields_have((array) ($f['sub_fields'] ?? []))) return true;
        } elseif (evk_rep_tl_field_on($f)) {
            return true;
        }
    }
    return false;
}

function evk_rep_tl_key(string $lang, string $key): string {
    return 'evk_tl_' . $lang . '__' . $key;
}

/** Klucz bliźniaka dowolnego języka (`evk_tl_en__tytul`, bez `__zrodlo`) → [język, klucz pola]. */
function evk_rep_tl_parse_key(string $key): ?array {
    if (!preg_match('/^evk_tl_([a-z0-9_]+?)__(.+)$/', $key, $m)) return null;
    if (substr($m[2], -8) === '__zrodlo') return null;
    return [$m[1], $m[2]];
}

/** Tekst podstawowy pola, od którego liczy się skrót (w Linku: etykieta). */
function evk_rep_tl_base_text(array $field, $val): string {
    if (($field['type'] ?? '') === 'link') return is_array($val) ? (string) ($val['title'] ?? '') : '';
    return is_scalar($val) ? (string) $val : '';
}

/** Czy tekst jest pusty po zdjęciu znaczników (pusty akapit z edytora to też pustka). */
function evk_rep_tl_empty(string $tekst): bool {
    return trim(str_replace("\xC2\xA0", ' ', html_entity_decode(wp_strip_all_tags($tekst), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '';
}

/**
 * Skrót tekstu: bez znaczników i encji, odstępy scalone — tyle, żeby wykryć
 * zmianę TREŚCI. Edytor WYSIWYG potrafi przestawić znaczniki bez udziału
 * człowieka (akapity, kolejność atrybutów), a to nie jest zmiana tekstu.
 * Bez ukośników wstecznych: meta wpisu przechodzi przez wp_unslash() i traci
 * je w drodze do bazy, opcja nie — skrót ma wyjść ten sam w obu miejscach.
 */
function evk_rep_tl_hash(string $tekst): string {
    $t = html_entity_decode(wp_strip_all_tags(str_replace('\\', '', $tekst)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    return $t === '' ? '' : substr(md5($t), 0, 12);
}

/**
 * Źródło zapisywane przy tłumaczeniu: skrót tekstu podstawowego, a przy
 * pustym — znak `pusty`, żeby późniejsze wpisanie tekstu też dało
 * „Do sprawdzenia".
 */
function evk_rep_tl_src(string $pl): string {
    $h = evk_rep_tl_hash($pl);
    return $h !== '' ? $h : 'pusty';
}

/**
 * Tłumaczenie AI jeszcze nieprzejrzane (1.75.0): źródło z przedrostkiem `ai-`.
 * Zdejmuje go „Sprawdzone" i każda ręczna zmiana tłumaczenia — wtedy źródło
 * to znów sam skrót (evk_rep_tl_clean()).
 */
function evk_rep_tl_ai(string $zrodlo): bool {
    return strpos($zrodlo, 'ai-') === 0;
}

/** Źródło bez znacznika AI — sam skrót tekstu podstawowego. */
function evk_rep_tl_src_hash(string $zrodlo): string {
    return evk_rep_tl_ai($zrodlo) ? substr($zrodlo, 3) : $zrodlo;
}

/** Czy tłumaczenie powstało z innego tekstu podstawowego niż bieżący. */
function evk_rep_tl_stale(string $tv, string $zrodlo, string $pl): bool {
    if (evk_rep_tl_empty($tv) || $zrodlo === '') return false;
    $h = evk_rep_tl_hash($pl);
    if ($h === '') return false;   // tekst podstawowy pusty — nie ma czego porównać
    return evk_rep_tl_src_hash($zrodlo) !== $h;
}

// =========================================================================
// ODCZYT NA FRONCIE
// =========================================================================

/** Język, w którym czytamy wartości: '' = podstawowy (panel, builder, brak tłumaczeń). */
function evk_rep_tl_reading_lang(): string {
    if (is_admin() && !wp_doing_ajax()) return '';
    if (function_exists('evk_rep_is_builder') && evk_rep_is_builder()) return '';
    $cur = sanitize_key((string) apply_filters('evk_fields_biezacy_jezyk', ''));
    return ($cur !== '' && isset(evk_rep_tl_langs()[$cur])) ? $cur : '';
}

/**
 * Wartość pola w bieżącym języku.
 *
 * @param mixed    $val  Wartość podstawowa (surowa, jak w bazie).
 * @param callable $twin fn(string $klucz): mixed — odczyt sąsiedniego klucza
 *                       z tego samego źródła (meta, wiersz, opcja).
 * @return mixed
 */
function evk_rep_tl_value(array $field, string $key, $val, callable $twin) {
    $lang = evk_rep_tl_reading_lang();
    if ($lang === '') return $val;
    if (($field['type'] ?? '') === 'link') {
        if (!is_array($val)) return $val;
        if (evk_rep_tl_field_on($field) && !evk_rep_tl_empty((string) ($val['title'] ?? ''))) {
            $t = $twin(evk_rep_tl_key($lang, $key));
            if (is_string($t) && !evk_rep_tl_empty($t)) $val['title'] = $t;
        }
        if (!empty($val['url'])) {
            $val['url'] = (string) apply_filters('evk_fields_url_jezyka', (string) $val['url'], $lang);
        }
        return $val;
    }
    if (!evk_rep_tl_field_on($field)) return $val;
    if (evk_rep_tl_empty(evk_rep_tl_base_text($field, $val))) return $val;
    $t = $twin(evk_rep_tl_key($lang, $key));
    return (is_string($t) && !evk_rep_tl_empty($t)) ? $t : $val;
}

// =========================================================================
// ZAPIS
// =========================================================================

/**
 * Tłumaczenie z formularza → [wartość, źródło]. Pusta wartość → ['', ''].
 *
 * Źródło = bieżący tekst podstawowy, gdy: tłumacz zmienił tłumaczenie (skrót
 * wysłany jako `__przed` nie pasuje), kliknął „Sprawdzone" (`__zrodlo` =
 * `teraz`) albo źródła jeszcze nie było. Inaczej zostaje stare — więc zmiana
 * samego tekstu podstawowego zostawia tłumaczenie „Do sprawdzenia".
 *
 * `__zrodlo` = `ai` (1.75.0): pole wypełnił przycisk AI i nikt go potem nie
 * poprawiał (translations.js) — źródło bieżące ze znacznikiem `ai-`.
 *
 * @param array<string,mixed> $post Dane formularza tego poziomu (wiersz, grupa).
 * @param mixed               $pl   Wartość podstawowa po sanitizacji.
 * @return array{0:string,1:string}
 */
function evk_rep_tl_clean(array $field, array $post, string $tk, $pl): array {
    $raw = $post[$tk] ?? '';
    $raw = is_scalar($raw) ? (string) $raw : '';
    $tv  = ($field['type'] ?? '') === 'link'
        ? sanitize_text_field($raw)
        : evk_rep_sanitize_value((string) ($field['type'] ?? 'text'), $raw, $field);
    $tv = is_string($tv) ? $tv : '';
    if (evk_rep_tl_empty($tv)) return ['', ''];
    $przed  = sanitize_key((string) ($post[$tk . '__przed'] ?? ''));
    $zrodlo = sanitize_key((string) ($post[$tk . '__zrodlo'] ?? ''));
    if ($zrodlo === 'ai') return [$tv, 'ai-' . evk_rep_tl_src(evk_rep_tl_base_text($field, $pl))];
    if ($zrodlo === '' || $zrodlo === 'teraz' || evk_rep_tl_hash($tv) !== $przed) {
        return [$tv, evk_rep_tl_src(evk_rep_tl_base_text($field, $pl))];
    }
    return [$tv, $zrodlo];
}

/**
 * Tłumaczenia jednego pola do tablicy wiersza / wartości grupy opcji.
 *
 * Bierze KAŻDY język obecny w formularzu, nie tylko włączone: wiersze i opcje
 * zapisują się w całości z formularza, więc tłumaczenie bez widocznego pola
 * (język wyłączony, „Nie tłumacz") wraca tu jako pole ukryte
 * (evk_rep_tl_render_carry()) i ma przetrwać zapis.
 *
 * @param array<string,mixed> $out  Wiersz / wartości grupy (dopisujemy).
 * @param array<string,mixed> $post Dane formularza tego poziomu.
 * @param mixed               $pl   Wartość podstawowa po sanitizacji.
 */
function evk_rep_tl_collect(array &$out, string $fkey, array $field, array $post, $pl): void {
    if (!evk_rep_tl_type_on($field)) return;
    foreach (array_keys($post) as $k) {
        $p = evk_rep_tl_parse_key((string) $k);
        if (!$p || $p[1] !== $fkey) continue;
        [$tv, $src] = evk_rep_tl_clean($field, $post, (string) $k, $pl);
        if ($tv === '') continue;
        $out[(string) $k]     = $tv;
        $out[$k . '__zrodlo'] = $src;
    }
}

/**
 * Tłumaczenia pola grupy pojedynczej (osobne meta). Język bez pola
 * w formularzu zostaje nietknięty — meta nie zapisuje się w całości.
 *
 * @param array<string,mixed> $post Tablica `evk_single` z formularza.
 * @param mixed               $pl   Wartość podstawowa po sanitizacji.
 */
function evk_rep_tl_save_meta(string $meta_type, int $object_id, string $fkey, array $field, array $post, $pl): void {
    if (!evk_rep_tl_type_on($field)) return;
    foreach (array_keys($post) as $k) {
        $p = evk_rep_tl_parse_key((string) $k);
        if (!$p || $p[1] !== $fkey) continue;
        [$tv, $src] = evk_rep_tl_clean($field, $post, (string) $k, $pl);
        if ($tv === '') {
            delete_metadata($meta_type, $object_id, (string) $k);
            delete_metadata($meta_type, $object_id, $k . '__zrodlo');
        } else {
            update_metadata($meta_type, $object_id, (string) $k, wp_slash($tv));
            update_metadata($meta_type, $object_id, $k . '__zrodlo', $src);
        }
    }
}

// =========================================================================
// API DLA WTYCZKI OD JĘZYKÓW (1.75.0): teksty pól wpisu i zapis tłumaczeń
// =========================================================================

/*
 * Tłumaczenie hurtem (np. AI w Evoke ONE) potrzebuje tekstów pól wpisu bez
 * formularza. Fields dalej nie zna żadnego tłumacza: podaje teksty, przyjmuje
 * tłumaczenie i zapisuje je tak jak formularz — bliźniak `evk_tl_{język}__{klucz}`
 * obok oryginału i źródło obok niego.
 *
 * Klucz miejsca: `{meta}|{ścieżka}`. Grupa pojedyncza — meta pola, ścieżka
 * pusta (`tytul|`). Wiersz repeatera — meta repeatera i ścieżka
 * `{wiersz}.{pole}`, głębiej `{wiersz}.{pole}.{wiersz}.{pole}` (`faq|2.pytanie`).
 */

/** Obiekty z tekstami pól (1.77.0): wpisy i termy taksonomii. */
const EVK_REP_TL_OBIEKTY = ['post', 'term'];

/**
 * Grupy pól wpisu (typ treści) albo termu (taksonomia, 1.77.0) — te same,
 * które dostają metabox albo formularz termu.
 *
 * @return array<string,array<string,mixed>>
 */
function evk_rep_tl_grupy_wpisu(int $post_id, string $obiekt = 'post'): array {
    if ($obiekt === 'term') {
        $term = get_term($post_id);
        return $term instanceof \WP_Term ? array_map(static function ($g) { return (array) $g; }, evk_rep_groups_for_taxonomy((string) $term->taxonomy)) : [];
    }
    $pt = (string) get_post_type($post_id);
    if ($pt === '') return [];
    $out = [];
    foreach (evk_rep_groups() as $key => $g) {
        if (($g['object_type'] ?? 'post') !== 'post' || !in_array($pt, (array) ($g['post_types'] ?? []), true)) continue;
        $out[(string) $key] = $g;
    }
    return $out;
}

/**
 * Pola tłumaczone jednego poziomu wierszy → miejsca (rekurencyjnie w podrepeaterach).
 *
 * @param array<string,array> $fields
 * @param mixed               $rows
 * @param array<int,array<string,mixed>> $out
 */
function evk_rep_tl_miejsca_wierszy(string $meta, string $sciezka, string $grupa, string $opis, array $fields, $rows, array &$out): void {
    if (!is_array($rows)) return;
    foreach (array_values($rows) as $i => $row) {
        if (!is_array($row)) continue;
        foreach ($fields as $fk => $f) {
            $fk = (string) $fk;
            $t  = (string) ($f['type'] ?? 'text');
            if (evk_rep_is_layout($t)) continue;
            $etyk = trim((string) ($f['label'] ?? '')) !== '' ? (string) $f['label'] : $fk;
            $gdzie = ($opis !== '' ? $opis . ' · ' : '') . 'pozycja ' . ($i + 1);
            if ($t === 'repeater') {
                evk_rep_tl_miejsca_wierszy($meta, $sciezka . $i . '.' . $fk . '.', $grupa, $gdzie . ' · ' . $etyk,
                    (array) ($f['sub_fields'] ?? []), $row[$fk] ?? [], $out);
                continue;
            }
            $twin = static function (string $k) use ($row) { return $row[$k] ?? null; };
            evk_rep_tl_miejsce($out, $meta . '|' . $sciezka . $i . '.' . $fk, $grupa, $gdzie . ' · ' . $etyk, $fk, $f, $row[$fk] ?? '', $twin);
        }
    }
}

/**
 * Jedno miejsce z tekstem podstawowym i stanem każdego języka.
 *
 * @param array<int,array<string,mixed>> $out
 * @param mixed    $val
 * @param callable $twin fn(string $klucz): mixed
 */
function evk_rep_tl_miejsce(array &$out, string $klucz, string $grupa, string $opis, string $fk, array $f, $val, callable $twin): void {
    if (!evk_rep_tl_field_on($f)) return;
    $pl = evk_rep_tl_base_text($f, $val);
    if (evk_rep_tl_empty($pl)) return;
    $m = ['klucz' => $klucz, 'grupa' => $grupa, 'opis' => $opis, 'typ' => (string) ($f['type'] ?? 'text'), 'pl' => $pl,
        'tl' => [], 'zrodlo' => [], 'ai' => [], 'stale' => []];
    foreach (array_keys(evk_rep_tl_langs()) as $lang) {
        $tk = evk_rep_tl_key($lang, $fk);
        $tv = $twin($tk);
        $tv = is_scalar($tv) ? (string) $tv : '';
        $z  = $twin($tk . '__zrodlo');
        $z  = is_scalar($z) ? (string) $z : '';
        $jest = !evk_rep_tl_empty($tv);
        $m['tl'][$lang]     = $jest ? $tv : '';
        $m['zrodlo'][$lang] = $jest ? $z : '';
        $m['ai'][$lang]     = $jest && evk_rep_tl_ai($z);
        $m['stale'][$lang]  = evk_rep_tl_stale($tv, $z, $pl);
    }
    $out[] = $m;
}

/**
 * Typy treści z grupami, które mają pola tłumaczone — tam szukać tekstów.
 *
 * @return list<string>
 */
function evk_fields_tl_typy(): array {
    if (!evk_rep_tl_langs()) return [];
    $pts = [];
    foreach (evk_rep_groups() as $g) {
        if (($g['object_type'] ?? 'post') !== 'post' || !evk_rep_tl_fields_have((array) ($g['fields'] ?? []))) continue;
        foreach ((array) ($g['post_types'] ?? []) as $pt) $pts[(string) $pt] = true;
    }
    return array_keys($pts);
}

/**
 * Taksonomie z grupami, które mają pola tłumaczone (1.77.0) — tam szukać
 * tekstów termów.
 *
 * @return list<string>
 */
function evk_fields_tl_taksonomie(): array {
    if (!evk_rep_tl_langs()) return [];
    $tx = [];
    foreach (evk_rep_groups_for_object('term') as $g) {
        if (!evk_rep_tl_fields_have((array) ($g['fields'] ?? []))) continue;
        foreach ((array) ($g['taxonomies'] ?? []) as $t) $tx[(string) $t] = true;
    }
    return array_keys($tx);
}

/** Obiekty, których teksty podaje API (1.77.0: także termy) — po tym wtyczka od języków poznaje wersję. */
function evk_fields_tl_obiekty(): array {
    return EVK_REP_TL_OBIEKTY;
}

/** Odczyt metadanej wpisu albo termu. @return mixed */
function evk_rep_tl_meta(string $obiekt, int $id, string $klucz) {
    return get_metadata($obiekt === 'term' ? 'term' : 'post', $id, $klucz, true);
}

/**
 * Teksty pól wpisu do tłumaczenia: pola z tłumaczeniami (bez „Nie tłumacz")
 * i z niepustym tekstem podstawowym, w kolejności grup i pól.
 *
 * @return list<array{klucz:string,grupa:string,opis:string,typ:string,pl:string,tl:array<string,string>,zrodlo:array<string,string>,ai:array<string,bool>,stale:array<string,bool>}>
 */
function evk_fields_tl_teksty(int $post_id, string $obiekt = 'post'): array {
    $out = [];
    if ($post_id <= 0 || !evk_rep_tl_langs() || !in_array($obiekt, EVK_REP_TL_OBIEKTY, true)) return $out;
    foreach (evk_rep_tl_grupy_wpisu($post_id, $obiekt) as $gkey => $g) {
        $grupa  = (string) ($g['label'] ?? $gkey);
        $fields = (array) ($g['fields'] ?? []);
        if (evk_rep_is_repeater($g)) {
            evk_rep_tl_miejsca_wierszy($gkey, '', $grupa, '', $fields, evk_rep_tl_meta($obiekt, $post_id, $gkey), $out);
            continue;
        }
        foreach ($fields as $fk => $f) {
            $fk = (string) $fk;
            $t  = (string) ($f['type'] ?? 'text');
            if (evk_rep_is_layout($t) || $t === 'calc') continue;
            $etyk = trim((string) ($f['label'] ?? '')) !== '' ? (string) $f['label'] : $fk;
            if ($t === 'repeater') {
                evk_rep_tl_miejsca_wierszy($fk, '', $grupa, $etyk, (array) ($f['sub_fields'] ?? []), evk_rep_tl_meta($obiekt, $post_id, $fk), $out);
                continue;
            }
            $twin = static function (string $k) use ($post_id, $obiekt) { return evk_rep_tl_meta($obiekt, $post_id, $k); };
            evk_rep_tl_miejsce($out, $fk . '|', $grupa, $etyk, $fk, $f, evk_rep_tl_meta($obiekt, $post_id, $fk), $twin);
        }
    }
    return $out;
}

/**
 * Definicja pola i położenie miejsca z klucza: [meta, pole, definicja, ścieżka wierszy].
 * Ścieżka wierszy to lista [indeks, pole repeatera] od góry, bez ostatniego pola.
 *
 * @return array{0:string,1:string,2:array<string,mixed>,3:list<array{0:int,1:string}>}|null
 */
function evk_rep_tl_znajdz(int $post_id, string $klucz, string $obiekt = 'post'): ?array {
    $p = strpos($klucz, '|');
    if ($p === false) return null;
    $meta = substr($klucz, 0, $p);
    $sciezka = substr($klucz, $p + 1);
    foreach (evk_rep_tl_grupy_wpisu($post_id, $obiekt) as $gkey => $g) {
        $fields = (array) ($g['fields'] ?? []);
        if (evk_rep_is_repeater($g)) {
            if ($gkey !== $meta || $sciezka === '') continue;
        } else {
            if (!isset($fields[$meta])) continue;
            $f = (array) $fields[$meta];
            if ($sciezka === '') return ($f['type'] ?? 'text') === 'repeater' ? null : [$meta, $meta, $f, []];
            if (($f['type'] ?? '') !== 'repeater') continue;
            $fields = (array) ($f['sub_fields'] ?? []);
        }
        $cz = explode('.', $sciezka);
        if (count($cz) % 2 !== 0) return null;
        $droga = [];
        for ($i = 0; $i < count($cz); $i += 2) {
            if (!ctype_digit($cz[$i]) || !isset($fields[$cz[$i + 1]])) return null;
            $f = (array) $fields[$cz[$i + 1]];
            if ($i + 2 < count($cz)) {
                if (($f['type'] ?? '') !== 'repeater') return null;
                $droga[] = [(int) $cz[$i], (string) $cz[$i + 1]];
                $fields = (array) ($f['sub_fields'] ?? []);
                continue;
            }
            $droga[] = [(int) $cz[$i], ''];
            return [$meta, (string) $cz[$i + 1], $f, $droga];
        }
    }
    return null;
}

/**
 * Zapis tłumaczenia jednego miejsca — jak formularz: bliźniak i źródło
 * (skrót bieżącego tekstu podstawowego; z `$ai` — ze znacznikiem `ai-`).
 * Pusty tekst usuwa tłumaczenie. Fałsz: nie ma takiego miejsca, języka albo
 * tekstu podstawowego.
 */
function evk_fields_tl_wpisz(int $post_id, string $klucz, string $lang, string $tekst, bool $ai = false, string $obiekt = 'post'): bool {
    return evk_rep_tl_zmien($post_id, $klucz, $lang, static function (array $f, string $pl, string $tv, string $z) use ($tekst, $ai): ?array {
        $nowy = ($f['type'] ?? '') === 'link' ? sanitize_text_field($tekst) : evk_rep_sanitize_value((string) ($f['type'] ?? 'text'), $tekst, $f);
        $nowy = is_string($nowy) ? $nowy : '';
        if (evk_rep_tl_empty($nowy)) return ['', ''];
        return [$nowy, ($ai ? 'ai-' : '') . evk_rep_tl_src($pl)];
    }, $obiekt);
}

/** „Sprawdzone": tłumaczenie bez zmian, źródło = bieżący tekst podstawowy, bez znacznika AI. */
function evk_fields_tl_sprawdzone(int $post_id, string $klucz, string $lang, string $obiekt = 'post'): bool {
    return evk_rep_tl_zmien($post_id, $klucz, $lang, static function (array $f, string $pl, string $tv, string $z): ?array {
        return evk_rep_tl_empty($tv) ? null : [$tv, evk_rep_tl_src($pl)];
    }, $obiekt);
}

/**
 * Wspólna droga zapisu: $zmiana(pole, tekst podstawowy, obecne tłumaczenie,
 * obecne źródło) → [tłumaczenie, źródło] albo null (bez zmian).
 */
function evk_rep_tl_zmien(int $post_id, string $klucz, string $lang, callable $zmiana, string $obiekt = 'post'): bool {
    $lang = sanitize_key($lang);
    if ($post_id <= 0 || !isset(evk_rep_tl_langs()[$lang]) || !in_array($obiekt, EVK_REP_TL_OBIEKTY, true)) return false;
    $gdzie = evk_rep_tl_znajdz($post_id, $klucz, $obiekt);
    if (!$gdzie) return false;
    [$meta, $fk, $f, $droga] = $gdzie;
    if (!evk_rep_tl_field_on($f)) return false;
    $tk = evk_rep_tl_key($lang, $fk);

    if (!$droga) {
        $pl = evk_rep_tl_base_text($f, evk_rep_tl_meta($obiekt, $post_id, $meta));
        if (evk_rep_tl_empty($pl)) return false;
        $tv = evk_rep_tl_meta($obiekt, $post_id, $tk);
        $z  = evk_rep_tl_meta($obiekt, $post_id, $tk . '__zrodlo');
        $w  = $zmiana($f, $pl, is_scalar($tv) ? (string) $tv : '', is_scalar($z) ? (string) $z : '');
        if ($w === null) return true;
        if ($w[0] === '') {
            delete_metadata($obiekt, $post_id, $tk);
            delete_metadata($obiekt, $post_id, $tk . '__zrodlo');
        } else {
            update_metadata($obiekt, $post_id, $tk, wp_slash($w[0]));
            update_metadata($obiekt, $post_id, $tk . '__zrodlo', $w[1]);
        }
        return true;
    }

    $rows = evk_rep_tl_meta($obiekt, $post_id, $meta);
    if (!is_array($rows)) return false;
    $rows = array_values($rows);
    $wez = &$rows;
    foreach ($droga as $n => [$i, $sub]) {
        if (!isset($wez[$i]) || !is_array($wez[$i])) return false;
        if ($sub === '') { $wez = &$wez[$i]; break; }
        if (!isset($wez[$i][$sub]) || !is_array($wez[$i][$sub])) return false;
        $wez[$i][$sub] = array_values($wez[$i][$sub]);
        $wez = &$wez[$i][$sub];
    }
    $pl = evk_rep_tl_base_text($f, $wez[$fk] ?? '');
    if (evk_rep_tl_empty($pl)) return false;
    $tv = $wez[$tk] ?? '';
    $z  = $wez[$tk . '__zrodlo'] ?? '';
    $w  = $zmiana($f, $pl, is_scalar($tv) ? (string) $tv : '', is_scalar($z) ? (string) $z : '');
    if ($w === null) return true;
    if ($w[0] === '') {
        unset($wez[$tk], $wez[$tk . '__zrodlo']);
    } else {
        $wez[$tk] = $w[0];
        $wez[$tk . '__zrodlo'] = $w[1];
    }
    unset($wez);
    /* Cała lista wierszy — update_metadata() zdejmuje ukośniki, więc wp_slash(). */
    update_metadata($obiekt, $post_id, $meta, wp_slash($rows));
    return true;
}

// =========================================================================
// PANEL: POLA TŁUMACZEŃ I PRZEŁĄCZNIK JĘZYKA
// =========================================================================

/**
 * Czy pola języków są teraz rysowane. Włącza je otwarcie grupy
 * z przełącznikiem (evk_rep_tl_group_open()), wyłącza jej zamknięcie —
 * profil użytkownika i media grupy nie otwierają, więc pól tam nie ma.
 */
function evk_rep_tl_ui(?bool $ustaw = null): bool {
    static $on = false;
    if ($ustaw !== null) $on = $ustaw;
    return $on;
}

/** Podgląd tekstu podstawowego nad polem tłumaczenia (bez znaczników, 160 znaków). */
function evk_rep_tl_preview(string $pl): string {
    $t = html_entity_decode(wp_strip_all_tags($pl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    return mb_strlen($t) > 160 ? mb_substr($t, 0, 159) . '…' : $t;
}

/**
 * Pola tłumaczeń pod polem (po jednym na język, widoczne tylko w widoku
 * danego języka). Link tłumaczy samą etykietę — pole tekstowe.
 *
 * Identyfikator pola powstaje z NAZWY, więc w szablonie wiersza zawiera jego
 * znacznik (`__INDEX__`) — admin.js podmienia go przy dodaniu wiersza i każdy
 * wiersz dostaje własny.
 *
 * @param string   $name Nazwa pola podstawowego w formularzu (`evk_single[tytul]`).
 * @param mixed    $val  Wartość podstawowa.
 * @param callable $twin fn(string $klucz): mixed — zapisane tłumaczenie.
 */
function evk_rep_tl_render_twins(string $fkey, array $field, string $name, $val, callable $twin): void {
    if (!evk_rep_tl_ui() || !evk_rep_tl_field_on($field)) return;
    $langs = evk_rep_tl_langs();
    if (!$langs) return;
    $ai = evk_rep_tl_ai_dane() !== null;

    $type  = (string) ($field['type'] ?? 'text');
    $pl    = evk_rep_tl_base_text($field, $val);
    $base  = (string) preg_replace('/\[[^\[\]]*\]$/', '', $name);   // `evk_single[tytul]` → `evk_single`
    $label = trim((string) ($field['label'] ?? '')) !== '' ? (string) $field['label'] : $fkey;
    $kod   = strtoupper(evk_rep_tl_default());
    $podgl = evk_rep_tl_preview($pl);
    $kopiuj = $kod === 'PL' ? 'Kopiuj z polskiego' : 'Kopiuj z ' . $kod;

    foreach ($langs as $lang => $lname) {
        $tk     = evk_rep_tl_key($lang, $fkey);
        $tname  = $base . '[' . $tk . ']';
        $tv     = $twin($tk);
        $tv     = is_scalar($tv) ? (string) $tv : '';
        $zrodlo = $twin($tk . '__zrodlo');
        $zrodlo = is_scalar($zrodlo) ? (string) $zrodlo : '';
        $id     = 'evk_tl_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tname);
        $L      = strtoupper($lang);
        $opis   = $type === 'link' ? $label . ' — etykieta ' . $L : $label . ' — ' . $L;

        echo '<div class="evk-tl-pole" data-lang="' . esc_attr($lang) . '" data-opis="' . esc_attr($label) . '"'
            . (evk_rep_tl_stale($tv, $zrodlo, $pl) ? ' data-sprawdz="1"' : '')
            . (!evk_rep_tl_empty($tv) && evk_rep_tl_ai($zrodlo) ? ' data-ai="1"' : '') . '>';
        /* Nagłówek (1.77.0): etykieta i przyciski w jednym wierszu; gdy się nie
           mieszczą (kolumna boczna, telefon), przyciski schodzą pod etykietę —
           `flex-wrap`, bez progu szerokości. */
        echo '<div class="evk-tl-naglowek">';
        echo '<label class="evk-tl-etykieta" for="' . esc_attr($id) . '">' . esc_html($opis) . '</label>';
        echo '<div class="evk-tl-narzedzia">';
        echo '<button type="button" class="button button-small evk-tl-kopiuj">' . esc_html($kopiuj) . '</button>';
        /* Przycisk AI (1.75.0) — tylko gdy wtyczka od języków podaje tłumacza (filtr `evk_fields_tl_ai`). */
        if ($ai) {
            echo '<button type="button" class="button button-small evk-tl-ai" aria-label="' . esc_attr('Przetłumacz (AI) — ' . $opis) . '">'
                . evk_rep_tl_ikona_ai() . '<span>Przetłumacz</span></button>';
        }
        echo '</div></div>';
        echo '<p class="evk-tl-oryginal"><span class="evk-tl-oryginal-jezyk">' . esc_html($kod) . ':</span> '
            . '<span class="evk-tl-oryginal-tekst">' . ($podgl !== '' ? esc_html($podgl) : '<em>(puste)</em>') . '</span></p>';
        if ($type === 'textarea') {
            $rows = max(3, (int) ($field['rows'] ?? 3));
            echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($tname) . '" rows="' . $rows . '" lang="' . esc_attr($lang) . '" class="evk-tl-wejscie">' . esc_textarea($tv) . '</textarea>';
        } elseif ($type === 'wysiwyg') {
            /* Edytor startuje dopiero, gdy pole staje się widoczne
               (translations.js): TinyMCE uruchomiony w ukrytym kontenerze
               dostaje zerową wysokość. */
            echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($tname) . '" rows="6" lang="' . esc_attr($lang) . '" class="evk-tl-wejscie evk-tl-wysiwyg">' . esc_textarea($tv) . '</textarea>';
        } else {
            echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($tname) . '" value="' . esc_attr($tv) . '" lang="' . esc_attr($lang) . '" class="evk-tl-wejscie">';
        }
        if ($type === 'link') {
            echo '<p class="evk-tl-uwaga">Adres zostaje ten sam — wewnętrzny prowadzi na stronie ' . esc_html($L) . ' do jej wersji ' . esc_html($L) . '.</p>';
        }
        echo '<input type="hidden" name="' . esc_attr($base . '[' . $tk . '__zrodlo]') . '" value="' . esc_attr($zrodlo) . '" class="evk-tl-zrodlo">';
        echo '<input type="hidden" name="' . esc_attr($base . '[' . $tk . '__przed]') . '" value="' . esc_attr(evk_rep_tl_hash($tv)) . '">';
        /* Znaczniki pod polem — widać je tylko z `data-ai` albo `data-sprawdz` (CSS). */
        echo '<div class="evk-tl-znaczniki">';
        echo '<span class="evk-tl-ai-znak">AI — do sprawdzenia</span>';
        echo '<span class="evk-tl-do-sprawdzenia">Do sprawdzenia — oryginał zmienił się po tłumaczeniu.</span>';
        echo '<button type="button" class="button button-small evk-tl-sprawdzone">Sprawdzone</button>';
        echo '</div>';
        if ($ai) echo '<p class="evk-tl-ai-stan" role="status"></p>';
        echo '</div>';
    }
}

/**
 * Tłumacz AI z wtyczki od języków (1.75.0) — filtr `evk_fields_tl_ai`
 * dostaje null, identyfikator edytowanego wpisu i kontekst, a oddaje dane dla
 * skryptu: {ajax, nonce, model, porcja, znaki} albo null (bez przycisków).
 * Fields nie zna żadnego dostawcy: przycisk wysyła teksty tam, gdzie wskaże filtr.
 *
 * Ekrany: edycja wpisu (identyfikator wpisu) i — od 1.76.0 — strona ustawień
 * (wpis 0, kontekst ['strona' => slug, 'tytul' => nazwa strony], tylko dla
 * kogoś z jej uprawnieniem), a od 1.77.0 edycja termu (kontekst ['term' => id]).
 *
 * @return array<string,mixed>|null
 */
function evk_rep_tl_ai_dane(): ?array {
    static $dane = false;
    if ($dane !== false) return $dane;
    $dane = null;
    $ekran = function_exists('get_current_screen') ? get_current_screen() : null;
    $post = $ekran && $ekran->base === 'post' ? get_post() : null;
    $strona = null;
    /* Edycja termu (1.77.0): kontekst `term`, prawo `edit_term` sprawdza wtyczka od języków. Formularz dodawania — bez przycisków. */
    $term = $ekran && $ekran->base === 'term' && isset($_GET['tag_ID']) ? absint($_GET['tag_ID']) : 0;
    if ($post instanceof \WP_Post) {
        $d = apply_filters('evk_fields_tl_ai', null, (int) $post->ID, []);
    } elseif ($term > 0) {
        $d = apply_filters('evk_fields_tl_ai', null, 0, ['term' => $term]);
    } else {
        $strona = is_admin() && isset($_GET['page']) ? evk_fields_tl_strona(sanitize_key(wp_unslash((string) $_GET['page']))) : null;
        if ($strona === null) return $dane;
        $d = apply_filters('evk_fields_tl_ai', null, 0, ['strona' => $strona['slug'], 'tytul' => $strona['nazwa']]);
    }
    if (is_array($d) && is_string($d['ajax'] ?? null) && is_string($d['nonce'] ?? null)) {
        $dane = ['ajax' => $d['ajax'], 'nonce' => $d['nonce'], 'post' => $post instanceof \WP_Post ? (int) $post->ID : 0,
            'strona' => $strona ? $strona['slug'] : '', 'term' => $term, 'model' => (string) ($d['model'] ?? ''),
            'porcja' => max(1, (int) ($d['porcja'] ?? 25)), 'znaki' => max(1, (int) ($d['znaki'] ?? 6000))];
    }
    return $dane;
}

/**
 * Strona ustawień, na której bieżący użytkownik może zapisywać (jej
 * uprawnienie) — {slug, nazwa}; null: nie ma takiej strony albo brak prawa.
 * Dla wtyczki od języków: przycisk AI na stronie ustawień (1.76.0).
 *
 * @return array{slug:string,nazwa:string}|null
 */
function evk_fields_tl_strona(string $slug): ?array {
    $p = evk_rep_settings_pages()[$slug] ?? null;
    if (!is_array($p) || !current_user_can((string) ($p['capability'] ?? 'manage_options'))) return null;
    return ['slug' => $slug, 'nazwa' => (string) ($p['label'] ?? $slug)];
}

/** ✦ — znak AI jak w builderze (Evoke ONE), kolor z `currentColor`. */
function evk_rep_tl_ikona_ai(): string {
    return '<svg class="evk-tl-ai-ikona" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" d="M8 0C8.6 4.6 11.4 7.4 16 8C11.4 8.6 8.6 11.4 8 16C7.4 11.4 4.6 8.6 0 8C4.6 7.4 7.4 4.6 8 0Z"/></svg>';
}

/**
 * Tłumaczenia bez widocznego pola jako pola ukryte: język wyłączony, pole
 * z „Nie tłumacz", widok bez przełącznika. Wiersze i opcje zapisują się
 * w całości z formularza, więc bez tego zwykły zapis wpisu skasowałby je
 * po cichu.
 *
 * @param array<string,mixed> $values Zapisane wartości tego poziomu (wiersz, grupa opcji).
 * @param array<string,array> $fields Pola tego poziomu (klucz => definicja).
 */
function evk_rep_tl_render_carry(string $base, array $values, array $fields): void {
    $ui    = evk_rep_tl_ui();
    $langs = evk_rep_tl_langs();
    foreach ($values as $k => $v) {
        $p = evk_rep_tl_parse_key((string) $k);
        if (!$p || !is_scalar($v) || (string) $v === '') continue;
        $f = $fields[$p[1]] ?? null;
        if (!is_array($f) || !evk_rep_tl_type_on($f)) continue;                   // pola już nie ma — zapis i tak by je pominął
        if ($ui && isset($langs[$p[0]]) && evk_rep_tl_field_on($f)) continue;   // jest widoczne pole
        $z = $values[$k . '__zrodlo'] ?? '';
        $z = is_scalar($z) ? (string) $z : '';
        echo '<input type="hidden" name="' . esc_attr($base . '[' . $k . ']') . '" value="' . esc_attr((string) $v) . '">';
        echo '<input type="hidden" name="' . esc_attr($base . '[' . $k . '__zrodlo]') . '" value="' . esc_attr($z) . '">';
        echo '<input type="hidden" name="' . esc_attr($base . '[' . $k . '__przed]') . '" value="' . esc_attr(evk_rep_tl_hash((string) $v)) . '">';
    }
}

/**
 * Otwarcie grupy z przełącznikiem języka (gdy grupa ma pola tłumaczalne).
 * Przełącznik działa na wszystkie grupy na ekranie naraz (translations.js).
 */
function evk_rep_tl_group_open(array $fields): bool {
    $langs = evk_rep_tl_langs();
    if (!$langs || !evk_rep_tl_fields_have($fields)) return false;
    evk_rep_tl_ui(true);
    $baza = evk_rep_tl_default();
    echo '<div class="evk-tl-grupa" data-evk-jezyk="' . esc_attr($baza) . '" data-evk-baza="' . esc_attr($baza) . '">';
    /* Wciśnięty język ma `button-primary`: kolor ze schematu panelu WordPressa,
       a przy ustawionym kolorze White Label (Evoke ONE) — z niego, bo White Label
       przebarwia właśnie `.button-primary`. */
    echo '<div class="evk-tl-przelacznik" role="group" aria-label="Język wartości pól">';
    echo '<button type="button" class="button button-primary evk-tl-jezyk" data-lang="' . esc_attr($baza) . '" aria-pressed="true">'
        . esc_html(strtoupper($baza)) . '<span class="screen-reader-text"> — oryginał</span></button>';
    foreach ($langs as $lang => $lname) {
        echo '<button type="button" class="button evk-tl-jezyk" data-lang="' . esc_attr($lang) . '" aria-pressed="false">'
            . esc_html(strtoupper($lang)) . '<span class="screen-reader-text"> — ' . esc_html($lname) . '</span>'
            . ' <span class="evk-tl-licznik" aria-hidden="true"></span><span class="screen-reader-text evk-tl-licznik-sr"></span></button>';
    }
    /* Cała grupa naraz (1.75.0): puste pola widocznego języka. Widać go tylko
       w widoku języka (CSS), bez atrybutu `hidden` — przegrywa z `display`. */
    if (evk_rep_tl_ai_dane() !== null) {
        /* Krótki napis (1.77.0): długi spadał do drugiego wiersza już przy dwóch językach. */
        echo '<button type="button" class="button evk-tl-ai-grupa" aria-label="Przetłumacz puste pola (AI)">' . evk_rep_tl_ikona_ai() . '<span>Tłumacz puste</span></button>';
        echo '<span class="evk-tl-ai-grupa-stan" role="status"></span>';
    }
    echo '</div>';
    return true;
}

function evk_rep_tl_group_close(bool $open): void {
    if (!$open) return;
    evk_rep_tl_ui(false);
    echo '</div>';
}

// =========================================================================
// SKRYPT I STYLE PANELU
// =========================================================================

/* Tam, gdzie ładuje się skrypt pól (wpis, term, strona ustawień) — i tylko
   przy włączonych tłumaczeniach. Priorytet 20: po kolejkowaniu admin.js. */
add_action('admin_enqueue_scripts', function () {
    if (!wp_script_is('evk-rep-admin', 'enqueued')) return;
    $langs = evk_rep_tl_langs();
    if (!$langs) return;
    wp_enqueue_editor();   // strona ustawień sama go nie woła, a pola WYSIWYG języków go potrzebują
    wp_enqueue_script('evk-rep-tl', EVK_REP_URL . 'assets/translations.js', ['jquery', 'evk-rep-admin'], EVK_REP_VERSION, true);
    wp_enqueue_style('evk-rep-tl', EVK_REP_URL . 'assets/translations.css', ['evk-rep-admin'], EVK_REP_VERSION);
    $ai = evk_rep_tl_ai_dane();
    if ($ai !== null) wp_add_inline_script('evk-rep-tl', 'window.evkRepTlAi = ' . wp_json_encode($ai) . ';', 'before');
    /* Pole języka widać tylko w widoku tego języka. Kody są z sanitize_key(),
       więc w selektorze nie ma czego uciekać. */
    $css = '';
    foreach (array_keys($langs) as $l) {
        $css .= '.evk-tl-grupa[data-evk-jezyk="' . $l . '"] .evk-tl-pole[data-lang="' . $l . '"]{display:block}';
    }
    wp_add_inline_style('evk-rep-tl', $css);
}, 20);
