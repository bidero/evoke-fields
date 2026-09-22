<?php
/**
 * Plugin Name: Evoke FIELDS
 * Description: System własnych pól do Bricks Builder — repeater, pola pojedyncze, zakładki, akordeony, query loop, Settings Pages, taksonomie.
 * Version: 1.69.0
 * Author: Evoke Design Studio
 * Text Domain: evk-repeater
 */

if (!defined('ABSPATH')) exit;

define('EVK_REP_VERSION', '1.69.0');
define('EVK_REP_FILE', __FILE__);
define('EVK_REP_URL', plugin_dir_url(__FILE__));
define('EVK_REP_PATH', plugin_dir_path(__FILE__));

/**
 * Uprawnienie dające dostęp do panelu Evoke FIELDS.
 *
 * Nadaje je Role Manager w Evoke ONE, ale FIELDS to osobna wtyczka i to TUTAJ
 * musi zapaść sprawdzenie — samo zaznaczenie pola w Evoke ONE nic nie zmieni,
 * dopóki wszystkie bramki (menu, zapisy, AJAX) nie pytają o to uprawnienie.
 *
 * FIELDS nie zakłada obecności Evoke ONE: gdy tamtej wtyczki nie ma, nikt nie
 * ma tego uprawnienia w bazie — dlatego mostek niżej nadaje je każdemu, kto ma
 * `manage_options`. Administrator wchodzi zawsze, także bez Evoke ONE.
 */
define('EVK_REP_CAP', 'evk_access_fields');

/**
 * Czy bieżący użytkownik może zarządzać wtyczką (panel + zapisy + AJAX).
 * Jedna bramka dla wszystkich ekranów — używaj JEJ, nie gołego 'manage_options'.
 */
function evk_rep_can_manage(): bool {
    return current_user_can('manage_options') || current_user_can(EVK_REP_CAP);
}

/**
 * Prymitywne uprawnienia CPT „Grupy pól" (własny zestaw, nie miesza się z wpisami).
 * Dzięki nim dostęp do definicji pól da się nadać BEZ nadawania praw do treści.
 */
function evk_rep_group_caps(): array {
    return [
        'edit_evk_field_groups',
        'edit_others_evk_field_groups',
        'edit_private_evk_field_groups',
        'edit_published_evk_field_groups',
        'publish_evk_field_groups',
        'read_private_evk_field_groups',
        'delete_evk_field_groups',
        'delete_others_evk_field_groups',
        'delete_private_evk_field_groups',
        'delete_published_evk_field_groups',
        'create_evk_field_groups',
    ];
}

/**
 * Mostek uprawnień (dynamiczny — nic nie zapisuje do ról w bazie):
 *  1. manage_options ⇒ EVK_REP_CAP — administrator ma dostęp zawsze, również
 *     gdy Evoke ONE nie jest zainstalowane i nikt nie nadał uprawnienia.
 *  2. EVK_REP_CAP ⇒ uprawnienia CPT „Grupy pól" — bez tego użytkownik z samym
 *     `evk_access_fields` zobaczyłby menu, ale ekran grup odbiłby go „brakiem
 *     uprawnień" (CPT chodzi po capach wpisów, nie po manage_options).
 */
add_filter('user_has_cap', function ($allcaps) {
    $via_admin = !empty($allcaps['manage_options']);
    if (!$via_admin && empty($allcaps[EVK_REP_CAP])) return $allcaps;

    // Filtr chodzi przy KAŻDYM current_user_can() — listę budujemy raz na request.
    static $group_caps = null;
    if ($group_caps === null) $group_caps = evk_rep_group_caps();

    if ($via_admin) $allcaps[EVK_REP_CAP] = true;
    foreach ($group_caps as $cap) $allcaps[$cap] = true;

    return $allcaps;
});

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
        'evoke-fields_page_evk-import',
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

/**
 * OCHRONA PRZED CICHĄ UTRATĄ DEFINICJI PRZY ZAPISIE EKRANU.
 *
 * Ekrany „Typy treści" i „Taksonomie" zapisują CAŁĄ listę naraz: to, co przyszło
 * w POST, zastępuje zawartość opcji. Taki zapis jest bezpieczny tylko wtedy, gdy
 * POST na pewno dotarł w całości — a nie dociera w dwóch sytuacjach:
 *
 *  1. PHP przekroczył `max_input_vars` (domyślnie 1000). Wtedy NIE zgłasza błędu do
 *     aplikacji — po prostu przestaje parsować dalsze zmienne. Nonce siedzi na
 *     początku formularza, więc walidacja przechodzi, a handler zapisuje ogryzek
 *     listy jako komplet. Definicje z końca listy znikają bez śladu. Każde pole
 *     dokładane do wiersza (np. checkbox „poza indeksem" w 1.67.0) zbliża duże
 *     instalacje do tego limitu.
 *  2. POST urwał się po drodze (proxy, limit `post_max_size`, wtyczka bezpieczeństwa).
 *
 * Stąd dwa zabezpieczenia. `evk_rep_post_truncated()` porównuje liczbę zmiennych
 * z limitem PHP. `evk_rep_form_complete()` sprawdza znacznik doklejany na SAMYM KOŃCU
 * formularza — jeśli go nie ma, POST urwał się przed końcem, cokolwiek było przyczyną.
 * Brak pewności = nie zapisujemy. Lepszy komunikat „nie zapisano" niż pusta lista typów.
 */
function evk_rep_count_input_vars($data): int {
    $n = 0;
    foreach ((array) $data as $v) {
        $n += is_array($v) ? evk_rep_count_input_vars($v) : 1;
    }
    return $n;
}

/** Czy $_POST dobił do limitu max_input_vars (czyli najpewniej został obcięty). */
function evk_rep_post_truncated(): bool {
    $max = (int) ini_get('max_input_vars');
    if ($max <= 0) return false;
    return evk_rep_count_input_vars($_POST) >= $max;
}

/** Znacznik końca formularza — drukowany tuż przed </form>. */
function evk_rep_form_end_marker(string $name): void {
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="1">';
}

/** Czy formularz dotarł w całości (znacznik końcowy obecny i POST nieobcięty). */
function evk_rep_form_complete(string $name): bool {
    return !empty($_POST[$name]) && !evk_rep_post_truncated();
}

/**
 * Ostrzeżenie PRZED zapisem, gdy formularz zbliża się do limitu max_input_vars.
 *
 * Wykrycie obcięcia po fakcie ratuje dane, ale nie pozwala zapisać zmian — a człowiek
 * i tak musi dowiedzieć się, co podkręcić na hostingu. Dlatego ekrany, które rosną
 * wraz z konfiguracją, szacują swój rozmiar i mówią o tym, zanim zrobi się problem.
 *
 * @param int $estimate Szacowana liczba pól formularza na tym ekranie.
 */
function evk_rep_input_vars_warning(int $estimate): void {
    $max = (int) ini_get('max_input_vars');
    if ($max <= 0 || $estimate < (int) ($max * 0.7)) return;

    echo '<div class="notice notice-warning"><p><strong>'
        . esc_html__( 'Ten ekran zbliża się do limitu PHP.', 'evk-repeater' ) . '</strong> '
        . sprintf(
            /* translators: 1: szacowana liczba pól, 2: limit max_input_vars */
            esc_html__( 'Formularz ma około %1$d pól przy limicie max_input_vars = %2$d. Po przekroczeniu limitu PHP ucina dane bez ostrzeżenia. Wtyczka wykryje obcięcie i odmówi zapisu (nic nie zginie), ale zapisanie zmian będzie niemożliwe do czasu zwiększenia limitu — poproś hosting o ustawienie max_input_vars na co najmniej %3$d.', 'evk-repeater' ),
            $estimate,
            $max,
            max( 5000, (int) ( $estimate * 2 ) )
        )
        . '</p></div>';
}

/** Komunikat dla urwanego POST-a — jeden tekst dla obu ekranów. */
function evk_rep_truncated_notice(): void {
    $max = (int) ini_get('max_input_vars');
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__( 'Nie zapisano — formularz dotarł niekompletny.', 'evk-repeater' ) . '</strong> '
        . esc_html__( 'Dotychczasowe definicje zostały nienaruszone. Najczęstsza przyczyna to limit PHP max_input_vars', 'evk-repeater' )
        . ( $max > 0 ? ' (' . esc_html__( 'obecnie', 'evk-repeater' ) . ': <code>' . (int) $max . '</code>)' : '' )
        . esc_html__( ' — formularz z wieloma typami treści przekracza go i PHP ucina resztę danych bez ostrzeżenia. Zwiększ limit (np. do 5000) w php.ini albo poproś o to hosting, a następnie zapisz ponownie.', 'evk-repeater' )
        . '</p></div>';
}

/**
 * Odświeżenie permalinków po zmianie definicji CPT / taksonomii.
 * register_post_type/register_taxonomy nie flushują rewrite rules — bez tego nowy
 * CPT daje 404 na froncie do ręcznego wejścia w Ustawienia → Bezpośrednie odnośniki.
 * Zapis definicji ustawia flagę; flush robimy RAZ, na następnym init, PO rejestracjach
 * (priorytet 99 > domyślnego 10 hooków rejestrujących).
 */
function evk_rep_schedule_rewrite_flush(): void {
    update_option('evk_rep_flush_rewrite', 1, false);
}
add_action('init', function () {
    if (!get_option('evk_rep_flush_rewrite')) return;
    delete_option('evk_rep_flush_rewrite');
    flush_rewrite_rules();
}, 99);

// Kolejność ładowania jest ważna:
// field-groups.php definiuje evk_rep_groups() — musi być przed metabox.php i bricks.php
require_once EVK_REP_PATH . 'includes/dashicon-picker.php'; // współdzielony picker — przed cpt.php i settings.php
require_once EVK_REP_PATH . 'includes/field-groups.php';
require_once EVK_REP_PATH . 'includes/metabox.php';
require_once EVK_REP_PATH . 'includes/bidirectional.php'; // sync pól dwukierunkowych (po metabox.php)
require_once EVK_REP_PATH . 'includes/locations.php'; // termy + profil użytkownika (po metabox.php — używa jego helperów)
require_once EVK_REP_PATH . 'includes/bricks.php';
require_once EVK_REP_PATH . 'includes/pdf-preview.php'; // podglądy JPG dla PDF (pole „plik")
require_once EVK_REP_PATH . 'includes/calc.php'; // pole obliczeniowe — silnik formuł + evk_rep_recalc() (po bricks.php: używa evk_rep_is_layout/is_repeater)
require_once EVK_REP_PATH . 'includes/api.php'; // publiczne API: evk_get_field(), evk_rows(), evk_get_option_field()
require_once EVK_REP_PATH . 'includes/builder.php';
require_once EVK_REP_PATH . 'includes/cpt.php';
require_once EVK_REP_PATH . 'includes/protect.php'; // pola wrażliwe + typy chronione (po cpt.php — używa evk_cpt_config)
require_once EVK_REP_PATH . 'includes/taxonomies.php';
require_once EVK_REP_PATH . 'includes/noindex.php'; // typy/taksonomie poza mapą strony i indeksem (po cpt.php i taxonomies.php — czyta ich opcje)
require_once EVK_REP_PATH . 'includes/settings.php';
require_once EVK_REP_PATH . 'includes/tools.php';
require_once EVK_REP_PATH . 'includes/backups.php'; // auto-kopie konfiguracji (po tools.php — używa jego eksportu/importu)
require_once EVK_REP_PATH . 'includes/vault.php'; // sejf konfiguracji w bazie (po backups.php i tools.php — używa ich helperów)
require_once EVK_REP_PATH . 'includes/import-csv.php'; // import CSV z mapowaniem (po backups.php — używa evk_backups_ensure_protection)
require_once EVK_REP_PATH . 'includes/admin-columns.php';
require_once EVK_REP_PATH . 'includes/github-updater.php'; // aktualizacje z GitHub (port z Evoke ONE 1.19.4)
