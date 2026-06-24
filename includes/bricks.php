<?php
if (!defined('ABSPATH')) exit;

/**
 * Integracja z Bricks (v8.2).
 *
 * Zmiany v8.2:
 * - Agresywna detekcja ID podglądu w evk_rep_filter_pid() przy wykryciu
 * typu postu 'bricks_template' lub pustego ID w żądaniach AJAX buildera.
 * - $ctx_pid propagowany przez render_content / render_tag → evk_rep_resolve().
 * - metadata_exists() zamiast $val!=='' przy single-field resolution.
 * - Stos push/podmień-wierzch per-iteracja (spl_object_id), zbilansowany pop.
 * - Usunięty zduplikowany no-op filter bricks/dynamic_tags_list.
 */

// =========================================================================
// HELPERY
// =========================================================================

function evk_rep_layout_types(): array { return ['tab', 'accordion', 'heading', 'description']; }
function evk_rep_is_layout(string $type): bool { return in_array($type, evk_rep_layout_types(), true); }
function evk_rep_is_repeater(array $group): bool { return array_key_exists('repeater', $group) ? !empty($group['repeater']) : true; }

function evk_rep_parse_options($raw): array {
    $out = [];
    $raw = (string) $raw;
    // Czyste standaryzowanie przełamań bez ucinania losowych liter "n" i "r"
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, ':') !== false) {
            [$v, $l] = array_map('trim', explode(':', $line, 2));
            $out[$v] = $l !== '' ? $l : $v;
        } else {
            $out[$line] = $line;
        }
    }
    return $out;
}

function evk_rep_group_data_fields(array $group): array {
    $out = [];
    foreach (($group['fields'] ?? []) as $fk => $f) {
        $t = $f['type'] ?? '';
        if (evk_rep_is_layout($t) || $t === 'repeater') continue;
        $out[$fk] = $f;
    }
    return $out;
}

if (!function_exists('evk_rep_groups')) {
    function evk_rep_groups(): array { return []; }
}

/**
 * Detekcja kontekstu buildera.
 */
function evk_rep_is_builder(): bool {
    if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) return true;
    if (function_exists('bricks_is_builder')      && bricks_is_builder())      return true;
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $a = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if (strpos($a, 'bricks_') === 0) return true;
    }
    if (isset($_GET['bricks']) && $_GET['bricks'] === 'run') return true;
    return false;
}

/**
 * Inteligentne filtrowanie ID wpisu.
 * Jeśli $pid wskazuje na szablon Bricks lub jest zerem, wymusza ID podglądu.
 */
function evk_rep_filter_pid(int $pid = 0): int {
    $final_id = $pid ?: (int) get_the_ID();

    if (evk_rep_is_builder()) {
        if (!$final_id || get_post_type($final_id) === 'bricks_template') {
            if (!empty($_REQUEST['preview_or_post_id'])) return (int) $_REQUEST['preview_or_post_id'];
            if (!empty($_REQUEST['postId']))             return (int) $_REQUEST['postId'];

            if (function_exists('bricks_get_preview_post_id') && bricks_get_preview_post_id()) {
                return (int) bricks_get_preview_post_id();
            }

            if (class_exists('Bricks\Database') && !empty(\Bricks\Database::$page_data['preview_or_post_id'])) {
                return (int) \Bricks\Database::$page_data['preview_or_post_id'];
            }
        }
    }

    return $final_id;
}

function evk_rep_post_id(): int {
    return evk_rep_filter_pid(0);
}

function evk_rep_extract_context_id($post_ctx): int {
    if ($post_ctx instanceof WP_Post) return $post_ctx->ID;
    if (is_numeric($post_ctx) && $post_ctx > 0) return (int) $post_ctx;
    if (is_array($post_ctx) && !empty($post_ctx['ID'])) return (int) $post_ctx['ID'];
    return 0;
}

// =========================================================================
// REJESTR PĘTLI
// =========================================================================

function evk_rep_loops(): array {
    // Memo per-żądanie — wołane z 3 filtrów Bricks (control_options, query/run,
    // query/loop_object) przy każdym renderze. Inwalidacja w evk_groups_cache_clear().
    if (isset($GLOBALS['evk_rep_loops_memo']) && is_array($GLOBALS['evk_rep_loops_memo'])) {
        return $GLOBALS['evk_rep_loops_memo'];
    }

    $loops = [];

    // $optPath ≠ $path dla pól w grupach POJEDYNCZYCH: dane opcji są w
    // evk_rep_opt_{grupa} zagnieżdżone pod [pole], więc ścieżka opcji = grupa.pole.
    $add_loop = function(string $path, string $label, array $rowFields, ?string $optPath = null) use (&$loops) {
        $optPath = $optPath ?? $path;
        $loops[$path]                 = ['label' => 'EVK: '       . $label, 'fields' => $rowFields];
        $loops['evk_opt_' . $optPath] = ['label' => 'EVK Opcje: ' . $label, 'fields' => $rowFields];
    };

    $walk_repeater = function(string $basePath, string $baseLabel, array $subFields, ?string $optBase = null) use (&$add_loop, &$walk_repeater, &$add_gallery, &$add_rel, &$add_gallery_flat) {
        $optBase   = $optBase ?? $basePath;
        $rowFields = [];
        foreach (($subFields ?? []) as $k => $f) {
            $t = $f['type'] ?? '';
            if (evk_rep_is_layout($t) || $t === 'repeater') continue;
            $rowFields[$k] = $f;
        }
        $add_loop($basePath, $baseLabel, $rowFields, $optBase);
        // Pod-pola wymagające własnej pętli (zagnieżdżonej) — galeria, relacja, repeater.
        foreach (($subFields ?? []) as $k => $f) {
            $t     = $f['type'] ?? '';
            $sub   = $basePath . '.' . $k;
            $oSub  = $optBase  . '.' . $k;
            $label = $baseLabel . ' › ' . ($f['label'] ?? $k);
            if ($t === 'repeater') {
                $walk_repeater($sub, $label, $f['sub_fields'] ?? [], $oSub);
            } elseif ($t === 'gallery') {
                $add_gallery($sub, $label, $f, $oSub);             // pętla per wiersz (zagnieżdżona)
                // Pola wiersza (skalarne) doklejane do każdego spłaszczonego obrazu — np. tytuł.
                $flatRow = [];
                foreach ($rowFields as $rk => $rf) {
                    if (in_array($rf['type'] ?? '', ['gallery', 'relationship'], true)) continue;
                    $flatRow[$rk] = $rf;
                }
                $add_gallery_flat($basePath, $optBase, $k, $f, $label, $flatRow); // płaska lista ze wszystkich wierszy
            } elseif ($t === 'relationship') {
                $add_rel($sub, $label, $oSub);
            }
        }
    };

    // Galeria = pętla zwracająca wiersze {img, cat}. Pola wiersza: img (obraz), cat (lista).
    $add_gallery = function(string $path, string $label, array $field, ?string $optPath = null) use (&$loops) {
        $optPath = $optPath ?? $path;
        $cats    = function_exists('evk_rep_gallery_categories') ? evk_rep_gallery_categories($field) : [];
        $catOpts = '';
        foreach ($cats as $v => $l) $catOpts .= $v . ' : ' . $l . "\n";
        $rowFields = [
            'img' => ['type' => 'image',  'label' => 'Obraz'],
            'cat' => ['type' => 'select', 'label' => 'Kategoria', 'options' => trim($catOpts)],
        ];
        $sort = $field['gallery_sort'] ?? '';
        $loops[$path]                 = ['label' => 'EVK Galeria: '       . $label, 'fields' => $rowFields, 'sort' => $sort];
        $loops['evk_opt_' . $optPath] = ['label' => 'EVK Galeria Opcje: ' . $label, 'fields' => $rowFields, 'sort' => $sort];

        // Pętla kategorii UŻYTYCH w tej galerii (do przycisków filtrów Isotope).
        // Wiersz: {slug, name}. Bez wariantów nieużytych — czyste, pasujące przyciski.
        $catFields = ['slug' => ['type' => 'text', 'label' => 'Slug'], 'name' => ['type' => 'text', 'label' => 'Nazwa']];
        $loops['evk_galcat_' . $path]       = ['label' => 'EVK Galeria kategorie: '         . $label, 'fields' => $catFields, 'galcat' => $field, 'galPath' => $path,    'galIsOption' => false];
        $loops['evk_galcatopt_' . $optPath] = ['label' => 'EVK Galeria kategorie (Opcje): ' . $label, 'fields' => $catFields, 'galcat' => $field, 'galPath' => $optPath, 'galIsOption' => true];
    };

    // Relacja = pętla zwracająca powiązane WP_Post (kontekst posta natywny w Bricks).
    $add_rel = function(string $path, string $label, ?string $optPath = null) use (&$loops) {
        $optPath = $optPath ?? $path;
        $loops[$path]                 = ['label' => 'EVK Relacja: '       . $label, 'fields' => [], 'relationship' => true];
        $loops['evk_opt_' . $optPath] = ['label' => 'EVK Relacja Opcje: ' . $label, 'fields' => [], 'relationship' => true];
    };

    // Galeria SPŁASZCZONA — wszystkie obrazy ze WSZYSTKICH wierszy repeatera w jednej
    // płaskiej liście (jeden kontener = jedna siatka Isotope, filtrowalna po kategorii).
    $add_gallery_flat = function(string $repPath, string $optRepPath, string $subKey, array $field, string $label, array $rowFields = []) use (&$loops) {
        $cats    = function_exists('evk_rep_gallery_categories') ? evk_rep_gallery_categories($field) : [];
        $catOpts = '';
        foreach ($cats as $v => $l) $catOpts .= $v . ' : ' . $l . "\n";
        $imgFields = ['img' => ['type' => 'image', 'label' => 'Obraz'], 'cat' => ['type' => 'select', 'label' => 'Kategoria', 'options' => trim($catOpts)]];
        // Dołącz pola wiersza (np. tytuł), by tagi typu {evk_field_tytul} działały na każdym obrazie.
        foreach ($rowFields as $rk => $rf) {
            if ($rk === 'img' || $rk === 'cat') continue;
            $imgFields[$rk] = $rf;
        }
        $rowKeys   = array_values(array_filter(array_keys($rowFields), function ($rk) { return $rk !== 'img' && $rk !== 'cat'; }));
        $catFields = ['slug' => ['type' => 'text', 'label' => 'Slug'], 'name' => ['type' => 'text', 'label' => 'Nazwa']];

        $fsort = $field['gallery_sort'] ?? '';
        $loops['evk_galflat_' . $repPath . '.' . $subKey]          = ['label' => 'EVK Galeria — wszystkie wiersze: '           . $label, 'fields' => $imgFields, 'galflat' => $field, 'repPath' => $repPath,    'subKey' => $subKey, 'rowKeys' => $rowKeys, 'sort' => $fsort, 'flatOption' => false];
        $loops['evk_galflatopt_' . $optRepPath . '.' . $subKey]    = ['label' => 'EVK Galeria — wszystkie wiersze (Opcje): '    . $label, 'fields' => $imgFields, 'galflat' => $field, 'repPath' => $optRepPath, 'subKey' => $subKey, 'rowKeys' => $rowKeys, 'sort' => $fsort, 'flatOption' => true];
        $loops['evk_galcatflat_' . $repPath . '.' . $subKey]       = ['label' => 'EVK Galeria kategorie — wszystkie: '          . $label, 'fields' => $catFields, 'galcatflat' => $field, 'repPath' => $repPath,    'subKey' => $subKey, 'flatOption' => false];
        $loops['evk_galcatflatopt_' . $optRepPath . '.' . $subKey] = ['label' => 'EVK Galeria kategorie — wszystkie (Opcje): ' . $label, 'fields' => $catFields, 'galcatflat' => $field, 'repPath' => $optRepPath, 'subKey' => $subKey, 'flatOption' => true];
    };

    foreach (evk_rep_groups() as $gk => $group) {
        $glabel = $group['label'] ?? $gk;
        if (evk_rep_is_repeater($group)) {
            $walk_repeater($gk, $glabel, $group['fields'] ?? []);
        } else {
            foreach (($group['fields'] ?? []) as $fk => $f) {
                $t = $f['type'] ?? '';
                if ($t === 'repeater') {
                    $walk_repeater($fk, $glabel . ' › ' . ($f['label'] ?? $fk), $f['sub_fields'] ?? [], $gk . '.' . $fk);
                } elseif ($t === 'gallery') {
                    $add_gallery($fk, $glabel . ' — ' . ($f['label'] ?? $fk), $f, $gk . '.' . $fk);
                } elseif ($t === 'relationship') {
                    $add_rel($fk, $glabel . ' — ' . ($f['label'] ?? $fk), $gk . '.' . $fk);
                }
            }
        }
    }

    // Pętle termów taksonomii (zwracają WP_Term, hide_empty=false → puste też widać).
    // Działają z natywnymi tagami termu Bricks i z tagami pól EVK termu.
    foreach (get_taxonomies(['public' => true], 'objects') as $tx) {
        if (in_array($tx->name, ['nav_menu', 'link_category', 'post_format'], true)) continue;
        if (strpos($tx->name, 'wp_') === 0) continue;
        $loops['evk_terms_' . $tx->name] = [
            'label'  => 'EVK Termy: ' . ($tx->label ?: $tx->name),
            'fields' => [],
            'terms'  => $tx->name,
        ];
    }

    $GLOBALS['evk_rep_loops_memo'] = $loops;
    return $loops;
}

// =========================================================================
// HELPERY PÓL
// =========================================================================

function evk_rep_find_single_field(string $key): ?array {
    foreach (evk_rep_groups() as $group) {
        if (evk_rep_is_repeater($group)) continue;
        $f = $group['fields'][$key] ?? null;
        if ($f && !evk_rep_is_layout($f['type'] ?? '') && ($f['type'] ?? '') !== 'repeater') return $f;
    }
    return null;
}

/** Jak wyżej, ale zwraca też typ obiektu grupy (post/term/user). */
function evk_rep_find_single_field_ctx(string $key): ?array {
    foreach (evk_rep_groups() as $group) {
        if (evk_rep_is_repeater($group)) continue;
        $f = $group['fields'][$key] ?? null;
        if ($f && !evk_rep_is_layout($f['type'] ?? '') && ($f['type'] ?? '') !== 'repeater') {
            return ['field' => $f, 'object_type' => $group['object_type'] ?? 'post'];
        }
    }
    return null;
}

/** ID bieżącego termu: pętla Bricks (jeśli term) lub queried object. */
function evk_rep_current_term_id(): int {
    if (!empty($GLOBALS['evk_rep_current_term'])) return (int) $GLOBALS['evk_rep_current_term'];
    $obj = get_queried_object();
    return $obj instanceof WP_Term ? (int) $obj->term_id : 0;
}

/** ID bieżącego użytkownika: pętla Bricks (jeśli user) lub queried object (archiwum autora). */
function evk_rep_current_user_id_ctx(): int {
    if (!empty($GLOBALS['evk_rep_current_user'])) return (int) $GLOBALS['evk_rep_current_user'];
    $obj = get_queried_object();
    return $obj instanceof WP_User ? (int) $obj->ID : 0;
}

/** ID bieżącego załącznika: iteracja pętli galerii (obraz wiersza) lub queried attachment. */
function evk_rep_current_attachment_id(): int {
    if (!empty($GLOBALS['evk_rep_current_attachment'])) return (int) $GLOBALS['evk_rep_current_attachment'];
    $obj = get_queried_object();
    return ($obj instanceof WP_Post && $obj->post_type === 'attachment') ? (int) $obj->ID : 0;
}

function evk_rep_find_field_anywhere(string $key): ?array {
    $found = null;
    $walk  = function(array $fields) use (&$walk, &$found, $key) {
        if ($found) return;
        foreach ($fields as $fk => $f) {
            if ($found) return;
            $t = $f['type'] ?? '';
            if ($fk === $key && !evk_rep_is_layout($t) && $t !== 'repeater') { $found = $f; return; }
            if ($t === 'repeater') $walk($f['sub_fields'] ?? []);
        }
    };
    foreach (evk_rep_groups() as $group) {
        $walk($group['fields'] ?? []);
        if ($found) break;
    }
    return $found;
}

// Format wyświetlania dla pól daty/czasu. Pusty `date_format` = ustawienie witryny.
function evk_rep_date_display_format(array $field): string {
    $fmt = trim((string) ($field['date_format'] ?? ''));
    if ($fmt !== '') return $fmt;
    $type = $field['type'] ?? 'date';
    if ($type === 'time')     return (string) get_option('time_format', 'H:i');
    if ($type === 'datetime') return trim(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'));
    return (string) get_option('date_format', 'Y-m-d');
}

function evk_rep_format_value(array $field, $val, string $prop) {
    $type = $field['type'] ?? 'text';
    if (in_array($type, ['date', 'time', 'datetime'], true)) {
        $s = is_scalar($val) ? trim((string) $val) : '';
        if ($s === '') return '';
        if ($prop === 'raw') return $s;            // wartość ISO z bazy
        $ts = strtotime($s);
        if ($ts === false) return $s;
        if ($prop === 'timestamp') return (string) $ts;
        return date_i18n(evk_rep_date_display_format($field), $ts); // domyślnie: sformatowana
    }
    if ($type === 'taxonomy') {
        $ids = is_array($val) ? $val : ($val ? [$val] : []);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return '';
        if ($prop === 'id')    return implode(',', $ids);
        $terms = array_values(array_filter(array_map('get_term', $ids), function ($t) { return $t && !is_wp_error($t); }));
        if ($prop === 'slug')  return implode(',', array_map(function ($t) { return $t->slug; }, $terms));
        if ($prop === 'count') return (string) count($terms);
        return implode(', ', array_map(function ($t) { return $t->name; }, $terms));
    }
    if ($type === 'image' && $val) {
        if ($prop === 'id')  return (int) $val;
        if ($prop === 'alt') return get_post_meta((int) $val, '_wp_attachment_image_alt', true);
        // prop może być nazwą rozmiaru obrazka (thumbnail/medium/large/full/własny). Domyślnie full.
        $size = ($prop !== '' && $prop !== 'url') ? $prop : 'full';
        return wp_get_attachment_image_url((int) $val, $size) ?: '';
    }
    if (in_array($type, ['select', 'radio', 'button_group'], true) && $prop === 'label') {
        $opts = evk_rep_parse_options($field['options'] ?? '');
        return $opts[$val] ?? $val;
    }
    if ($type === 'image_select' && $prop === 'label') {
        $opts = function_exists('evk_rep_parse_image_select_options') ? evk_rep_parse_image_select_options($field['options'] ?? '') : [];
        return $opts[$val] ?? $val;
    }
    if ($type === 'gallery') {
        $rows = is_array($val) ? $val : [];
        $ids  = [];
        foreach ($rows as $r) {
            $id = (int) (is_array($r) ? ($r['img'] ?? 0) : $r);
            if ($id > 0) $ids[] = $id;
        }
        if (empty($ids)) return '';
        if ($prop === 'ids')   return implode(',', $ids);
        if ($prop === 'count') return (string) count($ids);
        if ($prop === 'id')    return (string) $ids[0];
        return wp_get_attachment_image_url($ids[0], 'full') ?: ''; // domyślnie: URL pierwszego
    }
    if ($type === 'relationship') {
        $ids = is_array($val)
            ? array_values(array_filter(array_map('intval', $val)))
            : ((int) $val > 0 ? [(int) $val] : []);
        if (empty($ids)) return '';
        if ($prop === 'ids')   return implode(',', $ids);
        if ($prop === 'count') return (string) count($ids);
        if ($prop === 'id')    return (string) $ids[0];
        if ($prop === 'url')   return get_permalink($ids[0]) ?: '';
        return get_the_title($ids[0]); // domyślnie: tytuł pierwszego
    }
    if ($type === 'link') {
        $lv     = is_array($val) ? $val : [];
        $url    = (string) ($lv['url'] ?? '');
        $title  = (string) ($lv['title'] ?? '');
        $target = !empty($lv['target']) ? '_blank' : '';
        if ($prop === 'title' || $prop === 'text' || $prop === 'label') return $title;
        if ($prop === 'target') return $target;
        if ($prop === 'html') {
            if ($url === '') return '';
            $t = $target ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . esc_url($url) . '"' . $t . '>' . esc_html($title !== '' ? $title : $url) . '</a>';
        }
        return $url; // domyślnie / :url
    }
    if ($type === 'checkbox') return $val ? '1' : '';
    return is_scalar($val) ? (string) $val : '';
}

// =========================================================================
// STOS KONTEKSTU PĘTLI
// =========================================================================

function evk_rep_stack(): array      { return $GLOBALS['evk_rep_stack'] ?? []; }
function evk_rep_stack_top(): ?array { $st = evk_rep_stack(); return empty($st) ? null : $st[count($st)-1]; }

function evk_rep_stack_push(array $ctx): void {
    if (!isset($GLOBALS['evk_rep_stack']) || !is_array($GLOBALS['evk_rep_stack'])) $GLOBALS['evk_rep_stack'] = [];
    $GLOBALS['evk_rep_stack'][] = $ctx;
}
function evk_rep_stack_pop(): void {
    if (!empty($GLOBALS['evk_rep_stack'])) array_pop($GLOBALS['evk_rep_stack']);
}

// =========================================================================
// RESOLVER TAGÓW
// =========================================================================

function evk_rep_resolve(string $key, string $prop = '', int $ctx_pid = 0) {
    // 1. Kontekst pętli (stos)
    $top = evk_rep_stack_top();
    if ($top) {
        $row    = $top['row']    ?? [];
        $fields = $top['fields'] ?? [];
        if (array_key_exists($key, $row)) {
            return evk_rep_format_value($fields[$key] ?? ['type' => 'text'], $row[$key], $prop);
        }
        if (evk_rep_is_builder() && array_key_exists($key, $fields)) {
            return evk_rep_builder_placeholder($fields[$key], $key, $prop);
        }
    }

    // 2. Pola pojedyncze (grupy non-repeater) — źródło wg typu obiektu grupy
    $sf = evk_rep_find_single_field_ctx($key);
    if ($sf) {
        $field = $sf['field'];
        $ot    = $sf['object_type'];

        if ($ot === 'term') {
            $tid = evk_rep_current_term_id();
            if ($tid && metadata_exists('term', $tid, $key)) {
                return evk_rep_format_value($field, get_term_meta($tid, $key, true), $prop);
            }
        } elseif ($ot === 'user') {
            $uid = evk_rep_current_user_id_ctx();
            if ($uid && metadata_exists('user', $uid, $key)) {
                return evk_rep_format_value($field, get_user_meta($uid, $key, true), $prop);
            }
        } elseif ($ot === 'media') {
            $aid = evk_rep_current_attachment_id();
            if ($aid && metadata_exists('post', $aid, $key)) {
                return evk_rep_format_value($field, get_post_meta($aid, $key, true), $prop);
            }
        } else {
            $pid = evk_rep_filter_pid($ctx_pid);
            if ($pid && metadata_exists('post', $pid, $key)) {
                return evk_rep_format_value($field, get_post_meta($pid, $key, true), $prop);
            }
        }
        if (evk_rep_is_builder()) return evk_rep_builder_placeholder($field, $key, $prop);
        return '';
    }

    // 3. Fallback builder: placeholder dla pól gdziekolwiek w schemacie
    if (evk_rep_is_builder()) {
        $any = evk_rep_find_field_anywhere($key);
        if ($any) return evk_rep_builder_placeholder($any, $key, $prop);
    }

    return '';
}

function evk_rep_builder_placeholder(array $field, string $key, string $prop) {
    $type  = $field['type'] ?? 'text';
    $label = $field['label'] ?? $key;
    if ($type === 'image') {
        if ($prop === 'id')  return 0;
        if ($prop === 'alt') return $label;
        return includes_url('images/media/default.png');
    }
    if (in_array($type, ['select', 'radio', 'button_group', 'image_select'], true) && $prop === 'label') return $label;
    if ($type === 'checkbox') return '1';
    return '[' . $label . ']';
}

// =========================================================================
// RESOLVER OPCJI GLOBALNYCH
// =========================================================================

function evk_rep_resolve_option(string $tagContent, string $prop = '') {
    foreach (evk_rep_groups() as $gk => $group) {
        $prefix = $gk . '_';
        if (strpos($tagContent, $prefix) !== 0) continue;
        $fk    = substr($tagContent, strlen($prefix));
        $field = $group['fields'][$fk] ?? null;
        if (!$field) return '';
        if (function_exists('evk_rep_get_option')) {
            $val = evk_rep_get_option($gk, $fk);
        } else {
            $vals = get_option('evk_rep_opt_' . $gk, []);
            $val  = is_array($vals) && array_key_exists($fk, $vals) ? $vals[$fk] : '';
        }
        return evk_rep_format_value($field, $val, $prop);
    }
    return '';
}

// =========================================================================
// POBIERANIE WIERSZY
// =========================================================================

function evk_rep_get_rows_for_path(string $path, int $fallback_post_id = 0, bool $is_option = false): array {
    $parts = explode('.', $path);
    $stack = evk_rep_stack();

    for ($i = count($stack) - 1; $i >= 0; $i--) {
        $ctx       = $stack[$i];
        $cpath     = $ctx['path'] ?? '';
        if (!$cpath) continue;
        $cpath_raw = strpos($cpath, 'evk_opt_') === 0 ? substr($cpath, 8) : $cpath;
        $cparts    = explode('.', $cpath_raw);

        $is_prefix = true;
        for ($j = 0; $j < count($cparts); $j++) {
            if (!isset($parts[$j]) || $parts[$j] !== $cparts[$j]) { $is_prefix = false; break; }
        }
        if (!$is_prefix) continue;

        $tail = array_slice($parts, count($cparts));
        if (empty($tail)) break;

        $cur = $ctx['row'] ?? [];
        foreach ($tail as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return [];
            $cur = $cur[$seg];
        }
        return is_array($cur) ? array_values($cur) : [];
    }

    $topKey = $parts[0] ?? '';
    if (!$topKey) return [];

    if ($is_option) {
        $top = get_option('evk_rep_opt_' . $topKey, []);
    } else {
        $post_id = $fallback_post_id ?: evk_rep_post_id();
        if (!$post_id) return [];
        $top = get_post_meta($post_id, $topKey, true);
    }

    if (!is_array($top)) return [];

    $cur = $top;
    for ($i = 1; $i < count($parts); $i++) {
        $seg = $parts[$i];
        if (!is_array($cur) || !array_key_exists($seg, $cur)) return [];
        $cur = $cur[$seg];
    }
    return is_array($cur) ? array_values($cur) : [];
}

/** Tasowanie deterministyczne dla danego ziarna (nie rusza globalnego RNG na stałe). */
function evk_rep_seeded_shuffle(array $arr, int $seed): array {
    $arr = array_values($arr);
    mt_srand($seed);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
    mt_srand(); // przywróć losowość
    return $arr;
}

/**
 * Sortowanie listy obrazów galerii wg trybu pola:
 * '' = bez zmian, 'random' = co wczytanie, 'random_hour'/'random_day' = stałe w oknie.
 * $salt różnicuje permutacje między galeriami przy tym samym oknie czasu.
 */
function evk_rep_sort_items(array $items, string $sort, string $salt): array {
    if ($sort === 'random') { shuffle($items); return array_values($items); }
    if ($sort === 'random_hour' || $sort === 'random_day') {
        $bucket = $sort === 'random_hour' ? (int) floor(time() / 3600) : (int) floor(time() / 86400);
        return evk_rep_seeded_shuffle($items, $bucket + (int) crc32($salt));
    }
    return $items;
}

/** Najnowszy obraz z biblioteki — podgląd w builderze Bricks (żeby galeria nie była pusta). */
function evk_rep_placeholder_image_id(): int {
    static $id = null;
    if ($id !== null) return $id;
    $att = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
    $id  = $att ? (int) $att[0] : 0;
    return $id;
}

function evk_rep_builder_placeholder_row(array $fields): array {
    $row = [];
    foreach ($fields as $k => $f) {
        $t       = $f['type'] ?? 'text';
        $row[$k] = $t === 'image' ? evk_rep_placeholder_image_id() : ($t === 'checkbox' ? '1' : ($t === 'range' ? ($f['min'] ?? 0) : '[' . ($f['label'] ?? $k) . ']'));
    }
    return $row;
}

// =========================================================================
// QUERY LOOP — FILTRY BRICKS
// =========================================================================

add_filter('bricks/setup/control_options', function ($control_options) {
    foreach (evk_rep_loops() as $key => $loop) {
        $control_options['queryTypes'][$key] = $loop['label'];
    }
    return $control_options;
});

add_filter('bricks/query/run', function ($results, $query_obj) {
    $loops    = evk_rep_loops();
    $raw_type = $query_obj->object_type ?? '';
    if (!isset($loops[$raw_type])) return $results;

    // Pętla termów → zwróć WP_Term[] (z pustymi). Bricks renderuje natywnym kontekstem termu.
    if (!empty($loops[$raw_type]['terms'])) {
        $terms = get_terms(['taxonomy' => $loops[$raw_type]['terms'], 'hide_empty' => false]);
        return is_wp_error($terms) ? [] : array_values($terms);
    }

    $post_id = !empty($query_obj->post_id) ? (int) $query_obj->post_id : 0;
    $post_id = evk_rep_filter_pid($post_id);

    if (!$post_id && !empty($query_obj->settings['evk_post_id'])) {
        $post_id = (int) $query_obj->settings['evk_post_id'];
    }

    // Galeria – kategorie użyte (distinct) do przycisków filtrów.
    if (!empty($loops[$raw_type]['galcat'])) {
        $def    = $loops[$raw_type];
        $grows  = evk_rep_get_rows_for_path($def['galPath'], $post_id, !empty($def['galIsOption']));
        $catMap = function_exists('evk_rep_gallery_categories') ? evk_rep_gallery_categories($def['galcat']) : [];
        $seen = []; $out = []; $i = 0;
        foreach ($grows as $r) {
            $c = is_array($r) ? (string) ($r['cat'] ?? '') : '';
            if ($c === '' || isset($seen[$c])) continue;
            $seen[$c] = true;
            $out[] = ['slug' => $c, 'name' => ($catMap[$c] ?? $c), '__evk_id' => $i++, '__evk_path' => $raw_type];
        }
        return $out;
    }

    // Galeria spłaszczona — obrazy (galflat) lub kategorie (galcatflat) ze WSZYSTKICH wierszy repeatera.
    if (!empty($loops[$raw_type]['galflat']) || !empty($loops[$raw_type]['galcatflat'])) {
        $def     = $loops[$raw_type];
        $isCat   = !empty($def['galcatflat']);
        $repRows = evk_rep_get_rows_for_path($def['repPath'], $post_id, !empty($def['flatOption']));
        $catMap  = ($isCat && function_exists('evk_rep_gallery_categories')) ? evk_rep_gallery_categories($def['galcatflat']) : [];
        $out = []; $i = 0; $att = []; $seen = [];
        foreach ($repRows as $row) {
            if (!is_array($row)) continue;
            $gal = $row[$def['subKey']] ?? [];
            if (!is_array($gal)) continue;
            foreach ($gal as $g) {
                if (!is_array($g)) continue;
                if ($isCat) {
                    $c = (string) ($g['cat'] ?? '');
                    if ($c === '' || isset($seen[$c])) continue;
                    $seen[$c] = true;
                    $out[] = ['slug' => $c, 'name' => ($catMap[$c] ?? $c), '__evk_id' => $i++, '__evk_path' => $raw_type];
                } else {
                    $img = (int) ($g['img'] ?? 0);
                    if ($img <= 0) continue;
                    $att[] = $img;
                    $item = ['img' => $img, 'cat' => ($g['cat'] ?? '')];
                    foreach (($def['rowKeys'] ?? []) as $rk) {   // pola wiersza (np. tytuł)
                        $item[$rk] = $row[$rk] ?? '';
                    }
                    $item['__evk_id']   = $i++;
                    $item['__evk_path'] = $raw_type;
                    $out[] = $item;
                }
            }
        }
        if (!$isCat && !empty($def['sort'])) {
            $out = evk_rep_sort_items($out, $def['sort'], $raw_type);
            foreach ($out as $idx => $v) { $out[$idx]['__evk_id'] = $idx; }
        }
        if ($att && function_exists('_prime_post_caches')) _prime_post_caches(array_values(array_unique($att)), false, true);
        return $out;
    }

    $is_option = strpos($raw_type, 'evk_opt_') === 0;
    $path      = $is_option ? substr($raw_type, 8) : $raw_type;
    $rows      = evk_rep_get_rows_for_path($path, $post_id, $is_option);

    // Sortowanie galerii (tylko pętle galerii mają klucz 'sort').
    if (!empty($loops[$raw_type]['sort'])) {
        $rows = evk_rep_sort_items($rows, $loops[$raw_type]['sort'], $raw_type);
    }

    // Relacja → zwróć powiązane WP_Post (Bricks renderuje je natywnym kontekstem posta).
    if (!empty($loops[$raw_type]['relationship'])) {
        $ids = array_values(array_filter(array_map('intval', $rows)));
        if ($ids && function_exists('_prime_post_caches')) _prime_post_caches($ids, true, true);
        $posts = [];
        foreach ($ids as $id) { $p = get_post($id); if ($p) $posts[] = $p; }
        return $posts;
    }

    if (empty($rows) && evk_rep_is_builder()) {
        $rows = [ evk_rep_builder_placeholder_row($loops[$raw_type]['fields']) ];
    }

    $out     = [];
    $att_ids = [];
    foreach (array_values($rows) as $i => $r) {
        $row = is_array($r) ? $r : [];
        $row['__evk_id']   = $i;
        $row['__evk_path'] = $raw_type;
        if (!empty($row['img'])) $att_ids[] = (int) $row['img'];
        $out[] = $row;
    }

    // Galeria: jeden bulk-prime cache załączników zamiast N×get_post w pętli.
    if ($att_ids && function_exists('_prime_post_caches')) {
        _prime_post_caches(array_values(array_unique($att_ids)), false, true);
    }
    return $out;
}, 10, 2);

add_filter('bricks/query/loop_object', function ($loop_object, $loop_key, $query_obj) {
    // Natywne pętle Bricks po termach / użytkownikach — zapamiętaj bieżący obiekt,
    // aby tagi pól term/user rozwiązywały się w iteracji pętli.
    if ($loop_object instanceof WP_Term)     $GLOBALS['evk_rep_current_term'] = (int) $loop_object->term_id;
    elseif ($loop_object instanceof WP_User) $GLOBALS['evk_rep_current_user'] = (int) $loop_object->ID;
    // Wiersz galerii (ma 'img') → bieżący załącznik, by pola media obrazka rozwiązały się w pętli.
    if (is_array($loop_object) && isset($loop_object['img'])) {
        $GLOBALS['evk_rep_current_attachment'] = (int) $loop_object['img'];
    }

    $loops = evk_rep_loops();
    $type  = $query_obj->object_type ?? '';
    if (!isset($loops[$type])) return $loop_object;
    if (!empty($loops[$type]['relationship'])) return $loop_object; // WP_Post → natywny kontekst Bricks
    if (!empty($loops[$type]['terms']))        return $loop_object; // WP_Term → natywny kontekst termu

    $post_id = !empty($query_obj->post_id) ? (int) $query_obj->post_id : 0;
    $post_id = evk_rep_filter_pid($post_id);

    $ctx = [
        'qid'     => spl_object_id($query_obj),
        'path'    => $type,
        'fields'  => $loops[$type]['fields'],
        'row'     => is_array($loop_object) ? $loop_object : [],
        'post_id' => $post_id,
    ];

    $st = $GLOBALS['evk_rep_stack'] ?? [];
    $n  = count($st);

    if ($n && ($st[$n - 1]['qid'] ?? null) === $ctx['qid']) {
        $GLOBALS['evk_rep_stack'][$n - 1] = $ctx;
    } else {
        evk_rep_stack_push($ctx);
    }

    return $loop_object;
}, 10, 3);

add_filter('bricks/query/loop_object_type', function ($object_type, $object, $query_id) {
    if (is_array($object) && isset($object['__evk_id'])) return 'evk_rep';
    if ($object instanceof WP_Term) return 'term';
    if ($object instanceof WP_User) return 'user';
    if ($object instanceof WP_Post) return 'post';
    return $object_type;
}, 10, 3);

add_filter('bricks/query/loop_object_id', function ($object_id, $object, $query_id) {
    if (is_array($object) && isset($object['__evk_id'])) return (int) $object['__evk_id'];
    if ($object instanceof WP_Term) return (int) $object->term_id;
    if ($object instanceof WP_User) return (int) $object->ID;
    if ($object instanceof WP_Post) return (int) $object->ID;
    return $object_id;
}, 10, 3);

add_action('bricks/query/after_loop', function ($query_obj = null) {
    evk_rep_stack_pop();
    unset($GLOBALS['evk_rep_current_term'], $GLOBALS['evk_rep_current_user'], $GLOBALS['evk_rep_current_attachment']);
}, 10, 1);

// =========================================================================
// PICKER — lista tagów dynamicznych
// =========================================================================

add_filter('bricks/dynamic_tags_list', function ($tags) {
    $seen = [];

    $add = function ($key, $label, $type) use (&$tags, &$seen) {
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $tags[] = ['name' => '{evk_field_' . $key . '}', 'label' => $label, 'group' => 'EVK Repeater'];
        if ($type === 'image') {
            $tags[] = ['name' => '{evk_field_' . $key . '__id}',  'label' => $label . ' (ID)',  'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__alt}', 'label' => $label . ' (alt)', 'group' => 'EVK Repeater'];
        } elseif (in_array($type, ['select', 'radio', 'button_group', 'image_select'], true)) {
            $tags[] = ['name' => '{evk_field_' . $key . '__label}', 'label' => $label . ' (etykieta)', 'group' => 'EVK Repeater'];
        } elseif ($type === 'taxonomy') {
            $tags[] = ['name' => '{evk_field_' . $key . '__id}',   'label' => $label . ' (ID termu)',   'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__slug}', 'label' => $label . ' (slug termu)', 'group' => 'EVK Repeater'];
        } elseif ($type === 'gallery') {
            $tags[] = ['name' => '{evk_field_' . $key . '__ids}',   'label' => $label . ' (lista ID)', 'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__count}', 'label' => $label . ' (liczba)',   'group' => 'EVK Repeater'];
        } elseif ($type === 'relationship') {
            $tags[] = ['name' => '{evk_field_' . $key . '__ids}',   'label' => $label . ' (lista ID)',  'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__count}', 'label' => $label . ' (liczba)',    'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__url}',   'label' => $label . ' (link 1.)',   'group' => 'EVK Repeater'];
        } elseif ($type === 'link') {
            $tags[] = ['name' => '{evk_field_' . $key . '__title}',  'label' => $label . ' (etykieta)',     'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__target}', 'label' => $label . ' (cel _blank)',   'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__html}',   'label' => $label . ' (gotowy <a>)',   'group' => 'EVK Repeater'];
        } elseif (in_array($type, ['date', 'time', 'datetime'], true)) {
            $tags[] = ['name' => '{evk_field_' . $key . '__raw}',       'label' => $label . ' (ISO)',     'group' => 'EVK Repeater'];
            $tags[] = ['name' => '{evk_field_' . $key . '__timestamp}', 'label' => $label . ' (timestamp)', 'group' => 'EVK Repeater'];
        }
    };

    $add_opt = function ($group_key, $key, $label, $type) use (&$tags, &$seen) {
        $opt_key = 'evk_opt_' . $group_key . '_' . $key;
        if (isset($seen[$opt_key])) return;
        $seen[$opt_key] = true;
        $tags[] = ['name' => '{' . $opt_key . '}', 'label' => $label . ' (Opcja globalna)', 'group' => 'EVK Opcje (Settings)'];
        if ($type === 'image') {
            $tags[] = ['name' => '{' . $opt_key . '__id}',  'label' => $label . ' (ID Opcji)',  'group' => 'EVK Opcje (Settings)'];
            $tags[] = ['name' => '{' . $opt_key . '__alt}', 'label' => $label . ' (Alt Opcji)', 'group' => 'EVK Opcje (Settings)'];
        } elseif (in_array($type, ['select', 'radio', 'button_group', 'image_select'], true)) {
            $tags[] = ['name' => '{' . $opt_key . '__label}', 'label' => $label . ' (Etykieta Opcji)', 'group' => 'EVK Opcje (Settings)'];
        }
    };

    foreach (evk_rep_groups() as $gk => $group) {
        $glabel = $group['label'] ?? $gk;

        $walk = function(string $baseLabel, array $fields) use (&$add, &$walk) {
            foreach (($fields ?? []) as $fk => $f) {
                $t = $f['type'] ?? '';
                if (evk_rep_is_layout($t)) continue;
                if ($t === 'repeater') { $walk($baseLabel . ' › ' . ($f['label'] ?? $fk), $f['sub_fields'] ?? []); continue; }
                $add($fk, $baseLabel . ' — ' . ($f['label'] ?? $fk), $t);
            }
        };

        if (evk_rep_is_repeater($group)) {
            $walk($glabel, $group['fields'] ?? []);
        } else {
            $walk($glabel, $group['fields'] ?? []);
            foreach (($group['fields'] ?? []) as $fk => $f) {
                $t = $f['type'] ?? '';
                if (evk_rep_is_layout($t) || $t === 'repeater') continue;
                $add_opt($gk, $fk, $glabel . ' — ' . ($f['label'] ?? $fk), $t);
            }
        }
    }
    return $tags;
});

// =========================================================================
// RENDEROWANIE TAGÓW DYNAMICZNYCH
// =========================================================================

function evk_rep_parse_tag(string $raw): array {
    // Props + standardowe rozmiary obrazków. Lista zamknięta, by klucze z „__" nie były psute.
    if (preg_match('/^(.*)__(ids|id|alt|label|slug|count|url|title|target|html|raw|timestamp|thumbnail|medium|medium_large|large|full|1536x1536|2048x2048)$/', $raw, $m)) return [$m[1], $m[2]];
    return [$raw, ''];
}

add_filter('bricks/dynamic_data/render_content', 'evk_rep_render_content', 20, 3);
add_filter('bricks/frontend/render_data',        'evk_rep_render_content', 20, 2);
add_filter('bricks/dynamic_data/render_tag',     'evk_rep_render_tag',     20, 3);

function evk_rep_render_content($content, $post = null, $context = 'text') {
    $content = (string) $content;
    $ctx_pid = evk_rep_extract_context_id($post);

    if (strpos($content, '{evk_field_') !== false) {
        $content = preg_replace_callback('/\{evk_field_([a-zA-Z0-9_\.]+)\}/i', function ($m) use ($ctx_pid) {
            [$key, $prop] = evk_rep_parse_tag($m[1]);
            $v = evk_rep_resolve($key, $prop, $ctx_pid);
            return is_scalar($v) ? (string) $v : '';
        }, $content);
    }
    if (strpos($content, '{evk_opt_') !== false) {
        $content = preg_replace_callback('/\{evk_opt_([a-zA-Z0-9_\.]+)\}/i', function ($m) {
            [$tagContent, $prop] = evk_rep_parse_tag($m[1]);
            $v = evk_rep_resolve_option($tagContent, $prop);
            return is_scalar($v) ? (string) $v : '';
        }, $content);
    }
    return $content;
}

/**
 * Wartość obrazka dla kontekstu 'image'/'media' Bricks. Element Image bierze $value[0],
 * więc trzeba zwrócić TABLICĘ INDEKSOWANĄ z URL-em pod [0] — nie string (dawał „h" =
 * pierwszy znak) ani tablicę asocjacyjną (brak [0] → pusto).
 * Wzorzec z forum Bricks: $value = !empty($value) ? [$value] : [];
 */
function evk_rep_image_tag_value(string $key, string $prop, int $ctx_pid, bool $is_option) {
    $id = $is_option
        ? (int) evk_rep_resolve_option($key, 'id')
        : (int) evk_rep_resolve($key, 'id', $ctx_pid);
    if ($id <= 0) return [];
    $size = ($prop !== '' && !in_array($prop, ['url', 'id', 'alt'], true)) ? $prop : 'large';
    $url  = wp_get_attachment_image_url($id, $size) ?: wp_get_attachment_image_url($id, 'full');
    return $url ? [$url] : [];
}

function evk_rep_render_tag($tag, $post = null, $context = 'text') {
    $t = is_string($tag) ? trim($tag, '{}') : '';
    $ctx_pid = evk_rep_extract_context_id($post);
    $img_ctx = in_array($context, ['image', 'media'], true);

    if (strpos($t, 'evk_field_') === 0) {
        [$key, $prop] = evk_rep_parse_tag(substr($t, 10));
        if ($img_ctx) return evk_rep_image_tag_value($key, $prop, $ctx_pid, false);
        $v = evk_rep_resolve($key, $prop, $ctx_pid);
        return is_scalar($v) ? $v : '';
    }
    if (strpos($t, 'evk_opt_') === 0) {
        [$tagContent, $prop] = evk_rep_parse_tag(substr($t, 8));
        if ($img_ctx) return evk_rep_image_tag_value($tagContent, $prop, 0, true);
        $v = evk_rep_resolve_option($tagContent, $prop);
        return is_scalar($v) ? $v : '';
    }
    return $tag;
}
