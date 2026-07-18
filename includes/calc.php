<?php
if (!defined('ABSPATH')) exit;

/**
 * Pole obliczeniowe (typ `calc`) — silnik formuł.
 *
 * Formuła: operatory + - * / ( ), liczby, {klucz} (pole z tego samego poziomu),
 * agregaty SUM/COUNT/AVG/MIN/MAX(repeater.subpole) po wierszach repeatera
 * (COUNT(repeater) bez subpola = liczba wierszy). Bez eval() — własny tokenizer
 * i parser zejść rekurencyjnych.
 *
 * Wartość liczona SERWEROWO przy zapisie (POST dla pól calc jest ignorowany)
 * i zapisywana jako zwykła meta — front (Bricks) czyta ją jak liczbę, a listy
 * mogą sortować po meta_value_num. Kolejność przeliczania jest stała: najpierw
 * wiersze repeaterów (od najgłębszych), potem poziom główny — dzięki temu
 * SUM po wierszowym subpolu calc działa, a cykle są niemożliwe konstrukcyjnie.
 * Łańcuchy calc→calc w obrębie TEGO SAMEGO poziomu są zabronione (→ 0).
 */

// =========================================================================
// LICZBY / TOKENIZER / PARSER
// =========================================================================

/** Wartość pola → float. Przecinek dziesiętny normalizowany do kropki. */
function evk_rep_calc_num($v): float {
    if (is_int($v) || is_float($v)) return (float) $v;
    if (!is_scalar($v)) return 0.0;
    $s = str_replace(',', '.', trim((string) $v));
    return is_numeric($s) ? (float) $s : 0.0;
}

/** Formuła → tokeny; null przy błędzie składni. */
function evk_rep_calc_tokens(string $f): ?array {
    $out = [];
    $len = strlen($f);
    $i   = 0;
    while ($i < $len) {
        $c = $f[$i];
        if ($c === ' ' || $c === "\t") { $i++; continue; }
        if (strpos('+-*/()', $c) !== false) { $out[] = ['op', $c]; $i++; continue; }
        if ($c === '{') {
            $e = strpos($f, '}', $i);
            if ($e === false) return null;
            $key = trim(substr($f, $i + 1, $e - $i - 1));
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) return null;
            $out[] = ['field', strtolower($key)];
            $i = $e + 1;
            continue;
        }
        if (preg_match('/\G(SUM|COUNT|AVG|MIN|MAX)\s*\(\s*([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\s*\)/i', $f, $m, 0, $i)) {
            $out[] = ['agg', strtoupper($m[1]), strtolower($m[2]), strtolower($m[3] ?? '')];
            $i += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G\d+(?:[\.,]\d+)?/', $f, $m, 0, $i)) {
            $out[] = ['num', (float) str_replace(',', '.', $m[0])];
            $i += strlen($m[0]);
            continue;
        }
        return null;
    }
    return $out;
}

/**
 * Ewaluacja formuły. $field_cb(string $key): float — wartość pola z tego samego
 * poziomu; $agg_cb(string $func, string $rep, string $sub): float — agregat.
 * Zwraca null przy błędzie składni / dzieleniu przez zero.
 */
function evk_rep_calc_eval(string $formula, callable $field_cb, callable $agg_cb): ?float {
    $formula = trim($formula);
    if ($formula === '') return null;
    $t = evk_rep_calc_tokens($formula);
    if ($t === null || $t === []) return null;
    $i = 0;
    $v = evk_rep_calc_expr($t, $i, $field_cb, $agg_cb);
    return ($v === null || $i !== count($t)) ? null : $v;
}

function evk_rep_calc_expr(array $t, int &$i, callable $fcb, callable $acb): ?float {
    $v = evk_rep_calc_term($t, $i, $fcb, $acb);
    if ($v === null) return null;
    while ($i < count($t) && $t[$i][0] === 'op' && ($t[$i][1] === '+' || $t[$i][1] === '-')) {
        $op = $t[$i][1];
        $i++;
        $r = evk_rep_calc_term($t, $i, $fcb, $acb);
        if ($r === null) return null;
        $v = ($op === '+') ? $v + $r : $v - $r;
    }
    return $v;
}

function evk_rep_calc_term(array $t, int &$i, callable $fcb, callable $acb): ?float {
    $v = evk_rep_calc_factor($t, $i, $fcb, $acb);
    if ($v === null) return null;
    while ($i < count($t) && $t[$i][0] === 'op' && ($t[$i][1] === '*' || $t[$i][1] === '/')) {
        $op = $t[$i][1];
        $i++;
        $r = evk_rep_calc_factor($t, $i, $fcb, $acb);
        if ($r === null) return null;
        if ($op === '/') {
            if (abs($r) < 1e-12) return null; // dzielenie przez zero → błąd (puste pole)
            $v = $v / $r;
        } else {
            $v = $v * $r;
        }
    }
    return $v;
}

function evk_rep_calc_factor(array $t, int &$i, callable $fcb, callable $acb): ?float {
    if ($i >= count($t)) return null;
    $tok = $t[$i];
    if ($tok[0] === 'op' && $tok[1] === '-') {
        $i++;
        $v = evk_rep_calc_factor($t, $i, $fcb, $acb);
        return $v === null ? null : -$v;
    }
    if ($tok[0] === 'num')   { $i++; return $tok[1]; }
    if ($tok[0] === 'field') { $i++; return (float) $fcb($tok[1]); }
    if ($tok[0] === 'agg')   { $i++; return (float) $acb($tok[1], $tok[2], $tok[3]); }
    if ($tok[0] === 'op' && $tok[1] === '(') {
        $i++;
        $v = evk_rep_calc_expr($t, $i, $fcb, $acb);
        if ($v === null) return null;
        if ($i >= count($t) || $t[$i][0] !== 'op' || $t[$i][1] !== ')') return null;
        $i++;
        return $v;
    }
    return null;
}

/** Agregat po wierszach repeatera. Puste wartości subpola pomijane. */
function evk_rep_calc_aggregate(string $func, array $rows, string $sub): float {
    $vals   = [];
    $rows_n = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rows_n++;
        if ($sub === '') continue;
        $raw = $row[$sub] ?? '';
        if ($raw === '' || $raw === null || (is_array($raw) && !$raw)) continue;
        $vals[] = evk_rep_calc_num($raw);
    }
    switch ($func) {
        case 'COUNT': return $sub === '' ? (float) $rows_n : (float) count($vals);
        case 'SUM':   return (float) array_sum($vals);
        case 'AVG':   return $vals ? array_sum($vals) / count($vals) : 0.0;
        case 'MIN':   return $vals ? (float) min($vals) : 0.0;
        case 'MAX':   return $vals ? (float) max($vals) : 0.0;
    }
    return 0.0;
}

/** Wynik → string do zapisu w mecie ('' gdy formuła błędna/pusta). */
function evk_rep_calc_store(array $field, ?float $v): string {
    if ($v === null) return '';
    $dec = isset($field['decimals']) ? max(0, min(6, (int) $field['decimals'])) : 6;
    $s = number_format(round($v, $dec), $dec, '.', '');
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return $s === '-0' ? '0' : $s;
}

// =========================================================================
// PRZEBIEGI PRZELICZAJĄCE
// =========================================================================

/**
 * Pass wierszowy: liczy subpola calc w każdym wierszu ({klucz} = ten sam wiersz,
 * agregaty = zagnieżdżony repeater w tym wierszu). Wywoływany na końcu
 * evk_rep_sanitize_rows(), więc zagnieżdżenia liczą się od najgłębszych.
 */
function evk_rep_calc_apply_rows(array $fields, array $rows): array {
    $calcs = [];
    foreach ($fields as $fk => $f) {
        if (($f['type'] ?? '') === 'calc') $calcs[$fk] = $f;
    }
    if (!$calcs) return $rows;
    foreach ($rows as &$row) {
        if (!is_array($row)) continue;
        $src = $row; // snapshot — calc w tym samym wierszu widzi 0, nie wynik sąsiada
        foreach ($calcs as $ck => $cf) {
            $v = evk_rep_calc_eval(
                (string) ($cf['formula'] ?? ''),
                function ($key) use ($fields, $src) {
                    if ((($fields[$key]['type'] ?? '') === 'calc')) return 0.0; // bez łańcuchów na tym samym poziomie
                    $val = $src[$key] ?? '';
                    return is_array($val) ? 0.0 : evk_rep_calc_num($val);
                },
                function ($func, $rep, $sub) use ($src) {
                    $rows2 = $src[$rep] ?? null;
                    return is_array($rows2) ? evk_rep_calc_aggregate($func, $rows2, $sub) : 0.0;
                }
            );
            $row[$ck] = evk_rep_calc_store($cf, $v);
        }
    }
    unset($row);
    return $rows;
}

/**
 * Pass po tablicy wartości grupy pojedynczej (Settings Pages / options).
 * Agregat: repeater-pole z tej samej grupy, a gdy go brak — grupa-repeater opcji.
 */
function evk_rep_calc_apply_group_values(array $fields, array $values): array {
    foreach ($fields as $fk => $f) {
        if (($f['type'] ?? '') !== 'calc') continue;
        $v = evk_rep_calc_eval(
            (string) ($f['formula'] ?? ''),
            function ($key) use ($fields, $values) {
                if ((($fields[$key]['type'] ?? '') === 'calc')) return 0.0;
                $val = $values[$key] ?? '';
                return is_array($val) ? 0.0 : evk_rep_calc_num($val);
            },
            function ($func, $rep, $sub) use ($values) {
                $rows = $values[$rep] ?? null;
                if (!is_array($rows)) {
                    $opt  = get_option('evk_rep_opt_' . $rep);
                    $rows = is_array($opt) ? $opt : null;
                }
                return is_array($rows) ? evk_rep_calc_aggregate($func, $rows, $sub) : 0.0;
            }
        );
        $values[$fk] = evk_rep_calc_store($f, $v);
    }
    return $values;
}

/** Czy klucz to pole calc w JAKIEJKOLWIEK grupie pojedynczej danego typu obiektu. */
function evk_rep_calc_key_is_calc(string $key, string $object_type): bool {
    foreach (evk_rep_groups() as $g) {
        if (($g['object_type'] ?? 'post') !== $object_type) continue;
        if (evk_rep_is_repeater($g)) continue;
        if ((($g['fields'][$key]['type'] ?? '') === 'calc')) return true;
    }
    return false;
}

/**
 * Pass grupy pojedynczej na STORED mecie (post/term/user) — po zapisie wszystkich
 * grup, więc {klucz} i agregaty mogą sięgać także do innych grup tego obiektu
 * (repeater-pole i grupa-repeater mają metę o tym samym kształcie).
 */
function evk_rep_calc_group_pass_meta(string $meta_type, int $object_id, array $group): void {
    $fields = $group['fields'] ?? [];
    foreach ($fields as $fk => $f) {
        if (($f['type'] ?? '') !== 'calc') continue;
        $v = evk_rep_calc_eval(
            (string) ($f['formula'] ?? ''),
            function ($key) use ($meta_type, $object_id) {
                if (evk_rep_calc_key_is_calc($key, $meta_type)) return 0.0; // bez łańcuchów na tym samym poziomie
                $val = get_metadata($meta_type, $object_id, $key, true);
                return is_array($val) ? 0.0 : evk_rep_calc_num($val);
            },
            function ($func, $rep, $sub) use ($meta_type, $object_id) {
                $rows = get_metadata($meta_type, $object_id, $rep, true);
                return is_array($rows) ? evk_rep_calc_aggregate($func, $rows, $sub) : 0.0;
            }
        );
        $s = evk_rep_calc_store($f, $v);
        if ($s === '') delete_metadata($meta_type, $object_id, $fk);
        else           update_metadata($meta_type, $object_id, $fk, $s);
    }
}

/** Po zapisaniu wskazanych grup (nonce OK) — przelicz ich pola calc ze stored mety. */
function evk_rep_calc_finish_saves(string $meta_type, int $object_id, array $group_keys): void {
    $groups = evk_rep_groups();
    foreach ($group_keys as $key) {
        $g = $groups[$key] ?? null;
        if (!$g || evk_rep_is_repeater($g)) continue; // wiersze policzone w evk_rep_sanitize_rows
        evk_rep_calc_group_pass_meta($meta_type, $object_id, $g);
    }
}

// =========================================================================
// PUBLICZNE API
// =========================================================================

/**
 * Przelicz wszystkie pola calc obiektu (post/term/user) z zapisanej mety.
 * Do wywoływania po programowych zmianach danych (import, własne nakładki —
 * np. lista obecności dopisująca wiersze bez ekranu edycji).
 */
function evk_rep_recalc(int $object_id, string $meta_type = 'post'): void {
    foreach (evk_rep_groups() as $key => $g) {
        if (($g['object_type'] ?? 'post') !== $meta_type) continue;

        if (evk_rep_is_repeater($g)) {
            $rows = get_metadata($meta_type, $object_id, $key, true);
            if (is_array($rows) && $rows) {
                update_metadata($meta_type, $object_id, $key, evk_rep_calc_apply_rows($g['fields'] ?? [], array_values($rows)));
            }
            continue;
        }

        // Repeater-pola w grupie: przelicz wiersze przed passem głównym.
        foreach (($g['fields'] ?? []) as $fk => $f) {
            if (($f['type'] ?? '') !== 'repeater') continue;
            $rows = get_metadata($meta_type, $object_id, $fk, true);
            if (is_array($rows) && $rows) {
                update_metadata($meta_type, $object_id, $fk, evk_rep_calc_apply_rows($f['sub_fields'] ?? [], array_values($rows)));
            }
        }

        // Calc licz tylko, gdy grupa zostawiła już ślad w mecie tego obiektu —
        // żeby nie tworzyć mety na obiektach, których grupa nie dotyczy.
        $has = false;
        foreach (($g['fields'] ?? []) as $fk => $f) {
            $t = $f['type'] ?? 'text';
            if (evk_rep_is_layout($t)) continue;
            if (metadata_exists($meta_type, $object_id, $fk)) { $has = true; break; }
        }
        if ($has) evk_rep_calc_group_pass_meta($meta_type, $object_id, $g);
    }
}
