<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — lokalizacje inne niż wpisy: termy taksonomii i profil użytkownika.
 *
 * Render i zapis korzystają ze wspólnych helperów z metabox.php:
 * evk_rep_render_group_object() / evk_rep_save_group_object() (typ meta: term / user).
 */

// =========================================================================
// FILTRY GRUP wg lokalizacji
// =========================================================================

function evk_rep_groups_for_object(string $object_type): array {
    $out = [];
    foreach (evk_rep_groups() as $key => $group) {
        if (($group['object_type'] ?? 'post') === $object_type) $out[$key] = $group;
    }
    return $out;
}

function evk_rep_groups_for_taxonomy(string $taxonomy): array {
    $out = [];
    foreach (evk_rep_groups_for_object('term') as $key => $group) {
        if (in_array($taxonomy, (array) ($group['taxonomies'] ?? []), true)) $out[$key] = $group;
    }
    return $out;
}

// =========================================================================
// ENQUEUE — te same assety co metabox wpisu (admin.js + admin.css + media + editor)
// =========================================================================

add_action('admin_enqueue_scripts', function ($hook) {
    $needs = false;
    if (in_array($hook, ['profile.php', 'user-edit.php'], true)) {
        $needs = (bool) evk_rep_groups_for_object('user');
    } elseif (in_array($hook, ['edit-tags.php', 'term.php'], true)) {
        $tax   = isset($_GET['taxonomy']) ? sanitize_key($_GET['taxonomy']) : '';
        $needs = $tax !== '' && (bool) evk_rep_groups_for_taxonomy($tax);
    }
    if (!$needs) return;

    wp_enqueue_media();
    wp_enqueue_editor();
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('evk-rep-admin', EVK_REP_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'wp-editor'], EVK_REP_VERSION, true);
    wp_enqueue_style('evk-rep-admin', EVK_REP_URL . 'assets/admin.css', [], EVK_REP_VERSION);
    evk_rep_admin_localize();
});

// =========================================================================
// TERMY TAKSONOMII
// =========================================================================

add_action('init', function () {
    foreach (evk_rep_groups_for_object('term') as $group) {
        foreach ((array) ($group['taxonomies'] ?? []) as $tax) {
            $tax = (string) $tax;
            if ($tax === '') continue;
            // add_action dedupuje po nazwie funkcji, więc wielokrotna rejestracja
            // tej samej taksonomii nie tworzy duplikatów.
            add_action($tax . '_add_form_fields',  'evk_rep_term_add_fields',  10, 1);
            add_action($tax . '_edit_form_fields', 'evk_rep_term_edit_fields', 10, 2);
            add_action('created_' . $tax, 'evk_rep_term_save', 10, 1);
            add_action('edited_'  . $tax, 'evk_rep_term_save', 10, 1);
        }
    }
}, 20);

function evk_rep_term_add_fields($taxonomy): void {
    $groups = evk_rep_groups_for_taxonomy((string) $taxonomy);
    if (!$groups) return;
    wp_nonce_field('evk_rep_term_save', 'evk_rep_term_nonce');
    foreach ($groups as $key => $group) {
        echo '<div class="form-field evk-term-fields">';
        echo '<h3 class="evk-loc-group-title">' . esc_html($group['label'] ?? $key) . '</h3>';
        evk_rep_render_group_object('term', 0, (string) $key, $group);
        echo '</div>';
    }
}

function evk_rep_term_edit_fields($term, $taxonomy): void {
    $groups = evk_rep_groups_for_taxonomy((string) $taxonomy);
    if (!$groups) return;
    wp_nonce_field('evk_rep_term_save', 'evk_rep_term_nonce');
    foreach ($groups as $key => $group) {
        echo '<tr class="form-field evk-term-fields"><th scope="row"><label>' . esc_html($group['label'] ?? $key) . '</label></th><td>';
        evk_rep_render_group_object('term', (int) $term->term_id, (string) $key, $group);
        echo '</td></tr>';
    }
}

function evk_rep_term_save($term_id): void {
    if (!isset($_POST['evk_rep_term_nonce']) || !wp_verify_nonce($_POST['evk_rep_term_nonce'], 'evk_rep_term_save')) return;
    if (!current_user_can('edit_term', $term_id)) return;
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) return;
    foreach (evk_rep_groups_for_taxonomy($term->taxonomy) as $key => $group) {
        evk_rep_save_group_object('term', (int) $term_id, (string) $key, $group);
    }
}

// =========================================================================
// PROFIL UŻYTKOWNIKA
// =========================================================================

add_action('show_user_profile', 'evk_rep_user_fields');
add_action('edit_user_profile', 'evk_rep_user_fields');
add_action('personal_options_update',  'evk_rep_user_save');
add_action('edit_user_profile_update', 'evk_rep_user_save');

function evk_rep_user_fields($user): void {
    $groups = evk_rep_groups_for_object('user');
    if (!$groups) return;
    wp_nonce_field('evk_rep_user_save', 'evk_rep_user_nonce');
    foreach ($groups as $key => $group) {
        echo '<div class="evk-user-fields">';
        echo '<h2 class="evk-loc-group-title">' . esc_html($group['label'] ?? $key) . '</h2>';
        evk_rep_render_group_object('user', (int) $user->ID, (string) $key, $group);
        echo '</div>';
    }
}

function evk_rep_user_save($user_id): void {
    if (!isset($_POST['evk_rep_user_nonce']) || !wp_verify_nonce($_POST['evk_rep_user_nonce'], 'evk_rep_user_save')) return;
    if (!current_user_can('edit_user', $user_id)) return;
    foreach (evk_rep_groups_for_object('user') as $key => $group) {
        evk_rep_save_group_object('user', (int) $user_id, (string) $key, $group);
    }
}
