<?php
/**
 * Evoke FIELDS — sprzątanie przy ODINSTALOWANIU (nie przy deaktywacji).
 *
 * DOMYŚLNIE NIE USUWA NICZEGO POZA ŚMIECIAMI (flagi, transienty, przerwane przebiegi).
 *
 * Dlaczego: „aktualizacja ręczna" wygląda u większości ludzi tak — wyłącz wtyczkę,
 * usuń ją, wgraj nowy ZIP. Krok „usuń" uruchamia TEN plik. Wersja, która bezwarunkowo
 * kasowała grupy pól, definicje CPT/taksonomii i strony ustawień, zamieniała rutynową
 * aktualizację w utratę całej konfiguracji — a ponieważ kasowała też katalog kopii
 * zapasowych, znikała razem z nią jedyna droga powrotu.
 *
 * Skasowanie konfiguracji trzeba więc włączyć świadomie: Narzędzia → Kopie zapasowe →
 * „Usuń konfigurację przy odinstalowaniu wtyczki". Nawet wtedy KATALOG KOPII ZOSTAJE.
 * Kopia, którą usuwa to samo kliknięcie co dane, nie jest kopią zapasową.
 *
 * Danych treści (meta wpisów/termów/użytkowników z wartościami pól) nie rusza nigdy:
 * mają czyste klucze, bez prefiksu, i może z nich korzystać motyw albo inny kod;
 * załączniki (w tym podglądy PDF) to media użytkownika.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Śmieci bez wartości informacyjnej — lecą zawsze, niezależnie od przełącznika.
delete_option('evk_rep_flush_rewrite');
delete_option('evk_tools_recalc_progress');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_evk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_evk\\_%'");

// Konfiguracja zostaje, dopóki ktoś wprost nie poprosi o jej skasowanie.
if (!get_option('evk_rep_delete_data_on_uninstall')) {
    return;
}

// Ostatnia kopia przed skasowaniem — plik zostaje w uploads i po ponownej instalacji
// odnajduje go evk_backups_adopt_existing_dir(). To jedyna droga powrotu z tego miejsca.
//
// Moduły wtyczki ładujemy tutaj RĘCZNIE: uninstall.php biegnie bez evk-repeater.php,
// więc stałe, których te pliki używają w czasie ładowania, trzeba podstawić samemu
// (brak stałej = fatal w PHP 8, czyli uninstall przerwany w połowie). Flaga
// EVK_UNINSTALLING wycisza wyzwalacze kopii, żeby kasowanie grup poniżej nie
// produkowało zrzutów stanu „w trakcie usuwania".
define('EVK_UNINSTALLING', true);

if (!defined('EVK_REP_FILE'))    define('EVK_REP_FILE', __DIR__ . '/evk-repeater.php');
if (!defined('EVK_REP_PATH'))    define('EVK_REP_PATH', trailingslashit(__DIR__));
if (!defined('EVK_REP_URL'))     define('EVK_REP_URL', plugin_dir_url(EVK_REP_FILE));
if (!defined('EVK_REP_CAP'))     define('EVK_REP_CAP', 'evk_access_fields');
if (!defined('EVK_REP_VERSION')) define('EVK_REP_VERSION', 'uninstall');

$evk_backups = __DIR__ . '/includes/backups.php';
$evk_tools   = __DIR__ . '/includes/tools.php';
if (is_readable($evk_backups) && is_readable($evk_tools)) {
    require_once $evk_tools;
    require_once $evk_backups;
    if (function_exists('evk_backups_write_now')) {
        evk_backups_write_now('uninstall');
    }
}

// 1. Grupy pól — posty CPT + ich meta (_evk_*).
$group_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'evk_field_group'");
foreach ((array) $group_ids as $gid) {
    wp_delete_post((int) $gid, true);
}

// 2. Opcje konfiguracyjne (stałe nazwy).
//    UWAGA: 'evk_backups_dir' NIE jest tu wymienione — bez tej opcji katalog kopii
//    staje się nieodnajdywalny z panelu, a to przekreśla sens trzymania kopii.
$options = [
    'evk_rep_settings_pages',
    'evk_custom_post_types',
    'evk_taxonomies',
    'evk_rep_schema',              // relikt sprzed migracji na CPT
    'evk_migration_done_v1',
    'evk_opt_autoload_off_done',
    'evk_rep_style_tokens',
    'evk_rep_delete_data_on_uninstall',
];

// Katalog roboczy importu CSV (pliki tymczasowe, nie kopie) — pliki + folder.
$up  = wp_get_upload_dir();
$sub = get_option('evk_csv_dir');
if (is_string($sub) && strpos($sub, 'evk-imports-') === 0 && empty($up['error']) && !empty($up['basedir'])) {
    $dir = trailingslashit($up['basedir']) . $sub;
    foreach ((array) glob(trailingslashit($dir) . '*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}
$options[] = 'evk_csv_dir';

foreach ($options as $opt) {
    delete_option($opt);
}

// 3. Wartości stron ustawień (evk_rep_opt_*) — bez wtyczki nieodczytywalne.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'evk\\_rep\\_opt\\_%'");

// 4. Rewrite rules po zniknięciu CPT/taksonomii odświeżą się przy następnym zapisie
//    permalinków; wymuszamy od razu, żeby nie zostały reguły-widma.
flush_rewrite_rules();
