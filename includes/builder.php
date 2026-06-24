<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — Builder / edytor grupy pól.
 *
 * Grupy pól to teraz CPT evk_field_group. Ten plik:
 * 1. Rejestruje główne menu Evoke FIELDS.
 * 2. Rejestruje metaboxy na ekranie edycji grupy (klucz, ustawienia, typy treści, pola).
 * 3. Obsługuje zapis grupy (save_post).
 * 4. Eksportuje współdzielone helpery (field_type_optgroups, field_row, parse_field).
 */

// =========================================================================
// MENU
// =========================================================================

add_action('admin_menu', function () {
    add_menu_page(
        'Evoke FIELDS', 'Evoke FIELDS', 'manage_options',
        'evk-repeater',
        function () {
            wp_safe_redirect(admin_url('edit.php?post_type=evk_field_group'));
            exit;
        },
        'dashicons-screenoptions', 58
    );
}, 5);

// =========================================================================
// METABOXY NA EKRANIE EDYCJI evk_field_group
// =========================================================================

add_action('add_meta_boxes_evk_field_group', function (\WP_Post $post) {
    add_meta_box('evk_group_key',     'Klucz grupy',       'evk_group_key_metabox',      'evk_field_group', 'side',   'high');
    add_meta_box('evk_group_opts',    'Ustawienia',        'evk_group_opts_metabox',     'evk_field_group', 'side',   'default');
    add_meta_box('evk_group_pts',     'Lokalizacja',       'evk_group_pts_metabox',      'evk_field_group', 'side',   'default');
    add_meta_box('evk_group_fields',  'Definicja pól',     'evk_group_fields_metabox',   'evk_field_group', 'normal', 'high');
});

function evk_group_key_metabox(\WP_Post $post): void {
    wp_nonce_field('evk_group_save_' . $post->ID, 'evk_group_nonce');
    $key       = get_post_meta($post->ID, '_evk_key', true);
    $is_new    = empty($key);
    $readonly  = $is_new ? '' : 'readonly';
    ?>
    <div class="evk-group-options">
        <label class="evk-group-option-field" for="evk_group_key_input">
            <span>Klucz</span>
            <input type="text" id="evk_group_key_input" name="evk_group_key"
                   value="<?php echo esc_attr($key); ?>"
                   <?php echo $readonly; ?>
                   placeholder="np. produkty"
                   style="font-family:Menlo,Consolas,monospace;">
            <?php if (!$is_new): ?>
            <em style="color:#b45309;">
                <span class="dashicons dashicons-warning" style="font-size:14px;vertical-align:middle;"></span>
                Klucz zablokowany — zmiana niszczy zapisane dane.
            </em>
            <?php else: ?>
            <em>Generowany z nazwy grupy. Można zmienić przed pierwszym zapisem danych.</em>
            <?php endif; ?>
        </label>
    </div>
    <?php
}

function evk_group_opts_metabox(\WP_Post $post): void {
    $rep       = get_post_meta($post->ID, '_evk_repeater',  true);
    $collapsed = get_post_meta($post->ID, '_evk_collapsed', true);
    $seamless  = get_post_meta($post->ID, '_evk_seamless',  true);
    $add_label = get_post_meta($post->ID, '_evk_add_label', true);
    $title_f   = get_post_meta($post->ID, '_evk_title_field', true);
    ?>
    <div class="evk-group-options">
        <div class="evk-group-options-checks">
            <label>
                <input type="checkbox" name="evk_group_repeater" value="1" <?php checked($rep); ?>>
                <strong>Cała grupa = repeater</strong>
            </label>
            <label>
                <input type="checkbox" name="evk_group_collapsed" value="1" <?php checked($collapsed); ?>>
                Wiersze zwinięte na start
            </label>
            <label>
                <input type="checkbox" name="evk_group_seamless" value="1" <?php checked($seamless); ?>>
                Bezramkowy (seamless)
            </label>
            <label>
                <input type="checkbox" name="evk_group_hide_title" value="1" <?php checked(get_post_meta($post->ID, '_evk_hide_title', true)); ?>>
                Ukryj tytuł grupy
            </label>
        </div>

        <label class="evk-group-option-field">
            <span>Etykieta przycisku</span>
            <input type="text" name="evk_group_add_label" value="<?php echo esc_attr($add_label); ?>" placeholder="Dodaj wiersz">
        </label>

        <label class="evk-group-option-field">
            <span>Etykieta wiersza</span>
            <input type="text" name="evk_group_title_field" value="<?php echo esc_attr($title_f); ?>" placeholder="np. title  albo  {tytul} | {cena}">
            <em>Klucz pola tekstowego jako nazwa wiersza — lub szablon z kluczy w klamrach, np. <code>{tytul} | {cena_dania}</code>.</em>
        </label>
    </div>
    <?php
}

function evk_group_pts_metabox(\WP_Post $post): void {
    $object_type = get_post_meta($post->ID, '_evk_object_type', true);
    if (!in_array($object_type, ['post', 'term', 'user', 'media'], true)) $object_type = 'post';

    $pts   = get_post_meta($post->ID, '_evk_post_types', true);
    $pts   = is_array($pts) ? $pts : [];
    $taxes = get_post_meta($post->ID, '_evk_taxonomies', true);
    $taxes = is_array($taxes) ? $taxes : [];

    $all_pts = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
        if (in_array($pt->name, ['attachment', 'evk_field_group'], true)) continue;
        $all_pts[$pt->name] = $pt->labels->singular_name ?: $pt->name;
    }
    $all_tax = [];
    foreach (get_taxonomies(['show_ui' => true], 'objects') as $tx) {
        if (in_array($tx->name, ['nav_menu', 'link_category', 'post_format'], true)) continue;
        $all_tax[$tx->name] = $tx->label ?: $tx->name;
    }
    ?>
    <div class="evk-group-location" data-object-type="<?php echo esc_attr($object_type); ?>">
        <label class="evk-group-option-field">
            <span>Pokaż w</span>
            <select name="evk_group_object_type" class="evk-group-object-type">
                <option value="post" <?php selected($object_type, 'post'); ?>>Wpisy / strony (typy treści)</option>
                <option value="term" <?php selected($object_type, 'term'); ?>>Termy taksonomii</option>
                <option value="user" <?php selected($object_type, 'user'); ?>>Profil użytkownika</option>
                <option value="media" <?php selected($object_type, 'media'); ?>>Media (załączniki)</option>
            </select>
        </label>

        <div class="evk-loc-block evk-loc-post">
            <div class="evk-b-section-title">Typy treści</div>
            <div class="evk-loc-checks">
                <?php foreach ($all_pts as $ptk => $ptl): ?>
                <label><input type="checkbox" name="evk_group_post_types[]" value="<?php echo esc_attr($ptk); ?>" <?php checked(in_array($ptk, $pts, true)); ?>>
                    <?php echo esc_html($ptl); ?> <code>(<?php echo esc_html($ptk); ?>)</code></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="evk-loc-block evk-loc-term">
            <div class="evk-b-section-title">Taksonomie</div>
            <?php if (empty($all_tax)): ?>
                <p class="description">Brak zarejestrowanych taksonomii.</p>
            <?php else: ?>
            <div class="evk-loc-checks">
                <?php foreach ($all_tax as $txk => $txl): ?>
                <label><input type="checkbox" name="evk_group_taxonomies[]" value="<?php echo esc_attr($txk); ?>" <?php checked(in_array($txk, $taxes, true)); ?>>
                    <?php echo esc_html($txl); ?> <code>(<?php echo esc_html($txk); ?>)</code></label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="evk-loc-block evk-loc-user">
            <p class="description">Pola pojawią się na ekranie edycji profilu każdego użytkownika.</p>
        </div>

        <div class="evk-loc-block evk-loc-media">
            <p class="description">Pola pojawią się w panelu „Szczegóły załącznika" (modal mediów, przy podglądzie z prawej). Tylko proste typy pól (tekst, lista, liczba, kolor, data…). Dostępne też w pętli galerii jako pola bieżącego obrazu — np. do Isotope.</p>
        </div>
    </div>
    <?php
}

function evk_group_fields_metabox(\WP_Post $post): void {
    $json   = get_post_meta($post->ID, '_evk_fields', true);
    $fields = $json ? (json_decode($json, true) ?? []) : [];
    ?>
    <div class="evk-b" style="padding:0;">
        <div class="evk-b-toolbar">
            <button type="button" class="evk-b-collapse-all" data-collapsed="0">
                <span class="dashicons dashicons-arrow-up-alt2"></span> Zwiń wszystko
            </button>
        </div>
        <div class="evk-b-fields" id="evk-edit-fields">
            <?php
            $fi = 0;
            foreach ($fields as $fkey => $f) {
                $f['_key'] = $fkey;
                evk_rep_builder_field_row('evk_fields[' . $fi . ']', $f);
                $fi++;
            }
            ?>
        </div>
        <button type="button" class="evk-b-field-add" style="margin-top:12px;">
            <span class="dashicons dashicons-plus-alt2"></span> Dodaj pole
        </button>
    </div>

    <script type="text/html" id="evk-b-field-tpl"><?php evk_rep_builder_field_row('evk_fields[__FINDEX__]'); ?></script>
    <script type="text/html" id="evk-b-subfield-tpl"><?php evk_rep_builder_field_row('__SUBBASE__', [], true); ?></script>
    <script type="text/html" id="evk-b-cond-tpl"><?php echo evk_rep_cond_rule_html('__CONDBASE__', '__CINDEX__'); ?></script>
    <script>
    (function($) {
        $(document).on('click', '#evk-edit-fields ~ .evk-b-field-add', function() {
            var html = $('#evk-b-field-tpl').html().split('__FINDEX__').join(Date.now());
            var $f = $(html);
            $('#evk-edit-fields').append($f);
            if (typeof applyType === 'function') applyType($f);
        });
    })(jQuery);
    </script>
    <?php
}

// =========================================================================
// ZAPIS
// =========================================================================

add_action('save_post_evk_field_group', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $nonce = $_POST['evk_group_nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'evk_group_save_' . $post_id)) return;

    // ── Klucz ──
    $existing_key = get_post_meta($post_id, '_evk_key', true);
    if (empty($existing_key)) {
        $raw = sanitize_key(remove_accents((string) ($_POST['evk_group_key'] ?? '')));
        if ($raw === '') {
            $title = get_post_field('post_title', $post_id);
            $raw   = sanitize_key(remove_accents($title));
        }
        if ($raw !== '') update_post_meta($post_id, '_evk_key', $raw);
    }

    // ── Ustawienia ──
    update_post_meta($post_id, '_evk_repeater',  !empty($_POST['evk_group_repeater'])  ? 1 : 0);
    update_post_meta($post_id, '_evk_collapsed', !empty($_POST['evk_group_collapsed']) ? 1 : 0);
    update_post_meta($post_id, '_evk_seamless',  !empty($_POST['evk_group_seamless'])  ? 1 : 0);
    update_post_meta($post_id, '_evk_hide_title', !empty($_POST['evk_group_hide_title']) ? 1 : 0);

    $add_label = sanitize_text_field($_POST['evk_group_add_label'] ?? '');
    if ($add_label) update_post_meta($post_id, '_evk_add_label', $add_label);
    else            delete_post_meta($post_id, '_evk_add_label');

    // Może być kluczem pola ALBO szablonem z kluczy (np. {tytul} | {cena}) — nie sanitize_key.
    $title_field = sanitize_text_field($_POST['evk_group_title_field'] ?? '');
    if ($title_field !== '') update_post_meta($post_id, '_evk_title_field', $title_field);
    else                     delete_post_meta($post_id, '_evk_title_field');

    // ── Lokalizacja ──
    $obj = sanitize_key($_POST['evk_group_object_type'] ?? 'post');
    if (!in_array($obj, ['post', 'term', 'user', 'media'], true)) $obj = 'post';
    update_post_meta($post_id, '_evk_object_type', $obj);

    $pts_raw = isset($_POST['evk_group_post_types']) && is_array($_POST['evk_group_post_types'])
        ? array_values(array_filter(array_map('sanitize_key', $_POST['evk_group_post_types'])))
        : [];
    update_post_meta($post_id, '_evk_post_types', $pts_raw ?: ['post']);

    $tax_raw = isset($_POST['evk_group_taxonomies']) && is_array($_POST['evk_group_taxonomies'])
        ? array_values(array_filter(array_map('sanitize_key', $_POST['evk_group_taxonomies'])))
        : [];
    update_post_meta($post_id, '_evk_taxonomies', $tax_raw);

    // ── Pola ──
    $raw_fields = isset($_POST['evk_fields']) && is_array($_POST['evk_fields'])
        ? wp_unslash($_POST['evk_fields'])
        : [];

    $allowed_types     = array_keys(evk_rep_field_types(false));
    $allowed_sub_types = array_keys(evk_rep_field_types(true));
    $allowed_widths    = array_keys(evk_rep_width_options());
    $auto  = 0;
    $clean = [];
    foreach ($raw_fields as $f) {
        if (!is_array($f)) continue;
        $def = evk_rep_builder_parse_field($f, false, $allowed_types, $allowed_sub_types, $allowed_widths, $auto);
        if ($def === null) continue;
        $fkey = $def['_key'];
        unset($def['_key']);
        $clean[$fkey] = $def;
    }

    // Kluczowa poprawka z chronieniem kodowania Unicode przy zapisie
    update_post_meta($post_id, '_evk_fields', wp_slash(wp_json_encode($clean, JSON_UNESCAPED_UNICODE)));

    evk_groups_cache_clear();
}, 10);

// =========================================================================
// HELPERY WSPÓŁDZIELONE
// =========================================================================

function evk_rep_field_type_optgroups(bool $sub = false): array {
    $data = [
        'text'     => 'Tekst',
        'textarea' => 'Tekst wielowierszowy',
        'number'   => 'Liczba',
        'range'    => 'Suwak',
        'email'    => 'E-mail',
        'url'      => 'Link (URL)',
        'link'     => 'Link / przycisk (URL + etykieta + cel)',
        'select'   => 'Lista rozwijana',
        'radio'    => 'Wybór (radio)',
        'button_group' => 'Grupa przycisków',
        'checkbox' => 'Checkbox (tak/nie)',
        'toggle'   => 'Przełącznik (toggle)',
        'color'    => 'Kolor',
        'date'     => 'Data',
        'time'     => 'Czas (godzina)',
        'datetime' => 'Data i godzina',
        'image'    => 'Obraz',
        'image_select' => 'Image Select',
        'gallery'  => 'Galeria',
        'wysiwyg'  => 'Edytor WYSIWYG',
    ];
    $relacje = ['taxonomy' => 'Taksonomia', 'relationship' => 'Relacja (posty)'];

    if ($sub) return ['Pola danych' => $data, 'Relacje' => $relacje];
    return [
        'Pola danych'        => $data,
        'Relacje'            => $relacje,
        'Repeater'           => ['repeater' => 'Repeater (pola powtarzalne)'],
        'Układ (separatory)' => ['tab' => 'Zakładka', 'accordion' => 'Akordeon', 'heading' => 'Nagłówek', 'description' => 'Opis (blok tekstowy)'],
    ];
}

function evk_rep_field_types(bool $sub = false): array {
    $flat = [];
    foreach (evk_rep_field_type_optgroups($sub) as $opts) $flat += $opts;
    return $flat;
}

function evk_rep_width_options(): array {
    return [0 => 'Auto', 25 => '25%', 33 => '33%', 50 => '50%', 66 => '66%', 75 => '75%', 100 => '100%'];
}

// Logika warunkowa — operatory reguł.
function evk_rep_cond_ops(): array {
    return [
        '=='        => 'jest równe',
        '!='        => 'różne od',
        'contains'  => 'zawiera',
        'empty'     => 'puste',
        'not_empty' => 'niepuste',
    ];
}

// Render pojedynczego wiersza reguły warunkowej (używany dla istniejących reguł i szablonu).
function evk_rep_cond_rule_html(string $base, $index, string $rf = '', string $rop = '==', string $rval = ''): string {
    $name = $base . '[conditions][rules][' . $index . ']';
    ob_start(); ?>
    <div class="evk-b-cond-rule">
        <select name="<?php echo esc_attr($name); ?>[field]" class="evk-b-cond-field" data-selected="<?php echo esc_attr($rf); ?>"></select>
        <select name="<?php echo esc_attr($name); ?>[op]" class="evk-b-cond-op">
            <?php foreach (evk_rep_cond_ops() as $ok => $ol): ?>
            <option value="<?php echo esc_attr($ok); ?>" <?php selected($rop, $ok); ?>><?php echo esc_html($ol); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="<?php echo esc_attr($name); ?>[value]" value="<?php echo esc_attr($rval); ?>" class="evk-b-cond-value" placeholder="wartość">
        <button type="button" class="evk-b-cond-remove" title="Usuń warunek"><span class="dashicons dashicons-no-alt"></span></button>
    </div>
    <?php
    return ob_get_clean();
}

function evk_rep_available_post_types(): array {
    $out = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
        if (in_array($pt->name, ['attachment', 'evk_field_group'], true)) continue;
        $out[$pt->name] = $pt->labels->singular_name ?: $pt->name;
    }
    return $out;
}

function evk_rep_normalize_options_text($raw): string {
    $text = (string) $raw;
    // Naprawa błędu podwójnego backslasha w starym string_replace, wystarczy standaryzacja \n
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = array_map('trim', explode("\n", $text));
    $lines = array_filter($lines, function($l) { return $l !== ''; });
    return implode("\n", $lines);
}

function evk_rep_sanitize_dimension($value, int $default, int $min = 1, int $max = 1000): int {
    $value = (int) $value;
    if ($value <= 0) $value = $default;
    return max($min, min($max, $value));
}

function evk_rep_builder_parse_field(array $f, bool $sub, array $allowed_types, array $allowed_sub_types, array $allowed_widths, int &$auto): ?array {
    $types     = $sub ? $allowed_sub_types : $allowed_types;
    $type      = in_array($f['type'] ?? '', $types, true) ? $f['type'] : 'text';
    $is_layout = !$sub && evk_rep_is_layout($type);
    $is_rep    = !$sub && $type === 'repeater';

    $fkey = sanitize_key(remove_accents((string) ($f['key'] ?? '')));
    if ($fkey === '') {
        if ($is_layout || $is_rep) { $fkey = $type . '_' . (++$auto); }
        else { return null; }
    }
    $label = sanitize_text_field($f['label'] ?? '');
    $def   = ['_key' => $fkey, 'label' => $label, 'type' => $type];

    if ($is_rep) {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 100;
        $subs = [];
        $sauto = 0;
        foreach ((array)($f['sub_fields'] ?? []) as $sf) {
            if (!is_array($sf)) continue;
            $parsed = evk_rep_builder_parse_field($sf, true, $allowed_types, $allowed_sub_types, $allowed_widths, $sauto);
            if ($parsed === null) continue;
            $sk = $parsed['_key'];
            unset($parsed['_key']);
            $subs[$sk] = $parsed;
        }
        $def['sub_fields'] = $subs;
        $tf = sanitize_key($f['title_field'] ?? '');
        if ($tf !== '' && isset($subs[$tf])) $def['title_field'] = $tf;
        $ttpl = sanitize_text_field($f['title_tpl'] ?? '');
        if ($ttpl !== '') $def['title_tpl'] = $ttpl;
        if (!empty($f['collapsed'])) $def['collapsed'] = true;
        $al = sanitize_text_field($f['add_label'] ?? '');
        if ($al !== '') $def['add_label'] = $al;
    } elseif ($type === 'taxonomy') {
        $def['width']    = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        $def['taxonomy'] = sanitize_key($f['taxonomy'] ?? '');
        if (!empty($f['multiple'])) $def['multiple'] = true;
    } elseif ($type === 'relationship') {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        $rpts = isset($f['rel_post_types']) && is_array($f['rel_post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $f['rel_post_types'])))
            : [];
        $def['rel_post_types'] = $rpts ?: ['post'];
        if (!empty($f['rel_multiple'])) $def['rel_multiple'] = true;
    } elseif ($type === 'image_select') {
        $def['width']        = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        $def['options']      = sanitize_textarea_field(evk_rep_normalize_options_text($f['options'] ?? ''));
        $def['image_width']  = evk_rep_sanitize_dimension($f['image_width'] ?? 80, 80);
        $def['image_height'] = evk_rep_sanitize_dimension($f['image_height'] ?? 80, 80);
    } elseif ($type === 'gallery') {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        // Select źródła zawsze przesyła wartość — szanujemy ją wprost (bez wnioskowania
        // z treści textarea), żeby przełączenie na „Brak" faktycznie się zapisało.
        $src = sanitize_key($f['gallery_cat_source'] ?? '');
        if ($src === 'manual') {
            $def['gallery_cat_source'] = 'manual';
            $cats = sanitize_textarea_field(evk_rep_normalize_options_text($f['gallery_categories'] ?? ''));
            if ($cats !== '') $def['gallery_categories'] = $cats;
        } elseif ($src === 'taxonomy') {
            $def['gallery_cat_source'] = 'taxonomy';
            $tax = sanitize_key($f['gallery_cat_taxonomy'] ?? '');
            if ($tax !== '') $def['gallery_cat_taxonomy'] = $tax;
        }
        // $src === '' → prosta galeria: nie zapisujemy źródła ani kategorii
        $sort = sanitize_key($f['gallery_sort'] ?? '');
        if (in_array($sort, ['random', 'random_hour', 'random_day'], true)) $def['gallery_sort'] = $sort;
        $iw = (int) ($f['gallery_item_width'] ?? 0);
        if ($iw > 0) $def['gallery_item_width'] = max(60, min(400, $iw));
    } elseif ($type === 'range') {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        $def['min']   = is_numeric($f['min'] ?? '') ? (float) $f['min'] : 0;
        $def['max']   = is_numeric($f['max'] ?? '') ? (float) $f['max'] : 100;
        $def['step']  = is_numeric($f['step'] ?? '') && (float) $f['step'] > 0 ? (float) $f['step'] : 1;
    } elseif ($type === 'heading') {
        $size = sanitize_key($f['heading_size'] ?? 'h3');
        if (!in_array($size, ['h1', 'h2', 'h3', 'h4', 'h5'], true)) $size = 'h3';
        $def['heading_size'] = $size;
        if (!empty($f['heading_separator'])) $def['heading_separator'] = true;
        $sub_t = sanitize_text_field($f['heading_sub'] ?? '');
        if ($sub_t !== '') $def['heading_sub'] = $sub_t;
    } elseif ($type === 'description') {
        $content = wp_kses_post($f['desc_content'] ?? '');
        if ($content !== '') $def['desc_content'] = $content;
        if (!empty($f['desc_collapsible'])) $def['desc_collapsible'] = true;
        if (!empty($f['desc_collapsed']))   $def['desc_collapsed']   = true;
    } elseif ($type === 'toggle') {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        $on  = sanitize_text_field($f['toggle_on']  ?? '1');
        $off = sanitize_text_field($f['toggle_off'] ?? '0');
        if ($on  !== '') $def['toggle_on']  = $on;
        if ($off !== '') $def['toggle_off'] = $off;
        $onl = sanitize_text_field($f['toggle_on_label']  ?? '');
        $ofl = sanitize_text_field($f['toggle_off_label'] ?? '');
        if ($onl !== '') $def['toggle_on_label']  = $onl;
        if ($ofl !== '') $def['toggle_off_label'] = $ofl;
    } elseif (!$is_layout) {
        $def['width'] = in_array((int)($f['width'] ?? 0), $allowed_widths, true) ? (int)$f['width'] : 0;
        if (in_array($type, ['select', 'radio', 'button_group'], true)) {
            $def['options'] = sanitize_textarea_field(evk_rep_normalize_options_text($f['options'] ?? ''));
        }
    }

    // Format wyświetlania daty/czasu (zapis zawsze ISO; to tylko output).
    if (in_array($type, ['date', 'time', 'datetime'], true)) {
        $df = sanitize_text_field($f['date_format'] ?? '');
        if ($df !== '') $def['date_format'] = $df;
    }

    // Wspólne opcje pól danych (top-level i sub): placeholder, wymagane, prefiks/sufiks, wiersze.
    if (!$is_layout && !$is_rep) {
        $ph = sanitize_text_field($f['placeholder'] ?? '');
        if ($ph !== '') $def['placeholder'] = $ph;
        if (!empty($f['required'])) $def['required'] = true;
        $px = sanitize_text_field($f['prefix'] ?? '');
        if ($px !== '') $def['prefix'] = $px;
        $sx = sanitize_text_field($f['suffix'] ?? '');
        if ($sx !== '') $def['suffix'] = $sx;
        $rw = (int) ($f['rows'] ?? 0);
        if ($rw > 0) $def['rows'] = min(50, $rw);
    }

    // Kolumna w panelu admina — tylko pola top-level danych (nie sub, nie layout, nie repeater).
    if (!$sub && !$is_layout && !$is_rep && !empty($f['column'])) {
        $def['column'] = true;
        $cl = sanitize_text_field($f['column_label'] ?? '');
        if ($cl !== '') $def['column_label'] = $cl;
        if (!empty($f['column_sortable'])) $def['column_sortable'] = true;
        if (isset($f['column_position']) && $f['column_position'] !== '' && is_numeric($f['column_position'])) {
            $def['column_position'] = (int) $f['column_position'];
        }
    }

    // Logika warunkowa — dotyczy każdego typu pola (pokaż/ukryj wg innych pól).
    $cond = $f['conditions'] ?? null;
    if (is_array($cond)) {
        $rel    = (($cond['relation'] ?? 'all') === 'any') ? 'any' : 'all';
        $valid_ops = array_keys(evk_rep_cond_ops());
        $rules  = [];
        foreach ((array) ($cond['rules'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $rf = sanitize_key(remove_accents((string) ($r['field'] ?? '')));
            if ($rf === '') continue;
            $op   = in_array($r['op'] ?? '', $valid_ops, true) ? $r['op'] : '==';
            $rule = ['field' => $rf, 'op' => $op];
            if ($op !== 'empty' && $op !== 'not_empty') {
                $rule['value'] = sanitize_text_field($r['value'] ?? '');
            }
            $rules[] = $rule;
        }
        if ($rules) $def['conditions'] = ['relation' => $rel, 'rules' => $rules];
    }

    return $def;
}

// =========================================================================
// RENDER POLA BUILDERA
// =========================================================================

function evk_rep_builder_field_row(string $base, array $field = [], bool $sub = false): void {
    $widths          = evk_rep_width_options();
    $label           = $field['label']       ?? '';
    $key             = $field['_key']        ?? '';
    $type            = $field['type']        ?? 'text';
    $width           = (int)($field['width'] ?? 0);
    $options         = $field['options']     ?? '';
    $image_width     = (int)($field['image_width']  ?? 80);
    $image_height    = (int)($field['image_height'] ?? 80);
    $range_min       = $field['min']         ?? 0;
    $range_max       = $field['max']         ?? 100;
    $range_step      = $field['step']        ?? 1;
    $subf            = $field['sub_fields']  ?? [];
    $title_field     = $field['title_field'] ?? '';
    $add_label       = $field['add_label']   ?? '';
    $field_collapsed = !empty($field['collapsed']);
    $tax_slug        = $field['taxonomy']    ?? '';
    $tax_multi       = !empty($field['multiple']);
    $gallery_cats        = $field['gallery_categories'] ?? '';
    $gallery_cat_source  = $field['gallery_cat_source'] ?? ($gallery_cats !== '' ? 'manual' : '');
    $gallery_cat_tax     = $field['gallery_cat_taxonomy'] ?? '';
    $gallery_sort        = $field['gallery_sort'] ?? '';
    $gallery_item_width  = (int)($field['gallery_item_width'] ?? 0);
    $rel_pts             = $field['rel_post_types'] ?? ['post'];
    $rel_multi           = !empty($field['rel_multiple']);
    $placeholder         = $field['placeholder'] ?? '';
    $required            = !empty($field['required']);
    $prefix              = $field['prefix'] ?? '';
    $suffix              = $field['suffix'] ?? '';
    $rows_opt            = (int)($field['rows'] ?? 0);
    $title_tpl           = $field['title_tpl'] ?? '';
    // Przełącznik
    $toggle_on           = $field['toggle_on']  ?? '1';
    $toggle_off          = $field['toggle_off'] ?? '0';
    $toggle_on_label     = $field['toggle_on_label']  ?? '';
    $toggle_off_label    = $field['toggle_off_label'] ?? '';
    // Opis
    $desc_content        = $field['desc_content']    ?? '';
    $desc_collapsible    = !empty($field['desc_collapsible']);
    $desc_collapsed      = !empty($field['desc_collapsed']);
    // Nagłówek
    $heading_size        = $field['heading_size']      ?? 'h3';
    $heading_separator   = !empty($field['heading_separator']);
    $heading_sub         = $field['heading_sub']       ?? '';
    // Data / czas
    $date_format         = $field['date_format']       ?? '';
    // Logika warunkowa
    $conditions          = is_array($field['conditions'] ?? null) ? $field['conditions'] : [];
    $cond_relation       = (($conditions['relation'] ?? 'all') === 'any') ? 'any' : 'all';
    $cond_rules          = is_array($conditions['rules'] ?? null) ? $conditions['rules'] : [];

    $has_opts = in_array($type, ['select', 'radio', 'button_group', 'image_select'], true);
    $layout   = !$sub && evk_rep_is_layout($type);
    $is_rep   = !$sub && $type === 'repeater';
    $is_tax   = $type === 'taxonomy';
    $is_img_select = $type === 'image_select';
    $is_range = $type === 'range';
    $is_gallery = $type === 'gallery';
    $is_relationship = $type === 'relationship';
    $is_toggle       = $type === 'toggle';
    $is_description  = $type === 'description';
    $is_heading_ext  = $type === 'heading'; // heading ma teraz własną konfigurację
    $cls      = 'evk-b-field'
        . ($has_opts ? ' is-opts'     : '')
        . ($layout   ? ' is-layout'   : '')
        . ($is_rep   ? ' is-repeater' : '')
        . ($sub      ? ' is-sub'      : '')
        . ($is_tax   ? ' is-taxonomy' : '')
        . ($is_img_select ? ' is-image-select' : '')
        . ($is_range ? ' is-range' : '')
        . ($is_gallery ? ' is-gallery' : '')
        . ($is_relationship ? ' is-relationship' : '')
        . ($is_toggle       ? ' is-toggle'       : '')
        . ($is_description  ? ' is-description'  : '')
        . ($is_heading_ext  ? ' is-heading-ext'  : '');
    ?>
    <div class="<?php echo esc_attr($cls); ?>" data-base="<?php echo esc_attr($base); ?>" data-ftype="<?php echo esc_attr($type); ?>">
        <div class="evk-b-field-top">
            <span class="evk-b-handle <?php echo $sub ? 'evk-b-subhandle' : 'evk-b-fhandle'; ?> dashicons dashicons-menu" title="Przeciągnij"></span>
            <span class="evk-b-badge" aria-hidden="true"></span>
            <input type="text" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr($label); ?>" placeholder="Etykieta pola" class="evk-b-fld-label">
            <?php if (!$sub): ?>
            <label class="evk-switch-wrap evk-b-col-switch" title="Pokaż jako kolumnę w panelu admina">
                <span class="evk-b-col-switch-label">Kolumna</span>
                <span class="evk-switch">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[column]" value="1" class="evk-b-col-enable" <?php checked(!empty($field['column'])); ?>>
                    <span class="evk-switch-slider"></span>
                </span>
            </label>
            <?php endif; ?>
            <div class="evk-b-field-actions">
                <button type="button" class="evk-b-field-clone" title="Klonuj pole"><span class="dashicons dashicons-admin-page"></span></button>
                <button type="button" class="evk-b-field-remove" title="Usuń pole"><span class="dashicons dashicons-trash"></span></button>
            </div>
        </div>

        <div class="evk-b-field-grid">
            <div class="evk-b-ctrl evk-b-ctrl-key">
                <label>Klucz / tag</label>
                <input type="text" name="<?php echo esc_attr($base); ?>[key]" value="<?php echo esc_attr($key); ?>" placeholder="klucz" class="evk-b-key">
            </div>
            <div class="evk-b-ctrl evk-b-ctrl-type">
                <label>Typ pola</label>
                <select name="<?php echo esc_attr($base); ?>[type]" class="evk-b-type">
                    <?php foreach (evk_rep_field_type_optgroups($sub) as $grp => $opts): ?>
                    <optgroup label="<?php echo esc_attr($grp); ?>">
                        <?php foreach ($opts as $tk => $tl): ?>
                        <option value="<?php echo esc_attr($tk); ?>" <?php selected($type, $tk); ?>><?php echo esc_html($tl); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="evk-b-ctrl evk-b-ctrl-width">
                <label>Szerokość</label>
                <select name="<?php echo esc_attr($base); ?>[width]" class="evk-b-width">
                    <?php foreach ($widths as $wk => $wl): ?>
                    <option value="<?php echo esc_attr($wk); ?>" <?php selected($width, $wk); ?>><?php echo esc_html($wl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="evk-b-field-opts">
            <label>
                <span class="evk-b-opts-standard">Opcje listy — jedna na linię, format <code>wartość : Etykieta</code></span>
                <span class="evk-b-opts-image">Opcje obrazków — jedna na linię, format <code>URL obrazka : Etykieta</code></span>
            </label>
            <textarea name="<?php echo esc_attr($base); ?>[options]" rows="3" placeholder="low : Niski&#10;high : Wysoki"><?php echo esc_textarea($options); ?></textarea>
        </div>

        <div class="evk-b-field-image-select">
            <div class="evk-b-section-title">Konfiguracja Image Select</div>
            <div class="evk-b-inline-grid">
                <div class="evk-b-ctrl">
                    <label>Szerokość obrazka (px)</label>
                    <input type="number" min="1" max="1000" step="1" name="<?php echo esc_attr($base); ?>[image_width]" value="<?php echo esc_attr($image_width); ?>" placeholder="80">
                </div>
                <div class="evk-b-ctrl">
                    <label>Wysokość obrazka (px)</label>
                    <input type="number" min="1" max="1000" step="1" name="<?php echo esc_attr($base); ?>[image_height]" value="<?php echo esc_attr($image_height); ?>" placeholder="80">
                </div>
            </div>
            <p style="margin:12px 0 0;">
                <button type="button" class="button evk-b-img-pick"><span class="dashicons dashicons-format-image"></span> Dodaj obrazy z biblioteki mediów</button>
            </p>
        </div>

        <div class="evk-b-field-gallery" data-cat-source="<?php echo esc_attr($gallery_cat_source); ?>">
            <div class="evk-b-section-title">Konfiguracja galerii</div>
            <div class="evk-b-ctrl" style="margin-bottom:12px;">
                <label>Sortowanie obrazów (front)</label>
                <select name="<?php echo esc_attr($base); ?>[gallery_sort]">
                    <option value="" <?php selected($gallery_sort, ''); ?>>Kolejność dodania / grupami</option>
                    <option value="random" <?php selected($gallery_sort, 'random'); ?>>Losowo (co wczytanie)</option>
                    <option value="random_hour" <?php selected($gallery_sort, 'random_hour'); ?>>Losowo — zmiana co godzinę</option>
                    <option value="random_day" <?php selected($gallery_sort, 'random_day'); ?>>Losowo — zmiana co dzień</option>
                </select>
            </div>
            <div class="evk-b-ctrl" style="margin-bottom:12px;">
                <label>Szerokość kafelka w edytorze (px)</label>
                <input type="number" min="60" max="400" step="1" name="<?php echo esc_attr($base); ?>[gallery_item_width]" value="<?php echo $gallery_item_width > 0 ? esc_attr((string) $gallery_item_width) : ''; ?>" placeholder="108 (domyślnie)">
            </div>
            <div class="evk-b-ctrl">
                <label>Źródło kategorii obrazów</label>
                <select name="<?php echo esc_attr($base); ?>[gallery_cat_source]" class="evk-b-gallery-cat-source">
                    <option value="" <?php selected($gallery_cat_source, ''); ?>>Brak — prosta galeria</option>
                    <option value="manual" <?php selected($gallery_cat_source, 'manual'); ?>>Lista ręczna</option>
                    <option value="taxonomy" <?php selected($gallery_cat_source, 'taxonomy'); ?>>Taksonomia (termy)</option>
                </select>
            </div>
            <div class="evk-b-gallery-cat-manual">
                <label class="evk-b-gallery-cats-label">Kategorie — jedna na linię, format <code>wartość : Etykieta</code></label>
                <textarea name="<?php echo esc_attr($base); ?>[gallery_categories]" rows="3" placeholder="nature : Natura&#10;city : Miasto"><?php echo esc_textarea($gallery_cats); ?></textarea>
            </div>
            <div class="evk-b-gallery-cat-taxonomy">
                <div class="evk-b-ctrl">
                    <label>Taksonomia</label>
                    <select name="<?php echo esc_attr($base); ?>[gallery_cat_taxonomy]">
                        <option value="">— wybierz —</option>
                        <?php foreach (get_taxonomies(['show_ui' => true], 'objects') as $tx): ?>
                        <option value="<?php echo esc_attr($tx->name); ?>" <?php selected($gallery_cat_tax, $tx->name); ?>><?php echo esc_html($tx->label); ?> (<?php echo esc_html($tx->name); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="description" style="margin:10px 0 0;">Brak źródła = prosta galeria. Z kategoriami każdy obraz dostaje wybór kategorii, a front zwraca jedną listę z kategorią przy każdym obrazie.</p>

            <?php $cheat_key = $key !== '' ? $key : 'klucz'; ?>
            <details class="evk-b-cheat">
                <summary class="evk-b-cheat-title"><span class="dashicons dashicons-lightbulb"></span> Jak wyświetlić w Bricks<span class="dashicons dashicons-arrow-down-alt2 evk-b-cheat-chevron"></span></summary>
                <div class="evk-b-cheat-body">
                    <div class="evk-b-cheat-row"><strong>Cała galeria (pętla — polecane):</strong></div>
                    <ol class="evk-b-cheat-steps">
                        <li>Element z <em>Query Loop</em> → Type: <strong>„EVK Galeria: …"</strong> (na stronie opcji: <strong>„EVK Galeria Opcje: …"</strong>)</li>
                        <li>W środku element <em>Image</em> → bind przez <code>{evk_field_img__id}</code> i ustaw <strong>Size = „Large" lub „Full"</strong> (nie „Thumbnail" — to mały, kwadratowy crop). Daje poprawne wymiary, srcset i lightbox do pełnego.<br><em>URL-e</em> <code>{evk_field_img}</code> / <code>{evk_field_img__large}</code> — raczej do tła / własnego HTML.</li>
                        <li>Kategoria danego obrazu → <code>{evk_field_cat__label}</code> (slug do klasy: <code>{evk_field_cat}</code>)</li>
                    </ol>
                    <div class="evk-b-cheat-row"><strong>Przyciski filtrów (tylko użyte kategorie):</strong></div>
                    <ol class="evk-b-cheat-steps">
                        <li>Druga pętla → Type: <strong>„EVK Galeria kategorie: …"</strong></li>
                        <li>Przycisk z <code>{evk_field_name}</code> (nazwa) i <code>data-filter</code> = <code>{evk_field_slug}</code></li>
                    </ol>
                    <div class="evk-b-cheat-row"><strong>Proste tagi:</strong></div>
                    <ul class="evk-b-cheat-tags">
                        <li><code class="evk-b-cheat-tag" data-tpl="{evk_field_%s__ids}">{evk_field_<?php echo esc_html($cheat_key); ?>__ids}</code> — lista ID (np. natywny element Galeria Bricks)</li>
                        <li><code class="evk-b-cheat-tag" data-tpl="{evk_field_%s__count}">{evk_field_<?php echo esc_html($cheat_key); ?>__count}</code> — liczba obrazów</li>
                        <li><code class="evk-b-cheat-tag" data-tpl="{evk_field_%s}">{evk_field_<?php echo esc_html($cheat_key); ?>}</code> — URL pierwszego obrazu</li>
                    </ul>
                </div>
            </details>
        </div>

        <div class="evk-b-field-range">
            <div class="evk-b-section-title">Konfiguracja suwaka</div>
            <div class="evk-b-inline-grid">
                <div class="evk-b-ctrl">
                    <label>Minimum</label>
                    <input type="number" step="any" name="<?php echo esc_attr($base); ?>[min]" value="<?php echo esc_attr((string) $range_min); ?>" placeholder="0">
                </div>
                <div class="evk-b-ctrl">
                    <label>Maksimum</label>
                    <input type="number" step="any" name="<?php echo esc_attr($base); ?>[max]" value="<?php echo esc_attr((string) $range_max); ?>" placeholder="100">
                </div>
                <div class="evk-b-ctrl">
                    <label>Krok</label>
                    <input type="number" min="0.0001" step="any" name="<?php echo esc_attr($base); ?>[step]" value="<?php echo esc_attr((string) $range_step); ?>" placeholder="1">
                </div>
            </div>
        </div>

        <div class="evk-b-field-tax">
            <div class="evk-b-section-title">Konfiguracja taksonomii</div>
            <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
                <div class="evk-b-ctrl">
                    <label>Taksonomia</label>
                    <select name="<?php echo esc_attr($base); ?>[taxonomy]">
                        <option value="">— wybierz —</option>
                        <?php foreach (get_taxonomies(['public' => true], 'objects') as $tax_obj): ?>
                        <option value="<?php echo esc_attr($tax_obj->name); ?>" <?php selected($tax_slug, $tax_obj->name); ?>>
                            <?php echo esc_html($tax_obj->label); ?> (<?php echo esc_html($tax_obj->name); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="evk-b-inline-check" style="padding-bottom:4px;">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[multiple]" value="1" <?php checked($tax_multi); ?>>
                    Wielokrotny wybór
                </label>
            </div>
        </div>

        <div class="evk-b-field-relationship">
            <div class="evk-b-section-title">Konfiguracja relacji</div>
            <label class="evk-b-gallery-cats-label">Typy treści do wyboru</label>
            <div class="evk-b-rel-pts">
                <?php foreach (get_post_types(['show_ui' => true], 'objects') as $rpt):
                    if (in_array($rpt->name, ['attachment', 'evk_field_group'], true)) continue; ?>
                <label><input type="checkbox" name="<?php echo esc_attr($base); ?>[rel_post_types][]" value="<?php echo esc_attr($rpt->name); ?>" <?php checked(in_array($rpt->name, (array) $rel_pts, true)); ?>>
                    <?php echo esc_html($rpt->labels->singular_name ?: $rpt->name); ?> <code>(<?php echo esc_html($rpt->name); ?>)</code></label>
                <?php endforeach; ?>
            </div>
            <label class="evk-b-inline-check" style="margin:10px 0 0;">
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[rel_multiple]" value="1" <?php checked($rel_multi); ?>> Wielokrotny wybór (wiele wpisów)
            </label>
        </div>

        <div class="evk-b-field-toggle">
            <div class="evk-b-section-title">Konfiguracja przełącznika</div>
            <div class="evk-b-inline-grid">
                <div class="evk-b-ctrl">
                    <label>Wartość gdy włączony</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[toggle_on]" value="<?php echo esc_attr($toggle_on); ?>" placeholder="1">
                </div>
                <div class="evk-b-ctrl">
                    <label>Wartość gdy wyłączony</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[toggle_off]" value="<?php echo esc_attr($toggle_off); ?>" placeholder="0">
                </div>
                <div class="evk-b-ctrl">
                    <label>Etykieta ON</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[toggle_on_label]" value="<?php echo esc_attr($toggle_on_label); ?>" placeholder="Tak">
                </div>
                <div class="evk-b-ctrl">
                    <label>Etykieta OFF</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[toggle_off_label]" value="<?php echo esc_attr($toggle_off_label); ?>" placeholder="Nie">
                </div>
            </div>
        </div>

        <div class="evk-b-field-description">
            <div class="evk-b-section-title">Treść opisu</div>
            <div class="evk-b-ctrl">
                <label>Tekst (HTML dozwolony)</label>
                <textarea name="<?php echo esc_attr($base); ?>[desc_content]" rows="3" placeholder="Tekst pomocy, instrukcja…"><?php echo esc_textarea($desc_content); ?></textarea>
            </div>
            <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:12px;">
                <label class="evk-b-inline-check" style="margin-left:0;">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[desc_collapsible]" value="1" <?php checked($desc_collapsible); ?>>
                    Zwijany (klik w tytuł)
                </label>
                <label class="evk-b-inline-check">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[desc_collapsed]" value="1" <?php checked($desc_collapsed); ?>>
                    Zwinięty na start
                </label>
            </div>
        </div>

        <div class="evk-b-field-heading-ext">
            <div class="evk-b-section-title">Konfiguracja nagłówka</div>
            <div class="evk-b-inline-grid">
                <div class="evk-b-ctrl">
                    <label>Rozmiar</label>
                    <select name="<?php echo esc_attr($base); ?>[heading_size]">
                        <?php foreach (['h1' => 'H1 — Największy', 'h2' => 'H2 — Duży', 'h3' => 'H3 — Średni (domyślny)', 'h4' => 'H4 — Mały', 'h5' => 'H5 — Bardzo mały'] as $hk => $hl): ?>
                        <option value="<?php echo esc_attr($hk); ?>" <?php selected($heading_size, $hk); ?>><?php echo esc_html($hl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="evk-b-ctrl" style="margin-top:10px;">
                <label>Podtekst (opcjonalny)</label>
                <input type="text" name="<?php echo esc_attr($base); ?>[heading_sub]" value="<?php echo esc_attr($heading_sub); ?>" placeholder="Krótki opis sekcji">
            </div>
            <label class="evk-b-inline-check" style="margin-top:10px;">
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[heading_separator]" value="1" <?php checked($heading_separator); ?>>
                Separator (linia pod nagłówkiem)
            </label>
        </div>

        <div class="evk-b-field-datefmt">
            <div class="evk-b-section-title">Format wyświetlania</div>
            <div class="evk-b-ctrl">
                <label>Format daty/czasu (PHP)</label>
                <input type="text" name="<?php echo esc_attr($base); ?>[date_format]" value="<?php echo esc_attr($date_format); ?>" placeholder="puste = ustawienie witryny">
            </div>
            <p class="description" style="margin:8px 0 0;">
                W bazie zapis ISO (<code>Y-m-d</code> / <code>H:i</code> / <code>Y-m-d H:i</code>) — niezależny od formatu.
                Tu ustawiasz tylko <strong>wyświetlanie</strong> (front/Bricks/kolumna). Przykłady:
                <code>d.m.Y</code>, <code>j F Y</code>, <code>H:i</code>, <code>d.m.Y H:i</code>.
                W Bricks dostępne też tagi <code>__raw</code> (ISO) i <code>__timestamp</code>.
            </p>
        </div>

        <details class="evk-b-field-extra">
            <summary class="evk-b-section-title evk-b-extra-summary">Opcje pola<span class="dashicons dashicons-arrow-down-alt2 evk-b-extra-chevron"></span></summary>
            <div class="evk-b-extra-body">
                <div class="evk-b-inline-grid">
                    <div class="evk-b-ctrl evk-b-opt-placeholder">
                        <label>Placeholder</label>
                        <input type="text" name="<?php echo esc_attr($base); ?>[placeholder]" value="<?php echo esc_attr($placeholder); ?>" placeholder="tekst podpowiedzi">
                    </div>
                    <div class="evk-b-ctrl evk-b-opt-affix">
                        <label>Przed polem (prefiks)</label>
                        <input type="text" name="<?php echo esc_attr($base); ?>[prefix]" value="<?php echo esc_attr($prefix); ?>" placeholder="np. $">
                    </div>
                    <div class="evk-b-ctrl evk-b-opt-affix">
                        <label>Po polu (sufiks)</label>
                        <input type="text" name="<?php echo esc_attr($base); ?>[suffix]" value="<?php echo esc_attr($suffix); ?>" placeholder="np. PLN">
                    </div>
                    <div class="evk-b-ctrl evk-b-opt-rows">
                        <label>Wiersze (textarea)</label>
                        <input type="number" min="1" max="50" step="1" name="<?php echo esc_attr($base); ?>[rows]" value="<?php echo $rows_opt > 0 ? esc_attr((string) $rows_opt) : ''; ?>" placeholder="3">
                    </div>
                </div>
                <label class="evk-b-inline-check" style="margin:10px 0 0;">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[required]" value="1" <?php checked($required); ?>> Pole wymagane
                </label>
            </div>
        </details>

        <details class="evk-b-field-cond">
            <summary class="evk-b-section-title evk-b-cond-summary">Logika warunkowa<span class="dashicons dashicons-arrow-down-alt2 evk-b-cond-chevron"></span></summary>
            <div class="evk-b-cond-body">
                <div class="evk-b-cond-head">
                    Pokaż to pole, gdy
                    <select name="<?php echo esc_attr($base); ?>[conditions][relation]" class="evk-b-cond-relation">
                        <option value="all" <?php selected($cond_relation, 'all'); ?>>wszystkie</option>
                        <option value="any" <?php selected($cond_relation, 'any'); ?>>dowolny</option>
                    </select>
                    z warunków spełnione:
                </div>
                <div class="evk-b-cond-rules">
                    <?php $ci = 0; foreach ($cond_rules as $r): if (!is_array($r)) continue;
                        echo evk_rep_cond_rule_html($base, $ci, (string) ($r['field'] ?? ''), (string) ($r['op'] ?? '=='), (string) ($r['value'] ?? ''));
                        $ci++; endforeach; ?>
                </div>
                <button type="button" class="evk-b-cond-add"><span class="dashicons dashicons-plus-alt2"></span> Dodaj warunek</button>
                <p class="description evk-b-cond-empty-hint" style="margin:8px 0 0;">Brak warunków = pole zawsze widoczne.</p>
            </div>
        </details>

        <?php if (!$sub): ?>
        <div class="evk-b-field-column">
            <div class="evk-b-section-title">Kolumna w panelu admina</div>
            <div class="evk-b-inline-grid">
                <div class="evk-b-ctrl">
                    <label>Tytuł kolumny</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[column_label]" value="<?php echo esc_attr($field['column_label'] ?? ''); ?>" placeholder="domyślnie: etykieta pola">
                </div>
                <div class="evk-b-ctrl">
                    <label>Pozycja (kolejność)</label>
                    <input type="number" min="0" step="1" name="<?php echo esc_attr($base); ?>[column_position]" value="<?php echo esc_attr(isset($field['column_position']) ? (string) $field['column_position'] : ''); ?>" placeholder="auto (na końcu)">
                </div>
            </div>
            <label class="evk-b-inline-check" style="margin:10px 0 0;">
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[column_sortable]" value="1" <?php checked(!empty($field['column_sortable'])); ?>> Umożliw sortowanie
            </label>
        </div>
        <?php endif; ?>

        <?php if (!$sub): ?>
        <div class="evk-b-subfields-wrap">
            <div class="evk-b-subfields-title">Pola powtarzalne</div>
            <div class="evk-b-title-row">
                <label>Etykieta wiersza (z pola)</label>
                <select name="<?php echo esc_attr($base); ?>[title_field]" class="evk-b-title-field">
                    <option value="">— pierwsze pole tekstowe (auto) —</option>
                    <?php $tt = ['text', 'textarea', 'email', 'url', 'number']; foreach ($subf as $sk => $sf): if (!in_array($sf['type'] ?? '', $tt, true)) continue; ?>
                    <option value="<?php echo esc_attr($sk); ?>" <?php selected($title_field, $sk); ?>><?php echo esc_html($sf['label'] ?? $sk); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="evk-b-inline-check evk-b-collapsed-check"><input type="checkbox" name="<?php echo esc_attr($base); ?>[collapsed]" value="1" <?php checked($field_collapsed); ?>> Wiersze zwinięte na start</label>
            <div class="evk-b-title-tpl-row">
                <label>…albo szablon z kluczy (ma pierwszeństwo)</label>
                <input type="text" name="<?php echo esc_attr($base); ?>[title_tpl]" value="<?php echo esc_attr($title_tpl); ?>" placeholder="np. {tytul} | {cena_dania}">
            </div>
            <div class="evk-b-subfields">
                <?php $si = 0; foreach ($subf as $sk => $sf) { $sf['_key'] = $sk; evk_rep_builder_field_row($base . '[sub_fields][' . $si . ']', $sf, true); $si++; } ?>
            </div>
            <button type="button" class="evk-b-subfield-add"><span class="dashicons dashicons-plus-alt2"></span> Dodaj pole powtarzalne</button>
            <div class="evk-b-add-label-wrap">
                <label>Etykieta przycisku:</label>
                <input type="text" name="<?php echo esc_attr($base); ?>[add_label]" value="<?php echo esc_attr($add_label); ?>" placeholder="Dodaj wiersz">
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
