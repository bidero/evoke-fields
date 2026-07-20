<?php
/**
 * Evoke FIELDS — sprzątanie przy ODINSTALOWANIU (nie przy deaktywacji).
 *
 * Usuwa KONFIGURACJĘ wtyczki: grupy pól (CPT evk_field_group), definicje
 * CPT/taksonomii, strony ustawień i ich wartości (evk_rep_opt_*), flagi,
 * transienty. Świadomie NIE usuwa danych treści: meta wpisów/termów/
 * użytkowników z wartościami pól ma czyste klucze (bez prefiksu) i może być
 * dalej używana przez motyw/inny kod; załączniki (w tym podglądy PDF) to media
 * użytkownika.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// 1. Grupy pól — posty CPT + ich meta (_evk_*).
$group_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'evk_field_group'");
foreach ((array) $group_ids as $gid) {
    wp_delete_post((int) $gid, true);
}

// 2. Opcje konfiguracyjne (stałe nazwy).
$options = [
    'evk_rep_settings_pages',
    'evk_custom_post_types',
    'evk_taxonomies',
    'evk_rep_schema',              // relikt sprzed migracji na CPT
    'evk_migration_done_v1',
    'evk_opt_autoload_off_done',
    'evk_rep_flush_rewrite',
    'evk_tools_recalc_progress',
    'evk_rep_style_tokens',
];

// Katalogi robocze w uploads (kopie konfiguracji + pliki importu CSV) — pliki + folder.
$up = wp_get_upload_dir();
foreach ([['evk_backups_dir', 'evk-backups-'], ['evk_csv_dir', 'evk-imports-']] as $pair) {
    list($opt_name, $prefix) = $pair;
    $sub = get_option($opt_name);
    if (is_string($sub) && strpos($sub, $prefix) === 0 && empty($up['error']) && !empty($up['basedir'])) {
        $dir = trailingslashit($up['basedir']) . $sub;
        foreach ((array) glob(trailingslashit($dir) . '*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }
    $options[] = $opt_name;
}

foreach ($options as $opt) {
    delete_option($opt);
}

// 3. Wartości stron ustawień (evk_rep_opt_*) — bez wtyczki nieodczytywalne.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'evk\\_rep\\_opt\\_%'");

// 4. Transienty wtyczki (cache schematu, notice narzędzi, guardy podglądu PDF).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_evk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_evk\\_%'");

// 5. Rewrite rules po zniknięciu CPT/taksonomii odświeżą się przy następnym zapisie
//    permalinków; wymuszamy od razu, żeby nie zostały reguły-widma.
flush_rewrite_rules();
