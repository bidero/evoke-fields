<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — CPT evk_field_group.
 * Każda grupa pól = jeden post. Status publish = aktywna, draft = nieaktywna.
 * Pola schematu w postmeta: _evk_key, _evk_fields (JSON), _evk_post_types,
 * _evk_repeater, _evk_collapsed, _evk_seamless, _evk_add_label.
 */

// =========================================================================
// REJESTRACJA CPT
// =========================================================================

add_action('init', function () {
    register_post_type('evk_field_group', [
        'labels' => [
            'name'               => 'Grupy pól',
            'singular_name'      => 'Grupa pól',
            'add_new'            => 'Nowa grupa',
            'add_new_item'       => 'Dodaj grupę pól',
            'edit_item'          => 'Edytuj grupę pól',
            'all_items'          => 'Grupy pól',
            'search_items'       => 'Szukaj grup',
            'not_found'          => 'Brak grup pól.',
            'not_found_in_trash' => 'Brak grup w koszu.',
        ],
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => 'evk-repeater',
        'show_in_rest'       => false,
        'supports'           => ['title'],
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'rewrite'            => false,
        'query_var'          => false,
    ]);
});

// =========================================================================
// LISTA — KOLUMNY
// =========================================================================

add_filter('manage_evk_field_group_posts_columns', function ($cols) {
    return [
        'cb'           => '<input type="checkbox">',
        'title'        => 'Etykieta grupy',
        'evk_key'      => 'Klucz',
        'evk_type'     => 'Typ',
        'evk_fields_n' => 'Pola',
        'evk_pts'      => 'Typy treści',
        'evk_active'   => 'Aktywna',
    ];
});

add_action('manage_evk_field_group_posts_custom_column', function ($col, $post_id) {
    switch ($col) {
        case 'evk_key':
            $k = get_post_meta($post_id, '_evk_key', true);
            echo $k ? '<code>' . esc_html($k) . '</code>' : '—';
            break;

        case 'evk_type':
            $rep = get_post_meta($post_id, '_evk_repeater', true);
            echo $rep ? '<span class="evk-badge evk-badge-rep">Repeater</span>' : '<span class="evk-badge evk-badge-single">Pojedyncza</span>';
            break;

        case 'evk_fields_n':
            $json   = get_post_meta($post_id, '_evk_fields', true);
            $fields = $json ? (json_decode($json, true) ?? []) : [];
            // Tylko pola danych (bez separatorów)
            $count = 0;
            $walk  = function (array $fs) use (&$walk, &$count) {
                foreach ($fs as $f) {
                    $t = $f['type'] ?? '';
                    if (in_array($t, ['tab', 'accordion', 'heading'], true)) continue;
                    if ($t === 'repeater') { $count++; $walk($f['sub_fields'] ?? []); continue; }
                    $count++;
                }
            };
            $walk($fields);
            echo '<strong>' . $count . '</strong>';
            break;

        case 'evk_pts':
            $pts = get_post_meta($post_id, '_evk_post_types', true);
            if (is_array($pts) && $pts) {
                echo implode(', ', array_map('esc_html', $pts));
            } else {
                echo '—';
            }
            break;

        case 'evk_active':
            $post    = get_post($post_id);
            $active  = $post && $post->post_status === 'publish';
            $nonce   = wp_create_nonce('evk_toggle_' . $post_id);
            $cls     = $active ? 'evk-toggle on' : 'evk-toggle';
            $label   = $active ? 'Tak' : 'Nie';
            echo '<button type="button" class="' . $cls . '" data-id="' . $post_id . '" data-nonce="' . $nonce . '" aria-label="Przełącz aktywność">';
            echo '<span class="evk-toggle-knob"></span>';
            echo '</button> <span class="evk-toggle-label">' . $label . '</span>';
            break;
    }
}, 10, 2);

add_filter('manage_edit-evk_field_group_sortable_columns', function ($cols) {
    $cols['evk_key']  = 'evk_key';
    $cols['evk_type'] = 'evk_type';
    return $cols;
});

// =========================================================================
// TOGGLE AJAX
// =========================================================================

add_action('wp_ajax_evk_toggle_group_active', function () {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $nonce   = $_POST['nonce'] ?? '';

    if (!$post_id || !check_ajax_referer('evk_toggle_' . $post_id, 'nonce', false)) {
        wp_send_json_error('invalid');
    }
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('caps');

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'evk_field_group') wp_send_json_error('type');

    $new_status = $post->post_status === 'publish' ? 'draft' : 'publish';
    wp_update_post(['ID' => $post_id, 'post_status' => $new_status]);
    evk_groups_cache_clear();

    wp_send_json_success(['status' => $new_status, 'active' => $new_status === 'publish']);
});

// =========================================================================
// ENQUEUE (lista CPT + edycja)
// =========================================================================

add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if (!$screen) return;

    // Lista grup
    if ($hook === 'edit.php' && $screen->post_type === 'evk_field_group') {
        wp_enqueue_script('evk-field-groups', EVK_REP_URL . 'assets/field-groups.js', ['jquery'], EVK_REP_VERSION, true);
        wp_enqueue_style('evk-field-groups', EVK_REP_URL . 'assets/evk-admin.css', [], EVK_REP_VERSION);
        wp_add_inline_style('evk-field-groups', evk_groups_list_css());
        evk_rep_mark_admin_body();
    }

    // Edycja grupy (metaboxy)
    if (in_array($hook, ['post.php', 'post-new.php'], true) && $screen->post_type === 'evk_field_group') {
        wp_enqueue_media(); // picker obrazów dla Image Select
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('evk-rep-builder', EVK_REP_URL . 'assets/builder.js', ['jquery', 'jquery-ui-sortable'], EVK_REP_VERSION, true);
        wp_enqueue_style('evk-rep-builder', EVK_REP_URL . 'assets/builder.css', [], EVK_REP_VERSION);
        // Wspólna warstwa (evk-admin.css) — info-boxy, switch, chipy, spójne przyciski
        wp_enqueue_style('evk-admin', EVK_REP_URL . 'assets/evk-admin.css', [], EVK_REP_VERSION);
        evk_rep_mark_admin_body();
    }
});

function evk_groups_list_css(): string {
    return '
    .column-evk_key{width:160px;}
    .column-evk_type,.column-evk_fields_n{width:90px;text-align:center;}
    .column-evk_pts{width:180px;}
    .column-evk_active{width:90px;text-align:center;}
    .evk-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px;}
    .evk-badge-rep{background:#dbeafe;color:#1e40af;}
    .evk-badge-single{background:#f0fdf4;color:#166534;}
    .evk-toggle{position:relative;display:inline-block;width:38px;height:22px;background:#d1d5db;border:0;border-radius:11px;cursor:pointer;transition:background .2s;vertical-align:middle;padding:0;}
    .evk-toggle.on{background:#2563eb;}
    .evk-toggle-knob{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;display:block;}
    .evk-toggle.on .evk-toggle-knob{transform:translateX(16px);}
    .evk-toggle-label{font-size:12px;color:#6b7280;margin-left:4px;vertical-align:middle;}
    ';
}

// =========================================================================
// evk_rep_groups() — czyta z CPT (z cache)
// =========================================================================

function evk_rep_groups(): array {
    // Memo per-żądanie: schemat czyta wiele miejsc (loops, resolver tagów, render),
    // co inaczej oznaczałoby dziesiątki odczytów transientu na jednej stronie.
    // Inwalidowane razem z transientem w evk_groups_cache_clear().
    if (isset($GLOBALS['evk_rep_groups_memo']) && is_array($GLOBALS['evk_rep_groups_memo'])) {
        return $GLOBALS['evk_rep_groups_memo'];
    }

    $cached = get_transient('evk_rep_groups_cache');
    if (is_array($cached)) {
        $GLOBALS['evk_rep_groups_memo'] = $cached;
        return $cached;
    }

    $posts = get_posts([
        'post_type'      => 'evk_field_group',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    $schema = [];
    foreach ($posts as $post) {
        $key = get_post_meta($post->ID, '_evk_key', true);
        if (!$key) continue;

        $json   = get_post_meta($post->ID, '_evk_fields', true);
        $fields = $json ? (json_decode($json, true) ?? []) : [];
        $pts    = get_post_meta($post->ID, '_evk_post_types', true);

        $obj = get_post_meta($post->ID, '_evk_object_type', true);
        $tax = get_post_meta($post->ID, '_evk_taxonomies', true);

        $entry = [
            'label'       => $post->post_title,
            'object_type' => in_array($obj, ['post', 'term', 'user', 'media'], true) ? $obj : 'post',
            'post_types'  => is_array($pts) && $pts ? $pts : ['post'],
            'taxonomies'  => is_array($tax) ? array_values($tax) : [],
            'repeater'    => (bool) get_post_meta($post->ID, '_evk_repeater', true),
            'collapsed'   => (bool) get_post_meta($post->ID, '_evk_collapsed', true),
            'seamless'    => (bool) get_post_meta($post->ID, '_evk_seamless', true),
            'hide_title'  => (bool) get_post_meta($post->ID, '_evk_hide_title', true),
            'fields'      => $fields,
        ];
        $al = get_post_meta($post->ID, '_evk_add_label', true);
        if ($al) $entry['add_label'] = (string) $al;
        $tf = get_post_meta($post->ID, '_evk_title_field', true);
        if ($tf) $entry['title_field'] = (string) $tf;

        $schema[$key] = $entry;
    }

    set_transient('evk_rep_groups_cache', $schema);
    $GLOBALS['evk_rep_groups_memo'] = $schema;
    return $schema;
}

// =========================================================================
// INWALIDACJA CACHE
// =========================================================================

function evk_groups_cache_clear(): void {
    delete_transient('evk_rep_groups_cache');
    unset($GLOBALS['evk_rep_groups_memo'], $GLOBALS['evk_rep_loops_memo']);
}

add_action('save_post_evk_field_group', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    evk_groups_cache_clear();
}, 20);

add_action('trashed_post',   function ($id) { if (get_post_type($id) === 'evk_field_group') evk_groups_cache_clear(); });
add_action('untrashed_post', function ($id) { if (get_post_type($id) === 'evk_field_group') evk_groups_cache_clear(); });
add_action('deleted_post',   function ($id) { if (get_post_type($id) === 'evk_field_group') evk_groups_cache_clear(); });

// =========================================================================
// MIGRACJA z evk_rep_schema (jednorazowa)
// =========================================================================

add_action('admin_init', function () {
    if (get_option('evk_migration_done_v1')) return;

    $old = get_option('evk_rep_schema');
    if (!is_array($old) || empty($old)) {
        update_option('evk_migration_done_v1', 1);
        return;
    }

    // Sprawdź czy już istnieją posty CPT (nie migruj ponownie)
    $existing = get_posts(['post_type' => 'evk_field_group', 'numberposts' => 1, 'no_found_rows' => true]);
    if ($existing) {
        update_option('evk_migration_done_v1', 1);
        return;
    }

    $order    = 0;
    $migrated = 0;
    foreach ($old as $key => $group) {
        $post_id = wp_insert_post([
            'post_type'   => 'evk_field_group',
            'post_title'  => $group['label'] ?? $key,
            'post_status' => 'publish',
            'menu_order'  => $order++,
        ], true);

        if (is_wp_error($post_id)) continue;
        $migrated++;

        update_post_meta($post_id, '_evk_key',        $key);
        update_post_meta($post_id, '_evk_fields',     wp_json_encode($group['fields'] ?? []));
        update_post_meta($post_id, '_evk_post_types', $group['post_types'] ?? ['post']);
        update_post_meta($post_id, '_evk_repeater',   !empty($group['repeater'])  ? 1 : 0);
        update_post_meta($post_id, '_evk_collapsed',  !empty($group['collapsed']) ? 1 : 0);
        update_post_meta($post_id, '_evk_seamless',   !empty($group['seamless'])  ? 1 : 0);
        if (!empty($group['add_label']))   update_post_meta($post_id, '_evk_add_label',   $group['add_label']);
        if (!empty($group['title_field'])) update_post_meta($post_id, '_evk_title_field', $group['title_field']);
    }

    evk_groups_cache_clear();
    update_option('evk_migration_done_v1', 1);
    add_action('admin_notices', function () use ($migrated) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Evoke FIELDS:</strong> Zmigrowano grupy pól z poprzedniej wersji (' . (int) $migrated . ' grup).</p></div>';
    });
});

// =========================================================================
// MENU — usuń auto-duplikat "Evoke FIELDS"
// =========================================================================

add_action('admin_menu', function () {
    // WP automatycznie tworzy pierwszy podmenu z tym samym slugiem co menu główne.
    // Callback naszego menu głównego robi redirect → ta pozycja wygląda na pustą.
    // Usuwamy ją po tym jak wszystkie add_*_page się wykonają.
    remove_submenu_page('evk-repeater', 'evk-repeater');
}, 999);
