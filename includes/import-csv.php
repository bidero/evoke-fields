<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — Import CSV z mapowaniem kolumn na pola.
 *
 * Przepływ trójkrokowy (stan trzymany w transiencie per użytkownik):
 *   1. Upload pliku CSV + separator/kodowanie → parsujemy nagłówek i próbkę.
 *   2. Mapowanie kolumn na cele: rdzeń wpisu (tytuł/treść/status/…), taksonomie,
 *      proste pola EVK (grup pojedynczych danego typu treści). Wybór trybu
 *      create/update i klucza dopasowania.
 *   3. Import porcjami (time-box jak w „Przelicz") ze wznowieniem; po każdym wpisie
 *      przeliczamy pola calc (evk_rep_recalc). Raport na końcu.
 *
 * Zakres MVP: typy treści (post/CPT) i proste typy pól. Bez repeaterów, relacji po
 * nazwie i sideloadu obrazów (planowane jako kolejne iteracje).
 */

const EVK_CSV_TIME_BUDGET = 20;   // sekundy na porcję w fallbacku POST (limit czasu hostingu)
const EVK_CSV_AJAX_BUDGET = 3;    // sekundy na porcję w trybie AJAX (płynny, częsty pasek)
const EVK_CSV_MAX_CHUNK   = 2000; // twardy limit wierszy na porcję (pamięć)

// =========================================================================
// TYPY / HELPERY (czyste — testowalne bez WP)
// =========================================================================

/** Proste typy pól EVK, które umiemy zapisać z pojedynczej komórki CSV. */
function evk_csv_simple_types(): array {
    return ['text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'button_group', 'checkbox', 'toggle', 'color', 'date', 'time', 'datetime'];
}

/** Wykryj separator z linii nagłówka (najczęstszy z , ; \t |). */
function evk_csv_sniff_delimiter(string $line): string {
    $cands = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($cands as $d => $_) $cands[$d] = substr_count($line, $d);
    arsort($cands);
    $best = array_key_first($cands);
    return $cands[$best] > 0 ? $best : ',';
}

/** Normalizacja wartości boolowskiej z CSV (checkbox/toggle). */
function evk_csv_bool($v): bool {
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'tak', 'yes', 'y', 'true', 'prawda', 'x', 'on'], true);
}

/** Wartość daty/czasu → format przechowywania EVK (ISO). Pusto/nieparsowalne = surowa. */
function evk_csv_normalize_datetime(string $type, string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    $ts = strtotime($v);
    if ($ts === false) return $v; // nie ruszamy — walidacja pola i tak przepuści/utnie
    if ($type === 'time')     return date('H:i', $ts);
    if ($type === 'datetime') return date('Y-m-d H:i', $ts);
    return date('Y-m-d', $ts);
}

/**
 * Zamień wartość CSV pola wyboru na przechowywaną WARTOŚĆ opcji.
 * Dopasowanie po wartości LUB po etykiecie (CSV bywa eksportem z etykietami).
 * $options = ['wartosc' => 'Etykieta', …]. Brak trafienia = wartość surowa.
 */
function evk_csv_map_choice(string $v, array $options): string {
    $v = trim($v);
    if ($v === '' || isset($options[$v])) return $v;
    foreach ($options as $val => $label) {
        if (strcasecmp((string) $label, $v) === 0) return (string) $val;
    }
    return $v;
}

// =========================================================================
// STAN SESJI IMPORTU (transient per użytkownik)
// =========================================================================

function evk_csv_session_key(): string { return 'evk_csv_import_' . get_current_user_id(); }
function evk_csv_get_session(): array { $s = get_transient(evk_csv_session_key()); return is_array($s) ? $s : []; }
function evk_csv_set_session(array $s): void { set_transient(evk_csv_session_key(), $s, HOUR_IN_SECONDS); }
function evk_csv_clear_session(bool $delete_file = true): void {
    if ($delete_file) {
        $s = evk_csv_get_session();
        if (!empty($s['file'])) { $p = evk_csv_file_path((string) $s['file']); if ($p !== '') @unlink($p); }
    }
    delete_transient(evk_csv_session_key());
}

// =========================================================================
// KATALOG PLIKÓW IMPORTU (chroniony, jak katalog kopii)
// =========================================================================

function evk_csv_dir(): ?string {
    $up = wp_get_upload_dir();
    if (!empty($up['error']) || empty($up['basedir'])) return null;
    $name = get_option('evk_csv_dir', '');
    if (!is_string($name) || strpos($name, 'evk-imports-') !== 0) {
        $name = 'evk-imports-' . wp_generate_password(12, false, false);
        update_option('evk_csv_dir', $name, false);
    }
    $path = trailingslashit($up['basedir']) . $name;
    if (!is_dir($path) && !wp_mkdir_p($path)) return null;
    if (function_exists('evk_backups_ensure_protection')) {
        evk_backups_ensure_protection($path);
    }
    return trailingslashit($path);
}

/** Bezpieczna ścieżka pliku importu z nazwy w sesji (nie wychodzi poza katalog). */
function evk_csv_file_path(string $file): string {
    $file = basename($file);
    // wp_generate_password(12,false,false) daje znaki A-Za-z0-9 (mieszana wielkość!).
    if (!preg_match('/^import-[A-Za-z0-9]{12}\.csv$/', $file)) return '';
    $dir = evk_csv_dir();
    if ($dir === null) return '';
    $p = $dir . $file;
    return is_file($p) ? $p : '';
}

// =========================================================================
// CELE MAPOWANIA (dla wybranego typu treści)
// =========================================================================

/** Pola rdzenia wpisu dostępne jako cele mapowania. */
function evk_csv_core_targets(): array {
    return [
        'core:id'         => 'ID wpisu (tylko dopasowanie)',
        'core:title'      => 'Tytuł',
        'core:content'    => 'Treść',
        'core:excerpt'    => 'Zajawka',
        'core:status'     => 'Status (publish/draft/…)',
        'core:slug'       => 'Slug',
        'core:date'       => 'Data publikacji',
        'core:menu_order' => 'Kolejność (menu_order)',
    ];
}

/** Taksonomie danego typu treści → cele 'tax:<slug>'. */
function evk_csv_tax_targets(string $post_type): array {
    $out = [];
    foreach (get_object_taxonomies($post_type, 'objects') as $tx) {
        if (!$tx->show_ui) continue;
        if (in_array($tx->name, ['post_format'], true)) continue;
        $out['tax:' . $tx->name] = $tx->label ?: $tx->name;
    }
    return $out;
}

/** Czytelna etykieta celu mapowania ('core:title' → „Tytuł", 'evk:cena' → „Cena" itd.). */
function evk_csv_target_label(string $target, string $post_type): string {
    $core = evk_csv_core_targets();
    if (isset($core[$target])) return $core[$target];
    $tax = evk_csv_tax_targets($post_type);
    if (isset($tax[$target])) return $tax[$target];
    $evk = evk_csv_evk_targets($post_type);
    if (isset($evk[$target])) return $evk[$target]['label'];
    return $target;
}

/** Proste pola EVK grup pojedynczych obejmujących ten typ treści → 'evk:<key>' => [label,type,field]. */
function evk_csv_evk_targets(string $post_type): array {
    $simple = evk_csv_simple_types();
    $out    = [];
    foreach (evk_rep_groups() as $g) {
        if (($g['object_type'] ?? 'post') !== 'post') continue;
        if (evk_rep_is_repeater($g)) continue;
        if (!in_array($post_type, (array) ($g['post_types'] ?? ['post']), true)) continue;
        foreach (($g['fields'] ?? []) as $fk => $f) {
            $t = $f['type'] ?? 'text';
            if (!in_array($t, $simple, true)) continue;
            if (isset($out['evk:' . $fk])) continue; // pierwszy wygrywa (klucz globalny)
            $out['evk:' . $fk] = ['label' => (($f['label'] ?? '') !== '' ? $f['label'] : $fk), 'type' => $t, 'field' => $f];
        }
    }
    return $out;
}

// =========================================================================
// EKSPORT WARTOŚCI → CSV (symetryczny do importu; pełny round-trip)
// =========================================================================

/**
 * Kolumny eksportu dla typu treści (uporządkowane): rdzeń → pola EVK → taksonomie.
 * Nagłówki = etykiety, które import auto-mapuje po nazwie (round-trip). Rdzeń używa
 * krótkich nazw ('ID','Tytuł','Zajawka','Kolejność'…) rozpoznawanych przez auto-mapę.
 * Zwraca [ target => label ].
 */
function evk_csv_export_targets(string $post_type): array {
    $out = [
        'core:id'         => 'ID',
        'core:title'      => 'Tytuł',
        'core:content'    => 'Treść',
        'core:excerpt'    => 'Zajawka',
        'core:status'     => 'Status',
        'core:slug'       => 'Slug',
        'core:date'       => 'Data',
        'core:menu_order' => 'Kolejność',
    ];
    foreach (evk_csv_evk_targets($post_type) as $k => $d) $out[$k] = $d['label'];
    foreach (evk_csv_tax_targets($post_type) as $k => $l) $out[$k] = $l;
    return $out;
}

/** Wartość pola EVK do komórki CSV (raw z mety; checkbox znormalizowany do 1/0). */
function evk_csv_export_field_value(string $type, $v): string {
    if ($type === 'checkbox') return (!empty($v) && $v !== '0') ? '1' : '0';
    return is_scalar($v) ? (string) $v : '';
}

/** Wartość jednej komórki eksportu dla wpisu i celu. */
function evk_csv_export_cell(WP_Post $post, string $target, array $evk_defs): string {
    switch ($target) {
        case 'core:id':         return (string) $post->ID;
        case 'core:title':      return (string) $post->post_title;
        case 'core:content':    return (string) $post->post_content;
        case 'core:excerpt':    return (string) $post->post_excerpt;
        case 'core:status':     return (string) $post->post_status;
        case 'core:slug':       return (string) $post->post_name;
        case 'core:date':       return (string) $post->post_date;
        case 'core:menu_order': return (string) (int) $post->menu_order;
    }
    if (strpos($target, 'tax:') === 0) {
        $tax   = substr($target, 4);
        $terms = wp_get_object_terms($post->ID, $tax, ['fields' => 'names']);
        return is_wp_error($terms) ? '' : implode('|', $terms);
    }
    if (strpos($target, 'evk:') === 0 && isset($evk_defs[$target])) {
        $key = substr($target, 4);
        return evk_csv_export_field_value($evk_defs[$target]['type'], get_post_meta($post->ID, $key, true));
    }
    return '';
}

// Handler eksportu — streamuje CSV bez trzymania wszystkiego w pamięci.
add_action('admin_init', function () {
    if (empty($_POST['evk_csv_export'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv_export', 'evk_csv_export_nonce');

    $post_type = sanitize_key($_POST['evk_csv_export_pt'] ?? '');
    if ($post_type === '' || !post_type_exists($post_type)) {
        evk_csv_notice('error', 'Wybierz prawidłowy typ treści do eksportu.');
        evk_csv_redirect();
    }

    $all_targets = evk_csv_export_targets($post_type);
    $chosen      = (array) ($_POST['evk_csv_export_cols'] ?? []);
    $cols        = [];
    foreach ($all_targets as $target => $label) {
        if (in_array($target, $chosen, true)) $cols[$target] = $label;
    }
    if (!$cols) { evk_csv_notice('error', 'Zaznacz co najmniej jedną kolumnę do eksportu.'); evk_csv_redirect(); }

    $enc    = in_array($_POST['evk_csv_export_enc'] ?? '', ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true) ? $_POST['evk_csv_export_enc'] : 'UTF-8';
    $delim  = evk_csv_delim_char((string) ($_POST['evk_csv_export_delim'] ?? 'comma'));
    $bom    = !empty($_POST['evk_csv_export_bom']) && $enc === 'UTF-8';
    $status = ($_POST['evk_csv_export_status'] ?? 'any') === 'publish' ? 'publish' : 'any';

    $evk_defs = evk_csv_evk_targets($post_type); // do wartości pól EVK

    $ids = get_posts([
        'post_type'     => $post_type,
        'post_status'   => $status,
        'numberposts'   => -1,
        'fields'        => 'ids',
        'orderby'       => 'ID',
        'order'         => 'ASC',
        'no_found_rows' => true,
    ]);

    nocache_headers();
    header('Content-Type: text/csv; charset=' . $enc);
    header('Content-Disposition: attachment; filename=evk-' . $post_type . '-' . gmdate('Ymd-His') . '.csv');
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean(); // czysty strumień (bez buforów WP)

    $out = fopen('php://output', 'w');
    if ($bom) fwrite($out, "\xEF\xBB\xBF"); // Excel rozpozna UTF-8

    // Konwersja komórki do docelowego kodowania (dane trzymamy w UTF-8).
    $conv = function (string $s) use ($enc): string {
        if ($enc === 'UTF-8' || !function_exists('mb_convert_encoding')) return $s;
        return mb_convert_encoding($s, $enc, 'UTF-8');
    };

    fputcsv($out, array_map($conv, array_values($cols)), $delim);

    $i = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        if (function_exists('_prime_post_caches')) _prime_post_caches($chunk, true, true);
        foreach ($chunk as $pid) {
            $post = get_post($pid);
            if (!$post) continue;
            $row = [];
            foreach ($cols as $target => $label) $row[] = $conv(evk_csv_export_cell($post, $target, $evk_defs));
            fputcsv($out, $row, $delim);
        }
        // Meta/term cache rośnie z każdą paczką — zrzucaj, by nie puchła pamięć przy dużych zbiorach.
        if ((++$i % 5) === 0) { wp_cache_flush(); flush(); }
    }
    fclose($out);
    exit;
});

// =========================================================================
// EKSPORT WARTOŚCI STRON OPCJI → CSV
// =========================================================================
// Strony opcji przechowują wartości w opcjach evk_rep_opt_{gkey} (nie we wpisach),
// więc eksport ma inny kształt niż typy treści:
//   • grupa-repeater  → TABELA: nagłówek = etykiety pól, jeden wiersz = jeden wiersz repeatera;
//   • grupa pojedyncza → PIONOWO: kolumny „Pole” | „Wartość”, jeden wiersz na pole.
// Round-trip (import) nie dotyczy opcji — to zrzut/kopia wartości. Kolumny obejmują
// wyłącznie proste typy pól (te same co eksport wpisów).

/**
 * Grupy pól przypisane do którejkolwiek strony ustawień → cele eksportu opcji.
 * Zwraca [ gkey => ['label','group'=>def,'repeater'=>bool,'page'=>etykieta strony] ].
 * Pierwsze wystąpienie grupy wygrywa (grupa może być na wielu stronach).
 */
function evk_csv_option_group_targets(): array {
    if (!function_exists('evk_rep_settings_pages')) return [];
    $groups = evk_rep_groups();
    $out    = [];
    foreach (evk_rep_settings_pages() as $slug => $page) {
        $plabel = (string) (($page['label'] ?? '') !== '' ? $page['label'] : $slug);
        foreach ((array) ($page['tabs'] ?? []) as $tab) {
            foreach ((array) ($tab['groups'] ?? []) as $gk) {
                $gk = (string) $gk;
                if ($gk === '' || isset($out[$gk]) || !isset($groups[$gk])) continue;
                $g = $groups[$gk];
                $out[$gk] = [
                    'label'    => ($g['label'] ?? '') !== '' ? $g['label'] : $gk,
                    'group'    => $g,
                    'repeater' => evk_rep_is_repeater($g),
                    'page'     => $plabel,
                ];
            }
        }
    }
    return $out;
}

/** Proste pola danych grupy jako kolumny eksportu: [ fkey => ['label','type'] ]. Pomija pola układu i typy złożone. */
function evk_csv_group_simple_fields(array $group): array {
    $simple = evk_csv_simple_types();
    $out    = [];
    foreach (($group['fields'] ?? []) as $fk => $f) {
        $t = $f['type'] ?? 'text';
        if (!in_array($t, $simple, true)) continue;
        $out[(string) $fk] = ['label' => (($f['label'] ?? '') !== '' ? $f['label'] : $fk), 'type' => $t];
    }
    return $out;
}

// Handler eksportu opcji — streamuje CSV bezpośrednio na wyjście.
add_action('admin_init', function () {
    if (empty($_POST['evk_csv_opt_export'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv_opt_export', 'evk_csv_opt_export_nonce');

    $gk      = sanitize_key($_POST['evk_csv_opt_export_group'] ?? '');
    $targets = evk_csv_option_group_targets();
    if ($gk === '' || !isset($targets[$gk])) {
        evk_csv_notice('error', 'Wybierz prawidłową grupę pól ze strony ustawień.');
        evk_csv_redirect();
    }

    $group    = (array) $targets[$gk]['group'];
    $repeater = !empty($targets[$gk]['repeater']);
    $fields   = evk_csv_group_simple_fields($group);
    if (!$fields) {
        evk_csv_notice('error', 'Ta grupa nie ma prostych pól, które można wyeksportować do CSV.');
        evk_csv_redirect();
    }

    $enc   = in_array($_POST['evk_csv_opt_export_enc'] ?? '', ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true) ? $_POST['evk_csv_opt_export_enc'] : 'UTF-8';
    $delim = evk_csv_delim_char((string) ($_POST['evk_csv_opt_export_delim'] ?? 'comma'));
    $bom   = !empty($_POST['evk_csv_opt_export_bom']) && $enc === 'UTF-8';

    $stored = evk_rep_get_option($gk);
    if (!is_array($stored)) $stored = [];

    nocache_headers();
    header('Content-Type: text/csv; charset=' . $enc);
    header('Content-Disposition: attachment; filename=evk-opt-' . $gk . '-' . gmdate('Ymd-His') . '.csv');
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean(); // czysty strumień (bez buforów WP)

    $out = fopen('php://output', 'w');
    if ($bom) fwrite($out, "\xEF\xBB\xBF"); // Excel rozpozna UTF-8

    $conv = function (string $s) use ($enc): string {
        if ($enc === 'UTF-8' || !function_exists('mb_convert_encoding')) return $s;
        return mb_convert_encoding($s, $enc, 'UTF-8');
    };

    if ($repeater) {
        // TABELA: nagłówek = etykiety pól, jeden wiersz repeatera = jeden wiersz CSV.
        fputcsv($out, array_map($conv, array_map(static fn($d) => $d['label'], $fields)), $delim);
        foreach (array_values($stored) as $row) {
            if (!is_array($row)) continue;
            $line = [];
            foreach ($fields as $fk => $d) {
                $line[] = $conv(evk_csv_export_field_value($d['type'], $row[$fk] ?? ''));
            }
            fputcsv($out, $line, $delim);
        }
    } else {
        // PIONOWO: Pole | Wartość, jeden wiersz na pole.
        fputcsv($out, array_map($conv, ['Pole', 'Wartość']), $delim);
        foreach ($fields as $fk => $d) {
            fputcsv($out, [$conv($d['label']), $conv(evk_csv_export_field_value($d['type'], $stored[$fk] ?? ''))], $delim);
        }
    }
    fclose($out);
    exit;
});

// =========================================================================
// MENU + ENQUEUE
// =========================================================================

add_action('admin_menu', function () {
    add_submenu_page('evk-repeater', 'Migracja CSV', 'Migracja CSV', EVK_REP_CAP, 'evk-import', 'evk_csv_page');
}, 27);

// =========================================================================
// KROK 1 — UPLOAD
// =========================================================================

add_action('admin_init', function () {
    if (empty($_POST['evk_csv_upload'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv', 'evk_csv_nonce');

    if (empty($_FILES['evk_csv_file']['tmp_name']) || !is_uploaded_file($_FILES['evk_csv_file']['tmp_name'])) {
        evk_csv_notice('error', 'Nie wybrano pliku lub przesyłanie nie powiodło się.');
        evk_csv_redirect();
    }
    if (!empty($_FILES['evk_csv_file']['error'])) {
        evk_csv_notice('error', 'Błąd przesyłania pliku (kod ' . (int) $_FILES['evk_csv_file']['error'] . ').');
        evk_csv_redirect();
    }

    $dir = evk_csv_dir();
    if ($dir === null) { evk_csv_notice('error', 'Katalog uploads niedostępny do zapisu.'); evk_csv_redirect(); }

    evk_csv_clear_session(true); // porzuć poprzednią sesję (i jej plik)
    $file = 'import-' . wp_generate_password(12, false, false) . '.csv';
    if (!@move_uploaded_file($_FILES['evk_csv_file']['tmp_name'], $dir . $file)) {
        evk_csv_notice('error', 'Nie udało się zapisać pliku importu.');
        evk_csv_redirect();
    }

    $encoding = sanitize_text_field($_POST['evk_csv_encoding'] ?? 'auto');
    $delim_in = (string) ($_POST['evk_csv_delimiter'] ?? 'auto');

    // Nagłówek + próbka (do 5 wierszy) — na potrzeby UI mapowania.
    $fh = fopen($dir . $file, 'r');
    if (!$fh) { evk_csv_notice('error', 'Nie udało się otworzyć pliku.'); evk_csv_redirect(); }
    $first = fgets($fh);
    if ($first === false) { fclose($fh); evk_csv_notice('error', 'Plik jest pusty.'); evk_csv_redirect(); }
    $first = evk_csv_strip_bom($first);
    $delim = $delim_in === 'auto' ? evk_csv_sniff_delimiter($first) : evk_csv_delim_char($delim_in);

    rewind($fh);
    $headers = fgetcsv($fh, 0, $delim);
    $headers = is_array($headers) ? array_map(function ($h) use ($encoding) { return evk_csv_conv(evk_csv_strip_bom((string) $h), $encoding); }, $headers) : [];
    $sample  = [];
    for ($i = 0; $i < 5; $i++) {
        $row = fgetcsv($fh, 0, $delim);
        if ($row === false) break;
        $sample[] = array_map(function ($v) use ($encoding) { return evk_csv_conv((string) $v, $encoding); }, $row);
    }
    fclose($fh);

    if (!$headers) { evk_csv_notice('error', 'Nie udało się odczytać nagłówka CSV.'); evk_csv_redirect(); }

    evk_csv_set_session([
        'file'      => $file,
        'delimiter' => $delim,
        'encoding'  => $encoding,
        'headers'   => $headers,
        'sample'    => $sample,
        'step'      => 'map',
    ]);
    evk_csv_redirect();
});

/** Znak separatora z wyboru UI. */
function evk_csv_delim_char(string $key): string {
    switch ($key) {
        case 'semicolon': return ';';
        case 'tab':       return "\t";
        case 'pipe':      return '|';
        default:          return ',';
    }
}

function evk_csv_strip_bom(string $s): string {
    return (strncmp($s, "\xEF\xBB\xBF", 3) === 0) ? substr($s, 3) : $s;
}

/** Konwersja wartości do UTF-8 wg wybranego kodowania ('auto' = zostaw). */
function evk_csv_conv(string $v, string $encoding): string {
    if ($encoding === '' || $encoding === 'auto' || $encoding === 'UTF-8') return $v;
    if (!function_exists('mb_convert_encoding')) return $v;
    return mb_convert_encoding($v, 'UTF-8', $encoding);
}

// =========================================================================
// KROK 2 — ZAPIS MAPOWANIA → START IMPORTU
// =========================================================================

add_action('admin_init', function () {
    if (empty($_POST['evk_csv_run'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv', 'evk_csv_nonce');

    $s = evk_csv_get_session();
    if (empty($s['file']) || evk_csv_file_path((string) $s['file']) === '') {
        evk_csv_notice('error', 'Sesja importu wygasła — wgraj plik ponownie.');
        evk_csv_clear_session(false);
        evk_csv_redirect();
    }

    $post_type = sanitize_key($_POST['evk_csv_post_type'] ?? '');
    if ($post_type === '' || !post_type_exists($post_type)) {
        evk_csv_notice('error', 'Wybierz prawidłowy typ treści.');
        evk_csv_redirect();
    }

    // Mapowanie: indeks kolumny → cel (walidowane względem dostępnych celów).
    $valid = ['' => true];
    foreach (evk_csv_core_targets() as $k => $_) $valid[$k] = true;
    foreach (evk_csv_tax_targets($post_type) as $k => $_) $valid[$k] = true;
    foreach (evk_csv_evk_targets($post_type) as $k => $_) $valid[$k] = true;

    $mapping = [];
    foreach ((array) ($_POST['evk_csv_map'] ?? []) as $idx => $target) {
        $idx = (int) $idx;
        $target = (string) $target;
        if ($target !== '' && isset($valid[$target])) $mapping[$idx] = $target;
    }
    if (!$mapping) { evk_csv_notice('error', 'Zmapuj co najmniej jedną kolumnę.'); evk_csv_redirect(); }

    $match_key = sanitize_text_field($_POST['evk_csv_match_key'] ?? 'none');
    $on_match  = ($_POST['evk_csv_on_match'] ?? 'update') === 'skip' ? 'skip' : 'update';

    // Klucz dopasowania musi mieć zmapowaną kolumnę-źródło.
    $need = ['post_id' => 'core:id', 'slug' => 'core:slug', 'title' => 'core:title'];
    if ($match_key !== 'none') {
        $need_target = $need[$match_key] ?? $match_key; // evk:<key> mapuje się na siebie
        if (!in_array($need_target, $mapping, true)) {
            $label = evk_csv_target_label($need_target, $post_type);
            evk_csv_notice('error', sprintf(
                'Wybrano dopasowanie istniejących wpisów po „%s", ale żadna kolumna nie jest na to pole zmapowana. Zmapuj kolumnę na „%s" — albo ustaw „Dopasuj istniejące wpisy po" na „zawsze twórz nowe".',
                $label, $label
            ));
            evk_csv_redirect();
        }
    }

    $s['post_type']      = $post_type;
    $s['mapping']        = $mapping;
    $s['match_key']      = $match_key;
    $s['on_match']       = $on_match;
    $s['status_default'] = sanitize_key($_POST['evk_csv_status'] ?? 'publish') ?: 'publish';
    $s['create_terms']   = !empty($_POST['evk_csv_create_terms']);
    $s['skip_empty']     = !isset($_POST['evk_csv_write_empty']); // domyślnie: pusta komórka nie nadpisuje
    $s['offset']         = 0;
    $s['total']          = evk_csv_count_rows((string) $s['file'], (string) $s['delimiter']);
    $s['stats']          = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $s['step']           = 'run';
    evk_csv_set_session($s);
    evk_csv_redirect();
});

/** Policz wiersze danych (bez nagłówka). */
function evk_csv_count_rows(string $file, string $delim): int {
    $path = evk_csv_file_path($file);
    if ($path === '') return 0;
    $fh = fopen($path, 'r');
    if (!$fh) return 0;
    $n = 0;
    fgetcsv($fh, 0, $delim); // nagłówek
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if ($row === [null] || (count($row) === 1 && ($row[0] === null || $row[0] === ''))) continue; // pusta linia
        $n++;
    }
    fclose($fh);
    return $n;
}

// =========================================================================
// KROK 3 — PRZETWARZANIE PORCJI (ze wznowieniem)
// =========================================================================

/**
 * Przetwórz jedną porcję (do $budget sekund / EVK_CSV_MAX_CHUNK wierszy) z aktywnej sesji.
 * Zwraca ['ok'=>bool, 'error'=>string, 'done'=>bool, 'offset'=>int, 'total'=>int, 'stats'=>array].
 * Wspólne dla ścieżki AJAX (płynny pasek) i POST (fallback bez JS).
 */
function evk_csv_process_chunk(int $budget): array {
    $s = evk_csv_get_session();
    $path = !empty($s['file']) ? evk_csv_file_path((string) $s['file']) : '';
    if ($path === '' || ($s['step'] ?? '') !== 'run') {
        return ['ok' => false, 'error' => 'Brak aktywnego importu.'];
    }

    $delim  = (string) $s['delimiter'];
    $enc    = (string) $s['encoding'];
    $offset = (int) ($s['offset'] ?? 0);
    $total  = (int) ($s['total'] ?? 0);

    $fh = fopen($path, 'r');
    if (!$fh) return ['ok' => false, 'error' => 'Nie udało się otworzyć pliku importu.'];
    fgetcsv($fh, 0, $delim); // pomiń nagłówek

    // Przewiń do offsetu (pomijając puste linie tak samo jak licznik).
    $seen = 0;
    while ($seen < $offset && ($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (evk_csv_is_blank_row($row)) continue;
        $seen++;
    }

    @set_time_limit(0);
    $start     = time();
    $processed = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (evk_csv_is_blank_row($row)) continue;
        $row = array_map(function ($v) use ($enc) { return evk_csv_conv((string) $v, $enc); }, $row);
        evk_csv_import_row($s, $row);
        $offset++;
        $processed++;
        if ($processed >= EVK_CSV_MAX_CHUNK) break;
        if ((time() - $start) >= $budget) break;
    }
    fclose($fh);

    $s['offset'] = $offset;
    $done = $offset >= $total;
    if ($done) $s['step'] = 'done';
    evk_csv_set_session($s);

    return ['ok' => true, 'error' => '', 'done' => $done, 'offset' => $offset, 'total' => $total, 'stats' => (array) $s['stats']];
}

// AJAX: płynny pasek postępu bez przeładowań całej strony.
add_action('wp_ajax_evk_csv_process', function () {
    if (!evk_rep_can_manage()) wp_send_json_error(['error' => 'Brak uprawnień.'], 403);
    if (!check_ajax_referer('evk_csv_ajax', 'nonce', false)) wp_send_json_error(['error' => 'Nieprawidłowy token.'], 400);
    $r = evk_csv_process_chunk(EVK_CSV_AJAX_BUDGET);
    if (empty($r['ok'])) wp_send_json_error(['error' => $r['error'] ?? 'Błąd importu.'], 400);
    wp_send_json_success($r);
});

// Fallback bez JS: przetwarzanie przez POST + redirect (większa porcja, mniej przeładowań).
add_action('admin_init', function () {
    if (empty($_POST['evk_csv_process'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv', 'evk_csv_nonce');
    $r = evk_csv_process_chunk(EVK_CSV_TIME_BUDGET);
    if (empty($r['ok'])) { evk_csv_notice('error', $r['error'] ?? 'Błąd importu.'); evk_csv_clear_session(false); }
    evk_csv_redirect();
});

function evk_csv_is_blank_row($row): bool {
    if (!is_array($row)) return true;
    if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) return true;
    foreach ($row as $c) { if (trim((string) $c) !== '') return false; }
    return true;
}

/** Import jednego wiersza: dopasowanie → insert/update → taksonomie + pola EVK → recalc. */
function evk_csv_import_row(array &$s, array $row): void {
    $mapping   = (array) $s['mapping'];
    $post_type = (string) $s['post_type'];

    // Wyciągnij wartości wg celu (ostatnia kolumna dla danego celu wygrywa).
    $vals = [];
    foreach ($mapping as $idx => $target) {
        $vals[$target] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
    }

    // ── Dopasowanie istniejącego wpisu ──
    $existing = evk_csv_match_existing($s, $vals, $post_type);
    if ($existing && ($s['on_match'] ?? 'update') === 'skip') { $s['stats']['skipped']++; return; }

    // ── Rdzeń wpisu ──
    $postarr = ['post_type' => $post_type];
    if (isset($vals['core:title']))      $postarr['post_title']   = $vals['core:title'];
    if (isset($vals['core:content']))    $postarr['post_content'] = $vals['core:content'];
    if (isset($vals['core:excerpt']))    $postarr['post_excerpt'] = $vals['core:excerpt'];
    if (isset($vals['core:slug']) && $vals['core:slug'] !== '') $postarr['post_name'] = sanitize_title($vals['core:slug']);
    if (isset($vals['core:menu_order']) && is_numeric($vals['core:menu_order'])) $postarr['menu_order'] = (int) $vals['core:menu_order'];
    if (isset($vals['core:date']) && $vals['core:date'] !== '') {
        $ts = strtotime($vals['core:date']);
        if ($ts !== false) { $postarr['post_date'] = date('Y-m-d H:i:s', $ts); $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts); }
    }
    if (isset($vals['core:status']) && $vals['core:status'] !== '') {
        $postarr['post_status'] = sanitize_key($vals['core:status']);
    } elseif (!$existing) {
        $postarr['post_status'] = (string) ($s['status_default'] ?? 'publish');
    }

    if ($existing) {
        $postarr['ID'] = $existing;
        $pid = wp_update_post(wp_slash($postarr), true);
    } else {
        if (!isset($postarr['post_title'])) $postarr['post_title'] = '';
        $pid = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($pid) || !$pid) {
        $s['stats']['errors'][] = 'Wiersz ' . ($s['offset'] + 1) . ': ' . (is_wp_error($pid) ? $pid->get_error_message() : 'zapis nieudany');
        if (count($s['stats']['errors']) > 50) array_shift($s['stats']['errors']);
        return;
    }
    $pid = (int) $pid;
    $existing ? $s['stats']['updated']++ : $s['stats']['created']++;

    // ── Taksonomie ──
    $tax_targets = evk_csv_tax_targets($post_type);
    foreach ($vals as $target => $v) {
        if (strpos($target, 'tax:') !== 0 || !isset($tax_targets[$target])) continue;
        $tax = substr($target, 4);
        if ($v === '') { if (!($s['skip_empty'] ?? true)) wp_set_object_terms($pid, [], $tax, false); continue; }
        $names = array_filter(array_map('trim', explode('|', $v)), 'strlen');
        $term_ids = [];
        foreach ($names as $name) {
            $term_ids = array_merge($term_ids, evk_csv_resolve_term($name, $tax, !empty($s['create_terms'])));
        }
        if ($term_ids) wp_set_object_terms($pid, array_map('intval', $term_ids), $tax, false);
    }

    // ── Proste pola EVK ──
    $evk_targets = evk_csv_evk_targets($post_type);
    foreach ($vals as $target => $v) {
        if (strpos($target, 'evk:') !== 0 || !isset($evk_targets[$target])) continue;
        $key = substr($target, 4);
        $def = $evk_targets[$target];
        if ($v === '' && ($s['skip_empty'] ?? true)) continue; // pusta komórka nie nadpisuje
        $store = evk_csv_field_value($def['type'], $def['field'], $v);
        if ($store === '' || $store === null) delete_post_meta($pid, $key);
        else                                  update_post_meta($pid, $key, $store);
    }

    // Tytuł z pola EVK (CPT bez „title") — po zapisaniu mety, przed recalc.
    // Import zapisuje metę sam (poza save_post EVK), więc wołamy jawnie.
    if (function_exists('evk_sync_cpt_title')) evk_sync_cpt_title($pid);

    // Pola calc tego wpisu — przelicz z zapisanej mety.
    if (function_exists('evk_rep_recalc')) evk_rep_recalc($pid, 'post');
}

/** Konwersja wartości CSV → wartość przechowywana, wg typu pola (+ sanityzacja EVK). */
function evk_csv_field_value(string $type, array $field, string $v) {
    switch ($type) {
        case 'number':
        case 'range':
            // CSV bywa w locale PL: „1 234,56" → „1234.56", by pole liczbowe zapisało
            // liczbę (nie string), co zachowuje sortowanie meta_value_num i calc.
            $n = str_replace([' ', "\xc2\xa0"], '', $v); // spacje + NBSP jako separator tysięcy
            if (strpos($n, ',') !== false && strpos($n, '.') === false) $n = str_replace(',', '.', $n);
            $v = $n;
            break;
        case 'checkbox': return evk_csv_bool($v) ? '1' : '';
        case 'toggle':
            $on = (string) ($field['toggle_on'] ?? '1');
            $off = (string) ($field['toggle_off'] ?? '0');
            // „1/tak/…" → wartość ON; wartość równa ON też → ON; inaczej OFF gdy niepuste.
            if (evk_csv_bool($v) || $v === $on) return $on;
            return $v === '' ? '' : $off;
        case 'select':
        case 'radio':
        case 'button_group':
            $opts = function_exists('evk_rep_parse_options') ? evk_rep_parse_options($field['options'] ?? '') : [];
            $v = evk_csv_map_choice($v, $opts);
            break;
        case 'date':
        case 'time':
        case 'datetime':
            $v = evk_csv_normalize_datetime($type, $v);
            break;
    }
    return function_exists('evk_rep_sanitize_value') ? evk_rep_sanitize_value($type, $v, $field) : $v;
}

/** Znajdź istniejący wpis wg klucza dopasowania. 0 = brak / tryb „zawsze twórz". */
function evk_csv_match_existing(array $s, array $vals, string $post_type): int {
    $key = (string) ($s['match_key'] ?? 'none');
    if ($key === 'none') return 0;

    if ($key === 'post_id') {
        $id = (int) ($vals['core:id'] ?? 0);
        return ($id > 0 && get_post_type($id) === $post_type) ? $id : 0;
    }
    if ($key === 'slug') {
        $slug = sanitize_title((string) ($vals['core:slug'] ?? ''));
        if ($slug === '') return 0;
        $q = get_posts(['post_type' => $post_type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
        return $q ? (int) $q[0] : 0;
    }
    if ($key === 'title') {
        $title = (string) ($vals['core:title'] ?? '');
        if ($title === '') return 0;
        $q = get_posts(['post_type' => $post_type, 'title' => $title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
        return $q ? (int) $q[0] : 0;
    }
    if (strpos($key, 'evk:') === 0) {
        $mk = substr($key, 4);
        $mv = (string) ($vals[$key] ?? '');
        if ($mv === '') return 0;
        $q = get_posts([
            'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_key' => $mk, 'meta_value' => $mv,
        ]);
        return $q ? (int) $q[0] : 0;
    }
    return 0;
}

/** Zwróć ID termu po nazwie/slug; utwórz, gdy pozwolono i nie istnieje. [] = brak. */
function evk_csv_resolve_term(string $name, string $tax, bool $create): array {
    $term = get_term_by('name', $name, $tax);
    if (!$term) $term = get_term_by('slug', sanitize_title($name), $tax);
    if ($term && !is_wp_error($term)) return [(int) $term->term_id];
    if (!$create) return [];
    $res = wp_insert_term($name, $tax);
    return (is_array($res) && !empty($res['term_id'])) ? [(int) $res['term_id']] : [];
}

// =========================================================================
// NOTICE / REDIRECT (wzorzec PRG, jak w tools.php)
// =========================================================================

function evk_csv_notice(string $type, string $msg): void {
    set_transient('evk_csv_notice_' . get_current_user_id(), ['type' => $type, 'msg' => $msg], 60);
}
function evk_csv_redirect(): void {
    wp_safe_redirect(add_query_arg(['page' => 'evk-import'], admin_url('admin.php')));
    exit;
}

// =========================================================================
// STRONA (UI trzech kroków)
// =========================================================================

function evk_csv_page(): void {
    if (!evk_rep_can_manage()) return;
    $notice = get_transient('evk_csv_notice_' . get_current_user_id());
    if ($notice) delete_transient('evk_csv_notice_' . get_current_user_id());
    $s    = evk_csv_get_session();
    $step = $s['step'] ?? 'upload';
    ?>
    <div class="wrap evk-b-wrap">
        <h1><span class="dashicons dashicons-database-import"></span> Import / Eksport CSV</h1>
        <?php if ($notice): ?>
            <div class="notice notice-<?php echo in_array($notice['type'], ['error', 'warning'], true) ? esc_attr($notice['type']) : 'success'; ?> is-dismissible"><p><?php echo esc_html($notice['msg']); ?></p></div>
        <?php endif; ?>

        <div class="evk-b-info">
            <span class="dashicons dashicons-info-outline"></span>
            <div>Import do <strong>typów treści</strong>: rdzeń wpisu (tytuł/treść/status…), <strong>taksonomie</strong>
            (po nazwie/slug) i <strong>proste pola EVK</strong> grup pojedynczych. Pola obliczeniowe przeliczają się po imporcie.
            Duże pliki przetwarzane są porcjami — nie zamykaj karty w trakcie.</div>
        </div>

        <?php
        if ($step === 'map' && !empty($s['headers']))      evk_csv_render_map($s);
        elseif (in_array($step, ['run', 'done'], true))    evk_csv_render_run($s);
        else { evk_csv_render_upload(); evk_csv_render_export(); evk_csv_render_opt_export(); }
        ?>
    </div>
    <?php
}

function evk_csv_render_upload(): void {
    ?>
    <div class="evk-settings-group">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-upload" style="vertical-align:text-bottom;color:#2563eb;"></span> Krok 1 — wgraj plik CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('evk_csv', 'evk_csv_nonce'); ?>
            <p><input type="file" name="evk_csv_file" accept=".csv,text/csv" required></p>
            <p style="display:flex;gap:20px;flex-wrap:wrap;">
                <label>Separator<br>
                    <select name="evk_csv_delimiter">
                        <option value="auto">Wykryj automatycznie</option>
                        <option value="comma">Przecinek ( , )</option>
                        <option value="semicolon">Średnik ( ; )</option>
                        <option value="tab">Tabulator</option>
                        <option value="pipe">Kreska ( | )</option>
                    </select>
                </label>
                <label>Kodowanie<br>
                    <select name="evk_csv_encoding">
                        <option value="UTF-8">UTF-8</option>
                        <option value="Windows-1250">Windows-1250 (środkowoeuropejskie)</option>
                        <option value="ISO-8859-2">ISO-8859-2</option>
                    </select>
                </label>
            </p>
            <button type="submit" name="evk_csv_upload" value="1" class="button button-primary"><span class="dashicons dashicons-arrow-right-alt2" style="vertical-align:text-bottom;"></span> Dalej — mapowanie</button>
        </form>
    </div>
    <?php
}

function evk_csv_render_export(): void {
    $pts = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
        if (in_array($pt->name, ['attachment', 'evk_field_group'], true)) continue;
        $pts[$pt->name] = $pt->labels->singular_name ?: $pt->name;
    }
    // Wybór typu (GET ?ept=) odświeża listę kolumn; domyślnie pierwszy.
    $sel = sanitize_key($_GET['ept'] ?? (array_key_first($pts) ?: 'post'));
    if (!isset($pts[$sel])) $sel = array_key_first($pts) ?: 'post';
    $targets = evk_csv_export_targets($sel);
    ?>
    <div class="evk-settings-group">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-database-export" style="vertical-align:text-bottom;color:#2563eb;"></span> Eksport wartości do CSV</h2>
        <p style="margin-top:0;color:#475569;">Pobierz wartości wpisów (rdzeń + proste pola EVK + taksonomie po nazwie) jako CSV.
            Nagłówki są tak dobrane, aby ten sam plik dało się z powrotem <strong>zaimportować</strong> (auto-mapowanie po nazwie kolumny).</p>

        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="evk-import">
            <label><strong>Typ treści:</strong>
                <select name="ept" onchange="this.form.submit()">
                    <?php foreach ($pts as $ptk => $ptl): ?>
                    <option value="<?php echo esc_attr($ptk); ?>" <?php selected($sel, $ptk); ?>><?php echo esc_html($ptl); ?> (<?php echo esc_html($ptk); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span class="description">Zmiana typu odświeża listę kolumn.</span>
        </form>

        <form method="post">
            <?php wp_nonce_field('evk_csv_export', 'evk_csv_export_nonce'); ?>
            <input type="hidden" name="evk_csv_export_pt" value="<?php echo esc_attr($sel); ?>">

            <div style="margin:8px 0;">
                <label style="font-weight:600;">Kolumny do eksportu</label>
                <p style="margin:4px 0 6px;">
                    <a href="#" class="evk-csv-exp-all" data-on="1">zaznacz wszystkie</a> ·
                    <a href="#" class="evk-csv-exp-all" data-on="0">odznacz wszystkie</a>
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 16px;max-width:900px;">
                    <?php foreach ($targets as $target => $label): ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
                        <input type="checkbox" name="evk_csv_export_cols[]" value="<?php echo esc_attr($target); ?>" checked>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">
                <label>Zakres<br>
                    <select name="evk_csv_export_status">
                        <option value="any">Wszystkie (poza koszem)</option>
                        <option value="publish">Tylko opublikowane</option>
                    </select>
                </label>
                <label>Separator<br>
                    <select name="evk_csv_export_delim">
                        <option value="comma">Przecinek ( , )</option>
                        <option value="semicolon">Średnik ( ; ) — Excel PL</option>
                        <option value="tab">Tabulator</option>
                    </select>
                </label>
                <label>Kodowanie<br>
                    <select name="evk_csv_export_enc">
                        <option value="UTF-8">UTF-8</option>
                        <option value="Windows-1250">Windows-1250</option>
                        <option value="ISO-8859-2">ISO-8859-2</option>
                    </select>
                </label>
                <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="evk_csv_export_bom" value="1" checked> BOM (UTF-8, dla Excela)</label>
            </p>

            <button type="submit" name="evk_csv_export" value="1" class="button button-primary"><span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span> Pobierz CSV</button>
        </form>
    </div>
    <script>
    document.querySelectorAll('.evk-csv-exp-all').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var on = a.getAttribute('data-on') === '1';
            a.closest('form').querySelectorAll('input[name="evk_csv_export_cols[]"]').forEach(function (c) { c.checked = on; });
        });
    });
    </script>
    <?php
}

function evk_csv_render_opt_export(): void {
    $targets = evk_csv_option_group_targets();
    ?>
    <div class="evk-settings-group">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-admin-settings" style="vertical-align:text-bottom;color:#2563eb;"></span> Eksport wartości stron opcji do CSV</h2>
        <p style="margin-top:0;color:#475569;">Pobierz wartości zapisane na <strong>stronach ustawień</strong> (opcje witryny, nie wpisy).
            Grupa-repeater eksportuje się jako <strong>tabela</strong> (wiersz = wiersz repeatera), grupa pojedyncza jako pary <strong>Pole / Wartość</strong>.
            Uwzględniane są proste typy pól.</p>

        <?php if (!$targets): ?>
            <div class="evk-b-info" style="margin:0;">
                <span class="dashicons dashicons-info-outline"></span>
                <div>Brak grup pól przypisanych do stron ustawień. Utwórz stronę i przypisz do niej grupy w
                <a href="<?php echo esc_url(admin_url('admin.php?page=evk-settings')); ?>">Strony ustawień</a>.</div>
            </div>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('evk_csv_opt_export', 'evk_csv_opt_export_nonce'); ?>
            <p style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;margin-top:4px;">
                <label>Grupa pól<br>
                    <select name="evk_csv_opt_export_group" class="evk-csv-opt-group">
                        <?php foreach ($targets as $gk => $d): ?>
                        <option value="<?php echo esc_attr($gk); ?>" data-repeater="<?php echo $d['repeater'] ? '1' : '0'; ?>">
                            <?php echo esc_html($d['label']); ?> — <?php echo esc_html($d['page']); ?> (<?php echo $d['repeater'] ? 'repeater' : 'pojedyncza'; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Separator<br>
                    <select name="evk_csv_opt_export_delim">
                        <option value="comma">Przecinek ( , )</option>
                        <option value="semicolon">Średnik ( ; ) — Excel PL</option>
                        <option value="tab">Tabulator</option>
                    </select>
                </label>
                <label>Kodowanie<br>
                    <select name="evk_csv_opt_export_enc">
                        <option value="UTF-8">UTF-8</option>
                        <option value="Windows-1250">Windows-1250</option>
                        <option value="ISO-8859-2">ISO-8859-2</option>
                    </select>
                </label>
                <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="evk_csv_opt_export_bom" value="1" checked> BOM (UTF-8, dla Excela)</label>
            </p>
            <p class="evk-csv-opt-hint description" style="margin:0 0 12px;"></p>
            <button type="submit" name="evk_csv_opt_export" value="1" class="button button-primary"><span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span> Pobierz CSV</button>
        </form>
        <script>
        (function () {
            var sel = document.querySelector('.evk-csv-opt-group');
            var hint = document.querySelector('.evk-csv-opt-hint');
            if (!sel || !hint) return;
            function upd() {
                var o = sel.options[sel.selectedIndex];
                hint.textContent = (o && o.getAttribute('data-repeater') === '1')
                    ? 'Format: tabela — nagłówek to etykiety pól, każdy wiersz repeatera to wiersz CSV.'
                    : 'Format: pionowy — kolumny „Pole” i „Wartość”, jeden wiersz na pole.';
            }
            sel.addEventListener('change', upd); upd();
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
}

function evk_csv_render_map(array $s): void {
    $headers = (array) $s['headers'];
    $sample  = (array) ($s['sample'] ?? []);
    $pts     = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
        if (in_array($pt->name, ['attachment', 'evk_field_group'], true)) continue;
        $pts[$pt->name] = $pt->labels->singular_name ?: $pt->name;
    }
    // Domyślnie pierwszy CPT/wpis; cele budujemy dla niego, przełączenie typu = przeładowanie (JS submit).
    $sel_pt = sanitize_key($_GET['pt'] ?? (array_key_first($pts) ?: 'post'));
    if (!isset($pts[$sel_pt])) $sel_pt = array_key_first($pts) ?: 'post';

    $core = evk_csv_core_targets();
    $tax  = evk_csv_tax_targets($sel_pt);
    $evk  = evk_csv_evk_targets($sel_pt);

    // Auto-dopasowanie kolumny → cel po nazwie nagłówka (etykieta/klucz).
    $auto = function (string $header) use ($core, $tax, $evk): string {
        $h = mb_strtolower(trim($header));
        $map = ['tytuł' => 'core:title', 'title' => 'core:title', 'treść' => 'core:content', 'content' => 'core:content', 'status' => 'core:status', 'slug' => 'core:slug', 'data' => 'core:date', 'id' => 'core:id', 'zajawka' => 'core:excerpt', 'excerpt' => 'core:excerpt', 'kolejność' => 'core:menu_order', 'menu_order' => 'core:menu_order'];
        if (isset($map[$h])) return $map[$h];
        foreach ($evk as $k => $d) { if (mb_strtolower($d['label']) === $h || 'evk:' . $h === $k) return $k; }
        foreach ($tax as $k => $l) { if (mb_strtolower($l) === $h) return $k; }
        return '';
    };
    ?>
    <form method="get" style="margin-bottom:12px;">
        <input type="hidden" name="page" value="evk-import">
        <label><strong>Typ treści (cel importu):</strong>
            <select name="pt" onchange="this.form.submit()">
                <?php foreach ($pts as $ptk => $ptl): ?>
                <option value="<?php echo esc_attr($ptk); ?>" <?php selected($sel_pt, $ptk); ?>><?php echo esc_html($ptl); ?> (<?php echo esc_html($ptk); ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <span class="description">Zmiana typu odświeża listę dostępnych pól i taksonomii.</span>
    </form>

    <form method="post">
        <?php wp_nonce_field('evk_csv', 'evk_csv_nonce'); ?>
        <input type="hidden" name="evk_csv_post_type" value="<?php echo esc_attr($sel_pt); ?>">

        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-editor-table" style="vertical-align:text-bottom;color:#2563eb;"></span> Krok 2 — mapuj kolumny</h2>
            <table class="widefat striped">
                <thead><tr><th style="width:30%;">Kolumna CSV</th><th style="width:35%;">Przykład</th><th style="width:35%;">Zapisz do</th></tr></thead>
                <tbody>
                <?php foreach ($headers as $i => $h):
                    $ex = '';
                    foreach ($sample as $r) { if (isset($r[$i]) && trim((string) $r[$i]) !== '') { $ex = (string) $r[$i]; break; } }
                    $pre = $auto((string) $h);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $h); ?></strong></td>
                        <td><code style="font-size:11px;color:#64748b;"><?php echo esc_html(mb_strimwidth($ex, 0, 60, '…')); ?></code></td>
                        <td>
                            <select name="evk_csv_map[<?php echo (int) $i; ?>]" style="width:100%;">
                                <option value="">— ignoruj —</option>
                                <optgroup label="Rdzeń wpisu">
                                    <?php foreach ($core as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($pre, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                                </optgroup>
                                <?php if ($tax): ?><optgroup label="Taksonomie">
                                    <?php foreach ($tax as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($pre, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                                </optgroup><?php endif; ?>
                                <?php if ($evk): ?><optgroup label="Pola EVK">
                                    <?php foreach ($evk as $k => $d): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($pre, $k); ?>><?php echo esc_html($d['label']); ?> (<?php echo esc_html($d['type']); ?>)</option><?php endforeach; ?>
                                </optgroup><?php endif; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-update" style="vertical-align:text-bottom;color:#2563eb;"></span> Tryb i opcje</h2>
            <p style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end;">
                <label>Dopasuj istniejące wpisy po<br>
                    <select name="evk_csv_match_key">
                        <option value="none">— zawsze twórz nowe —</option>
                        <option value="post_id">ID wpisu (kolumna „ID wpisu")</option>
                        <option value="slug">Slug</option>
                        <option value="title">Tytuł</option>
                        <?php foreach ($evk as $k => $d): ?><option value="<?php echo esc_attr($k); ?>">Pole EVK: <?php echo esc_html($d['label']); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Gdy znaleziono dopasowanie<br>
                    <select name="evk_csv_on_match">
                        <option value="update">Aktualizuj wpis</option>
                        <option value="skip">Pomiń wiersz</option>
                    </select>
                </label>
                <label>Status nowych wpisów<br>
                    <select name="evk_csv_status">
                        <option value="publish">Opublikowany</option>
                        <option value="draft">Szkic</option>
                        <option value="pending">Oczekujący</option>
                        <option value="private">Prywatny</option>
                    </select>
                </label>
            </p>
            <p><label><input type="checkbox" name="evk_csv_create_terms" value="1" checked> Twórz brakujące termy taksonomii</label></p>
            <p><label><input type="checkbox" name="evk_csv_write_empty" value="1"> Puste komórki nadpisują istniejące wartości (domyślnie: puste są pomijane)</label></p>
            <p>
                <button type="submit" name="evk_csv_run" value="1" class="button button-primary"><span class="dashicons dashicons-controls-play" style="vertical-align:text-bottom;"></span> Rozpocznij import</button>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'evk-import', 'reset' => 1], admin_url('admin.php'))); ?>" class="button">Anuluj / wgraj inny plik</a>
            </p>
        </div>
    </form>
    <?php
}

function evk_csv_render_run(array $s): void {
    $total = (int) ($s['total'] ?? 0);
    $done  = (int) ($s['offset'] ?? 0);
    $st    = (array) ($s['stats'] ?? []);
    $finished = ($s['step'] ?? '') === 'done';
    $pct = $total > 0 ? min(100, (int) round($done / $total * 100)) : 100;
    $reset_url = esc_url(add_query_arg(['page' => 'evk-import', 'reset' => 1], admin_url('admin.php')));
    ?>
    <div class="evk-settings-group" id="evk-csv-run"
         data-total="<?php echo (int) $total; ?>"
         data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('evk_csv_ajax')); ?>"
         data-reset="<?php echo $reset_url; ?>">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-database-import" style="vertical-align:text-bottom;color:#2563eb;"></span> Krok 3 — import <span id="evk-csv-state"><?php echo $finished ? '(zakończony)' : 'w toku…'; ?></span></h2>
        <div style="background:#e2e8f0;border-radius:8px;height:22px;overflow:hidden;max-width:600px;">
            <div id="evk-csv-bar" style="background:<?php echo $finished ? '#16a34a' : '#2563eb'; ?>;height:100%;width:<?php echo (int) $pct; ?>%;transition:width .25s;"></div>
        </div>
        <p style="margin:8px 0;color:#475569;">
            <span id="evk-csv-done"><?php echo (int) $done; ?></span> / <span id="evk-csv-total"><?php echo (int) $total; ?></span> wierszy
            (<span id="evk-csv-pct"><?php echo (int) $pct; ?></span>%) —
            utworzono <strong id="evk-csv-created"><?php echo (int) ($st['created'] ?? 0); ?></strong>,
            zaktualizowano <strong id="evk-csv-updated"><?php echo (int) ($st['updated'] ?? 0); ?></strong>,
            pominięto <strong id="evk-csv-skipped"><?php echo (int) ($st['skipped'] ?? 0); ?></strong>.
        </p>

        <div id="evk-csv-errors" style="<?php echo empty($st['errors']) ? 'display:none;' : ''; ?>margin:8px 0;">
            <details><summary style="cursor:pointer;color:#b91c1c;">Błędy (<span id="evk-csv-errcount"><?php echo count((array) ($st['errors'] ?? [])); ?></span>)</summary>
                <ul id="evk-csv-errlist" style="margin:6px 0 0 18px;list-style:disc;color:#b91c1c;font-size:12px;">
                    <?php foreach ((array) ($st['errors'] ?? []) as $e): ?><li><?php echo esc_html($e); ?></li><?php endforeach; ?>
                </ul>
            </details>
        </div>

        <div id="evk-csv-done-box" style="<?php echo $finished ? '' : 'display:none;'; ?>">
            <p style="color:#166534;"><span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;"></span> Import zakończony.</p>
            <a href="<?php echo $reset_url; ?>" class="button button-primary">Nowy import</a>
        </div>

        <?php if (!$finished): ?>
        <div id="evk-csv-running">
            <p style="color:#64748b;margin:6px 0;"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Przetwarzanie w toku — nie zamykaj tej karty.</p>
            <a href="<?php echo $reset_url; ?>" class="button">Przerwij</a>
        </div>
        <!-- Fallback bez JS: ręczne porcjowanie przez POST. -->
        <noscript>
            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field('evk_csv', 'evk_csv_nonce'); ?>
                <button type="submit" name="evk_csv_process" value="1" class="button button-primary">Przetwórz kolejną porcję</button>
            </form>
        </noscript>
        <?php endif; ?>
    </div>

    <?php if (!$finished): ?>
    <script>
    (function () {
        var box = document.getElementById('evk-csv-run');
        if (!box) return;
        var ajax = box.getAttribute('data-ajax'), nonce = box.getAttribute('data-nonce');
        var elBar = document.getElementById('evk-csv-bar'), elDone = document.getElementById('evk-csv-done'),
            elTot = document.getElementById('evk-csv-total'), elPct = document.getElementById('evk-csv-pct'),
            elC = document.getElementById('evk-csv-created'), elU = document.getElementById('evk-csv-updated'),
            elS = document.getElementById('evk-csv-skipped'), elState = document.getElementById('evk-csv-state'),
            errBox = document.getElementById('evk-csv-errors'), errCount = document.getElementById('evk-csv-errcount'),
            errList = document.getElementById('evk-csv-errlist'), running = document.getElementById('evk-csv-running'),
            doneBox = document.getElementById('evk-csv-done-box');

        function render(d) {
            var total = d.total || 0, done = d.offset || 0, st = d.stats || {};
            var pct = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 100;
            elBar.style.width = pct + '%';
            elDone.textContent = done; elTot.textContent = total; elPct.textContent = pct;
            elC.textContent = st.created || 0; elU.textContent = st.updated || 0; elS.textContent = st.skipped || 0;
            var errs = st.errors || [];
            if (errs.length) {
                errBox.style.display = ''; errCount.textContent = errs.length;
                errList.innerHTML = errs.map(function (e) { var li = document.createElement('li'); li.textContent = e; return li.outerHTML; }).join('');
            }
        }
        function finish() {
            elBar.style.background = '#16a34a';
            elState.textContent = '(zakończony)';
            if (running) running.style.display = 'none';
            if (doneBox) doneBox.style.display = '';
        }
        function fail(msg) {
            elState.textContent = '— błąd';
            if (running) running.innerHTML = '<p style="color:#b91c1c;">Import przerwany: ' + (msg || 'błąd serwera') +
                '. <a href="' + box.getAttribute('data-reset') + '">Zacznij od nowa</a>.</p>';
        }
        function tick() {
            var body = new URLSearchParams(); body.append('action', 'evk_csv_process'); body.append('nonce', nonce);
            fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { fail(res && res.data && res.data.error); return; }
                    render(res.data);
                    if (res.data.done) finish(); else setTimeout(tick, 60);
                })
                .catch(function () { fail(); });
        }
        setTimeout(tick, 200);
    })();
    </script>
    <?php endif; ?>
    <?php
}

// Reset sesji z linku „Nowy import / Anuluj".
add_action('admin_init', function () {
    if (empty($_GET['reset']) || ($_GET['page'] ?? '') !== 'evk-import') return;
    if (!evk_rep_can_manage()) return;
    evk_csv_clear_session(true);
    evk_csv_redirect();
});
