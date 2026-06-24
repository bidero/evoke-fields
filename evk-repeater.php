<?php
/**
 * Plugin Name: Evoke FIELDS
 * Description: System własnych pól do Bricks Builder — repeater, pola pojedyncze, zakładki, akordeony, query loop, Settings Pages, taksonomie.
 * Version: 1.25.1
 * Author: Evoke Design Studio
 * Text Domain: evk-repeater
 */

if (!defined('ABSPATH')) exit;

define('EVK_REP_VERSION', '1.25.1');
define('EVK_REP_URL', plugin_dir_url(__FILE__));
define('EVK_REP_PATH', plugin_dir_path(__FILE__));

/**
 * Stała klasa <body> dla WSZYSTKICH ekranów wtyczki.
 *
 * Cała wspólna warstwa CSS (evk-admin.css) jest scope'owana do `.evk-admin`,
 * dzięki czemu style nie zależą już od hook suffixu (ten pochodzi od
 * sanitize_title() tytułu menu i potrafi się zmienić). Wywoływane z każdego
 * miejsca, które ładuje evk-admin.css. Idempotentne w obrębie żądania.
 */
function evk_rep_mark_admin_body(): void {
    static $added = false;
    if ($added) return;
    $added = true;
    add_filter('admin_body_class', function ($classes) {
        return rtrim((string) $classes) . ' evk-admin';
    });
}

// Globalne style na stronach pluginu
add_action('admin_enqueue_scripts', function ($hook) {
    // Uwaga: hook suffix podmenu pochodzi od sanitize_title() tytułu menu
    // nadrzędnego ('Evoke FIELDS' → 'evoke-fields'), a NIE od jego slug ('evk-repeater').
    $evk_pages = [
        'toplevel_page_evk-repeater',
        'evoke-fields_page_evk-cpt',
        'evoke-fields_page_evk-tax',
        'evoke-fields_page_evk-settings',
        'evoke-fields_page_evk-tools',
    ];
    if (in_array($hook, $evk_pages, true)) {
        wp_enqueue_style('evk-admin', EVK_REP_URL . 'assets/evk-admin.css', [], EVK_REP_VERSION);
        evk_rep_mark_admin_body();
    }

    // Drag-sort wierszy na listach CPT / taksonomii (uchwyt zamiast przycisków ↑/↓).
    if (in_array($hook, ['evoke-fields_page_evk-cpt', 'evoke-fields_page_evk-tax'], true)) {
        wp_enqueue_script('jquery-ui-sortable');
    }
}, 5);

// Skrypty metaboxu na ekranie edycji wpisu (dane — nie grupy pól)
add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'evk_field_group') return; // obsługuje field-groups.php
    wp_enqueue_media();
    wp_enqueue_editor();
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('evk-rep-admin', EVK_REP_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'wp-editor'], EVK_REP_VERSION, true);
    wp_enqueue_style('evk-rep-admin', EVK_REP_URL . 'assets/admin.css', [], EVK_REP_VERSION);
    evk_rep_admin_localize();
});

// Kolejność ładowania jest ważna:
// field-groups.php definiuje evk_rep_groups() — musi być przed metabox.php i bricks.php
require_once EVK_REP_PATH . 'includes/dashicon-picker.php'; // współdzielony picker — przed cpt.php i settings.php
require_once EVK_REP_PATH . 'includes/field-groups.php';
require_once EVK_REP_PATH . 'includes/metabox.php';
require_once EVK_REP_PATH . 'includes/locations.php'; // termy + profil użytkownika (po metabox.php — używa jego helperów)
require_once EVK_REP_PATH . 'includes/bricks.php';
require_once EVK_REP_PATH . 'includes/builder.php';
require_once EVK_REP_PATH . 'includes/cpt.php';
require_once EVK_REP_PATH . 'includes/taxonomies.php';
require_once EVK_REP_PATH . 'includes/settings.php';
require_once EVK_REP_PATH . 'includes/tools.php';
require_once EVK_REP_PATH . 'includes/admin-columns.php';
