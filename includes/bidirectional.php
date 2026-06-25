<?php
if (!defined('ABSPATH')) exit;

/**
 * Relacje dwukierunkowe — generyczne.
 *
 * Pole z `bidirectional` + `reverse_key` synchronizuje przy zapisie pole odwrotne na
 * powiązanych obiektach. Typ pola wyznacza meta type drugiej strony:
 *   relationship → post | taxonomy → term | user → user.
 * Tylko pola top-level w grupach pojedynczych (post/term/user); nie repeater, nie opcje.
 */

function evk_rep_bidir_target_meta_type(string $field_type): ?string {
    switch ($field_type) {
        case 'relationship': return 'post';
        case 'taxonomy':     return 'term';
        case 'user':         return 'user';
    }
    return null;
}

function evk_rep_bidir_ids($v): array {
    if (is_array($v)) return array_values(array_unique(array_filter(array_map('intval', $v))));
    return ((int) $v > 0) ? [(int) $v] : [];
}

function evk_rep_bidir_is_field(array $field): bool {
    return !empty($field['bidirectional'])
        && !empty($field['reverse_key'])
        && in_array($field['type'] ?? '', ['relationship', 'taxonomy', 'user'], true);
}

/** Synchronizuje pole odwrotne po zapisie pola dwukierunkowego. */
function evk_rep_sync_bidirectional(array $field, int $source_id, array $old_ids, array $new_ids): void {
    static $guard = false;
    if ($guard || $source_id <= 0 || !evk_rep_bidir_is_field($field)) return;

    $reverse   = sanitize_key((string) $field['reverse_key']);
    $target_mt = evk_rep_bidir_target_meta_type($field['type']);
    if ($reverse === '' || $target_mt === null) return;

    $added   = array_diff($new_ids, $old_ids);
    $removed = array_diff($old_ids, $new_ids);
    if (!$added && !$removed) return;

    // Bezpośredni update_metadata NIE odpala save_post/edited_term/profile_update,
    // więc strona odwrotna nie wywoła ponownie synchronizacji. Flag = dodatkowa ochrona.
    $guard = true;
    foreach ($added as $tid) {
        $cur = evk_rep_bidir_ids(get_metadata($target_mt, (int) $tid, $reverse, true));
        if (!in_array($source_id, $cur, true)) {
            $cur[] = $source_id;
            update_metadata($target_mt, (int) $tid, $reverse, $cur);
        }
    }
    foreach ($removed as $tid) {
        $cur = evk_rep_bidir_ids(get_metadata($target_mt, (int) $tid, $reverse, true));
        $new = array_values(array_diff($cur, [$source_id]));
        if (count($new) !== count($cur)) {
            if ($new) update_metadata($target_mt, (int) $tid, $reverse, $new);
            else      delete_metadata($target_mt, (int) $tid, $reverse);
        }
    }
    $guard = false;
}

/**
 * Zbiera pola dwukierunkowe z wierszy repeatera (rekurencyjnie, także repeater w repeaterze)
 * i zwraca dla każdego klucza odwrotnego UNIĘ powiązanych ID ze wszystkich wierszy.
 * Klucz sygnatury = reverse_key|typ — wszystkie ID trafiające do tej samej meta odwrotnej
 * sumują się (to samo powiązanie w kilku wierszach liczy się raz).
 * Zwraca: [ sig => ['field' => def, 'ids' => int[]] ].
 */
function evk_rep_collect_bidir_from_rows(array $fields, $rows): array {
    $acc = [];
    if (!is_array($rows)) return $acc;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($fields as $fk => $f) {
            $t = $f['type'] ?? 'text';
            if ($t === 'repeater') {
                $nested = evk_rep_collect_bidir_from_rows($f['sub_fields'] ?? [], $row[$fk] ?? []);
                foreach ($nested as $sig => $entry) {
                    if (!isset($acc[$sig])) $acc[$sig] = $entry;
                    else $acc[$sig]['ids'] = array_merge($acc[$sig]['ids'], $entry['ids']);
                }
                continue;
            }
            if (!evk_rep_bidir_is_field($f)) continue;
            $sig = sanitize_key((string) $f['reverse_key']) . '|' . $t;
            if (!isset($acc[$sig])) $acc[$sig] = ['field' => $f, 'ids' => []];
            foreach (evk_rep_bidir_ids($row[$fk] ?? '') as $id) $acc[$sig]['ids'][] = $id;
        }
    }
    foreach ($acc as &$e) $e['ids'] = array_values(array_unique($e['ids']));
    unset($e);
    return $acc;
}

/** Synchronizuje pola odwrotne dla relacji wewnątrz repeatera (stan stary vs nowy wierszy). */
function evk_rep_sync_bidirectional_rows(int $source_id, array $fields, $old_rows, $new_rows): void {
    $old = evk_rep_collect_bidir_from_rows($fields, $old_rows);
    $new = evk_rep_collect_bidir_from_rows($fields, $new_rows);
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $sig) {
        $field = $new[$sig]['field'] ?? ($old[$sig]['field'] ?? null);
        if (!is_array($field)) continue;
        evk_rep_sync_bidirectional($field, $source_id, $old[$sig]['ids'] ?? [], $new[$sig]['ids'] ?? []);
    }
}

/** Usuwa $object_id z meta odwrotnej na podanych obiektach docelowych. */
function evk_rep_bidir_remove_from_targets(array $field, int $object_id, array $ids): void {
    $reverse   = sanitize_key((string) ($field['reverse_key'] ?? ''));
    $target_mt = evk_rep_bidir_target_meta_type($field['type'] ?? '');
    if ($reverse === '' || $target_mt === null) return;
    foreach ($ids as $tid) {
        $cur = evk_rep_bidir_ids(get_metadata($target_mt, (int) $tid, $reverse, true));
        $new = array_values(array_diff($cur, [$object_id]));
        if (count($new) !== count($cur)) {
            if ($new) update_metadata($target_mt, (int) $tid, $reverse, $new);
            else      delete_metadata($target_mt, (int) $tid, $reverse);
        }
    }
}

/** Przy usuwaniu obiektu — usuń jego ID z pól odwrotnych powiązanych obiektów. */
function evk_rep_bidir_cleanup(string $meta_type, int $object_id): void {
    if ($object_id <= 0) return;
    foreach (evk_rep_groups() as $gkey => $group) {
        $ot  = $group['object_type'] ?? 'post';
        $gmt = ($ot === 'media') ? 'post' : $ot;
        if ($gmt !== $meta_type) continue;

        // Grupa-repeater: relacje siedzą w wierszach pod kluczem grupy.
        if (evk_rep_is_repeater($group)) {
            $rows = get_metadata($meta_type, $object_id, $gkey, true);
            foreach (evk_rep_collect_bidir_from_rows($group['fields'] ?? [], $rows) as $e) {
                evk_rep_bidir_remove_from_targets($e['field'], $object_id, $e['ids']);
            }
            continue;
        }

        foreach (($group['fields'] ?? []) as $fkey => $field) {
            // Pole-repeater w grupie pojedynczej: relacje w jego wierszach.
            if (($field['type'] ?? '') === 'repeater') {
                $rows = get_metadata($meta_type, $object_id, $fkey, true);
                foreach (evk_rep_collect_bidir_from_rows($field['sub_fields'] ?? [], $rows) as $e) {
                    evk_rep_bidir_remove_from_targets($e['field'], $object_id, $e['ids']);
                }
                continue;
            }
            if (!evk_rep_bidir_is_field($field)) continue;
            $ids = evk_rep_bidir_ids(get_metadata($meta_type, $object_id, $fkey, true));
            evk_rep_bidir_remove_from_targets($field, $object_id, $ids);
        }
    }
}

add_action('before_delete_post', function ($pid)  { evk_rep_bidir_cleanup('post', (int) $pid); });
add_action('pre_delete_term',    function ($term) { evk_rep_bidir_cleanup('term', (int) $term); });
add_action('delete_user',        function ($uid)  { evk_rep_bidir_cleanup('user', (int) $uid); });
