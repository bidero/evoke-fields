<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — strony ustawień w CSV: pełny eksport i import (1.79.0).
 *
 * Decyzja zgłaszającego z 03.10: import „jak eksport” — plik z eksportu wraca
 * 1:1. Kształty (te same w obie strony):
 *   • grupa-repeater → TABELA: nagłówek = etykiety pól (import przyjmie też
 *     klucze), jeden wiersz CSV = jeden wiersz repeatera;
 *   • grupa pojedyncza → „Pole | Wartość”, jeden wiersz na pole; pole-repeater
 *     to JSON w kolumnie Wartość (jak komórka wpisu);
 *   • obraz i plik — ID; galeria, relacje i link — JSON w komórce (import
 *     przyjmie też adres, nazwę, „1|2|3”);
 *   • tłumaczenia — kolumna (tabela) albo wiersz (pary) „Etykieta [en]”.
 * Import grupy pojedynczej zmienia tylko pola obecne w pliku.
 */

/** Typy, których wartość w komórce jest JSON-em (lista albo obiekt). */
function evk_csv_opt_json_types(): array {
    return ['gallery', 'relationship', 'taxonomy', 'user', 'link'];
}

/** Wartość pola do komórki: prosta jak dotąd, złożona jako JSON, obraz/plik jako ID. */
function evk_csv_opt_cell(array $field, $v): string {
    $type = (string) ($field['type'] ?? 'text');
    if (in_array($type, evk_csv_opt_json_types(), true)) {
        $w = evk_csv_rep_export_value($field, $v);
        return $w === null ? '' : (string) wp_json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (in_array($type, ['image', 'file'], true)) return (int) $v > 0 ? (string) (int) $v : '';
    return evk_csv_export_field_value($type, $v);
}

/** Komórka → kształt formularza: JSON dla typów złożonych (gdy wygląda na JSON), reszta jak pole wiersza. */
function evk_csv_opt_raw(array $field, string $cell, array &$warn, string $gdzie) {
    $type = (string) ($field['type'] ?? 'text');
    $v    = $cell;
    if (in_array($type, evk_csv_opt_json_types(), true) && preg_match('/^\s*[\[{]/', $cell)) {
        $d = json_decode($cell, true);
        if (is_array($d)) $v = $d;
    }
    return evk_csv_rep_raw($field, $v, $warn, $gdzie);
}

/** Etykieta pola do nagłówka / kolumny „Pole”. */
function evk_csv_opt_label(string $fk, array $f): string {
    return ($f['label'] ?? '') !== '' ? (string) $f['label'] : $fk;
}

/** „Slogan [en]” → ['Slogan', 'en']; bez języka → [nazwa, '']. */
function evk_csv_opt_split_lang(string $nazwa): array {
    if (preg_match('/^(.*\S)\s*\[([a-z0-9_]+)\]$/u', trim($nazwa), $m)) return [$m[1], $m[2]];
    return [trim($nazwa), ''];
}

/** Języki, w których któraś wartość ma tłumaczenie (żeby nie dokładać pustych kolumn). */
function evk_csv_opt_used_langs(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $k => $v) {
            $p = evk_rep_tl_parse_key((string) $k);
            if ($p && is_scalar($v) && (string) $v !== '') $out[$p[0]] = true;
        }
    }
    return array_keys($out);
}

/**
 * Wiersze CSV eksportu grupy (z nagłówkiem): tabela dla grupy-repeatera,
 * pary „Pole | Wartość” dla pojedynczej.
 *
 * @return list<list<string>>
 */
function evk_csv_opt_export_rows(array $group, $stored): array {
    $stored = is_array($stored) ? $stored : [];
    $pola   = evk_csv_rep_data_fields((array) ($group['fields'] ?? []));
    if (evk_rep_is_repeater($group)) {
        $rows  = array_values(array_filter($stored, 'is_array'));
        $langs = evk_csv_opt_used_langs($rows);
        $head  = [];
        foreach ($pola as $fk => $f) $head[] = evk_csv_opt_label($fk, $f);
        foreach ($langs as $l) foreach ($pola as $fk => $f) if (evk_rep_tl_type_on($f)) $head[] = evk_csv_opt_label($fk, $f) . ' [' . $l . ']';
        $out = [$head];
        foreach ($rows as $row) {
            $w = [];
            foreach ($pola as $fk => $f) $w[] = evk_csv_opt_cell($f, $row[$fk] ?? '');
            foreach ($langs as $l) foreach ($pola as $fk => $f) if (evk_rep_tl_type_on($f)) $w[] = (string) ($row['evk_tl_' . $l . '__' . $fk] ?? '');
            $out[] = $w;
        }
        return $out;
    }
    $out = [['Pole', 'Wartość']];
    foreach ((array) ($group['fields'] ?? []) as $fk => $f) {
        $t = (string) ($f['type'] ?? 'text');
        if (evk_rep_is_layout($t) || $t === 'calc') continue;
        $fk = (string) $fk;
        if ($t === 'repeater') {
            $rows  = is_array($stored[$fk] ?? null) ? $stored[$fk] : [];
            $out[] = [evk_csv_opt_label($fk, $f), evk_csv_rep_export($rows, (array) ($f['sub_fields'] ?? []))];
            continue;
        }
        $out[] = [evk_csv_opt_label($fk, $f), evk_csv_opt_cell($f, $stored[$fk] ?? '')];
    }
    foreach (evk_csv_opt_used_langs([$stored]) as $l) {
        foreach ((array) ($group['fields'] ?? []) as $fk => $f) {
            $v = $stored['evk_tl_' . $l . '__' . $fk] ?? '';
            if (is_array($f) && evk_rep_tl_type_on($f) && is_scalar($v) && (string) $v !== '') $out[] = [evk_csv_opt_label((string) $fk, $f) . ' [' . $l . ']', (string) $v];
        }
    }
    return $out;
}

/**
 * Import pliku do grupy strony ustawień. Zwraca wynik do komunikatu.
 *
 * @param list<list<string>> $tab wiersze CSV (pierwszy to nagłówek)
 * @return array{ok:bool,blad:string,wiersze:int,pola:int,warn:list<string>,wartosc:array}
 */
function evk_csv_opt_import(array $group, array $tab, $stored, string $tryb): array {
    $wynik  = ['ok' => false, 'blad' => '', 'wiersze' => 0, 'pola' => 0, 'warn' => [], 'wartosc' => []];
    $stored = is_array($stored) ? $stored : [];
    $fields = (array) ($group['fields'] ?? []);
    $pola   = evk_csv_rep_data_fields($fields);
    $langs  = function_exists('evk_rep_tl_langs') ? evk_rep_tl_langs() : [];
    $head   = array_map(static fn($h) => trim((string) $h), (array) array_shift($tab));
    $warn   = [];

    if (evk_rep_is_repeater($group)) {
        /* Tabela: kolumna → [klucz pola, język]. */
        $kol = [];
        foreach ($head as $i => $h) {
            [$nazwa, $l] = evk_csv_opt_split_lang($h);
            $fk = evk_csv_rep_key($nazwa, $pola);
            if ($fk === '' || ($l !== '' && (!isset($langs[$l]) || !evk_rep_tl_type_on($pola[$fk])))) { if ($h !== '') $warn[] = 'Kolumna „' . $h . '”: nie ma takiego pola w grupie — pominięta'; continue; }
            $kol[$i] = [$fk, $l];
        }
        if (!$kol) { $wynik['blad'] = 'Żadna kolumna nie pasuje do pól grupy — to plik innej grupy albo grupa pojedyncza (kolumny „Pole” i „Wartość”).'; return $wynik; }
        $raw = [];
        foreach ($tab as $n => $w) {
            if (evk_csv_is_blank_row($w)) continue;
            $r = [];
            foreach ($kol as $i => [$fk, $l]) {
                $cell = (string) ($w[$i] ?? '');
                if ($l !== '') { if (trim($cell) !== '') $r['evk_tl_' . $l . '__' . $fk] = $cell; continue; }
                $r[$fk] = evk_csv_opt_raw($pola[$fk] + ['key' => $fk], $cell, $warn, 'Wiersz ' . ((int) $n + 2));
            }
            $raw[] = $r;
        }
        $clean = evk_rep_sanitize_rows($fields, $raw);
        $old   = array_values(array_filter($stored, 'is_array'));
        $wynik['wartosc'] = $tryb === 'append' ? array_merge($old, $clean) : evk_csv_rep_keep_sources($clean, $old);
        $wynik['wiersze'] = count($clean);
    } else {
        /* Pary: nazwa pola (etykieta albo klucz, z „[en]” dla tłumaczenia) → wartość. */
        $wszystkie = [];
        foreach ($fields as $fk => $f) if (!evk_rep_is_layout((string) ($f['type'] ?? 'text')) && ($f['type'] ?? '') !== 'calc') $wszystkie[(string) $fk] = $f;
        $pary = $head ? [$head] : [];
        if ($head && mb_strtolower($head[0]) === 'pole') $pary = []; // nagłówek z eksportu
        $pary = array_merge($pary, $tab);
        $raw = []; $ruszone = []; $tl = [];
        foreach ($pary as $n => $w) {
            if (evk_csv_is_blank_row($w)) continue;
            [$nazwa, $l] = evk_csv_opt_split_lang((string) ($w[0] ?? ''));
            $cell = (string) ($w[1] ?? '');
            $fk   = evk_csv_rep_key($nazwa, $wszystkie);
            if ($fk === '' || ($l !== '' && (!isset($langs[$l]) || !evk_rep_tl_type_on($wszystkie[$fk])))) { $warn[] = 'Pole „' . ($w[0] ?? '') . '”: nie ma takiego pola w grupie — pominięte'; continue; }
            if ($l !== '') { $raw['evk_tl_' . $l . '__' . $fk] = $cell; $tl[] = 'evk_tl_' . $l . '__' . $fk; continue; }
            $f = $wszystkie[$fk];
            if (($f['type'] ?? '') === 'repeater') {
                if (trim($cell) === '') { $raw[$fk] = []; $ruszone[] = $fk; continue; }
                $wiersze = evk_csv_rep_decode($cell, (array) ($f['sub_fields'] ?? []), $warn, 'Pole „' . evk_csv_opt_label($fk, $f) . '”');
                if ($wiersze === null) { $warn[] = 'Pole „' . evk_csv_opt_label($fk, $f) . '”: to nie jest lista wierszy w JSON — pominięte'; continue; }
                $raw[$fk] = $wiersze;
            } else {
                $raw[$fk] = evk_csv_opt_raw($f + ['key' => $fk], $cell, $warn, 'Pole');
            }
            $ruszone[] = $fk;
        }
        if (!$ruszone && !$tl) { $wynik['blad'] = 'Żaden wiersz nie pasuje do pól grupy — to plik innej grupy albo grupa-repeater (tabela).'; return $wynik; }
        /* Podstawa tekstu tłumaczenia: wartość z pliku albo ta, która zostaje. */
        foreach ($tl as $k) { $p = evk_rep_tl_parse_key($k); if ($p && !isset($raw[$p[1]])) $raw[$p[1]] = $stored[$p[1]] ?? ''; }
        $clean = evk_rep_sanitize_group_values($fields, $raw);
        $nowa  = $stored;
        foreach (array_unique($ruszone) as $fk) {
            $nowa[$fk] = $clean[$fk] ?? '';
            if (($wszystkie[$fk]['type'] ?? '') === 'repeater') {
                $old = is_array($stored[$fk] ?? null) ? array_values($stored[$fk]) : [];
                $nowa[$fk] = $tryb === 'append' ? array_merge($old, (array) $clean[$fk]) : evk_csv_rep_keep_sources((array) $clean[$fk], $old);
            }
        }
        foreach ($tl as $k) {
            if (isset($clean[$k]) && $clean[$k] !== '') {
                $bez = ($stored[$k] ?? null) === $clean[$k] && isset($stored[$k . '__zrodlo']);
                $nowa[$k] = $clean[$k];
                $nowa[$k . '__zrodlo'] = $bez ? $stored[$k . '__zrodlo'] : ($clean[$k . '__zrodlo'] ?? '');
            } else {
                unset($nowa[$k], $nowa[$k . '__zrodlo']);
            }
        }
        $wynik['wartosc'] = function_exists('evk_rep_calc_apply_group_values') ? evk_rep_calc_apply_group_values($fields, $nowa) : $nowa;
        $wynik['pola'] = count(array_unique($ruszone)) + count($tl);
    }
    $wynik['ok']   = true;
    $wynik['warn'] = array_values(array_unique($warn));
    return $wynik;
}

/** Plik CSV → wiersze (separator i kodowanie z formularza albo wykryte). */
function evk_csv_opt_read(string $plik, string $delim_in, string $enc): array {
    $fh = fopen($plik, 'r');
    if (!$fh) return [];
    $first = (string) fgets($fh);
    $first = evk_csv_strip_bom($first);
    $delim = $delim_in === 'auto' ? evk_csv_sniff_delimiter($first) : evk_csv_delim_char($delim_in);
    rewind($fh);
    $out = [];
    while (($w = fgetcsv($fh, 0, $delim)) !== false) {
        $out[] = array_map(static function ($v) use ($enc) { return evk_csv_conv((string) $v, $enc); }, $w);
    }
    fclose($fh);
    if ($out) $out[0][0] = evk_csv_strip_bom((string) ($out[0][0] ?? ''));
    return $out;
}

// Import pliku do grupy strony ustawień (jeden przebieg — wartości stron są małe).
add_action('admin_init', function () {
    if (empty($_POST['evk_csv_opt_import'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_csv_opt_import', 'evk_csv_opt_import_nonce');

    $gk      = sanitize_key($_POST['evk_csv_opt_import_group'] ?? '');
    $targets = evk_csv_option_group_targets();
    if (!isset($targets[$gk])) { evk_csv_notice('error', 'Wybierz grupę strony ustawień.'); evk_csv_redirect(); }
    if (empty($_FILES['evk_csv_opt_file']['tmp_name']) || !is_uploaded_file($_FILES['evk_csv_opt_file']['tmp_name'])) {
        evk_csv_notice('error', 'Nie wybrano pliku lub przesyłanie nie powiodło się.');
        evk_csv_redirect();
    }
    $tab = evk_csv_opt_read((string) $_FILES['evk_csv_opt_file']['tmp_name'], (string) ($_POST['evk_csv_opt_import_delim'] ?? 'auto'),
        sanitize_text_field($_POST['evk_csv_opt_import_enc'] ?? 'auto'));
    if (!$tab) { evk_csv_notice('error', 'Plik jest pusty.'); evk_csv_redirect(); }

    $tryb  = ($_POST['evk_csv_opt_import_mode'] ?? '') === 'append' ? 'append' : 'replace';
    $group = $targets[$gk]['group'];
    $old   = get_option('evk_rep_opt_' . $gk, []);
    $w     = evk_csv_opt_import($group, $tab, $old, $tryb);
    if (!$w['ok']) { evk_csv_notice('error', $w['blad']); evk_csv_redirect(); }
    update_option('evk_rep_opt_' . $gk, $w['wartosc'], false);
    $msg = evk_rep_is_repeater($group)
        ? sprintf('Grupa „%s”: zaimportowano wierszy: %d (%s).', $targets[$gk]['label'], $w['wiersze'], $tryb === 'append' ? 'dopisane na końcu' : 'zastąpiły poprzednie')
        : sprintf('Grupa „%s”: zaktualizowano pól: %d.', $targets[$gk]['label'], $w['pola']);
    evk_csv_notice($w['warn'] ? 'warning' : 'success', $msg, $w['warn']);
    evk_csv_redirect();
});

/** Formularz importu stron ustawień (pod eksportem). */
function evk_csv_render_opt_import(): void {
    $targets = evk_csv_option_group_targets();
    if (!$targets) return;
    ?>
    <div class="evk-settings-group" id="evk-csv-opt-import">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-database-import" style="vertical-align:text-bottom;color:#2563eb;"></span> Import wartości stron opcji z CSV</h2>
        <p style="margin-top:0;color:#475569;">Ten sam format co eksport wyżej — plik z eksportu wraca 1:1. Grupa-repeater: tabela (nagłówki to etykiety albo klucze pól).
            Grupa pojedyncza: „Pole | Wartość”; zmieniają się tylko pola obecne w pliku, a pole-repeater to lista wierszy w JSON.
            Tłumaczenie: kolumna albo wiersz „Etykieta [en]”.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('evk_csv_opt_import', 'evk_csv_opt_import_nonce'); ?>
            <p style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;margin-top:4px;">
                <label>Grupa pól<br>
                    <select name="evk_csv_opt_import_group">
                        <?php foreach ($targets as $gk => $d): ?>
                        <option value="<?php echo esc_attr($gk); ?>"><?php echo esc_html($d['label']); ?> — <?php echo esc_html($d['page']); ?> (<?php echo $d['repeater'] ? 'repeater' : 'pojedyncza'; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Plik CSV<br><input type="file" name="evk_csv_opt_file" accept=".csv,text/csv" required></label>
                <label>Wiersze repeaterów<br>
                    <select name="evk_csv_opt_import_mode">
                        <option value="replace">Zastąp wiersze</option>
                        <option value="append">Dopisz na końcu</option>
                    </select>
                </label>
                <label>Separator<br>
                    <select name="evk_csv_opt_import_delim">
                        <option value="auto">Wykryj</option>
                        <option value="comma">Przecinek ( , )</option>
                        <option value="semicolon">Średnik ( ; )</option>
                        <option value="tab">Tabulator</option>
                    </select>
                </label>
                <label>Kodowanie<br>
                    <select name="evk_csv_opt_import_enc">
                        <option value="auto">Wykryj</option>
                        <option value="UTF-8">UTF-8</option>
                        <option value="Windows-1250">Windows-1250</option>
                        <option value="ISO-8859-2">ISO-8859-2</option>
                    </select>
                </label>
            </p>
            <button type="submit" name="evk_csv_opt_import" value="1" class="button button-primary"><span class="dashicons dashicons-upload" style="vertical-align:text-bottom;"></span> Importuj do grupy</button>
        </form>
    </div>
    <?php
}
