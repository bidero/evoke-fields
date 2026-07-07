<?php
if (!defined('ABSPATH')) exit;

/**
 * Podglądy JPG dla PDF-ów — dla hostingów, na których natywna generacja podglądów
 * PDF w WordPressie nie działa (współdzielone, brak dostępu do policy.xml / restartu PHP).
 *
 * Po wgraniu PDF tworzymy OSOBNY załącznik JPG (Imagick, 1. strona) i wiążemy go z PDF-em
 * przez meta `_evk_auto_pdf_thumb_id`. Tag {..__preview} pola „plik" oddaje URL/ID tego JPG.
 * Wymaga wyłącznie działającego Imagick z obsługą PDF (Ghostscript) — bez zmian w WP core.
 */

// Po wygenerowaniu metadanych wgranego PDF — utwórz towarzyszący JPG.
add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    if (get_post_mime_type($attachment_id) === 'application/pdf') {
        evk_rep_generate_pdf_companion_jpg((int) $attachment_id);
    }
    return $metadata;
}, 10, 2);

// Sprzątanie: usuń towarzyszący JPG przy kasowaniu PDF-a.
add_action('delete_attachment', function ($attachment_id) {
    $thumb = (int) get_post_meta($attachment_id, '_evk_auto_pdf_thumb_id', true);
    if ($thumb > 0) wp_delete_attachment($thumb, true);
});

/**
 * Tworzy (lub zwraca istniejący) załącznik JPG będący podglądem 1. strony PDF.
 * Idempotentne: jeśli podgląd już istnieje, nic nie generuje. Zwraca ID JPG albo 0.
 */
function evk_rep_generate_pdf_companion_jpg(int $pdf_id): int {
    if ($pdf_id <= 0 || get_post_mime_type($pdf_id) !== 'application/pdf') return 0;

    // Już mamy podgląd i wciąż istnieje?
    $existing = (int) get_post_meta($pdf_id, '_evk_auto_pdf_thumb_id', true);
    if ($existing > 0 && get_post_status($existing) !== false) return $existing;

    if (!class_exists('Imagick')) return 0;

    $pdf_path = get_attached_file($pdf_id);
    if (!$pdf_path || !file_exists($pdf_path)) return 0;

    // Guard: nie ponawiaj ciężkiej konwersji w kółko po nieudanej próbie.
    $flag = 'evk_pdf_prev_fail_' . $pdf_id;
    if (get_transient($flag)) return 0;

    $info = pathinfo($pdf_path);
    $dir  = trailingslashit($info['dirname']);
    $name = wp_unique_filename($dir, $info['basename'] . '.jpg'); // np. dokument.pdf.jpg
    $path = $dir . $name;

    try {
        $im = new Imagick();
        $im->setResolution(300, 300);      // wysoka bazowa rozdzielczość → ostry tekst wektorowy
        $im->readImage($pdf_path . '[0]'); // pierwsza strona
        $im->setImageFormat('jpg');
        $im->setImageCompressionQuality(85);
        $im->setImageBackgroundColor(new ImagickPixel('white'));
        $im = $im->flattenImages();        // spłaszcz przezroczystość na biel
        $im->scaleImage(1200, 1200, true); // bestfit — zachowuje proporcje
        $im->writeImage($path);
        $im->clear();
        $im->destroy();
    } catch (\Throwable $e) {
        error_log('Evoke FIELDS: konwersja PDF→JPG nie powiodła się (#' . $pdf_id . '): ' . $e->getMessage());
        set_transient($flag, 1, HOUR_IN_SECONDS);
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $ft = wp_check_filetype($name, null);

    $attach_id = wp_insert_attachment([
        'post_mime_type' => $ft['type'] ?: 'image/jpeg',
        'post_title'     => sanitize_file_name($info['filename']) . ' — podgląd',
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $pdf_id,
    ], $path, $pdf_id, true);

    if (is_wp_error($attach_id) || !$attach_id) {
        set_transient($flag, 1, HOUR_IN_SECONDS);
        return 0;
    }

    $meta = wp_generate_attachment_metadata((int) $attach_id, $path);
    wp_update_attachment_metadata((int) $attach_id, $meta);
    update_post_meta($pdf_id, '_evk_auto_pdf_thumb_id', (int) $attach_id);
    update_post_meta((int) $attach_id, '_evk_pdf_companion', $pdf_id); // znacznik → ukrycie w bibliotece

    return (int) $attach_id;
}

/**
 * ID załącznika-podglądu JPG dla PDF (generuje w razie potrzeby). 0 dla nie-PDF / błędu.
 */
function evk_rep_pdf_preview_id(int $fid): int {
    if ($fid <= 0 || get_post_mime_type($fid) !== 'application/pdf') return 0;
    $thumb = (int) get_post_meta($fid, '_evk_auto_pdf_thumb_id', true);
    if ($thumb > 0 && get_post_status($thumb) !== false) {
        // Self-heal: podglądy z wcześniejszych buildów mogły nie mieć znacznika ukrycia.
        if (!metadata_exists('post', $thumb, '_evk_pdf_companion')) {
            update_post_meta($thumb, '_evk_pdf_companion', $fid);
        }
        return $thumb;
    }
    return evk_rep_generate_pdf_companion_jpg($fid);
}

/**
 * URL podglądu JPG dla PDF w żądanym rozmiarze (kaskada). '' dla nie-PDF / braku podglądu.
 */
function evk_rep_pdf_preview_url(int $fid, string $size = 'large'): string {
    $thumb = evk_rep_pdf_preview_id($fid);
    if ($thumb <= 0) return '';
    foreach (array_unique([$size, 'large', 'medium', 'thumbnail', 'full']) as $s) {
        $u = wp_get_attachment_image_url($thumb, $s);
        if ($u) return $u;
    }
    return '';
}

// =========================================================================
// UKRYCIE PODGLĄDÓW Z BIBLIOTEKI MEDIÓW
// Załącznik-podgląd zostaje (Bricks Image potrzebuje ID do srcset), ale jest
// odfiltrowany z widoku siatki i listy — użytkownik go nie widzi ani nie wybiera.
// =========================================================================

// Meta-query wykluczające załączniki oznaczone jako podgląd PDF.
function evk_rep_hide_companion_meta_query(array $meta_query): array {
    $meta_query[] = ['key' => '_evk_pdf_companion', 'compare' => 'NOT EXISTS'];
    return $meta_query;
}

// Widok siatki + okno mediów (AJAX).
add_filter('ajax_query_attachments_args', function ($args) {
    $mq = (isset($args['meta_query']) && is_array($args['meta_query'])) ? $args['meta_query'] : [];
    $args['meta_query'] = evk_rep_hide_companion_meta_query($mq);
    return $args;
});

// Widok listy (upload.php ?mode=list).
add_action('pre_get_posts', function ($q) {
    if (!is_admin()) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'upload') return;
    if (($q->get('post_type') ?: 'attachment') !== 'attachment') return;
    $mq = $q->get('meta_query');
    $q->set('meta_query', evk_rep_hide_companion_meta_query(is_array($mq) ? $mq : []));
});
