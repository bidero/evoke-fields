<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — Meta box.
 * Grupa repeater  => wiersze (add/remove/sortable), pola danych.
 * Grupa pojedyncza => pola raz, organizowane zakładkami / akordeonami / nagłówkami.
 * Zapis: repeater -> zserializowana tablica pod kluczem grupy; single -> osobne meta per pole.
 */

// =========================================================================
// REJESTRACJA
// =========================================================================

add_action('add_meta_boxes', function () {
    foreach (evk_rep_groups() as $key => $group) {
        // termy/użytkownicy → locations.php; media → panel szczegółów załącznika (niżej).
        if (($group['object_type'] ?? 'post') !== 'post') continue;
        $seamless = !empty($group['seamless']);
        foreach ((array) ($group['post_types'] ?? []) as $pt) {
            add_meta_box('evk_rep_' . $key, $group['label'] ?? $key, 'evk_rep_render_metabox', $pt, 'normal', 'default', ['group_key' => $key]);
            if ($seamless) {
                add_filter('postbox_classes_' . $pt . '_evk_rep_' . $key, function ($classes) {
                    $classes[] = 'evk-seamless';
                    return $classes;
                });
            }
        }
    }
});

// =========================================================================
// RENDER POJEDYNCZEGO POLA
// =========================================================================

function evk_rep_parse_image_select_options($raw): array {
    $out = [];
    $raw = (string) $raw;
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // Wzorzec wykrywający, że dany token jest URL-em obrazka (http(s)://, //, / lub
    // rozszerzenie graficzne). Używany, by parser był odporny na kolejność i zgodny
    // ze starym formatem "Etykieta : URL" oraz nowym "URL : Etykieta".
    $url_re = '#^(https?:)?//|^/|\.(png|jpe?g|gif|svg|webp|avif)$#i';
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $url = $label = $line;
        // Separator = dwukropek otoczony spacją; dzięki temu "https://" w URL-u nie
        // jest mylony z separatorem wartość/etykieta.
        if (preg_match('/^(\S.*?)\s+:\s+(.+)$/', $line, $m)) {
            $a = trim($m[1]);
            $b = trim($m[2]);
            if (preg_match($url_re, $b) && !preg_match($url_re, $a)) {
                // Stary format: "Etykieta : URL"
                $url = $b; $label = $a;
            } else {
                // Nowy (spójny) format: "URL : Etykieta"
                $url = $a; $label = $b;
            }
        }
        if ($url !== '') $out[$url] = $label !== '' ? $label : $url;
    }
    return $out;
}

/** Mapa kategorii galerii [wartość => etykieta] — z listy ręcznej lub z taksonomii. */
function evk_rep_gallery_categories(array $field): array {
    $src = $field['gallery_cat_source'] ?? (($field['gallery_categories'] ?? '') !== '' ? 'manual' : '');
    if ($src === 'taxonomy') {
        $tax = $field['gallery_cat_taxonomy'] ?? '';
        if ($tax && taxonomy_exists($tax)) {
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name']);
            $out   = [];
            if (!is_wp_error($terms)) foreach ($terms as $t) $out[$t->slug] = $t->name;
            return $out;
        }
        return [];
    }
    if ($src === 'manual') return evk_rep_parse_options($field['gallery_categories'] ?? '');
    return [];
}

function evk_rep_render_gallery_item(string $name, $index, int $img, string $cat, array $cats, bool $tpl = false, int $item_w = 0): void {
    $src = '';
    if (!$tpl && $img > 0) {
        $s   = wp_get_attachment_image_src($img, 'thumbnail');
        $src = $s ? $s[0] : '';
    }
    $idv = $tpl ? '__IMG__' : (string) $img;
    $base = esc_attr($name) . '[' . esc_attr((string) $index) . ']';
    $style = $item_w > 0 ? ' style="width:' . $item_w . 'px"' : '';
    echo '<div class="evk-gallery-item" data-id="' . esc_attr($idv) . '"' . $style . '>';
    echo '<input type="hidden" class="evk-gallery-id" name="' . $base . '[img]" value="' . esc_attr($idv) . '">';
    echo '<span class="evk-gallery-thumb"><img src="' . ($tpl ? '__SRC__' : esc_url($src)) . '" alt=""></span>';
    if (!empty($cats)) {
        echo '<select class="evk-gallery-cat" name="' . $base . '[cat]"><option value="">— kategoria —</option>';
        foreach ($cats as $cv => $cl) {
            echo '<option value="' . esc_attr($cv) . '"' . selected($cat, (string) $cv, false) . '>' . esc_html($cl) . '</option>';
        }
        echo '</select>';
    }
    echo '<button type="button" class="evk-gallery-remove" title="Usuń"><span class="dashicons dashicons-no-alt"></span></button>';
    echo '</div>';
}

function evk_rep_render_rel_item(string $name, $id, string $title, bool $tpl = false): void {
    $idv = $tpl ? '__RID__' : (string) $id;
    echo '<div class="evk-rel-item" data-id="' . esc_attr($idv) . '">';
    echo '<span class="evk-rel-handle dashicons dashicons-menu" title="Przeciągnij"></span>';
    echo '<input type="hidden" name="' . esc_attr($name) . '[]" value="' . esc_attr($idv) . '">';
    echo '<span class="evk-rel-title">' . ($tpl ? '' : esc_html($title)) . '</span>';
    echo '<button type="button" class="evk-rel-remove" title="Usuń"><span class="dashicons dashicons-no-alt"></span></button>';
    echo '</div>';
}

/** Lokalizacja danych AJAX dla pola relacji — wołana po każdym enqueue evk-rep-admin. */
function evk_rep_admin_localize(): void {
    wp_localize_script('evk-rep-admin', 'evkRel', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evk_rel_search'),
    ]);
}

// AJAX: wyszukiwarka wpisów dla pola relacji.
add_action('wp_ajax_evk_rel_search', function () {
    if (!check_ajax_referer('evk_rel_search', 'nonce', false)) wp_send_json_error('nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('caps');

    $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    // Tryb użytkowników (pole „Użytkownik") — ta sama wyszukiwarka co relacja.
    if (isset($_GET['source']) && sanitize_key($_GET['source']) === 'user') {
        $roles = isset($_GET['roles']) ? array_filter(array_map('sanitize_key', explode(',', (string) $_GET['roles']))) : [];
        $args  = [
            'search'         => $s !== '' ? '*' . $s . '*' : '',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number'         => 20,
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ];
        if ($roles) $args['role__in'] = $roles;
        $uq  = new WP_User_Query($args);
        $out = [];
        foreach ((array) $uq->get_results() as $u) {
            $out[] = ['id' => $u->ID, 'title' => $u->display_name ?: $u->user_login, 'type' => $u->user_email];
        }
        wp_send_json_success($out);
    }

    $raw = isset($_GET['post_types']) ? (string) $_GET['post_types'] : 'post';
    $pts = array_values(array_filter(array_map('sanitize_key', explode(',', $raw)), 'post_type_exists'));
    if (empty($pts)) $pts = ['post'];

    $q = new WP_Query([
        'post_type'      => $pts,
        'post_status'    => ['publish', 'private'],
        'posts_per_page' => 20,
        's'              => $s,
        'no_found_rows'  => true,
        'orderby'        => $s !== '' ? 'relevance' : 'date',
        'order'          => 'DESC',
    ]);
    $out = [];
    foreach ($q->posts as $p) {
        $pto = get_post_type_object($p->post_type);
        $out[] = [
            'id'    => $p->ID,
            'title' => get_the_title($p) ?: '(bez tytułu)',
            'type'  => $pto ? $pto->labels->singular_name : $p->post_type,
        ];
    }
    wp_send_json_success($out);
});

/** Owija input prefiksem/sufiksem (np. „PLN") jeśli ustawione w polu. */
function evk_rep_wrap_affix(string $input, array $field): string {
    $pre = $field['prefix'] ?? '';
    $suf = $field['suffix'] ?? '';
    if ($pre === '' && $suf === '') return $input;
    $h = '<span class="evk-rep-affix">';
    if ($pre !== '') $h .= '<span class="evk-rep-affix-pre">' . esc_html($pre) . '</span>';
    $h .= $input;
    if ($suf !== '') $h .= '<span class="evk-rep-affix-suf">' . esc_html($suf) . '</span>';
    return $h . '</span>';
}

function evk_rep_render_field_input(string $name, array $field, $val, string $context = 'single', string $editor_id = ''): void {
    $type = $field['type'] ?? 'text';
    $ph   = (isset($field['placeholder']) && $field['placeholder'] !== '') ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';
    $req  = !empty($field['required']) ? ' required' : '';

    switch ($type) {
        case 'textarea':
            $rows = (int) ($field['rows'] ?? 0); if ($rows < 1) $rows = 3;
            echo '<textarea name="' . esc_attr($name) . '" rows="' . esc_attr((string) $rows) . '"' . $ph . $req . '>' . esc_textarea((string) $val) . '</textarea>';
            break;

        case 'number':
            echo evk_rep_wrap_affix('<input type="number" step="any" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $ph . $req . '>', $field);
            break;

        case 'range':
            $min  = is_numeric($field['min'] ?? null) ? (float) $field['min'] : 0;
            $max  = is_numeric($field['max'] ?? null) ? (float) $field['max'] : 100;
            $step = is_numeric($field['step'] ?? null) && (float) $field['step'] > 0 ? (float) $field['step'] : 1;
            $cur  = $val !== '' && is_numeric($val) ? (string) $val : (string) $min;
            echo '<div class="evk-rep-range">';
            echo '<input type="range" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" step="' . esc_attr((string) $step) . '" value="' . esc_attr($cur) . '">';
            echo '<input type="number" name="' . esc_attr($name) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" step="' . esc_attr((string) $step) . '" value="' . esc_attr($cur) . '" class="evk-rep-range-value">';
            echo '</div>';
            break;

        case 'email':
            echo evk_rep_wrap_affix('<input type="email" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $ph . $req . '>', $field);
            break;

        case 'url':
            echo evk_rep_wrap_affix('<input type="url" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $ph . $req . '>', $field);
            break;

        case 'link':
            $lv     = is_array($val) ? $val : [];
            $l_url  = (string) ($lv['url'] ?? '');
            $l_ttl  = (string) ($lv['title'] ?? '');
            $l_tgt  = !empty($lv['target']);
            echo '<div class="evk-rep-link">';
            echo '<input type="url" name="' . esc_attr($name) . '[url]" value="' . esc_attr($l_url) . '" placeholder="https://… lub /sciezka"' . $req . ' class="evk-rep-link-url">';
            echo '<input type="text" name="' . esc_attr($name) . '[title]" value="' . esc_attr($l_ttl) . '" placeholder="Etykieta (tekst przycisku)" class="evk-rep-link-title">';
            echo '<label class="evk-rep-link-target"><input type="checkbox" name="' . esc_attr($name) . '[target]" value="_blank" ' . checked($l_tgt, true, false) . '> Otwórz w nowym oknie</label>';
            echo '</div>';
            break;

        case 'color':
            echo '<input type="color" name="' . esc_attr($name) . '" value="' . esc_attr($val !== '' ? (string) $val : '#000000') . '"' . $req . '>';
            break;

        case 'date':
            echo evk_rep_wrap_affix('<input type="date" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $req . '>', $field);
            break;

        case 'time':
            echo evk_rep_wrap_affix('<input type="time" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $req . '>', $field);
            break;

        case 'datetime':
            // Magazyn = 'Y-m-d H:i'; input datetime-local oczekuje 'Y-m-dTH:i'.
            $dt_val = str_replace(' ', 'T', (string) $val);
            echo evk_rep_wrap_affix('<input type="datetime-local" name="' . esc_attr($name) . '" value="' . esc_attr($dt_val) . '"' . $req . '>', $field);
            break;

        case 'toggle':
            $on_val  = $field['toggle_on']  ?? '1';
            $off_val = $field['toggle_off'] ?? '0';
            $on_lbl  = $field['toggle_on_label']  ?? 'Tak';
            $off_lbl = $field['toggle_off_label'] ?? 'Nie';
            $is_on   = ((string) $val === (string) $on_val);
            echo '<label class="evk-rep-toggle">';
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($off_val) . '">';
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($on_val) . '" class="evk-rep-toggle-input" ' . checked($is_on, true, false) . '>';
            echo '<span class="evk-rep-toggle-slider"></span>';
            echo '<span class="evk-rep-toggle-labels"><span class="evk-rep-toggle-off">' . esc_html($off_lbl) . '</span><span class="evk-rep-toggle-on">' . esc_html($on_lbl) . '</span></span>';
            echo '</label>';
            break;

        case 'checkbox':
            echo '<label class="evk-rep-cb"><input type="hidden" name="' . esc_attr($name) . '" value="0">'
               . '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($val), true, false) . '> Tak</label>';
            break;

        case 'select':
            $opts = evk_rep_parse_options($field['options'] ?? '');
            echo '<select name="' . esc_attr($name) . '"' . $req . '><option value="">— wybierz —</option>';
            foreach ($opts as $ov => $ol) {
                echo '<option value="' . esc_attr($ov) . '" ' . selected((string) $val, (string) $ov, false) . '>' . esc_html($ol) . '</option>';
            }
            echo '</select>';
            break;

        case 'radio':
            $opts = evk_rep_parse_options($field['options'] ?? '');
            echo '<div class="evk-rep-radios">';
            foreach ($opts as $ov => $ol) {
                echo '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($ov) . '" ' . checked((string) $val, (string) $ov, false) . '> ' . esc_html($ol) . '</label>';
            }
            echo '</div>';
            break;

        case 'button_group':
            $opts = evk_rep_parse_options($field['options'] ?? '');
            echo '<div class="evk-rep-button-group">';
            foreach ($opts as $ov => $ol) {
                echo '<label class="' . ((string) $val === (string) $ov ? 'is-selected' : '') . '">';
                echo '<input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($ov) . '" ' . checked((string) $val, (string) $ov, false) . '>';
                echo '<span>' . esc_html($ol) . '</span></label>';
            }
            echo '</div>';
            break;

        case 'image_select':
            $opts = evk_rep_parse_image_select_options($field['options'] ?? '');
            $img_w = max(1, min(1000, (int)($field['image_width'] ?? 80)));
            $img_h = max(1, min(1000, (int)($field['image_height'] ?? 80)));
            echo '<div class="evk-rep-image-select" style="--evk-img-w:' . esc_attr((string) $img_w) . 'px;--evk-img-h:' . esc_attr((string) $img_h) . 'px;">';
            foreach ($opts as $url => $label) {
                echo '<label class="' . ((string) $val === (string) $url ? 'is-selected' : '') . '">';
                echo '<input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($url) . '" ' . checked((string) $val, (string) $url, false) . '>';
                echo '<span class="evk-rep-image-select-img"><img src="' . esc_url($url) . '" alt="' . esc_attr($label) . '"></span>';
                echo '<span class="evk-rep-image-select-label">' . esc_html($label) . '</span></label>';
            }
            echo '</div>';
            break;

        case 'image':
            $has = $val !== '' && $val !== null && $val !== 0 && $val !== '0';
            echo '<div class="evk-rep-image">';
            echo '<input type="hidden" class="evk-rep-image-id" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
            echo '<div class="evk-rep-image-preview">' . ($has ? wp_get_attachment_image((int) $val, 'thumbnail') : '') . '</div>';
            echo '<button type="button" class="button evk-rep-image-pick">Wybierz</button>';
            echo '<button type="button" class="button-link evk-rep-image-clear" style="' . ($has ? '' : 'display:none;') . '">Usuń</button>';
            echo '</div>';
            break;

        case 'gallery':
            $cats   = evk_rep_gallery_categories($field);
            $item_w = (int) ($field['gallery_item_width'] ?? 0);
            $rows   = is_array($val) ? array_values($val) : [];
            echo '<div class="evk-gallery' . (!empty($cats) ? ' has-cats' : '') . '">';
            echo '<div class="evk-gallery-items">';
            $gi = 0;
            foreach ($rows as $row) {
                $img = (int) (is_array($row) ? ($row['img'] ?? 0) : $row);
                if ($img <= 0) continue;
                evk_rep_render_gallery_item($name, $gi, $img, (string) (is_array($row) ? ($row['cat'] ?? '') : ''), $cats, false, $item_w);
                $gi++;
            }
            echo '</div>';
            echo '<template class="evk-gallery-tpl">';
            evk_rep_render_gallery_item($name, '__GIDX__', 0, '', $cats, true, $item_w);
            echo '</template>';
            echo '<button type="button" class="button evk-gallery-add"><span class="dashicons dashicons-images-alt2"></span> Dodaj obrazy</button>';
            echo '</div>';
            break;

        case 'relationship':
            $ids = is_array($val)
                ? array_values(array_filter(array_map('intval', $val)))
                : ((int) $val > 0 ? [(int) $val] : []);
            $rpts     = !empty($field['rel_post_types']) && is_array($field['rel_post_types']) ? $field['rel_post_types'] : ['post'];
            $multiple = !empty($field['rel_multiple']);
            echo '<div class="evk-rel" data-name="' . esc_attr($name) . '" data-post-types="' . esc_attr(implode(',', $rpts)) . '" data-multiple="' . ($multiple ? '1' : '0') . '">';
            echo '<div class="evk-rel-selected">';
            foreach ($ids as $pid) {
                if (get_post_status($pid) === false) continue;
                evk_rep_render_rel_item($name, $pid, get_the_title($pid));
            }
            echo '</div>';
            echo '<div class="evk-rel-search-wrap"><input type="text" class="evk-rel-search" placeholder="Szukaj wpisów…" autocomplete="off"><div class="evk-rel-results"></div></div>';
            echo '<template class="evk-rel-tpl">';
            evk_rep_render_rel_item($name, '__RID__', '', true);
            echo '</template>';
            echo '</div>';
            break;

        case 'user':
            $uids = is_array($val)
                ? array_values(array_filter(array_map('intval', $val)))
                : ((int) $val > 0 ? [(int) $val] : []);
            $u_multi = !empty($field['user_multiple']);
            $u_roles = !empty($field['user_roles']) && is_array($field['user_roles']) ? $field['user_roles'] : [];
            echo '<div class="evk-rel" data-name="' . esc_attr($name) . '" data-source="user" data-roles="' . esc_attr(implode(',', $u_roles)) . '" data-multiple="' . ($u_multi ? '1' : '0') . '">';
            echo '<div class="evk-rel-selected">';
            foreach ($uids as $uid) {
                $u = get_userdata($uid);
                if (!$u) continue;
                evk_rep_render_rel_item($name, $uid, $u->display_name ?: $u->user_login);
            }
            echo '</div>';
            echo '<div class="evk-rel-search-wrap"><input type="text" class="evk-rel-search" placeholder="Szukaj użytkowników…" autocomplete="off"><div class="evk-rel-results"></div></div>';
            echo '<template class="evk-rel-tpl">';
            evk_rep_render_rel_item($name, '__RID__', '', true);
            echo '</template>';
            echo '</div>';
            break;

        case 'taxonomy':
            $tax_slug    = $field['taxonomy'] ?? '';
            $multiple    = !empty($field['multiple']);
            $terms       = [];
            if ($tax_slug) {
                $result = get_terms(['taxonomy' => $tax_slug, 'hide_empty' => false, 'orderby' => 'name']);
                if (!is_wp_error($result)) $terms = $result;
            }
            // Normalizuj aktualną wartość do tablicy int ID
            if (is_array($val)) {
                $current_ids = array_map('intval', array_filter($val));
            } else {
                $current_ids = (int)$val > 0 ? [(int)$val] : [];
            }
            if (!$terms) {
                echo '<p class="description">' . ($tax_slug ? 'Brak termów w taksonomii: <code>' . esc_html($tax_slug) . '</code>' : 'Nie wybrano taksonomii w konfiguracji pola.') . '</p>';
                break;
            }
            if ($multiple) {
                echo '<div class="evk-rep-tax-checks">';
                foreach ($terms as $term) {
                    $checked = in_array($term->term_id, $current_ids, true) ? ' checked' : '';
                    echo '<label class="evk-rep-tax-check"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($term->term_id) . '"' . $checked . '> ' . esc_html($term->name) . '</label>';
                }
                echo '</div>';
            } else {
                echo '<select name="' . esc_attr($name) . '">';
                echo '<option value="">— wybierz —</option>';
                foreach ($terms as $term) {
                    $sel = in_array($term->term_id, $current_ids, true) ? ' selected' : '';
                    echo '<option value="' . esc_attr($term->term_id) . '"' . $sel . '>' . esc_html($term->name) . '</option>';
                }
                echo '</select>';
            }
            break;

        case 'wysiwyg':
            if ($context === 'single' && $editor_id !== '') {
                // Poza repeaterem — pełny edytor WordPress
                wp_editor((string) $val, $editor_id, [
                    'textarea_name' => $name,
                    'media_buttons' => true,
                    'textarea_rows' => 8,
                    'tinymce'       => true,
                    'quicktags'     => true,
                ]);
            } else {
                // W wierszu repeatera — textarea inicjalizowana przez wp.editor.initialize() w JS.
                // ID zawiera token (__INDEX__ / __IDX1__ itp.) zastępowany przez admin.js przy klonowaniu.
                $eid = 'evk_wy_' . str_replace(['[', ']', '.', ' '], ['_', '', '_', '_'], $name);
                echo '<div class="evk-wysiwyg-wrap">';
                echo '<textarea id="' . esc_attr($eid) . '" name="' . esc_attr($name) . '" class="evk-wysiwyg-area" rows="6">' . esc_textarea((string) $val) . '</textarea>';
                echo '</div>';
            }
            break;

        case 'text':
        default:
            echo evk_rep_wrap_affix('<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '"' . $ph . $req . '>', $field);
    }
}

function evk_rep_field_span(array $field): int {
    $w = (int) ($field['width'] ?? 0);
    if ($w) return max(1, min(12, (int) round($w / 100 * 12)));
    return in_array($field['type'] ?? '', ['textarea', 'image', 'image_select', 'gallery', 'relationship', 'user', 'wysiwyg', 'taxonomy'], true) ? 12 : 6;
}

// =========================================================================
// RENDER — LISTA PÓŁ (wspólna dla single i wiersza)
// =========================================================================

function evk_rep_token(int $depth): string {
    return $depth <= 0 ? '__INDEX__' : '__IDX' . $depth . '__';
}

// Atrybut z logiką warunkową dla wrappera pola (runtime show/hide w JS).
function evk_rep_cond_data_attr(array $field): string {
    $c = $field['conditions'] ?? null;
    if (!is_array($c) || empty($c['rules']) || !is_array($c['rules'])) return '';
    $payload = wp_json_encode(['relation' => $c['relation'] ?? 'all', 'rules' => array_values($c['rules'])]);
    if (!is_string($payload)) return '';
    return ' data-evk-cond="' . esc_attr($payload) . '"';
}

// Ikona „?" z dymkiem przy etykiecie (CSS tooltip na data-evk-tip).
function evk_rep_label_tooltip(array $field): string {
    $tip = trim((string) ($field['tooltip'] ?? ''));
    if ($tip === '') return '';
    // Literalne „?" w kółku — bez zależności od fontu dashicons (ten bywa zawodny w metaboxie).
    return ' <span class="evk-s-tip" tabindex="0" role="img"'
        . ' data-evk-tip="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '">?</span>';
}

// Szara podpowiedź pod inputem (instrukcja pola).
function evk_rep_field_instructions_html(array $field): string {
    $ins = trim((string) ($field['instructions'] ?? ''));
    if ($ins === '') return '';
    return '<p class="evk-s-instructions">' . esc_html($ins) . '</p>';
}

function evk_rep_render_ctx_field(string $fkey, array $field, array $ctx): void {
    $type = $field['type'] ?? 'text';
    $mode = $ctx['mode'] ?? 'single';

    if ($type === 'repeater') {
        if ($mode === 'single') {
            $rows = get_metadata($ctx['meta_type'] ?? 'post', (int) ($ctx['object_id'] ?? $ctx['post_id'] ?? 0), $fkey, true);
            $base = 'evk_single[' . $fkey . ']';
        } elseif ($mode === 'option') {
            $rows = $ctx['values'][$fkey] ?? [];
            $base = $ctx['name_base'] . '[' . $fkey . ']';
        } else {
            $rows = $ctx['values'][$fkey] ?? [];
            $base = $ctx['name_base'] . '[' . $ctx['index'] . '][' . $fkey . ']';
        }
        $rows = is_array($rows) ? array_values($rows) : [];
        $rep_title_src = (($field['title_tpl'] ?? '') !== '') ? $field['title_tpl'] : ($field['title_field'] ?? '');
        echo '<div class="evk-s-field evk-rep-field--repeater" data-key="' . esc_attr($fkey) . '"' . evk_rep_cond_data_attr($field) . ' style="grid-column:span 12;">';
        $rep_lbl = $field['label'] ?? $fkey;
        $rep_tip = evk_rep_label_tooltip($field);
        if ($rep_lbl !== '' || $rep_tip !== '') echo '<label class="evk-s-label">' . esc_html($rep_lbl) . $rep_tip . '</label>';
        echo evk_rep_field_instructions_html($field);
        evk_rep_render_repeater_widget($base, $field['sub_fields'] ?? [], $rows, $rep_title_src, (int) ($ctx['depth'] ?? -1) + 1, !empty($field['collapsed']), $field['add_label'] ?? '');
        echo '</div>';
        return;
    }

    if ($mode === 'single') {
        $val  = get_metadata($ctx['meta_type'] ?? 'post', (int) ($ctx['object_id'] ?? $ctx['post_id'] ?? 0), $fkey, true);
        $name = 'evk_single[' . $fkey . ']';
        $eid  = 'evk_ed_' . ($ctx['uid'] ?? 'g') . '_' . $fkey;
        $c    = 'single';
    } elseif ($mode === 'option') {
        $val  = $ctx['values'][$fkey] ?? '';
        $name = $ctx['name_base'] . '[' . $fkey . ']';
        $eid  = 'evk_ed_' . ($ctx['uid'] ?? 'o') . '_' . $fkey;
        $c    = 'single';
    } else {
        $val  = $ctx['values'][$fkey] ?? '';
        $name = $ctx['name_base'] . '[' . $ctx['index'] . '][' . $fkey . ']';
        $eid  = '';
        $c    = 'row';
    }
    $span = evk_rep_field_span($field);
    echo '<div class="evk-s-field evk-rep-field--' . esc_attr($type) . '" data-key="' . esc_attr($fkey) . '"' . evk_rep_cond_data_attr($field) . ' style="grid-column:span ' . $span . ';">';
    $lbl = $field['label'] ?? $fkey;
    $tip = evk_rep_label_tooltip($field);
    if ($lbl !== '') {
        echo '<label class="evk-s-label">' . esc_html($lbl) . (!empty($field['required']) ? ' <span class="evk-req">*</span>' : '') . '</label>';
    }
    // Tooltip „?" po prawej stronie pola (input skraca się, robiąc miejsce na ikonę).
    if ($tip !== '') echo '<div class="evk-s-input-row">';
    evk_rep_render_field_input($name, $field, $val, $c, $eid);
    if ($tip !== '') echo $tip . '</div>';
    echo evk_rep_field_instructions_html($field);
    echo '</div>';
}

function evk_rep_render_field_list(array $fields, array $ctx): void {
    $has_tabs = false;
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'tab') { $has_tabs = true; break; }
    }

    $panels = [];
    $cur = null;
    $acc = null;
    foreach ($fields as $fkey => $f) {
        $t = $f['type'] ?? 'text';
        if ($t === 'tab') {
            $panels[] = ['label' => $f['label'] ?? ('Zakładka ' . (count($panels) + 1)), 'blocks' => []];
            $cur = count($panels) - 1;
            $acc = null;
            continue;
        }
        if ($cur === null) {
            $panels[] = ['label' => $has_tabs ? 'Ogólne' : null, 'blocks' => []];
            $cur = count($panels) - 1;
        }
        if ($t === 'heading') {
            $acc = null;
            $panels[$cur]['blocks'][] = ['type' => 'heading', 'label' => $f['label'] ?? '', 'field' => $f];
            continue;
        }
        if ($t === 'description') {
            $acc = null;
            $panels[$cur]['blocks'][] = ['type' => 'description', 'label' => $f['label'] ?? '', 'field' => $f];
            continue;
        }
        if ($t === 'accordion') {
            $panels[$cur]['blocks'][] = ['type' => 'accordion', 'label' => $f['label'] ?? '', 'fields' => []];
            $acc = array_key_last($panels[$cur]['blocks']);
            continue;
        }
        if ($acc !== null) {
            $panels[$cur]['blocks'][$acc]['fields'][] = ['key' => $fkey, 'field' => $f];
        } else {
            $panels[$cur]['blocks'][] = ['type' => 'field', 'key' => $fkey, 'field' => $f];
        }
    }

    echo '<div class="evk-s">';
    if ($has_tabs) {
        echo '<div class="evk-s-tabs">';
        foreach ($panels as $pi => $p) {
            echo '<button type="button" class="evk-s-tab' . ($pi === 0 ? ' active' : '') . '" data-tab="' . $pi . '">' . esc_html((string) $p['label']) . '</button>';
        }
        echo '</div>';
    }
    echo '<div class="evk-s-panels">';
    foreach ($panels as $pi => $p) {
        echo '<div class="evk-s-panel' . ($pi === 0 ? ' active' : '') . '" data-panel="' . $pi . '">';
        foreach ($p['blocks'] as $b) {
            if ($b['type'] === 'heading') {
                $f        = $b['field'] ?? [];
                $size     = $f['heading_size'] ?? 'h3';
                $subtxt   = $f['heading_sub']  ?? '';
                $separator = !empty($f['heading_separator']);
                $tag      = in_array($size, ['h1','h2','h3','h4','h5'], true) ? $size : 'h3';
                echo '<div class="evk-s-heading-wrap' . ($separator ? ' has-separator' : '') . '">';
                echo '<' . $tag . ' class="evk-s-heading evk-s-heading--' . esc_attr($tag) . '">' . esc_html($b['label']) . '</' . $tag . '>';
                if ($subtxt !== '') echo '<p class="evk-s-heading-sub">' . esc_html($subtxt) . '</p>';
                echo '</div>';
            } elseif ($b['type'] === 'description') {
                $f         = $b['field'] ?? [];
                $content   = $f['desc_content'] ?? '';
                $collapsed = !empty($f['desc_collapsed']);
                $collapsible = !empty($f['desc_collapsible']);
                if ($collapsible) {
                    $open = $collapsed ? '' : ' open';
                    echo '<details class="evk-s-desc evk-s-desc--collapsible"' . $open . '>';
                    echo '<summary class="evk-s-desc-summary">' . esc_html($b['label']) . ' <span class="evk-s-desc-chevron dashicons dashicons-arrow-down-alt2"></span></summary>';
                    echo '<div class="evk-s-desc-body">' . wp_kses_post($content) . '</div>';
                    echo '</details>';
                } else {
                    echo '<div class="evk-s-desc">';
                    if ($b['label'] !== '') echo '<strong class="evk-s-desc-title">' . esc_html($b['label']) . '</strong>';
                    echo '<div class="evk-s-desc-body">' . wp_kses_post($content) . '</div>';
                    echo '</div>';
                }
            } elseif ($b['type'] === 'accordion') {
                echo '<div class="evk-s-acc"><button type="button" class="evk-s-acc-head">' . esc_html($b['label']) . '<span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="evk-s-acc-body">';
                foreach ($b['fields'] as $it) {
                    evk_rep_render_ctx_field($it['key'], $it['field'], $ctx);
                }
                echo '</div></div>';
            } else {
                evk_rep_render_ctx_field($b['key'], $b['field'], $ctx);
            }
        }
        echo '</div>';
    }
    echo '</div></div>';
}

// =========================================================================
// WIDGET REPEATERA
// =========================================================================

function evk_rep_render_repeater_widget(string $name_base, array $fields, array $rows, string $title_field = '', int $depth = 0, bool $collapsed = false, string $add_label = ''): void {
    $token     = evk_rep_token($depth);
    $btn_label = $add_label !== '' ? $add_label : 'Dodaj wiersz';
    echo '<div class="evk-rep" data-group="' . esc_attr($name_base) . '" data-title-field="' . esc_attr($title_field) . '" data-depth="' . $depth . '">';
    echo '<div class="evk-rep-rows">';
    foreach ($rows as $i => $row) {
        evk_rep_render_row($name_base, $fields, $i, (array) $row, $depth, $collapsed, $title_field);
    }
    echo '</div>';
    echo '<template class="evk-rep-template">';
    evk_rep_render_row($name_base, $fields, $token, [], $depth, false, $title_field);
    echo '</template>';
    echo '<p class="evk-rep-add-wrap"><button type="button" class="button evk-rep-add">';
    echo '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ';
    echo esc_html($btn_label);
    echo '</button></p>';
    echo '</div>';
}

function evk_rep_render_single(string $group_key, array $fields, int $object_id, string $meta_type = 'post'): void {
    evk_rep_render_field_list($fields, [
        'mode'      => 'single',
        'meta_type' => $meta_type,
        'object_id' => $object_id,
        'post_id'   => $object_id, // zgodność wsteczna
        'depth'     => -1,
        'uid'       => 'g_' . $group_key,
    ]);
}

/**
 * Render grupy dla dowolnego obiektu (post/term/user) — używane przez metabox wpisu
 * oraz przez pola termów / profilu użytkownika (locations.php).
 */
function evk_rep_render_group_object(string $meta_type, int $object_id, string $key, array $group): void {
    $fields = $group['fields'] ?? [];
    if (evk_rep_is_repeater($group)) {
        $rows = get_metadata($meta_type, $object_id, $key, true);
        $rows = is_array($rows) ? array_values($rows) : [];
        evk_rep_render_repeater_widget($key, $fields, $rows, $group['title_field'] ?? '', 0, !empty($group['collapsed']), $group['add_label'] ?? '');
    } else {
        evk_rep_render_single($key, $fields, $object_id, $meta_type);
    }
}

/**
 * Zapis wartości grupy dla dowolnego obiektu (post/term/user).
 * Czyta z $_POST ($key dla repeatera, evk_single dla grupy pojedynczej).
 */
function evk_rep_save_group_object(string $meta_type, int $object_id, string $key, array $group): void {
    if (evk_rep_is_repeater($group)) {
        $clean = evk_rep_sanitize_rows($group['fields'] ?? [], isset($_POST[$key]) ? wp_unslash($_POST[$key]) : []);
        if ($clean) update_metadata($meta_type, $object_id, $key, $clean);
        else        delete_metadata($meta_type, $object_id, $key);
        return;
    }
    $single = isset($_POST['evk_single']) && is_array($_POST['evk_single']) ? wp_unslash($_POST['evk_single']) : [];
    foreach (($group['fields'] ?? []) as $fkey => $field) {
        $type = $field['type'] ?? 'text';
        if (evk_rep_is_layout($type)) continue;
        if ($type === 'repeater') {
            $clean = evk_rep_sanitize_rows($field['sub_fields'] ?? [], $single[$fkey] ?? []);
            if ($clean) update_metadata($meta_type, $object_id, $fkey, $clean);
            else        delete_metadata($meta_type, $object_id, $fkey);
            continue;
        }
        $v = evk_rep_sanitize_value($type, $single[$fkey] ?? '');
        if ($v === '' || $v === null) delete_metadata($meta_type, $object_id, $fkey);
        else                          update_metadata($meta_type, $object_id, $fkey, $v);
    }
}

function evk_rep_render_row(string $name_base, array $fields, $index, array $values = [], int $depth = 0, bool $collapsed = false, string $title_field = ''): void {
    $title = '';
    if ($title_field !== '' && strpos($title_field, '{') !== false) {
        // Szablon z kluczy, np. „{tytul} | {cena}".
        $title = trim(preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($values) {
            $v = $values[$m[1]] ?? '';
            return is_scalar($v) ? (string) $v : '';
        }, $title_field));
    } elseif ($title_field !== '' && isset($fields[$title_field]) && in_array($fields[$title_field]['type'] ?? '', ['text', 'textarea', 'email', 'url', 'number'], true)) {
        $title = (string) ($values[$title_field] ?? '');
    }
    if ($title === '') {
        foreach ($fields as $fk => $fd) {
            $t = $fd['type'] ?? '';
            if (evk_rep_is_layout($t) || $t === 'repeater') continue;
            if (in_array($t, ['text', 'textarea', 'email', 'url'], true)) {
                $tv = (string) ($values[$fk] ?? '');
                if ($tv !== '') { $title = $tv; break; }
            }
        }
    }
    if ($title === '') $title = 'Wiersz';

    echo '<div class="evk-rep-row' . ($collapsed ? ' collapsed' : '') . '" data-index="' . esc_attr($index) . '">';
    echo '<div class="evk-rep-row-head">';
    echo '<span class="evk-rep-handle evk-rep-h' . $depth . ' dashicons dashicons-move" title="Przeciągnij"></span>';
    echo '<button type="button" class="evk-rep-row-toggle" title="Zwiń / rozwiń"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
    echo '<span class="evk-rep-row-title">' . esc_html($title) . '</span>';
    echo '<button type="button" class="evk-rep-remove" title="Usuń wiersz"><span class="dashicons dashicons-trash"></span></button>';
    echo '</div>';
    echo '<div class="evk-rep-row-body">';
    evk_rep_render_field_list($fields, ['mode' => 'row', 'name_base' => $name_base, 'index' => $index, 'values' => $values, 'depth' => $depth, 'uid' => 'r']);
    echo '</div>';
    echo '</div>';
}

// =========================================================================
// META BOX
// =========================================================================

function evk_rep_render_metabox(\WP_Post $post, array $box): void {
    $key    = $box['args']['group_key'];
    $groups = evk_rep_groups();
    if (!isset($groups[$key])) return;
    wp_nonce_field('evk_rep_save_' . $key, 'evk_rep_nonce_' . $key);
    evk_rep_render_group_object('post', $post->ID, $key, $groups[$key]);
}

// =========================================================================
// ZAPIS
// =========================================================================

function evk_rep_sanitize_value(string $type, $v) {
    switch ($type) {
        case 'textarea': return sanitize_textarea_field((string) $v);
        case 'wysiwyg':  return wp_kses_post((string) $v);
        case 'url':      return esc_url_raw((string) $v);
        case 'email':    return sanitize_email((string) $v);
        case 'number':   return $v === '' ? '' : (is_numeric($v) ? $v + 0 : sanitize_text_field((string) $v));
        case 'range':    return $v === '' ? '' : (is_numeric($v) ? $v + 0 : sanitize_text_field((string) $v));
        case 'image':    return $v ? (int) $v : '';
        case 'gallery':
            if (!is_array($v)) return '';
            $out = [];
            foreach ($v as $row) {
                if (!is_array($row)) continue;
                $id = (int) ($row['img'] ?? 0);
                if ($id <= 0) continue;
                $r = ['img' => $id];
                $c = sanitize_text_field($row['cat'] ?? '');
                if ($c !== '') $r['cat'] = $c;
                $out[] = $r;
            }
            return empty($out) ? '' : $out;
        case 'relationship':
        case 'user':
            if (is_array($v)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $v))));
                return empty($ids) ? '' : $ids;
            }
            return (int) $v > 0 ? [(int) $v] : '';
        case 'toggle':   return sanitize_text_field((string) $v);
        case 'checkbox': return (!empty($v) && $v !== '0') ? '1' : '';
        case 'color':    return sanitize_hex_color((string) $v) ?: '';
        case 'date':     return sanitize_text_field((string) $v);
        case 'time':     return sanitize_text_field((string) $v);
        case 'datetime': return sanitize_text_field(str_replace('T', ' ', (string) $v)); // 'Y-m-dTH:i' → 'Y-m-d H:i'
        case 'link':
            if (!is_array($v)) return '';
            $url   = esc_url_raw(trim((string) ($v['url'] ?? '')));
            $title = sanitize_text_field((string) ($v['title'] ?? ''));
            if ($url === '' && $title === '') return '';
            $out = ['url' => $url, 'title' => $title];
            if (!empty($v['target'])) $out['target'] = '_blank';
            return $out;
        case 'taxonomy':
            if (is_array($v)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $v))));
                return empty($ids) ? '' : $ids;
            }
            return (int)$v > 0 ? [(int)$v] : '';
        case 'select':
        case 'radio':
        case 'button_group':
        case 'image_select':
            return sanitize_text_field((string) $v);
        default:         return sanitize_text_field((string) $v);
    }
}

function evk_rep_sanitize_rows(array $fields, $raw): array {
    $clean = [];
    if (!is_array($raw)) return $clean;
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $crow = [];
        foreach ($fields as $fk => $f) {
            $t = $f['type'] ?? 'text';
            if (evk_rep_is_layout($t)) continue;
            if ($t === 'repeater') {
                $sub = evk_rep_sanitize_rows($f['sub_fields'] ?? [], $row[$fk] ?? []);
                if (!empty($sub)) $crow[$fk] = $sub;
                continue;
            }
            $crow[$fk] = evk_rep_sanitize_value($t, $row[$fk] ?? '');
        }
        $nonempty = false;
        foreach ($crow as $v) {
            if (is_array($v)) { if (!empty($v)) { $nonempty = true; break; } }
            elseif ($v !== '' && $v !== null) { $nonempty = true; break; }
        }
        if ($nonempty) $clean[] = $crow;
    }
    return $clean;
}

function evk_rep_sanitize_group_values(array $fields, $raw): array {
    $out = [];
    if (!is_array($raw)) $raw = [];
    foreach ($fields as $fk => $f) {
        $t = $f['type'] ?? 'text';
        if (evk_rep_is_layout($t)) continue;
        if ($t === 'repeater') {
            $out[$fk] = evk_rep_sanitize_rows($f['sub_fields'] ?? [], $raw[$fk] ?? []);
        } else {
            $out[$fk] = evk_rep_sanitize_value($t, $raw[$fk] ?? '');
        }
    }
    return $out;
}

add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    foreach (evk_rep_groups() as $key => $group) {
        if (($group['object_type'] ?? 'post') !== 'post') continue; // media → attachment_fields_to_save
        $nonce = $_POST['evk_rep_nonce_' . $key] ?? '';
        if (!wp_verify_nonce($nonce, 'evk_rep_save_' . $key)) continue;
        evk_rep_save_group_object('post', $post_id, $key, $group);
    }
});

// =========================================================================
// MEDIA — pola w panelu „Szczegóły załącznika" (modal mediów, przy podglądzie)
// Tylko proste typy (panel modala nie obsługuje złożonego renderu/JS EVK).
// =========================================================================

function evk_rep_media_simple_types(): array {
    return ['text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'button_group', 'checkbox', 'color', 'date', 'time', 'datetime'];
}

function evk_rep_attachment_field_html(int $post_id, string $fk, array $f, $val): string {
    $name = 'attachments[' . $post_id . '][evk_' . $fk . ']';
    $type = $f['type'] ?? 'text';
    switch ($type) {
        case 'textarea':
            return '<textarea name="' . esc_attr($name) . '" rows="2" class="widefat">' . esc_textarea((string) $val) . '</textarea>';
        case 'select':
        case 'radio':
        case 'button_group':
            $opts = evk_rep_parse_options($f['options'] ?? '');
            $h = '<select name="' . esc_attr($name) . '"><option value="">— wybierz —</option>';
            foreach ($opts as $ov => $ol) {
                $h .= '<option value="' . esc_attr($ov) . '" ' . selected((string) $val, (string) $ov, false) . '>' . esc_html($ol) . '</option>';
            }
            return $h . '</select>';
        case 'checkbox':
            return '<input type="hidden" name="' . esc_attr($name) . '" value="0">'
                 . '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($val), true, false) . '> Tak</label>';
        case 'number':
        case 'range':
            return '<input type="number" step="any" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
        case 'email':
            return '<input type="email" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
        case 'url':
            return '<input type="url" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '" class="widefat">';
        case 'color':
            return '<input type="color" name="' . esc_attr($name) . '" value="' . esc_attr($val !== '' ? (string) $val : '#000000') . '">';
        case 'date':
            return '<input type="date" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
        case 'time':
            return '<input type="time" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '">';
        case 'datetime':
            return '<input type="datetime-local" name="' . esc_attr($name) . '" value="' . esc_attr(str_replace(' ', 'T', (string) $val)) . '">';
        default:
            return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $val) . '" class="widefat">';
    }
}

add_filter('attachment_fields_to_edit', function ($form_fields, $post) {
    $simple = evk_rep_media_simple_types();
    foreach (evk_rep_groups() as $group) {
        if (($group['object_type'] ?? '') !== 'media') continue;
        foreach (($group['fields'] ?? []) as $fk => $f) {
            if (!in_array($f['type'] ?? '', $simple, true)) continue;
            $val = get_post_meta($post->ID, $fk, true);
            $form_fields['evk_' . $fk] = [
                'label' => $f['label'] ?? $fk,
                'input' => 'html',
                'html'  => evk_rep_attachment_field_html((int) $post->ID, (string) $fk, $f, $val),
            ];
        }
    }
    return $form_fields;
}, 10, 2);

add_filter('attachment_fields_to_save', function ($post, $attachment) {
    $simple = evk_rep_media_simple_types();
    foreach (evk_rep_groups() as $group) {
        if (($group['object_type'] ?? '') !== 'media') continue;
        foreach (($group['fields'] ?? []) as $fk => $f) {
            $type = $f['type'] ?? 'text';
            if (!in_array($type, $simple, true)) continue;
            $ak = 'evk_' . $fk;
            if (!array_key_exists($ak, $attachment)) continue;
            $v = evk_rep_sanitize_value($type, wp_unslash($attachment[$ak]));
            if ($v === '' || $v === null) delete_post_meta($post['ID'], $fk);
            else                          update_post_meta($post['ID'], $fk, $v);
        }
    }
    return $post;
}, 10, 2);
