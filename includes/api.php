<?php
if (!defined('ABSPATH')) exit;

/**
 * Publiczne API PHP — do użycia w motywach i własnym kodzie, niezależnie od Bricks.
 * Nazwy globalne krótkie (evk_*), więc każda funkcja za guardem function_exists.
 */

if (!function_exists('evk_get_field')) {
    /**
     * Wartość pola EVK dla wpisu — sformatowana tak samo jak tag dynamiczny
     * {evk_field_klucz} / {evk_field_klucz__prop}.
     *
     * @param string $key     Klucz pola (grupy pojedynczej).
     * @param int    $post_id Wpis (0 = bieżący w pętli).
     * @param string $prop    Wariant jak w tagach: '' (domyślny), 'id', 'label',
     *                        'filename', 'preview', nazwa rozmiaru obrazka itd.
     * @return mixed Wartość (string/int) lub '' gdy brak.
     */
    function evk_get_field(string $key, int $post_id = 0, string $prop = '') {
        $post_id = $post_id ?: (int) get_the_ID();
        return evk_rep_resolve($key, $prop, $post_id);
    }
}

if (!function_exists('evk_rows')) {
    /**
     * Wiersze repeatera dla wpisu — grupa-repeater (klucz grupy) lub pole-repeater
     * (klucz pola). Subpola calc mają w wierszach już policzone wartości.
     *
     * @return array[] Lista wierszy (tablice asocjacyjne klucz-subpola => wartość).
     */
    function evk_rows(string $key, int $post_id = 0): array {
        $post_id = $post_id ?: (int) get_the_ID();
        $rows = get_post_meta($post_id, $key, true);
        return is_array($rows) ? array_values($rows) : [];
    }
}

if (!function_exists('evk_get_option_field')) {
    /**
     * Wartość pola ze strony ustawień (options) — odpowiednik {evk_opt_grupa_klucz}.
     * Cienka nakładka na evk_rep_get_option() z settings.php (spójna rodzina nazw).
     */
    function evk_get_option_field(string $group_key, string $field_key = '', $default = '') {
        return evk_rep_get_option($group_key, $field_key, $default);
    }
}
