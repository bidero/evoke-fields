<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — repeatery w CSV (1.79.0).
 *
 * Decyzje zgłaszającego z 03.10 (docs/plan-kolejka.md w Evoke ONE):
 *   • wiersze repeatera to JSON w JEDNEJ komórce — lista obiektów z KLUCZAMI
 *     pól; import przyjmuje też etykiety, eksport daje klucze;
 *   • tłumaczenia pól wiersza — obiekt języka w wierszu:
 *     {"tytul": "Pakiet S", "en": {"tytul": "Package S"}};
 *   • obraz, plik, galeria — ID albo adres pliku z biblioteki mediów (także
 *     rozmiar „-300x200”, „-scaled” i adres z innej domeny z tą samą ścieżką
 *     w uploads, jak po przenosinach strony); brak w bibliotece → pole puste
 *     i ostrzeżenie w raporcie, BEZ pobierania;
 *   • relacje (wpisy, termy, użytkownicy) — ID albo nazwa: slug/tytuł wpisu,
 *     nazwa/slug termu, login/e-mail użytkownika; eksport daje ID;
 *   • przy aktualizacji wpisu: „Zastąp wiersze” albo „Dopisz na końcu”
 *     (wybór na kolumnę), pusta komórka zostawia wiersze bez zmian.
 *
 * Funkcje tego pliku nie piszą do bazy — zwracają surowe wiersze w kształcie
 * formularza, a zapis robi evk_rep_sanitize_rows(), ten sam co w metaboksie.
 */

/**
 * Repeatery dostępne dla typu treści: grupa-repeater (meta = klucz grupy)
 * i pole „repeater” w grupie pojedynczej (meta = klucz pola).
 *
 * @return array<string,array{label:string,key:string,fields:array}> 'rep:<klucz>' => opis
 */
function evk_csv_rep_targets(string $post_type): array {
    $out = [];
    foreach (evk_rep_groups() as $gk => $g) {
        if (($g['object_type'] ?? 'post') !== 'post') continue;
        if (!in_array($post_type, (array) ($g['post_types'] ?? ['post']), true)) continue;
        if (evk_rep_is_repeater($g)) {
            $gk = (string) $gk;
            if (!isset($out['rep:' . $gk])) {
                $out['rep:' . $gk] = ['label' => (($g['label'] ?? '') !== '' ? (string) $g['label'] : $gk), 'key' => $gk, 'fields' => (array) ($g['fields'] ?? [])];
            }
            continue;
        }
        foreach ((array) ($g['fields'] ?? []) as $fk => $f) {
            if (($f['type'] ?? '') !== 'repeater' || isset($out['rep:' . $fk])) continue;
            $out['rep:' . $fk] = ['label' => (($f['label'] ?? '') !== '' ? (string) $f['label'] : (string) $fk), 'key' => (string) $fk, 'fields' => (array) ($f['sub_fields'] ?? [])];
        }
    }
    return $out;
}

/** Pola danych wiersza (bez układu i bez pól obliczeniowych — te liczy zapis). */
function evk_csv_rep_data_fields(array $fields): array {
    $out = [];
    foreach ($fields as $fk => $f) {
        $t = (string) ($f['type'] ?? 'text');
        if (evk_rep_is_layout($t) || $t === 'calc' || $t === 'repeater') continue;
        $out[(string) $fk] = $f;
    }
    return $out;
}

/** Nazwa z pliku → klucz pola: najpierw klucz dokładnie, potem etykieta bez wielkości liter. '' = nie ma. */
function evk_csv_rep_key(string $nazwa, array $fields): string {
    if (isset($fields[$nazwa])) return $nazwa;
    $n = mb_strtolower(trim($nazwa));
    foreach ($fields as $fk => $f) {
        if (mb_strtolower((string) $fk) === $n) return (string) $fk;
        if (mb_strtolower(trim((string) ($f['label'] ?? ''))) === $n && $n !== '') return (string) $fk;
    }
    return '';
}

/** Wartość z JSON jako tekst komórki (liczba, prawda/fałsz, null). */
function evk_csv_rep_scalar($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '';
    if (is_scalar($v)) return trim((string) $v);
    return '';
}

/**
 * ID załącznika z ID albo adresu pliku biblioteki. Adres rozmiaru
 * („-300x200”) i „-scaled” sprowadza do oryginału. 0 = nie ma w bibliotece.
 */
function evk_csv_attachment_id($v): int {
    if (is_array($v)) $v = $v['id'] ?? ($v['img'] ?? ($v['url'] ?? ''));
    $s = evk_csv_rep_scalar($v);
    if ($s === '') return 0;
    if (ctype_digit($s)) return get_post_type((int) $s) === 'attachment' ? (int) $s : 0;
    if (strpos($s, '/') === 0) $s = home_url($s);
    $s = (string) preg_replace('/[?#].*$/', '', $s);
    $id = (int) attachment_url_to_postid($s);
    if (!$id) {
        $orig = (string) preg_replace('/-(\d+x\d+|scaled)(\.[A-Za-z0-9]+)$/', '$2', $s);
        if ($orig !== $s) $id = (int) attachment_url_to_postid($orig);
    }
    if (!$id) {
        /* Oryginał „-scaled” pod adresem bez przyrostka: biblioteka zna adres z przyrostkiem. */
        $sc = (string) preg_replace('/(\.[A-Za-z0-9]+)$/', '-scaled$1', (string) preg_replace('/-(\d+x\d+)(\.[A-Za-z0-9]+)$/', '$2', $s));
        $id = (int) attachment_url_to_postid($sc);
    }
    if (!$id && !isset($GLOBALS['evk_csv_att_glebiej'])) {
        /* Inna domena, ta sama ścieżka w uploads (strona po przenosinach): adres z bieżącą bazą. */
        $baza = (string) (wp_get_upload_dir()['baseurl'] ?? '');
        $sciezka = (string) wp_parse_url($baza, PHP_URL_PATH);
        $poz = $sciezka !== '' ? strpos($s, $sciezka . '/') : false;
        if ($poz !== false && strpos($s, $baza . '/') !== 0) {
            $GLOBALS['evk_csv_att_glebiej'] = 1;
            $id = evk_csv_attachment_id($baza . substr($s, $poz + strlen($sciezka)));
            unset($GLOBALS['evk_csv_att_glebiej']);
        }
    }
    return $id;
}

/** Lista z JSON albo z tekstu „1|2|3” / „1, 2, 3”. */
function evk_csv_rep_list($v): array {
    if (is_array($v)) return array_keys($v) === range(0, count($v) - 1) ? $v : [$v];
    $s = evk_csv_rep_scalar($v);
    if ($s === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/[|,]/', $s) ?: []), 'strlen'));
}

/** Wpis po ID, slugu albo tytule wśród dozwolonych typów treści. 0 = nie ma. */
function evk_csv_rel_post_id($v, array $typy): int {
    $s = evk_csv_rep_scalar($v);
    if ($s === '') return 0;
    if (ctype_digit($s)) {
        $pt = get_post_type((int) $s);
        return $pt && in_array($pt, $typy, true) ? (int) $s : 0;
    }
    $q = get_posts(['post_type' => $typy, 'name' => sanitize_title($s), 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
    if (!$q) $q = get_posts(['post_type' => $typy, 'title' => $s, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
    return $q ? (int) $q[0] : 0;
}

/** Term po ID, nazwie albo slugu w taksonomii pola (bez tworzenia). 0 = nie ma. */
function evk_csv_rel_term_id($v, string $tax): int {
    $s = evk_csv_rep_scalar($v);
    if ($s === '' || $tax === '') return 0;
    if (ctype_digit($s)) { $t = get_term((int) $s, $tax); return $t && !is_wp_error($t) ? (int) $t->term_id : 0; }
    $t = get_term_by('name', $s, $tax) ?: get_term_by('slug', sanitize_title($s), $tax);
    return $t && !is_wp_error($t) ? (int) $t->term_id : 0;
}

/** Użytkownik po ID, e-mailu albo loginie. 0 = nie ma. */
function evk_csv_rel_user_id($v): int {
    $s = evk_csv_rep_scalar($v);
    if ($s === '') return 0;
    if (ctype_digit($s)) return get_userdata((int) $s) ? (int) $s : 0;
    $u = strpos($s, '@') !== false ? get_user_by('email', $s) : (get_user_by('login', $s) ?: get_user_by('slug', sanitize_title($s)));
    return $u ? (int) $u->ID : 0;
}

/**
 * Wartość pola wiersza z JSON → kształt formularza (przed evk_rep_sanitize_value()).
 * Nietrafione obrazy i relacje dopisują ostrzeżenie do $warn.
 *
 * @param list<string> $warn
 * @return mixed
 */
function evk_csv_rep_raw(array $field, $v, array &$warn, string $gdzie) {
    $type  = (string) ($field['type'] ?? 'text');
    $nazwa = (string) (($field['label'] ?? '') !== '' ? $field['label'] : ($field['key'] ?? ''));
    $brak  = static function (string $co, $war) use (&$warn, $gdzie, $nazwa): void {
        $warn[] = $gdzie . ', „' . $nazwa . '”: ' . $co . ' „' . (is_scalar($war) ? (string) $war : wp_json_encode($war)) . '” — pominięte';
    };
    switch ($type) {
        case 'image':
        case 'file':
            if (evk_csv_rep_scalar(is_array($v) ? ($v['id'] ?? ($v['url'] ?? '')) : $v) === '') return '';
            $id = evk_csv_attachment_id($v);
            if (!$id) $brak('nie ma w bibliotece mediów', is_array($v) ? ($v['url'] ?? ($v['id'] ?? '')) : $v);
            return $id ?: '';
        case 'gallery':
            $out = [];
            foreach (evk_csv_rep_list($v) as $el) {
                $id = evk_csv_attachment_id($el);
                if (!$id) { $brak('nie ma w bibliotece mediów', is_array($el) ? ($el['url'] ?? ($el['img'] ?? ($el['id'] ?? ''))) : $el); continue; }
                $r = ['img' => $id];
                if (is_array($el) && evk_csv_rep_scalar($el['cat'] ?? '') !== '') $r['cat'] = evk_csv_rep_scalar($el['cat']);
                $out[] = $r;
            }
            return $out;
        case 'relationship':
            $typy = !empty($field['rel_post_types']) && is_array($field['rel_post_types']) ? $field['rel_post_types'] : ['post'];
            $ids  = [];
            foreach (evk_csv_rep_list($v) as $el) { $id = evk_csv_rel_post_id($el, $typy); if ($id) $ids[] = $id; else $brak('nie ma wpisu', $el); }
            return empty($field['rel_multiple']) ? array_slice($ids, 0, 1) : $ids;
        case 'taxonomy':
            $ids = [];
            foreach (evk_csv_rep_list($v) as $el) { $id = evk_csv_rel_term_id($el, (string) ($field['taxonomy'] ?? '')); if ($id) $ids[] = $id; else $brak('nie ma termu', $el); }
            return $ids;
        case 'user':
            $ids = [];
            foreach (evk_csv_rep_list($v) as $el) { $id = evk_csv_rel_user_id($el); if ($id) $ids[] = $id; else $brak('nie ma użytkownika', $el); }
            return $ids;
        case 'link':
            if (is_array($v)) {
                return ['url' => evk_csv_rep_scalar($v['url'] ?? ''), 'title' => evk_csv_rep_scalar($v['title'] ?? ($v['label'] ?? ($v['etykieta'] ?? ''))),
                    'target' => !empty($v['target']) && $v['target'] !== '_self' ? '_blank' : ''];
            }
            return evk_csv_rep_scalar($v) === '' ? [] : ['url' => evk_csv_rep_scalar($v), 'title' => ''];
        case 'textarea':
        case 'wysiwyg':
            return is_scalar($v) ? (string) $v : '';
        case 'image_select':
            return evk_csv_rep_scalar($v);
    }
    /* Proste typy: te same zamiany co kolumna pola (liczby po polsku, tak/nie, etykiety opcji, daty). */
    return evk_csv_field_raw($type, $field, evk_csv_rep_scalar($v));
}

/**
 * Komórka CSV z JSON → surowe wiersze (kształt formularza) dla evk_rep_sanitize_rows().
 * null = komórka nie jest listą wierszy w JSON (błąd wiersza importu).
 *
 * @param list<string> $warn
 * @return list<array<string,mixed>>|null
 */
function evk_csv_rep_decode(string $cell, array $fields, array &$warn, string $gdzie): ?array {
    $dane = json_decode($cell, true);
    if (!is_array($dane)) return null;
    if ($dane && array_keys($dane) !== range(0, count($dane) - 1)) $dane = [$dane]; // jeden obiekt = jeden wiersz
    $pola  = evk_csv_rep_data_fields($fields);
    $langs = function_exists('evk_rep_tl_langs') ? evk_rep_tl_langs() : [];
    $out   = [];
    foreach ($dane as $i => $wiersz) {
        $tu = $gdzie . ', wiersz listy ' . ((int) $i + 1);
        if (!is_array($wiersz)) { $warn[] = $tu . ': to nie jest obiekt {…} — pominięty'; continue; }
        $raw = [];
        foreach ($wiersz as $k => $v) {
            $k  = (string) $k;
            $fk = evk_csv_rep_key($k, $pola);
            if ($fk !== '') { $raw[$fk] = evk_csv_rep_raw($pola[$fk] + ['key' => $fk], $v, $warn, $tu); continue; }
            /* Obiekt języka: {"en": {"tytul": "…"}} — tylko pola z wersjami językowymi. */
            if (is_array($v) && isset($langs[sanitize_key($k)])) {
                $lang = sanitize_key($k);
                foreach ($v as $tk => $tv) {
                    $tfk = evk_csv_rep_key((string) $tk, $pola);
                    if ($tfk === '' || !evk_rep_tl_type_on($pola[$tfk])) { $warn[] = $tu . ': pole „' . $tk . '” w języku „' . $lang . '” nie ma tłumaczeń — pominięte'; continue; }
                    $raw['evk_tl_' . $lang . '__' . $tfk] = is_scalar($tv) ? (string) $tv : '';
                }
                continue;
            }
            $warn[] = $gdzie . ': nieznane pole „' . $k . '” — pominięte';
        }
        $out[] = $raw;
    }
    return $out;
}

/** Wartość pola wiersza do JSON eksportu (ID zamiast obiektów, puste = null). */
function evk_csv_rep_export_value(array $field, $v) {
    $type = (string) ($field['type'] ?? 'text');
    switch ($type) {
        case 'image':
        case 'file':
            return (int) $v > 0 ? (int) $v : null;
        case 'gallery':
            if (!is_array($v) || !$v) return null;
            $kat = false;
            foreach ($v as $r) if (is_array($r) && ($r['cat'] ?? '') !== '') $kat = true;
            /* Same ID; obiekty {img, cat} tylko wtedy, gdy któryś obraz ma kategorię. */
            return array_values(array_map(static function ($r) use ($kat) {
                $id  = is_array($r) ? (int) ($r['img'] ?? 0) : (int) $r;
                $cat = is_array($r) ? (string) ($r['cat'] ?? '') : '';
                if (!$kat) return $id;
                return $cat !== '' ? ['img' => $id, 'cat' => $cat] : ['img' => $id];
            }, $v));
        case 'relationship':
        case 'taxonomy':
        case 'user':
            $ids = is_array($v) ? array_values(array_filter(array_map('intval', $v))) : ((int) $v > 0 ? [(int) $v] : []);
            return $ids ?: null;
        case 'link':
            if (!is_array($v) || (($v['url'] ?? '') === '' && ($v['title'] ?? '') === '')) return null;
            return array_filter(['url' => (string) ($v['url'] ?? ''), 'title' => (string) ($v['title'] ?? ''), 'target' => (string) ($v['target'] ?? '')], 'strlen');
        case 'checkbox':
            return (!empty($v) && $v !== '0') ? 1 : null;
    }
    if (is_int($v) || is_float($v)) return $v;
    return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
}

/** Wiersze repeatera → komórka JSON (klucze pól; tłumaczenia jako obiekt języka). '' przy braku wierszy. */
function evk_csv_rep_export(array $rows, array $fields): string {
    $pola = evk_csv_rep_data_fields($fields);
    $out  = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $o = [];
        foreach ($pola as $fk => $f) {
            $w = evk_csv_rep_export_value($f, $row[$fk] ?? '');
            if ($w !== null) $o[$fk] = $w;
        }
        foreach ($row as $k => $v) {
            $p = function_exists('evk_rep_tl_parse_key') ? evk_rep_tl_parse_key((string) $k) : null;
            if (!$p || !isset($pola[$p[1]]) || !is_scalar($v) || (string) $v === '') continue;
            $o[$p[0]][$p[1]] = (string) $v;
        }
        $out[] = (object) $o;
    }
    return $out ? (string) wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

/** Ostrzeżenie do raportu importu — bez powtórzeń, najwyżej 100. */
function evk_csv_warn(array &$s, array $warn): void {
    foreach ($warn as $w) {
        $lista = (array) ($s['stats']['warnings'] ?? []);
        if (in_array($w, $lista, true)) continue;
        if (count($lista) >= 100) { $s['stats']['warnings_more'] = (int) ($s['stats']['warnings_more'] ?? 0) + 1; continue; }
        $lista[] = $w;
        $s['stats']['warnings'] = $lista;
    }
}

/**
 * Zastąpienie wierszy: tłumaczenie, które w tym samym wierszu się nie zmieniło,
 * zachowuje dotychczasowe źródło. Bez tego ponowny import własnego eksportu
 * (eksport niesie tekst tłumaczenia, nie znacznik) zdejmowałby „Do sprawdzenia”
 * z tłumaczeń AI.
 */
function evk_csv_rep_keep_sources(array $new, array $old): array {
    foreach ($new as $i => $row) {
        if (!is_array($row) || !isset($old[$i]) || !is_array($old[$i])) continue;
        foreach ($row as $k => $v) {
            $p = evk_rep_tl_parse_key((string) $k);
            if (!$p || !is_scalar($v) || !isset($old[$i][$k], $old[$i][$k . '__zrodlo'])) continue;
            if ((string) $old[$i][$k] === (string) $v) $new[$i][$k . '__zrodlo'] = $old[$i][$k . '__zrodlo'];
        }
    }
    return $new;
}
