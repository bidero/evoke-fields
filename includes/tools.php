<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — Narzędzia.
 *
 * 1. Eksport pełnej konfiguracji (grupy pól + CPT + taksonomie + strony ustawień +
 *    wartości stron opcji) do pliku JSON.
 * 2. Import takiego pliku (merge wg klucza/slug; nadpisanie istniejących opcjonalne).
 * 3. Czyszczenie osieroconych kluczy — bezpieczne: tylko opcje evk_rep_opt_* po
 *    skasowanych grupach oraz martwe odwołania do grup w stronach ustawień.
 *
 * Dane pól z wpisów (postmeta) NIE są obejmowane — są związane z konkretnymi ID
 * wpisów/załączników i nieprzenośne między stronami.
 */

const EVK_TOOLS_OPT_PREFIX = 'evk_rep_opt_';

// =========================================================================
// MENU
// =========================================================================

add_action('admin_menu', function () {
    add_submenu_page('evk-repeater', 'Narzędzia', 'Narzędzia', 'manage_options', 'evk-tools', 'evk_tools_page');
}, 25);

// =========================================================================
// HELPERY — odczyt konfiguracji
// =========================================================================

/** Wszystkie klucze grup (dowolny status poza koszem) — do wykrywania sierot. */
function evk_tools_all_group_keys(): array {
    $posts = get_posts([
        'post_type'      => 'evk_field_group',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts'    => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    $keys = [];
    foreach ($posts as $pid) {
        $k = get_post_meta($pid, '_evk_key', true);
        if ($k) $keys[] = $k;
    }
    return $keys;
}

function evk_tools_find_group_post_by_key(string $key): int {
    $posts = get_posts([
        'post_type'      => 'evk_field_group',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts'    => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => '_evk_key',
        'meta_value'     => $key,
    ]);
    return $posts ? (int) $posts[0] : 0;
}

function evk_tools_export_groups(): array {
    $out   = [];
    $posts = get_posts([
        'post_type'      => 'evk_field_group',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts'    => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);
    foreach ($posts as $p) {
        $key = get_post_meta($p->ID, '_evk_key', true);
        if (!$key) continue;
        $json   = get_post_meta($p->ID, '_evk_fields', true);
        $fields = $json ? (json_decode($json, true) ?? []) : [];
        $pts    = get_post_meta($p->ID, '_evk_post_types', true);

        $obj = get_post_meta($p->ID, '_evk_object_type', true);
        $tax = get_post_meta($p->ID, '_evk_taxonomies', true);

        $entry = [
            'key'         => $key,
            'label'       => $p->post_title,
            'status'      => $p->post_status,
            'menu_order'  => (int) $p->menu_order,
            'object_type' => in_array($obj, ['post', 'term', 'user', 'media'], true) ? $obj : 'post',
            'post_types'  => is_array($pts) && $pts ? array_values($pts) : ['post'],
            'taxonomies'  => is_array($tax) ? array_values($tax) : [],
            'repeater'    => (bool) get_post_meta($p->ID, '_evk_repeater', true),
            'collapsed'   => (bool) get_post_meta($p->ID, '_evk_collapsed', true),
            'seamless'    => (bool) get_post_meta($p->ID, '_evk_seamless', true),
            'hide_title'  => (bool) get_post_meta($p->ID, '_evk_hide_title', true),
            'fields'      => $fields,
        ];
        $al = get_post_meta($p->ID, '_evk_add_label', true);
        if ($al) $entry['add_label'] = (string) $al;
        $tf = get_post_meta($p->ID, '_evk_title_field', true);
        if ($tf) $entry['title_field'] = (string) $tf;
        $out[] = $entry;
    }
    return $out;
}

function evk_tools_export_option_values(): array {
    global $wpdb;
    $like  = $wpdb->esc_like(EVK_TOOLS_OPT_PREFIX) . '%';
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    $out   = [];
    foreach ((array) $names as $n) {
        $out[$n] = get_option($n);
    }
    return $out;
}

function evk_tools_build_export(): array {
    return [
        'plugin'         => 'evoke-fields',
        'schema_version' => 1,
        'plugin_version' => EVK_REP_VERSION,
        'exported_at'    => gmdate('c'),
        'site_url'       => home_url(),
        'groups'         => evk_tools_export_groups(),
        'post_types'     => array_values((array) get_option('evk_custom_post_types', [])),
        'taxonomies'     => array_values((array) get_option('evk_taxonomies', [])),
        'settings_pages' => (array) get_option('evk_rep_settings_pages', []),
        'option_values'  => evk_tools_export_option_values(),
    ];
}

// =========================================================================
// HELPERY — notice przez transient (wzorzec PRG)
// =========================================================================

function evk_tools_set_notice(string $type, string $msg): void {
    set_transient('evk_tools_notice_' . get_current_user_id(), ['type' => $type, 'msg' => $msg], 60);
}

function evk_tools_redirect(): void {
    wp_safe_redirect(add_query_arg(['page' => 'evk-tools'], admin_url('admin.php')));
    exit;
}

// =========================================================================
// EKSPORT (download)
// =========================================================================

add_action('admin_init', function () {
    if (empty($_POST['evk_tools_export'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('evk_tools_export', 'evk_tools_nonce');

    $data = evk_tools_build_export();
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=evoke-fields-' . gmdate('Ymd-His') . '.json');
    echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// =========================================================================
// IMPORT
// =========================================================================

/** Merge listy wpisów po slugu (CPT / taksonomie). */
function evk_tools_merge_by_slug(array $existing, array $incoming, bool $overwrite): array {
    $by = [];
    foreach ($existing as $e) { if (is_array($e) && !empty($e['slug'])) $by[$e['slug']] = $e; }
    foreach ($incoming as $i) {
        if (!is_array($i) || empty($i['slug'])) continue;
        if (isset($by[$i['slug']]) && !$overwrite) continue;
        $by[$i['slug']] = $i;
    }
    return array_values($by);
}

function evk_tools_run_import(array $data, bool $overwrite): array {
    $created = $updated = $skipped = 0;

    // ── Grupy pól (per klucz) ──
    foreach ((array) ($data['groups'] ?? []) as $g) {
        if (!is_array($g) || empty($g['key'])) continue;
        $key = sanitize_key($g['key']);
        if ($key === '') continue;

        $existing = evk_tools_find_group_post_by_key($key);
        if ($existing && !$overwrite) { $skipped++; continue; }

        $status  = in_array($g['status'] ?? 'publish', ['publish', 'draft', 'pending', 'private', 'future'], true) ? $g['status'] : 'publish';
        $postarr = [
            'post_type'   => 'evk_field_group',
            'post_title'  => sanitize_text_field($g['label'] ?? $key),
            'post_status' => $status,
            'menu_order'  => (int) ($g['menu_order'] ?? 0),
        ];
        if ($existing) { $postarr['ID'] = $existing; $pid = wp_update_post($postarr, true); }
        else           { $pid = wp_insert_post($postarr, true); }
        if (is_wp_error($pid) || !$pid) continue;
        $existing ? $updated++ : $created++;

        update_post_meta($pid, '_evk_key', $key);
        update_post_meta($pid, '_evk_fields', wp_slash(wp_json_encode((array) ($g['fields'] ?? []), JSON_UNESCAPED_UNICODE)));
        $pts = array_values(array_filter(array_map('sanitize_key', (array) ($g['post_types'] ?? ['post']))));
        update_post_meta($pid, '_evk_post_types', $pts ?: ['post']);
        update_post_meta($pid, '_evk_repeater',  !empty($g['repeater'])  ? 1 : 0);
        update_post_meta($pid, '_evk_collapsed', !empty($g['collapsed']) ? 1 : 0);
        update_post_meta($pid, '_evk_seamless',  !empty($g['seamless'])  ? 1 : 0);
        // Lokalizacja grupy. Stare pliki eksportu (≤1.52.x) nie mają tych kluczy —
        // wtedy NIE nadpisujemy istniejącej grupy (import zdegradowałby np. grupę
        // termów do grupy wpisów); nowa grupa dostaje domyślne 'post'.
        if (array_key_exists('object_type', $g) || !$existing) {
            $obj = in_array($g['object_type'] ?? 'post', ['post', 'term', 'user', 'media'], true) ? ($g['object_type'] ?? 'post') : 'post';
            update_post_meta($pid, '_evk_object_type', $obj);
        }
        if (array_key_exists('taxonomies', $g) || !$existing) {
            $txs = array_values(array_filter(array_map('sanitize_key', (array) ($g['taxonomies'] ?? []))));
            update_post_meta($pid, '_evk_taxonomies', $txs);
        }
        if (array_key_exists('hide_title', $g) || !$existing) {
            update_post_meta($pid, '_evk_hide_title', !empty($g['hide_title']) ? 1 : 0);
        }
        if (!empty($g['add_label']))   update_post_meta($pid, '_evk_add_label', sanitize_text_field($g['add_label']));
        else                           delete_post_meta($pid, '_evk_add_label');
        if (!empty($g['title_field'])) update_post_meta($pid, '_evk_title_field', sanitize_key($g['title_field']));
        else                           delete_post_meta($pid, '_evk_title_field');
    }

    // ── CPT / taksonomie (merge po slugu) ──
    if (isset($data['post_types']) && is_array($data['post_types'])) {
        $merged = evk_tools_merge_by_slug((array) get_option('evk_custom_post_types', []), $data['post_types'], $overwrite);
        update_option('evk_custom_post_types', $merged);
        evk_rep_schedule_rewrite_flush();
    }
    if (isset($data['taxonomies']) && is_array($data['taxonomies'])) {
        $merged = evk_tools_merge_by_slug((array) get_option('evk_taxonomies', []), $data['taxonomies'], $overwrite);
        update_option('evk_taxonomies', $merged);
        evk_rep_schedule_rewrite_flush();
    }

    // ── Strony ustawień (mapa keyed po slugu) ──
    if (isset($data['settings_pages']) && is_array($data['settings_pages'])) {
        $pages = (array) get_option('evk_rep_settings_pages', []);
        foreach ($data['settings_pages'] as $slug => $page) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '' || !is_array($page)) continue;
            if (isset($pages[$slug]) && !$overwrite) continue;
            $pages[$slug] = $page;
        }
        update_option('evk_rep_settings_pages', $pages);
    }

    // ── Wartości stron opcji (per opcja) ──
    foreach ((array) ($data['option_values'] ?? []) as $name => $val) {
        if (strpos((string) $name, EVK_TOOLS_OPT_PREFIX) !== 0) continue;
        if (get_option($name, null) !== null && !$overwrite) continue;
        update_option($name, $val, false); // autoload=false — jak zapis w settings.php
    }

    evk_groups_cache_clear();
    return compact('created', 'updated', 'skipped');
}

add_action('admin_init', function () {
    if (empty($_POST['evk_tools_import'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('evk_tools_import', 'evk_tools_nonce');

    if (empty($_FILES['evk_import_file']['tmp_name']) || !is_uploaded_file($_FILES['evk_import_file']['tmp_name'])) {
        evk_tools_set_notice('error', 'Nie wybrano pliku importu lub przesyłanie nie powiodło się.');
        evk_tools_redirect();
    }
    if (!empty($_FILES['evk_import_file']['error'])) {
        evk_tools_set_notice('error', 'Błąd przesyłania pliku (kod ' . (int) $_FILES['evk_import_file']['error'] . ').');
        evk_tools_redirect();
    }

    $raw  = file_get_contents($_FILES['evk_import_file']['tmp_name']);
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || ($data['plugin'] ?? '') !== 'evoke-fields') {
        evk_tools_set_notice('error', 'To nie jest prawidłowy plik eksportu Evoke FIELDS.');
        evk_tools_redirect();
    }

    $overwrite = !empty($_POST['evk_import_overwrite']);
    $r = evk_tools_run_import($data, $overwrite);
    evk_tools_set_notice('success', sprintf(
        'Import zakończony: utworzono %d, zaktualizowano %d, pominięto %d grup. Konfiguracja CPT / taksonomii / stron i wartości opcji scalona. Odśwież, aby menu się zaktualizowało.',
        $r['created'], $r['updated'], $r['skipped']
    ));
    evk_tools_redirect();
});

// =========================================================================
// TOKENY WYGLĄDU PÓL (zmienne CSS sterowane z Narzędzi)
// Kilka tokenów zamiast pełnego edytora per-element — patrz README/CHANGELOG.
// =========================================================================

function evk_rep_style_token_defs(): array {
    return [
        'label_size'     => ['label' => 'Wielkość czcionki etykiety',     'var' => '--evk-label-size',     'def' => 13, 'min' => 9,  'max' => 20],
        'input_size'     => ['label' => 'Wielkość czcionki tekstu w polach', 'var' => '--evk-input-size',   'def' => 14, 'min' => 10, 'max' => 20],
        'label_gap'      => ['label' => 'Odstęp pod etykietą',            'var' => '--evk-label-gap',      'def' => 7,  'min' => 0,  'max' => 24],
        'heading_top'    => ['label' => 'Odstęp nad nagłówkiem',          'var' => '--evk-heading-top',    'def' => 16, 'min' => 0,  'max' => 48],
        'heading_bottom' => ['label' => 'Odstęp pod nagłówkiem',          'var' => '--evk-heading-bottom', 'def' => 2,  'min' => 0,  'max' => 40],
        'row_pad'        => ['label' => 'Padding pionowy pola (metabox)', 'var' => '--evk-row-pad',        'def' => 13, 'min' => 4,  'max' => 32],
    ];
}

function evk_rep_style_tokens(): array {
    $v = get_option('evk_rep_style_tokens', []);
    return is_array($v) ? $v : [];
}

/** ':root{...}' z NIEDOMYŚLNYCH tokenów (pusto = brak nadpisań → zero narzutu). */
function evk_rep_style_tokens_css(): string {
    $saved = evk_rep_style_tokens();
    $css = '';
    foreach (evk_rep_style_token_defs() as $k => $d) {
        if (!isset($saved[$k]) || $saved[$k] === '' || !is_numeric($saved[$k])) continue;
        $val = max($d['min'], min($d['max'], (int) $saved[$k]));
        if ($val === (int) $d['def']) continue; // równe domyślnej — nie nadpisuj
        $css .= $d['var'] . ':' . $val . 'px;';
    }
    return $css === '' ? '' : ':root{' . $css . '}';
}

add_action('admin_head', function () {
    $css = evk_rep_style_tokens_css();
    if ($css !== '') echo '<style id="evk-style-tokens">' . $css . "</style>\n";
});

add_action('admin_init', function () {
    if (empty($_POST['evk_tools_style_save'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('evk_tools_style', 'evk_tools_nonce');

    $out = [];
    foreach (evk_rep_style_token_defs() as $k => $d) {
        $raw = $_POST['evk_style'][$k] ?? '';
        if ($raw === '' || !is_numeric($raw)) continue;
        $out[$k] = max($d['min'], min($d['max'], (int) $raw));
    }
    update_option('evk_rep_style_tokens', $out, false);
    evk_tools_set_notice('success', 'Zapisano wygląd pól.');
    evk_tools_redirect();
});

// =========================================================================
// PRZELICZANIE PÓL OBLICZENIOWYCH (bulk evk_rep_recalc)
// =========================================================================

/**
 * Typy treści, których dotyczą grupy z polami calc (do selecta w UI).
 * Fallback: wszystkie publiczne, gdy żadna grupa nie ma pola calc.
 */
function evk_tools_recalc_post_types(): array {
    $pts = [];
    foreach (evk_rep_groups() as $g) {
        if (($g['object_type'] ?? 'post') !== 'post') continue;
        $has_calc = false;
        $walk = function (array $fs) use (&$walk, &$has_calc) {
            foreach ($fs as $f) {
                if (($f['type'] ?? '') === 'calc') { $has_calc = true; return; }
                if (($f['type'] ?? '') === 'repeater') $walk($f['sub_fields'] ?? []);
            }
        };
        $walk($g['fields'] ?? []);
        // Grupa-repeater bez calc też się liczy: jej wiersze mogą karmić calc z innej grupy.
        if ($has_calc || evk_rep_is_repeater($g)) {
            foreach ((array) ($g['post_types'] ?? []) as $pt) $pts[$pt] = true;
        }
    }
    $out = [];
    foreach (array_keys($pts) as $pt) {
        $obj = get_post_type_object($pt);
        if ($obj) $out[$pt] = $obj->labels->name . ' (' . $pt . ')';
    }
    if (!$out) {
        foreach (get_post_types(['public' => true], 'objects') as $pt => $obj) {
            if ($pt === 'attachment') continue;
            $out[$pt] = $obj->labels->name . ' (' . $pt . ')';
        }
    }
    return $out;
}

/**
 * Postęp wznowienia przeliczania (z konwersją starego formatu: post_type → scope).
 * Zwraca ['scope' => 'post:pt'|'options'|'term'|'user', 'offset' => int] albo null.
 */
function evk_tools_recalc_progress(): ?array {
    $p = get_option('evk_tools_recalc_progress');
    if (!is_array($p)) return null;
    if (isset($p['scope']))     return ['scope' => (string) $p['scope'], 'offset' => (int) ($p['offset'] ?? 0)];
    if (isset($p['post_type'])) return ['scope' => 'post:' . $p['post_type'], 'offset' => (int) ($p['offset'] ?? 0)];
    return null;
}

/** Czytelna etykieta zakresu przeliczania (dla notice i podpowiedzi wznowienia). */
function evk_tools_recalc_scope_label(string $scope): string {
    if (strpos($scope, 'post:') === 0) {
        $pt  = substr($scope, 5);
        $obj = get_post_type_object($pt);
        return $obj ? $obj->labels->name . ' (' . $pt . ')' : $pt;
    }
    if ($scope === 'options') return 'strony ustawień (opcje)';
    if ($scope === 'term')    return 'termy taksonomii';
    if ($scope === 'user')    return 'użytkownicy';
    return $scope;
}

add_action('admin_init', function () {
    if (empty($_POST['evk_tools_recalc'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('evk_tools_recalc', 'evk_tools_nonce');

    $scope = sanitize_text_field(wp_unslash($_POST['evk_recalc_scope'] ?? ''));

    // Strony ustawień: jeden przebieg (grup opcji jest najwyżej kilkanaście).
    if ($scope === 'options') {
        $n = evk_rep_recalc_options();
        evk_tools_set_notice('success', sprintf('Przeliczono pola obliczeniowe stron ustawień — zaktualizowano %d grup opcji.', $n));
        evk_tools_redirect();
    }

    // Pozostałe zakresy: lista ID + typ mety, wspólna pętla ze wznowieniem.
    $ids       = [];
    $meta_type = '';
    if (strpos($scope, 'post:') === 0) {
        $pt = sanitize_key(substr($scope, 5));
        if ($pt === '' || !post_type_exists($pt)) {
            evk_tools_set_notice('error', 'Wybierz prawidłowy typ treści.');
            evk_tools_redirect();
        }
        $meta_type = 'post';
        $ids = get_posts([
            'post_type'     => $pt,
            'post_status'   => 'any',
            'numberposts'   => -1,
            'fields'        => 'ids',
            'orderby'       => 'ID',
            'order'         => 'ASC',
            'no_found_rows' => true,
        ]);
    } elseif ($scope === 'term') {
        $taxes = [];
        foreach (evk_rep_groups_for_object('term') as $g) {
            foreach ((array) ($g['taxonomies'] ?? []) as $tx) {
                if (taxonomy_exists($tx)) $taxes[$tx] = true;
            }
        }
        if (!$taxes) {
            evk_tools_set_notice('error', 'Żadna aktywna grupa pól nie jest przypisana do termów taksonomii.');
            evk_tools_redirect();
        }
        $meta_type = 'term';
        $terms = get_terms(['taxonomy' => array_keys($taxes), 'hide_empty' => false, 'fields' => 'ids']);
        $ids   = is_wp_error($terms) ? [] : array_map('intval', $terms);
    } elseif ($scope === 'user') {
        if (!evk_rep_groups_for_object('user')) {
            evk_tools_set_notice('error', 'Żadna aktywna grupa pól nie jest przypisana do profilu użytkownika.');
            evk_tools_redirect();
        }
        $meta_type = 'user';
        $ids = array_map('intval', get_users(['fields' => 'ID']));
    } else {
        evk_tools_set_notice('error', 'Wybierz prawidłowy zakres przeliczania.');
        evk_tools_redirect();
    }

    // Stabilna kolejność między przebiegami — wznowienie liczy od offsetu.
    $ids = array_map('intval', (array) $ids);
    sort($ids);

    // Wznowienie przerwanego przebiegu TEGO SAMEGO zakresu (limit czasu hostingu).
    $progress = evk_tools_recalc_progress();
    $offset   = ($progress && $progress['scope'] === $scope) ? $progress['offset'] : 0;

    $total = count($ids);
    $start = time();
    @set_time_limit(0);

    for ($i = $offset; $i < $total; $i++) {
        evk_rep_recalc($ids[$i], $meta_type);
        // Cache mety rośnie z każdym obiektem — zrzucaj co 500, żeby nie puchła pamięć.
        if ((($i + 1) % 500) === 0) wp_cache_flush();
        if ((time() - $start) > 20 && ($i + 1) < $total) {
            update_option('evk_tools_recalc_progress', ['scope' => $scope, 'offset' => $i + 1], false);
            evk_tools_set_notice('warning', sprintf(
                'Przeliczono %d z %d obiektów — przerwano przed limitem czasu hostingu. Kliknij „Przelicz" ponownie (ten sam zakres), dokończę od miejsca przerwania.',
                $i + 1, $total
            ));
            evk_tools_redirect();
        }
    }

    delete_option('evk_tools_recalc_progress');
    evk_tools_set_notice('success', sprintf('Przeliczono pola obliczeniowe %d obiektów — zakres: %s.', $total, evk_tools_recalc_scope_label($scope)));
    evk_tools_redirect();
});

// =========================================================================
// CZYSZCZENIE OSIEROCONYCH KLUCZY (bezpieczne)
// =========================================================================

function evk_tools_scan_orphans(): array {
    global $wpdb;
    $all_keys = evk_tools_all_group_keys();

    // 1) Osierocone opcje evk_rep_opt_{klucz}
    $like        = $wpdb->esc_like(EVK_TOOLS_OPT_PREFIX) . '%';
    $opt_names   = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    $orphan_opts = [];
    foreach ((array) $opt_names as $n) {
        $key = substr($n, strlen(EVK_TOOLS_OPT_PREFIX));
        if ($key !== '' && !in_array($key, $all_keys, true)) $orphan_opts[] = $n;
    }

    // 2) Martwe odwołania do grup w stronach ustawień
    $pages    = (array) get_option('evk_rep_settings_pages', []);
    $dangling = [];
    foreach ($pages as $slug => $page) {
        foreach ((array) ($page['tabs'] ?? []) as $tab) {
            foreach ((array) ($tab['groups'] ?? []) as $gk) {
                if (!in_array($gk, $all_keys, true)) $dangling[(string) $slug][] = $gk;
            }
        }
    }
    foreach ($dangling as $s => $list) $dangling[$s] = array_values(array_unique($list));

    return ['options' => $orphan_opts, 'dangling' => $dangling];
}

add_action('admin_init', function () {
    if (empty($_POST['evk_tools_cleanup'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('evk_tools_cleanup', 'evk_tools_nonce');

    $scan      = evk_tools_scan_orphans();
    $removed   = 0;
    $cleaned_p = 0;

    foreach ($scan['options'] as $name) {
        if (delete_option($name)) $removed++;
    }

    if (!empty($scan['dangling'])) {
        $all_keys = evk_tools_all_group_keys();
        $pages    = (array) get_option('evk_rep_settings_pages', []);
        foreach ($pages as $slug => &$page) {
            if (!isset($scan['dangling'][$slug])) continue;
            $changed = false;
            foreach ((array) ($page['tabs'] ?? []) as $ti => &$tab) {
                if (empty($tab['groups'])) continue;
                $before        = (array) $tab['groups'];
                $tab['groups'] = array_values(array_filter($before, function ($gk) use ($all_keys) {
                    return in_array($gk, $all_keys, true);
                }));
                if (count($tab['groups']) !== count($before)) $changed = true;
            }
            unset($tab);
            if ($changed) $cleaned_p++;
        }
        unset($page);
        update_option('evk_rep_settings_pages', $pages);
    }

    evk_groups_cache_clear();
    evk_tools_set_notice('success', sprintf('Wyczyszczono: %d osieroconych opcji, %d stron ustawień z martwymi odwołaniami.', $removed, $cleaned_p));
    evk_tools_redirect();
});

// =========================================================================
// STRONA
// =========================================================================

function evk_tools_page(): void {
    if (!current_user_can('manage_options')) return;

    $notice = get_transient('evk_tools_notice_' . get_current_user_id());
    if ($notice) delete_transient('evk_tools_notice_' . get_current_user_id());

    $export    = evk_tools_build_export();
    $n_groups  = count($export['groups']);
    $n_cpt     = count($export['post_types']);
    $n_tax     = count($export['taxonomies']);
    $n_pages   = count($export['settings_pages']);
    $n_optvals = count($export['option_values']);

    $scan       = evk_tools_scan_orphans();
    $n_orphans  = count($scan['options']);
    $n_dangling = 0;
    foreach ($scan['dangling'] as $list) $n_dangling += count($list);
    ?>
    <div class="wrap evk-b-wrap">
        <h1><span class="dashicons dashicons-admin-tools"></span> Narzędzia</h1>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo in_array($notice['type'], ['error', 'warning'], true) ? $notice['type'] : 'success'; ?> is-dismissible">
                <p><?php echo esc_html($notice['msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="evk-b-info">
            <span class="dashicons dashicons-info-outline"></span>
            <div>
                Eksport/import obejmuje <strong>konfigurację</strong>: grupy pól, typy treści,
                taksonomie, strony ustawień oraz wartości stron opcji. <em>Nie</em> obejmuje
                wartości pól zapisanych przy wpisach (są związane z ID wpisów danej witryny).
            </div>
        </div>

        <!-- EKSPORT -->
        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-download" style="vertical-align:text-bottom;color:#2563eb;"></span> Eksport</h2>
            <div>
                <p style="margin-top:0;color:#475569;">
                    Pobierz aktualną konfigurację jako plik JSON:
                    <code><?php echo (int) $n_groups; ?></code> grup,
                    <code><?php echo (int) $n_cpt; ?></code> CPT,
                    <code><?php echo (int) $n_tax; ?></code> taksonomii,
                    <code><?php echo (int) $n_pages; ?></code> stron ustawień,
                    <code><?php echo (int) $n_optvals; ?></code> zapisanych opcji.
                </p>
                <form method="post">
                    <?php wp_nonce_field('evk_tools_export', 'evk_tools_nonce'); ?>
                    <button type="submit" name="evk_tools_export" value="1" class="button button-primary">
                        <span class="dashicons dashicons-download"></span> Pobierz plik JSON
                    </button>
                </form>
            </div>
        </div>

        <!-- IMPORT -->
        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-upload" style="vertical-align:text-bottom;color:#2563eb;"></span> Import</h2>
            <div>
                <p style="margin-top:0;color:#475569;">
                    Wgraj plik eksportu. Domyślnie dodawane są tylko nowe elementy (grupy wg klucza,
                    CPT/taksonomie wg slug, strony wg slug, opcje wg nazwy). Zaznacz nadpisywanie,
                    aby zastąpić istniejące.
                </p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('evk_tools_import', 'evk_tools_nonce'); ?>
                    <p><input type="file" name="evk_import_file" accept="application/json,.json" required></p>
                    <p><label class="evk-switch-wrap">
                        <span class="evk-switch"><input type="checkbox" name="evk_import_overwrite" value="1"><span class="evk-switch-slider"></span></span>
                        <span class="evk-switch-label">Nadpisuj istniejące elementy</span>
                    </label></p>
                    <button type="submit" name="evk_tools_import" value="1" class="button button-primary">
                        <span class="dashicons dashicons-upload"></span> Importuj
                    </button>
                </form>
            </div>
        </div>

        <!-- PRZELICZANIE PÓL OBLICZENIOWYCH -->
        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-calculator" style="vertical-align:text-bottom;color:#2563eb;"></span> Przelicz pola obliczeniowe</h2>
            <div>
                <p style="margin-top:0;color:#475569;">
                    Po zmianie formuły pola obliczeniowego istniejące wartości trzymają stary wynik aż do
                    ponownego zapisania. To narzędzie przelicza je hurtowo (<code>evk_rep_recalc</code> /
                    <code>evk_rep_recalc_options</code>) — wpisy wybranego typu, wartości stron ustawień,
                    termy lub użytkowników. Przy dużej liczbie obiektów przebieg może wymagać kilku
                    kliknięć — wznawia się od miejsca przerwania.
                </p>
                <?php $recalc_progress = evk_tools_recalc_progress(); ?>
                <?php if ($recalc_progress): ?>
                <p style="color:#92400e;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                    <span class="dashicons dashicons-clock" style="vertical-align:text-bottom;"></span>
                    Przerwany przebieg — zakres <code><?php echo esc_html(evk_tools_recalc_scope_label($recalc_progress['scope'])); ?></code>
                    (od obiektu <?php echo (int) $recalc_progress['offset']; ?>) — wybierz ten sam zakres i kliknij „Przelicz", aby dokończyć.
                </p>
                <?php endif; ?>
                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <?php wp_nonce_field('evk_tools_recalc', 'evk_tools_nonce'); ?>
                    <?php $cur_scope = $recalc_progress['scope'] ?? ''; ?>
                    <select name="evk_recalc_scope">
                        <optgroup label="Wpisy (typ treści)">
                            <?php foreach (evk_tools_recalc_post_types() as $pt => $lbl): ?>
                            <option value="post:<?php echo esc_attr($pt); ?>" <?php selected($cur_scope === 'post:' . $pt); ?>><?php echo esc_html($lbl); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Inne zakresy">
                            <option value="options" <?php selected($cur_scope === 'options'); ?>>Strony ustawień (opcje)</option>
                            <option value="term" <?php selected($cur_scope === 'term'); ?>>Termy taksonomii</option>
                            <option value="user" <?php selected($cur_scope === 'user'); ?>>Użytkownicy</option>
                        </optgroup>
                    </select>
                    <button type="submit" name="evk_tools_recalc" value="1" class="button button-primary">
                        <span class="dashicons dashicons-update"></span> Przelicz
                    </button>
                </form>
            </div>
        </div>

        <!-- WYGLĄD PÓL (TOKENY) -->
        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-admin-appearance" style="vertical-align:text-bottom;color:#2563eb;"></span> Wygląd pól</h2>
            <div>
                <p style="margin-top:0;color:#475569;">
                    Dostosuj typografię i odstępy UI pól (globalnie, we wszystkich metaboxach i na stronach ustawień).
                    Puste = wartość domyślna. Wartości w pikselach.
                </p>
                <?php $st = evk_rep_style_tokens(); ?>
                <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;align-items:end;">
                    <?php wp_nonce_field('evk_tools_style', 'evk_tools_nonce'); ?>
                    <?php foreach (evk_rep_style_token_defs() as $k => $d): ?>
                    <label style="display:block;">
                        <span style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:5px;"><?php echo esc_html($d['label']); ?> (px)</span>
                        <input type="number" min="<?php echo (int) $d['min']; ?>" max="<?php echo (int) $d['max']; ?>" step="1" name="evk_style[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($st[$k] ?? ''); ?>" placeholder="<?php echo (int) $d['def']; ?> (domyślnie)" style="width:100%;">
                    </label>
                    <?php endforeach; ?>
                    <div><button type="submit" name="evk_tools_style_save" value="1" class="button button-primary"><span class="dashicons dashicons-saved" style="vertical-align:text-bottom;"></span> Zapisz wygląd</button></div>
                </form>
            </div>
        </div>

        <!-- CZYSZCZENIE -->
        <div class="evk-settings-group">
            <h2 class="evk-settings-group-title"><span class="dashicons dashicons-trash" style="vertical-align:text-bottom;color:#2563eb;"></span> Czyszczenie osieroconych kluczy</h2>
            <div>
                <?php if ($n_orphans === 0 && $n_dangling === 0): ?>
                    <p style="margin:0;color:#166534;">
                        <span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;"></span>
                        Brak sierot — wszystko czysto.
                    </p>
                <?php else: ?>
                    <p style="margin-top:0;color:#475569;">Znaleziono:</p>
                    <?php if ($n_orphans): ?>
                        <p><strong>Osierocone wartości opcji</strong> (po skasowanych grupach) — <code><?php echo (int) $n_orphans; ?></code>:</p>
                        <ul style="margin:4px 0 12px 18px;list-style:disc;">
                            <?php foreach ($scan['options'] as $name): ?>
                                <li><code><?php echo esc_html($name); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($n_dangling): ?>
                        <p><strong>Martwe odwołania do grup w stronach ustawień</strong> — <code><?php echo (int) $n_dangling; ?></code>:</p>
                        <ul style="margin:4px 0 12px 18px;list-style:disc;">
                            <?php foreach ($scan['dangling'] as $slug => $keys): ?>
                                <li><code><?php echo esc_html($slug); ?></code> → <?php echo esc_html(implode(', ', $keys)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Usunąć wszystkie wymienione osierocone elementy? Tej operacji nie można cofnąć.');">
                        <?php wp_nonce_field('evk_tools_cleanup', 'evk_tools_nonce'); ?>
                        <button type="submit" name="evk_tools_cleanup" value="1" class="button button-primary" style="background:#dc2626;border-color:#b91c1c;">
                            <span class="dashicons dashicons-trash"></span> Usuń osierocone
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
