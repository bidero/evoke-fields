<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — kolumny w panelu admina.
 *
 * Pola oznaczone „Pokaż jako kolumnę" (grupy pojedyncze) pojawiają się w tabelach
 * wpisów / termów / użytkowników — z konfigurowalnym tytułem, pozycją i sortowaniem.
 * Wyszukiwanie po meta = Faza 4b.
 *
 * Klucz kolumny: evk_col_{fieldkey}. Źródło wartości: get_metadata(post|term|user).
 */

/** Zbiera definicje kolumn pogrupowane wg typu obiektu. Memo per-żądanie. */
function evk_rep_column_fields(): array {
    static $memo = null;
    if ($memo !== null) return $memo;

    $cols = ['post' => [], 'term' => [], 'user' => []];
    foreach (evk_rep_groups() as $group) {
        if (evk_rep_is_repeater($group)) continue; // kolumny tylko dla grup pojedynczych
        $ot = $group['object_type'] ?? 'post';
        if (!isset($cols[$ot])) continue;

        foreach (($group['fields'] ?? []) as $fk => $f) {
            if (empty($f['column'])) continue;
            $t = $f['type'] ?? 'text';
            if (evk_rep_is_layout($t) || $t === 'repeater') continue;

            $cols[$ot][] = [
                'key'        => $fk,
                'label'      => (($f['column_label'] ?? '') !== '') ? $f['column_label'] : ($f['label'] ?? $fk),
                'sortable'   => !empty($f['column_sortable']),
                'position'   => isset($f['column_position']) ? (int) $f['column_position'] : null,
                'field'      => $f,
                'post_types' => $group['post_types'] ?? ['post'],
                'taxonomies' => $group['taxonomies'] ?? [],
            ];
        }
    }
    $memo = $cols;
    return $cols;
}

function evk_rep_column_find(string $object_type, string $key): ?array {
    foreach (evk_rep_column_fields()[$object_type] ?? [] as $c) {
        if ($c['key'] === $key) return $c['field'];
    }
    return null;
}

function evk_rep_column_is_numeric(array $field): bool {
    return in_array($field['type'] ?? '', ['number', 'range'], true);
}

/** Wstawia kolumny evk_col_* do listy kolumn (wg pozycji; null = na końcu). */
function evk_rep_insert_columns(array $columns, array $list): array {
    usort($list, function ($a, $b) {
        $pa = $a['position']; $pb = $b['position'];
        if ($pa === null && $pb === null) return 0;
        if ($pa === null) return 1;
        if ($pb === null) return -1;
        return $pa <=> $pb;
    });
    // Domyślny punkt wstawienia dla kolumn bez pozycji = zaraz po kolumnie głównej
    // (title / name / username), żeby były widoczne, a nie doklejone na końcu tabeli.
    $anchor = null;
    foreach (['title', 'name', 'username'] as $k) {
        if (isset($columns[$k])) { $anchor = $k; break; }
    }

    foreach ($list as $c) {
        $colkey = 'evk_col_' . $c['key'];
        if (isset($columns[$colkey])) continue;

        if ($c['position'] !== null) {
            $pos     = max(0, min(count($columns), (int) $c['position']));
            $columns = array_slice($columns, 0, $pos, true)
                     + [$colkey => $c['label']]
                     + array_slice($columns, $pos, null, true);
        } elseif ($anchor !== null) {
            $idx     = array_search($anchor, array_keys($columns), true) + 1;
            $columns = array_slice($columns, 0, $idx, true)
                     + [$colkey => $c['label']]
                     + array_slice($columns, $idx, null, true);
            $anchor  = $colkey; // kolejne auto-kolumny ustawiaj po tej
        } else {
            $columns[$colkey] = $c['label'];
        }
    }
    return $columns;
}

/** HTML wartości pola w koladce (formatowanie zależne od typu). */
function evk_rep_column_value_html(array $field, $val): string {
    $type = $field['type'] ?? 'text';
    if ($val === '' || $val === null || $val === []) return '—';

    switch ($type) {
        case 'image':
            $img = wp_get_attachment_image((int) $val, [40, 40]);
            return $img ?: '—';
        case 'color':
            $c = sanitize_hex_color((string) $val);
            return $c
                ? '<span style="display:inline-block;width:18px;height:18px;border-radius:4px;border:1px solid #ccc;vertical-align:middle;background:' . esc_attr($c) . ';" title="' . esc_attr($c) . '"></span>'
                : esc_html((string) $val);
        case 'checkbox':
            return !empty($val) ? '<span class="dashicons dashicons-yes" style="color:#16a34a;"></span>' : '—';
        case 'taxonomy':
            $names = evk_rep_format_value($field, $val, '');
            return $names !== '' ? esc_html((string) $names) : '—';
        case 'select':
        case 'radio':
        case 'button_group':
        case 'image_select':
            $label = evk_rep_format_value($field, $val, 'label');
            return esc_html((string) $label);
        case 'url':
            return '<a href="' . esc_url((string) $val) . '" target="_blank" rel="noopener">' . esc_html((string) $val) . '</a>';
        case 'wysiwyg':
        case 'textarea':
            $txt = trim(wp_strip_all_tags((string) $val));
            if ($txt === '') return '—';
            return esc_html(mb_strlen($txt) > 80 ? mb_substr($txt, 0, 80) . '…' : $txt);
        default:
            $s = is_scalar($val) ? (string) $val : '';
            return $s === '' ? '—' : esc_html(mb_strlen($s) > 120 ? mb_substr($s, 0, 120) . '…' : $s);
    }
}

/** Zwraca HTML wartości dla danego klucza kolumny lub '' gdy to nie nasza kolumna. */
function evk_rep_object_column_html(string $colkey, $object_id, string $meta_type, array $list): string {
    foreach ($list as $c) {
        if ('evk_col_' . $c['key'] !== $colkey) continue;
        $val = get_metadata($meta_type, (int) $object_id, $c['key'], true);
        return evk_rep_column_value_html($c['field'], $val);
    }
    return '';
}

// =========================================================================
// REJESTRACJA HOOKÓW KOLUMN
// =========================================================================

add_action('admin_init', function () {
    $cols = evk_rep_column_fields();

    // ── WPISY ──
    $by_pt = [];
    foreach ($cols['post'] as $c) {
        foreach ((array) $c['post_types'] as $pt) $by_pt[$pt][] = $c;
    }
    foreach ($by_pt as $pt => $list) {
        add_filter("manage_{$pt}_posts_columns", function ($columns) use ($list) {
            return evk_rep_insert_columns($columns, $list);
        });
        add_action("manage_{$pt}_posts_custom_column", function ($colkey, $post_id) use ($list) {
            $h = evk_rep_object_column_html($colkey, $post_id, 'post', $list);
            if ($h !== '') echo $h;
        }, 10, 2);

        $sortable = array_values(array_filter($list, function ($c) { return $c['sortable']; }));
        if ($sortable) {
            add_filter("manage_edit-{$pt}_sortable_columns", function ($columns) use ($sortable) {
                foreach ($sortable as $c) $columns['evk_col_' . $c['key']] = 'evk_col_' . $c['key'];
                return $columns;
            });
        }
    }

    // ── TERMY ──
    $by_tax = [];
    foreach ($cols['term'] as $c) {
        foreach ((array) $c['taxonomies'] as $tx) $by_tax[$tx][] = $c;
    }
    foreach ($by_tax as $tx => $list) {
        add_filter("manage_edit-{$tx}_columns", function ($columns) use ($list) {
            return evk_rep_insert_columns($columns, $list);
        });
        add_filter("manage_{$tx}_custom_column", function ($content, $colkey, $term_id) use ($list) {
            $h = evk_rep_object_column_html($colkey, $term_id, 'term', $list);
            return $h !== '' ? $h : $content;
        }, 10, 3);

        $sortable = array_values(array_filter($list, function ($c) { return $c['sortable']; }));
        if ($sortable) {
            add_filter("manage_edit-{$tx}_sortable_columns", function ($columns) use ($sortable) {
                foreach ($sortable as $c) $columns['evk_col_' . $c['key']] = 'evk_col_' . $c['key'];
                return $columns;
            });
        }
    }

    // ── UŻYTKOWNICY ──
    if (!empty($cols['user'])) {
        $list = $cols['user'];
        add_filter('manage_users_columns', function ($columns) use ($list) {
            return evk_rep_insert_columns($columns, $list);
        });
        add_filter('manage_users_custom_column', function ($content, $colkey, $user_id) use ($list) {
            $h = evk_rep_object_column_html($colkey, $user_id, 'user', $list);
            return $h !== '' ? $h : $content;
        }, 10, 3);

        $sortable = array_values(array_filter($list, function ($c) { return $c['sortable']; }));
        if ($sortable) {
            add_filter('manage_users_sortable_columns', function ($columns) use ($sortable) {
                foreach ($sortable as $c) $columns['evk_col_' . $c['key']] = 'evk_col_' . $c['key'];
                return $columns;
            });
        }
    }
});

// =========================================================================
// SORTOWANIE (meta_key + orderby). Uwaga: przy sortowaniu po kolumnie widoczne
// są obiekty, które mają zapisaną wartość tego pola (standardowe zachowanie WP).
// =========================================================================

add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query()) return;
    $ob = $q->get('orderby');
    if (!is_string($ob) || strpos($ob, 'evk_col_') !== 0) return;
    $key   = substr($ob, 8);
    $field = evk_rep_column_find('post', $key);
    if (!$field) return;
    $q->set('meta_key', $key);
    $q->set('orderby', evk_rep_column_is_numeric($field) ? 'meta_value_num' : 'meta_value');
});

add_action('pre_get_users', function ($q) {
    if (!is_admin()) return;
    $ob = $q->get('orderby');
    if (!is_string($ob) || strpos($ob, 'evk_col_') !== 0) return;
    $key   = substr($ob, 8);
    $field = evk_rep_column_find('user', $key);
    if (!$field) return;
    $q->set('meta_key', $key);
    $q->set('orderby', evk_rep_column_is_numeric($field) ? 'meta_value_num' : 'meta_value');
});

add_filter('get_terms_args', function ($args, $taxonomies) {
    if (!is_admin()) return $args;
    $ob = $args['orderby'] ?? '';
    if (!is_string($ob) || strpos($ob, 'evk_col_') !== 0) return $args;
    $key   = substr($ob, 8);
    $field = evk_rep_column_find('term', $key);
    if (!$field) return $args;
    $args['meta_key'] = $key;
    $args['orderby']  = evk_rep_column_is_numeric($field) ? 'meta_value_num' : 'meta_value';
    return $args;
}, 10, 2);
