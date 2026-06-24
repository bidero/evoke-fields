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

/** Przy usuwaniu obiektu — usuń jego ID z pól odwrotnych powiązanych obiektów. */
function evk_rep_bidir_cleanup(string $meta_type, int $object_id): void {
    if ($object_id <= 0) return;
    foreach (evk_rep_groups() as $group) {
        if (evk_rep_is_repeater($group)) continue;
        $ot  = $group['object_type'] ?? 'post';
        $gmt = ($ot === 'media') ? 'post' : $ot;
        if ($gmt !== $meta_type) continue;
        foreach (($group['fields'] ?? []) as $fkey => $field) {
            if (!evk_rep_bidir_is_field($field)) continue;
            $reverse   = sanitize_key((string) $field['reverse_key']);
            $target_mt = evk_rep_bidir_target_meta_type($field['type']);
            if ($reverse === '' || $target_mt === null) continue;
            $ids = evk_rep_bidir_ids(get_metadata($meta_type, $object_id, $fkey, true));
            foreach ($ids as $tid) {
                $cur = evk_rep_bidir_ids(get_metadata($target_mt, (int) $tid, $reverse, true));
                $new = array_values(array_diff($cur, [$object_id]));
                if (count($new) !== count($cur)) {
                    if ($new) update_metadata($target_mt, (int) $tid, $reverse, $new);
                    else      delete_metadata($target_mt, (int) $tid, $reverse);
                }
            }
        }
    }
}

add_action('before_delete_post', function ($pid)  { evk_rep_bidir_cleanup('post', (int) $pid); });
add_action('pre_delete_term',    function ($term) { evk_rep_bidir_cleanup('term', (int) $term); });
add_action('delete_user',        function ($uid)  { evk_rep_bidir_cleanup('user', (int) $uid); });
