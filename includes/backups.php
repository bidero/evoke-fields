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
 * Zrzut robiony jest RAZ na żądanie (na 'shutdown'), więc masowa operacja (np. import
 * wielu elementów) daje jedną kopię z finalnym stanem. Zero UI przy zapisie — działa
 * w tle; przywracanie / pobieranie / ręczna kopia są w Narzędziach.
 */

const EVK_BACKUPS_MAX      = 30;              // ile kopii trzymamy (rotacja najstarszych)
const EVK_BACKUPS_DIR_OPT  = 'evk_backups_dir'; // opcja z nazwą katalogu (z losowym sufiksem)

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
    if (!is_string($name) || $name === '' || strpos($name, 'evk-backups-') !== 0) {
        $name = 'evk-backups-' . wp_generate_password(12, false, false);
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

/** Zaplanuj zrzut kopii RAZ w tym żądaniu (na shutdown — łapie finalny stan). */
function evk_backups_schedule(): void {
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    add_action('shutdown', 'evk_backups_write_now');
}

/**
 * Zbuduj i zapisz kopię teraz. Zwraca nazwę pliku albo '' przy błędzie/niedostępności.
 * Ciche — nigdy nie przerywa zapisu, który je wywołał (błąd FS = brak kopii, nie fatal).
 */
function evk_backups_write_now(): string {
    if (!function_exists('evk_tools_build_export')) return '';
    $info = evk_backups_dir_info();
    if (!$info) return '';

    $data = evk_tools_build_export();
    $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return '';

    // Sufiks losowy w nazwie chroni przed kolizją przy dwóch zapisach w tej samej sekundzie.
    $file = 'evk-config-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false) . '.json';
    if (@file_put_contents($info['path'] . $file, $json, LOCK_EX) === false) return '';

    evk_backups_rotate();
    return $file;
}

/** Usuń najstarsze kopie ponad limit EVK_BACKUPS_MAX. */
function evk_backups_rotate(int $max = EVK_BACKUPS_MAX): void {
    $list = evk_backups_list();
    if (count($list) <= $max) return;
    // list() sortuje malejąco po czasie; nadmiar to ogon (najstarsze).
    foreach (array_slice($list, $max) as $b) {
        @unlink($b['path']);
    }
}

/** Lista kopii [ ['file','path','time','size'] ], najnowsze pierwsze. */
function evk_backups_list(): array {
    $info = evk_backups_dir_info();
    if (!$info) return [];
    $out = [];
    foreach ((array) glob($info['path'] . 'evk-config-*.json') as $p) {
        if (!is_file($p)) continue;
        $out[] = ['file' => basename($p), 'path' => $p, 'time' => (int) filemtime($p), 'size' => (int) filesize($p)];
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

// =========================================================================
// WYZWALACZE — zmiany STRUKTURY (nie każda zmiana wartości opcji)
// =========================================================================

// Zapis grupy pól (schemat) — po zapisie treści (builder.php prio 10, cache 20).
add_action('save_post_evk_field_group', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    evk_backups_schedule();
}, 100);

// Kosz / przywrócenie / trwałe usunięcie grupy pól.
add_action('before_delete_post', function ($id) { if (get_post_type($id) === 'evk_field_group') evk_backups_schedule(); });
add_action('trashed_post',       function ($id) { if (get_post_type($id) === 'evk_field_group') evk_backups_schedule(); });
add_action('untrashed_post',     function ($id) { if (get_post_type($id) === 'evk_field_group') evk_backups_schedule(); });

// Zmiana definicji CPT / taksonomii / stron ustawień (dowolne źródło, także import).
foreach (['evk_custom_post_types', 'evk_taxonomies', 'evk_rep_settings_pages'] as $evk_bopt) {
    add_action("update_option_{$evk_bopt}", 'evk_backups_schedule');
    add_action("add_option_{$evk_bopt}",    'evk_backups_schedule');
}
unset($evk_bopt);

// =========================================================================
// AKCJE ADMINA — ręczna kopia / przywróć / pobierz / usuń
// =========================================================================

// Ręczna kopia teraz.
add_action('admin_init', function () {
    if (empty($_POST['evk_backup_create'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_backups', 'evk_backups_nonce');
    $file = evk_backups_write_now();
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

    evk_backups_write_now(); // snapshot „sprzed przywrócenia"
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

// =========================================================================
// SEKCJA W NARZĘDZIACH
// =========================================================================

/** Renderuje sekcję „Kopie zapasowe" na stronie Narzędzia (wołane z evk_tools_page). */
function evk_backups_render_section(): void {
    $list = evk_backups_list();
    $info = evk_backups_dir_info();
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
                wtyczka automatycznie zapisuje pełną kopię konfiguracji w chronionym katalogu w
                <code>uploads</code>. Trzymamy do <code><?php echo (int) EVK_BACKUPS_MAX; ?></code> ostatnich
                (najstarsze są rotowane). Przywrócenie działa jak import z nadpisaniem wszystkich sekcji;
                bieżący stan sprzed przywrócenia jest najpierw zapisywany jako nowa kopia.
            </p>
            <?php if (!$info): ?>
                <p style="color:#92400e;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                    <span class="dashicons dashicons-warning" style="vertical-align:text-bottom;"></span>
                    Katalog <code>uploads</code> jest niedostępny do zapisu — automatyczne kopie nie działają.
                </p>
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
                <table class="widefat striped" style="max-width:720px;">
                    <thead><tr>
                        <th>Data utworzenia</th>
                        <th style="width:90px;">Rozmiar</th>
                        <th style="width:260px;">Akcje</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($list as $b): ?>
                        <tr>
                            <td>
                                <?php echo esc_html(wp_date('Y-m-d H:i:s', $b['time'])); ?>
                                <br><code style="font-size:11px;color:#94a3b8;"><?php echo esc_html($b['file']); ?></code>
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
        </div>
    </details>
    <style>
    .evk-acc-group > summary{list-style:none;}
    .evk-acc-group > summary::-webkit-details-marker{display:none;}
    .evk-acc-group[open] > summary .evk-acc-chevron{transform:rotate(180deg);}
    </style>
    <?php
}
