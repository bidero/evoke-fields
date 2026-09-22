<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — typy treści i taksonomie „poza indeksem".
 *
 * PO CO TO ISTNIEJE. Typ treści zrobiony pod slider, menu albo listę
 * referencji nie ma być stroną: jego wpisy renderują się wewnątrz innych
 * stron. Mimo to WordPress traktuje każdy publiczny typ jak pełnoprawną
 * treść — wpuszcza go do `wp-sitemap.xml`, do wyników wyszukiwania i do
 * indeksu Google. Slajdy pod adresem `/slajd/tlo-hero/` to nie jest usterka
 * wyglądu, tylko śmieci w indeksie serwisu.
 *
 * DLACZEGO FLAGA SIEDZI TUTAJ, A NIE TYLKO W EVOKE ONE. Ekran „Typy treści"
 * jest jedynym miejscem, w którym widać, PO CO dany typ powstał — i jedynym,
 * przez które przechodzi każdy nowy typ. Panel Evoke ONE pokazuje to samo
 * ustawienie dla typów z dowolnej wtyczki, ale flagę z FIELDS pokazuje jako
 * zablokowaną: odznaczenie jej tam wyglądałoby na skuteczne, a wracałoby przy
 * pierwszym zapisie tego ekranu.
 *
 * CZYM TO SIĘ RÓŻNI OD „CHRONIONEGO" (`protect.php`). Typ chroniony to zamek:
 * 404 bez klucza, poza REST-em, poza pętlami gościa. Ten przełącznik niczego
 * nie zamyka — wpis dalej renderuje się wszędzie tam, gdzie go wstawiono,
 * a znika wyłącznie z mapy strony, wyszukiwarki i indeksu.
 *
 * FIELDS NIE WYMAGA EVOKE ONE. Wyprowadzenie z mapy i z wyszukiwarki robi ten
 * plik sam. Meta tag `noindex` drukuje tylko wtedy, gdy nie ma modułu SEO
 * Evoke ONE — tamten renderuje komplet meta tagów z jednego resolvera
 * (`evk_seo_get_meta()`) i drugi `<meta name="robots">` obok byłby dubletem,
 * którego wyszukiwarki nie mają jak rozstrzygnąć.
 */

// =========================================================================
// ŹRÓDŁO PRAWDY — CO JEST OZNACZONE
// =========================================================================

/** Slugi typów treści EVK oznaczonych jako „poza indeksem". */
function evk_noindex_post_types(): array {
    $out = [];
    foreach ((array) get_option('evk_custom_post_types', array()) as $pt) {
        if (empty($pt['noindex'])) continue;
        $slug = substr((string) ($pt['slug'] ?? ''), 0, 20);
        if ($slug !== '') $out[] = $slug;
    }
    return array_values(array_unique($out));
}

/** Slugi taksonomii EVK oznaczonych jako „poza indeksem". */
function evk_noindex_taxonomies(): array {
    $out = [];
    foreach ((array) get_option('evk_taxonomies', array()) as $tax) {
        if (empty($tax['noindex'])) continue;
        $slug = substr((string) ($tax['slug'] ?? ''), 0, 32);
        if ($slug !== '') $out[] = $slug;
    }
    return array_values(array_unique($out));
}

// =========================================================================
// POZA MAPĄ STRONY
// =========================================================================

add_filter('wp_sitemaps_post_types', function ($types) {
    foreach (evk_noindex_post_types() as $slug) unset($types[$slug]);
    return $types;
});

add_filter('wp_sitemaps_taxonomies', function ($taxonomies) {
    foreach (evk_noindex_taxonomies() as $slug) unset($taxonomies[$slug]);
    return $taxonomies;
});

// =========================================================================
// POZA INDEKSEM — META TAG (tylko bez modułu SEO Evoke ONE)
// =========================================================================

add_action('wp_head', function () {
    if (function_exists('evk_seo_get_meta')) return; // Evoke ONE drukuje robots sam

    $poza = false;

    if (is_singular()) {
        $poza = in_array((string) get_post_type(), evk_noindex_post_types(), true);
    } elseif (is_post_type_archive()) {
        $typ  = get_query_var('post_type');
        $typ  = is_array($typ) ? (string) reset($typ) : (string) $typ;
        $poza = $typ !== '' && in_array($typ, evk_noindex_post_types(), true);
    } elseif (is_tax()) {
        $term = get_queried_object();
        $poza = $term instanceof WP_Term && in_array($term->taxonomy, evk_noindex_taxonomies(), true);
    }

    if ($poza) echo '<meta name="robots" content="noindex, follow">' . "\n";
}, 5);
