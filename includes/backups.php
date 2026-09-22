<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — automatyczne kopie zapasowe KONFIGURACJI.
 *
 * Wtyczka jest jedynym źródłem definicji pól (CPT evk_field_group), CPT/taksonomii,
 * stron ustawień i ich wartości. Jeden błędny zapis schematu albo import z „nadpisz"
 * potrafi skasować pracę. Ten moduł przy każdej ZMIANIE STRUKTURY zrzuca pełny eksport
 * (evk_tools_build_export) do pliku JSON w chronionym katalogu w uploads i rotuje kopie.
 *
 * TRZY ZASADY, KTÓRE TRZYMAJĄ TEN MODUŁ PRZY ŻYCIU (naruszenie każdej z nich raz już
 * kosztowało komplet konfiguracji na produkcji):
 *
 *  1. KOPIA POWSTAJE PRZED ZMIANĄ, NIE PO. Zrzut na samym 'shutdown' zapisuje stan JUŻ
 *     nadpisany — po błędnym zapisie w pliku ląduje pustka, a nie to, co zginęło.
 *     Dlatego pierwsza zmiana struktury w żądaniu odpala snapshot „przed" (na filtrze
 *     pre_update_option_*), a shutdown dokłada stan „po".
 *  2. KATALOG KOPII ODNAJDUJE SIĘ SAM. Nazwa katalogu ma losowy sufiks i siedzi w opcji
 *     evk_backups_dir. Gdy ta opcja zniknie (odinstalowanie, przenosiny bazy, ręczne
 *     porządki), wymyślenie nowej nazwy sprawia, że kopie LEŻĄ NA DYSKU, ale dla wtyczki
 *     nie istnieją. Dlatego zanim wylosujemy nazwę, przeszukujemy uploads.
 *  3. ROTACJA NIE KASUJE OSTATNIEJ NIEPUSTEJ KOPII. Seria błędnych zapisów generuje serię
 *     pustych kopii — przy zwykłej rotacji „najstarsze out" wypchnęłyby jedyny ratunek.
 */

const EVK_BACKUPS_MAX      = 30;                // ile kopii trzymamy (rotacja najstarszych)
const EVK_BACKUPS_DIR_OPT  = 'evk_backups_dir'; // opcja z nazwą katalogu (z losowym sufiksem)
const EVK_BACKUPS_DIR_PREFIX = 'evk-backups-';

// =========================================================================
// KATALOG + OCHRONA
// =========================================================================

/**
 * Ścieżka + URL katalogu kopii (tworzy przy pierwszym użyciu, z ochroną dostępu).
 * Nazwa ma losowy sufiks (utrudnia zgadnięcie URL-a — eksport bywa wrażliwy).
 * Zwraca ['path' => …, 'url' => …] albo null, gdy uploads niedostępne.
 */
function evk_backups_dir_info(): ?array {
    $up = wp_get_upload_dir();
    if (!empty($up['error']) || empty($up['basedir'])) return null;

    $name = get_option(EVK_BACKUPS_DIR_OPT, '');
    if (!evk_backups_is_dir_name($name)) {
        // Opcja przepadła. Wylosowanie nowej nazwy TU jest tym momentem, w którym
        // dotychczasowe kopie znikają z panelu (plik po pliku dalej leżą w uploads).
        // Dlatego najpierw szukamy starego katalogu i wracamy do niego.
        $name = evk_backups_adopt_existing_dir($up['basedir']);
        if ($name === '') $name = EVK_BACKUPS_DIR_PREFIX . wp_generate_password(12, false, false);
        update_option(EVK_BACKUPS_DIR_OPT, $name, false);
    }

    $path = trailingslashit($up['basedir']) . $name;
    $url  = trailingslashit($up['baseurl']) . $name;

    if (!is_dir($path)) {
        if (!wp_mkdir_p($path)) return null;
    }
    evk_backups_ensure_protection($path);

    return ['path' => trailingslashit($path), 'url' => trailingslashit($url)];
}

/** Czy to bezpieczna nazwa katalogu kopii (bez ścieżek, z naszym prefiksem). */
function evk_backups_is_dir_name($name): bool {
    return is_string($name) && (bool) preg_match('/^' . preg_quote(EVK_BACKUPS_DIR_PREFIX, '/') . '[A-Za-z0-9]{4,32}$/', $name);
}

/**
 * Wszystkie katalogi kopii w uploads — także osierocone po poprzednich instalacjach.
 * [ ['name','path','count','newest'] ], najbogatsze pierwsze.
 */
function evk_backups_scan_dirs(?string $basedir = null): array {
    if ($basedir === null) {
        $up = wp_get_upload_dir();
        if (!empty($up['error']) || empty($up['basedir'])) return [];
        $basedir = $up['basedir'];
    }
    $out = [];
    foreach ((array) glob(trailingslashit($basedir) . EVK_BACKUPS_DIR_PREFIX . '*', GLOB_ONLYDIR) as $dir) {
        $name = basename($dir);
        if (!evk_backups_is_dir_name($name)) continue;
        $files  = (array) glob(trailingslashit($dir) . 'evk-config-*.json');
        $newest = 0;
        foreach ($files as $f) {
            if (is_file($f)) $newest = max($newest, (int) filemtime($f));
        }
        $out[] = ['name' => $name, 'path' => trailingslashit($dir), 'count' => count($files), 'newest' => $newest];
    }
    usort($out, function ($a, $b) {
        if ($a['count'] !== $b['count']) return $b['count'] <=> $a['count'];
        return $b['newest'] <=> $a['newest'];
    });
    return $out;
}

/** Nazwa istniejącego katalogu kopii do przejęcia ('' gdy żadnego nie ma). */
function evk_backups_adopt_existing_dir(string $basedir): string {
    foreach (evk_backups_scan_dirs($basedir) as $dir) {
        if ($dir['count'] > 0) return $dir['name'];
    }
    return '';
}

/** Pliki blokujące podejrzenie/listowanie katalogu przez WWW (Apache/IIS + brak index). */
function evk_backups_ensure_protection(string $path): void {
    $path = trailingslashit($path);
    if (!file_exists($path . 'index.php')) {
        @file_put_contents($path . 'index.php', "<?php\n// Silence is golden.\n");
    }
    if (!file_exists($path . '.htaccess')) {
        $ht = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";
        @file_put_contents($path . '.htaccess', $ht);
    }
    if (!file_exists($path . 'web.config')) {
        @file_put_contents($path . 'web.config', "<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n");
    }
}

// =========================================================================
// ZAPIS + ROTACJA
// =========================================================================

/**
 * Zaplanuj zrzut kopii RAZ w tym żądaniu (na shutdown — łapie finalny stan).
 * To jest kopia „PO zmianie". Kopię „PRZED zmianą" robi evk_backups_snapshot_before().
 */
function evk_backups_schedule(): void {
    static $scheduled = false;
    if ($scheduled || defined('EVK_UNINSTALLING')) return;
    $scheduled = true;
    add_action('shutdown', 'evk_backups_write_after');
}

/** Wrapper na shutdown — domyślny powód zrzutu. */
function evk_backups_write_after(): void {
    evk_backups_write_now('after-change');
}

/**
 * Kopia stanu SPRZED pierwszej zmiany struktury w tym żądaniu.
 *
 * Bez niej moduł jest ozdobą: zrzut robiony po zapisie utrwala skutek błędu, a nie
 * stan, do którego chce się wrócić. Raz na żądanie — masowa operacja (import, zapis
 * całego ekranu typów) ma jeden punkt odniesienia „jak było".
 */
function evk_backups_snapshot_before(): void {
    static $done = false;
    if ($done || defined('EVK_UNINSTALLING')) return;
    $done = true;
    evk_backups_write_now('before-change');
}

/**
 * Zbuduj i zapisz kopię teraz. Zwraca nazwę pliku albo '' przy błędzie/niedostępności.
 * Ciche — nigdy nie przerywa zapisu, który je wywołał (błąd FS = brak kopii, nie fatal).
 */
function evk_backups_write_now(string $reason = 'manual'): string {
    if (!function_exists('evk_tools_build_export')) return '';

    $export = evk_tools_build_export();

    // Sejf w bazie PRZED plikiem: zrzut do uploads bywa niemożliwy (brak praw do zapisu,
    // katalog skasowany przez starą wersję uninstall.php), a wtedy brak kopii byłby
    // zupełny. Sejf nie zależy od systemu plików.
    if (function_exists('evk_vault_store')) evk_vault_store($export, $reason);

    $info = evk_backups_dir_info();
    if (!$info) return '';

    // Metadane na POCZĄTKU pliku — lista kopii czyta je z pierwszego kilobajta,
    // bez dekodowania całego JSON-a (przy 30 kopiach to różnica rzędu wielkości).
    $data = array_merge([
        'backup_reason' => $reason,
        'backup_counts' => evk_backups_counts($export),
    ], $export);

    $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return '';

    // Sufiks losowy w nazwie chroni przed kolizją przy dwóch zapisach w tej samej sekundzie.
    $file = 'evk-config-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false) . '.json';
    if (@file_put_contents($info['path'] . $file, $json, LOCK_EX) === false) return '';

    evk_backups_rotate();
    return $file;
}

/** Ile czego jest w eksporcie — po tym poznaje się kopię pustą (czyli po katastrofie). */
function evk_backups_counts(array $export): array {
    return [
        'groups'         => count((array) ($export['groups'] ?? [])),
        'post_types'     => count((array) ($export['post_types'] ?? [])),
        'taxonomies'     => count((array) ($export['taxonomies'] ?? [])),
        'settings_pages' => count((array) ($export['settings_pages'] ?? [])),
        'option_values'  => count((array) ($export['option_values'] ?? [])),
    ];
}

/** Czy kopia zawiera jakąkolwiek konfigurację (sumarycznie). */
function evk_backups_is_empty(array $counts): bool {
    foreach (['groups', 'post_types', 'taxonomies', 'settings_pages'] as $k) {
        if (!empty($counts[$k])) return false;
    }
    return true;
}

/**
 * Usuń najstarsze kopie ponad limit EVK_BACKUPS_MAX.
 *
 * Z jednym wyjątkiem: NAJNOWSZA NIEPUSTA kopia nie podlega rotacji. Po serii błędnych
 * zapisów limit wypełnia się kopiami pustymi i zwykła rotacja „FIFO" wypchnęłaby jedyny
 * plik, z którego da się cokolwiek odtworzyć.
 */
function evk_backups_rotate(int $max = EVK_BACKUPS_MAX): void {
    $list = evk_backups_list();
    if (count($list) <= $max) return;

    $guard = '';
    foreach ($list as $b) { // list() sortuje malejąco po czasie
        if (!evk_backups_is_empty($b['counts'])) { $guard = $b['file']; break; }
    }

    foreach (array_slice($list, $max) as $b) {
        if ($b['file'] === $guard) continue;
        @unlink($b['path']);
    }
}

/**
 * Metadane kopii bez dekodowania całego pliku — 'reason' i 'counts' siedzą w nagłówku.
 * Starsze kopie (sprzed 1.68.0) nagłówka nie mają: wtedy dekodujemy plik w całości,
 * bo puste liczniki pokazane obok kopii z danymi myliłyby przy wyborze do przywrócenia.
 */
function evk_backups_read_meta(string $path): array {
    $meta = ['reason' => '', 'counts' => ['groups' => 0, 'post_types' => 0, 'taxonomies' => 0, 'settings_pages' => 0, 'option_values' => 0]];

    $head = '';
    $fh   = @fopen($path, 'rb');
    if ($fh) { $head = (string) fread($fh, 1024); fclose($fh); }

    if ($head !== '' && preg_match('/"backup_reason"\s*:\s*"([a-z-]+)"/', $head, $m)) {
        $meta['reason'] = $m[1];
        foreach (array_keys($meta['counts']) as $k) {
            if (preg_match('/"' . $k . '"\s*:\s*(\d+)/', $head, $c)) $meta['counts'][$k] = (int) $c[1];
        }
        return $meta;
    }

    // Kopia w starym formacie — pełne dekodowanie, ale tylko dla rozsądnych rozmiarów.
    if ((int) @filesize($path) > 4 * MB_IN_BYTES) return $meta;
    $data = json_decode((string) @file_get_contents($path), true);
    if (is_array($data)) $meta['counts'] = evk_backups_counts($data);
    return $meta;
}

/** Lista kopii [ ['file','path','time','size','reason','counts'] ], najnowsze pierwsze. */
function evk_backups_list(): array {
    $info = evk_backups_dir_info();
    if (!$info) return [];
    $out = [];
    foreach ((array) glob($info['path'] . 'evk-config-*.json') as $p) {
        if (!is_file($p)) continue;
        $meta  = evk_backups_read_meta($p);
        $out[] = [
            'file'   => basename($p),
            'path'   => $p,
            'time'   => (int) filemtime($p),
            'size'   => (int) filesize($p),
            'reason' => $meta['reason'],
            'counts' => $meta['counts'],
        ];
    }
    usort($out, function ($a, $b) { return $b['time'] <=> $a['time']; });
    return $out;
}

/** Bezpieczna ścieżka pliku kopii z nazwy z formularza (nie wychodzi poza katalog). */
function evk_backups_resolve(string $file): string {
    $file = basename($file); // zdejmij ewentualne ../
    // Sufiks z wp_generate_password(4,false,false) = A-Za-z0-9 (mieszana wielkość!).
    if (!preg_match('/^evk-config-[0-9]{8}-[0-9]{6}-[A-Za-z0-9]{4}\.json$/', $file)) return '';
    $info = evk_backups_dir_info();
    if (!$info) return '';
    $path = $info['path'] . $file;
    return is_file($path) ? $path : '';
}

/** Czytelna etykieta powodu zrzutu. */
function evk_backups_reason_label(string $reason): string {
    $map = [
        'before-change' => 'przed zmianą',
        'after-change'  => 'po zmianie',
        'before-restore'=> 'przed przywróceniem',
        'deactivate'    => 'przed wyłączeniem wtyczki',
        'activate'      => 'przy aktywacji wtyczki',
        'uninstall'     => 'przed odinstalowaniem',
        'heartbeat'     => 'zrzut dobowy',
        'manual'        => 'ręczna',
    ];
    return $map[$reason] ?? '—';
}

// =========================================================================
// WYZWALACZE — zmiany STRUKTURY (nie każda zmiana wartości opcji)
// =========================================================================

// Zapis grupy pól (schemat) — po zapisie treści (builder.php prio 10, cache 20).
add_action('save_post_evk_field_group', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    evk_backups_schedule();
}, 100);

// Kosz / przywrócenie / trwałe usunięcie grupy pól. Kasowanie robi snapshot PRZED —
// po wykonaniu usunięcia grupy nie ma już czego zrzucać.
add_action('before_delete_post', function ($id) {
    if (get_post_type($id) !== 'evk_field_group') return;
    evk_backups_snapshot_before();
    evk_backups_schedule();
});
add_action('trashed_post', function ($id) { if (get_post_type($id) === 'evk_field_group') evk_backups_schedule(); });
add_action('untrashed_post', function ($id) { if (get_post_type($id) === 'evk_field_group') evk_backups_schedule(); });

// Zmiana definicji CPT / taksonomii / stron ustawień (dowolne źródło, także import).
foreach (['evk_custom_post_types', 'evk_taxonomies', 'evk_rep_settings_pages'] as $evk_bopt) {
    // PRZED zapisem — filtr pre_update_option_* biegnie, zanim nowa wartość trafi do bazy.
    add_filter("pre_update_option_{$evk_bopt}", function ($value, $old_value) {
        if ($value !== $old_value) evk_backups_snapshot_before();
        return $value;
    }, 10, 2);
    add_action("update_option_{$evk_bopt}", 'evk_backups_schedule');
    add_action("add_option_{$evk_bopt}",    'evk_backups_schedule');
}
unset($evk_bopt);

/**
 * Kopia przed wyłączeniem wtyczki.
 *
 * „Aktualizacja ręczna" to u większości ludzi: wyłącz → usuń → wgraj nowy ZIP. Krok
 * „usuń" uruchamia uninstall.php, więc ostatnia chwila na zrzut jest TUTAJ. Plik zostaje
 * w uploads i po ponownej instalacji odnajduje go evk_backups_adopt_existing_dir().
 */
function evk_backups_on_deactivate(): void {
    evk_backups_write_now('deactivate');
}
register_deactivation_hook(EVK_REP_FILE, 'evk_backups_on_deactivate');

// =========================================================================
// AKCJE ADMINA — ręczna kopia / przywróć / pobierz / usuń / zmień katalog
// =========================================================================

// Ręczna kopia teraz.
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_create'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');
    $file = evk_backups_write_now('manual');
    if ($file !== '') evk_tools_set_notice('success', 'Utworzono kopię zapasową konfiguracji: ' . $file);
    else              evk_tools_set_notice('error', 'Nie udało się utworzyć kopii (katalog uploads niedostępny do zapisu?).');
    evk_tools_redirect();
});

// Przywróć kopię (import z nadpisaniem WSZYSTKICH sekcji). Przed przywróceniem robimy
// świeży snapshot bieżącego stanu — żeby dało się cofnąć nietrafione przywrócenie.
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_restore'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');

    $path = evk_backups_resolve((string) ($_POST['evk_backup_file'] ?? ''));
    if ($path === '') { evk_tools_set_notice('error', 'Nie znaleziono wskazanej kopii.'); evk_tools_redirect(); }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data['plugin'] ?? '') !== 'evoke-fields') {
        evk_tools_set_notice('error', 'Plik kopii jest uszkodzony lub w nieznanym formacie.');
        evk_tools_redirect();
    }

    evk_backups_write_now('before-restore'); // snapshot „sprzed przywrócenia"
    $r = evk_tools_run_import($data, true, null); // null = wszystkie sekcje
    evk_tools_set_notice('success', sprintf(
        'Przywrócono kopię z %s. Grupy: utworzono %d, zaktualizowano %d. Bieżący stan sprzed przywrócenia zapisano jako nową kopię. Odśwież, aby menu się zaktualizowało.',
        esc_html(basename($path)), $r['created'], $r['updated']
    ));
    evk_tools_redirect();
});

// Usuń wskazaną kopię.
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_delete'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');
    $path = evk_backups_resolve((string) ($_POST['evk_backup_file'] ?? ''));
    if ($path !== '' && @unlink($path)) evk_tools_set_notice('success', 'Kopia usunięta.');
    else                                evk_tools_set_notice('error', 'Nie udało się usunąć kopii.');
    evk_tools_redirect();
});

// Pobierz wskazaną kopię (stream JSON).
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_download'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');
    $path = evk_backups_resolve((string) ($_POST['evk_backup_file'] ?? ''));
    if ($path === '') { evk_tools_set_notice('error', 'Nie znaleziono wskazanej kopii.'); evk_tools_redirect(); }
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . basename($path));
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
});

// Przełącz aktywny katalog kopii (ratunek po utracie opcji evk_backups_dir).
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_use_dir'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');

    $name = (string) ($_POST['evk_backup_dir'] ?? '');
    if (!evk_backups_is_dir_name($name)) {
        evk_tools_set_notice('error', 'Nieprawidłowa nazwa katalogu kopii.');
        evk_tools_redirect();
    }
    $up = wp_get_upload_dir();
    if (!empty($up['error']) || empty($up['basedir']) || !is_dir(trailingslashit($up['basedir']) . $name)) {
        evk_tools_set_notice('error', 'Wskazany katalog kopii nie istnieje.');
        evk_tools_redirect();
    }
    update_option(EVK_BACKUPS_DIR_OPT, $name, false);
    evk_tools_set_notice('success', 'Aktywny katalog kopii: ' . $name . '. Lista poniżej pokazuje teraz jego zawartość.');
    evk_tools_redirect();
});

// Przełącznik „usuń dane przy odinstalowaniu".
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_save_uninstall'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');
    update_option('evk_rep_delete_data_on_uninstall', empty($_POST['evk_rep_delete_data_on_uninstall']) ? 0 : 1, false);
    evk_tools_set_notice('success', 'Zapisano zachowanie przy odinstalowaniu wtyczki.');
    evk_tools_redirect();
});

// =========================================================================
// SEKCJA W NARZĘDZIACH
// =========================================================================

/** Renderuje sekcję „Kopie zapasowe" na stronie Narzędzia (wołane z evk_tools_page). */
function evk_backups_render_section(): void {
    $list    = evk_backups_list();
    $info    = evk_backups_dir_info();
    $active  = (string) get_option(EVK_BACKUPS_DIR_OPT, '');
    $dirs    = evk_backups_scan_dirs();
    $others  = array_values(array_filter($dirs, function ($d) use ($active) { return $d['name'] !== $active && $d['count'] > 0; }));
    $wipe_on = (int) get_option('evk_rep_delete_data_on_uninstall', 0);
    ?>
    <details class="evk-settings-group evk-acc-group">
        <summary class="evk-settings-group-title" style="cursor:pointer;display:flex;align-items:center;gap:6px;">
            <span class="dashicons dashicons-backup" style="color:#2563eb;"></span>
            <span>Kopie zapasowe konfiguracji</span>
            <span class="dashicons dashicons-arrow-down-alt2 evk-acc-chevron" style="margin-left:auto;transition:transform .2s;"></span>
        </summary>
        <div>
            <p style="margin-top:0;color:#475569;">
                Przy każdej zmianie struktury (grupy pól, typy treści, taksonomie, strony ustawień)
                wtyczka zapisuje pełną kopię konfiguracji w chronionym katalogu w <code>uploads</code> —
                raz ze stanem <strong>przed</strong> zmianą i raz <strong>po</strong> niej. Trzymamy do
                <code><?php echo (int) EVK_BACKUPS_MAX; ?></code> ostatnich (najstarsze są rotowane, ale
                najnowsza niepusta kopia nigdy nie jest kasowana). Przywrócenie działa jak import
                z nadpisaniem wszystkich sekcji; bieżący stan sprzed przywrócenia jest najpierw
                zapisywany jako nowa kopia.
            </p>
            <?php if (!$info): ?>
                <p style="color:#92400e;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                    <span class="dashicons dashicons-warning" style="vertical-align:text-bottom;"></span>
                    Katalog <code>uploads</code> jest niedostępny do zapisu — automatyczne kopie nie działają.
                </p>
            <?php else: ?>
                <p style="margin:0 0 14px;color:#64748b;font-size:12px;">
                    Katalog kopii: <code><?php echo esc_html($active); ?></code>
                </p>
            <?php endif; ?>

            <?php if ($others): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:10px 12px;border-radius:6px;margin:0 0 14px;">
                    <p style="margin:0 0 8px;color:#1e3a8a;">
                        <span class="dashicons dashicons-info-outline" style="vertical-align:text-bottom;"></span>
                        <strong>Znaleziono kopie w innych katalogach</strong> — zwykle zostają po wcześniejszej
                        instalacji wtyczki. Jeśli brakuje Ci kopii, przełącz się na taki katalog.
                    </p>
                    <?php foreach ($others as $d): ?>
                        <form method="post" style="display:flex;align-items:center;gap:8px;margin:4px 0;">
                            <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                            <input type="hidden" name="evk_backup_dir" value="<?php echo esc_attr($d['name']); ?>">
                            <code style="font-size:11px;"><?php echo esc_html($d['name']); ?></code>
                            <span style="color:#475569;font-size:12px;">
                                <?php echo (int) $d['count']; ?> kopii<?php if ($d['newest']): ?>, najnowsza <?php echo esc_html(wp_date('Y-m-d H:i', $d['newest'])); ?><?php endif; ?>
                            </span>
                            <button type="submit" name="evk_backup_use_dir" value="1" class="button button-small">Użyj tego katalogu</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" style="margin:0 0 14px;">
                <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                <button type="submit" name="evk_backup_create" value="1" class="button">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom;"></span> Utwórz kopię teraz
                </button>
            </form>

            <?php if (empty($list)): ?>
                <p style="margin:0;color:#64748b;">Brak kopii — pojawią się po pierwszej zmianie konfiguracji lub po kliknięciu „Utwórz kopię teraz".</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:860px;">
                    <thead><tr>
                        <th>Data utworzenia</th>
                        <th style="width:150px;">Moment</th>
                        <th style="width:190px;">Zawartość</th>
                        <th style="width:80px;">Rozmiar</th>
                        <th style="width:260px;">Akcje</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($list as $b): $empty = evk_backups_is_empty($b['counts']); ?>
                        <tr>
                            <td>
                                <?php echo esc_html(wp_date('Y-m-d H:i:s', $b['time'])); ?>
                                <br><code style="font-size:11px;color:#94a3b8;"><?php echo esc_html($b['file']); ?></code>
                            </td>
                            <td style="color:#475569;"><?php echo esc_html(evk_backups_reason_label($b['reason'])); ?></td>
                            <td style="<?php echo $empty ? 'color:#b91c1c;' : 'color:#166534;'; ?>">
                                <?php if ($empty): ?>
                                    <span class="dashicons dashicons-warning" style="vertical-align:text-bottom;"></span> pusta konfiguracja
                                <?php else: ?>
                                    <?php echo (int) $b['counts']['groups']; ?> grup,
                                    <?php echo (int) $b['counts']['post_types']; ?> CPT,
                                    <?php echo (int) $b['counts']['taxonomies']; ?> taks.
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(size_format($b['size'], 1)); ?></td>
                            <td>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Przywrócić tę kopię? Bieżąca konfiguracja zostanie nadpisana (a jej stan zapisany jako nowa kopia).');">
                                    <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                                    <input type="hidden" name="evk_backup_file" value="<?php echo esc_attr($b['file']); ?>">
                                    <button type="submit" name="evk_backup_restore" value="1" class="button button-small button-primary">Przywróć</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                                    <input type="hidden" name="evk_backup_file" value="<?php echo esc_attr($b['file']); ?>">
                                    <button type="submit" name="evk_backup_download" value="1" class="button button-small">Pobierz</button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć tę kopię?');">
                                    <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                                    <input type="hidden" name="evk_backup_file" value="<?php echo esc_attr($b['file']); ?>">
                                    <button type="submit" name="evk_backup_delete" value="1" class="button button-small button-link-delete">Usuń</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="post" style="margin:16px 0 0;padding-top:14px;border-top:1px solid #e2e8f0;">
                <?php wp_nonce_field('evk_backups', 'evk_backups_nonce'); ?>
                <p style="margin:0 0 6px;color:#475569;">
                    <strong>Odinstalowanie wtyczki.</strong> Domyślnie usunięcie wtyczki z listy wtyczek
                    <strong>zostawia</strong> całą konfigurację w bazie — po ponownej instalacji wszystko wraca.
                    Katalog kopii zapasowych nie jest kasowany w żadnym wypadku.
                </p>
                <label class="evk-switch-wrap">
                    <span class="evk-switch"><input type="checkbox" name="evk_rep_delete_data_on_uninstall" value="1" <?php checked($wipe_on, 1); ?>><span class="evk-switch-slider"></span></span>
                    <span class="evk-switch-label">Usuń konfigurację przy odinstalowaniu wtyczki (niezalecane)</span>
                </label>
                <p style="margin:8px 0 0;">
                    <button type="submit" name="evk_backup_save_uninstall" value="1" class="button button-small">Zapisz</button>
                </p>
            </form>
        </div>
    </details>
    <style>
    .evk-acc-group > summary{list-style:none;}
    .evk-acc-group > summary::-webkit-details-marker{display:none;}
    .evk-acc-group[open] > summary .evk-acc-chevron{transform:rotate(180deg);}
    </style>
    <?php
}
