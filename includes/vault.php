<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — SEJF KONFIGURACJI W BAZIE.
 *
 * PO CO TO ISTNIEJE — HISTORIA JEDNEJ UTRATY DANYCH.
 *
 * Kopie w `uploads` (backups.php) chronią przed błędnym zapisem. Nie chronią przed
 * usunięciem wtyczki, bo `uninstall.php` sprzed 1.68.0 kasował katalog kopii razem
 * z danymi. A usunięcie starej wtyczki zdarza się rutynowo: wgrywasz wersję z innej
 * gałęzi (inna nazwa katalogu → WordPress widzi DRUGĄ wtyczkę), po czym kasujesz
 * starą. WordPress uruchamia wtedy `uninstall.php` Z JEJ katalogu — czyli starą,
 * destrukcyjną wersję pliku. Poprawka w nowej wtyczce jest wtedy bezsilna: kod,
 * który kasuje dane, leży w katalogu, który właśnie znika, i nikt go nie zaktualizował.
 *
 * Stąd ten moduł. Trzyma ostatnie zrzuty konfiguracji W BAZIE, pod nazwą opcji
 * `evk_config_vault`. Nazwa jest dobrana celowo: KAŻDA wersja `uninstall.php`
 * sprzed 1.68.0 kasuje opcje z zamkniętej listy nazw plus wzorce `evk_rep_opt_%`
 * i transienty `_transient_evk_%`. `evk_config_vault` nie pasuje do żadnego z nich,
 * więc przeżywa odinstalowanie dowolnej starszej kopii wtyczki.
 *
 * ZASADA, KTÓREJ NIE WOLNO ZŁAMAĆ: nie dopisuj `evk_config_vault` do listy opcji
 * kasowanych w `uninstall.php` inaczej niż pod świadomym przełącznikiem użytkownika.
 * Sejf, który znika razem z danymi, nie jest sejfem — na tym dokładnie polegał błąd,
 * który ten plik naprawia.
 *
 * Sejf jest ostatnią linią, nie pierwszą: pełna historia i pobieranie plików siedzą
 * w kopiach w `uploads`. Tutaj trzymamy kilka ostatnich zrzutów, żeby po katastrofie
 * dało się wrócić jednym kliknięciem, bez dostępu do FTP i bez kopii bazy od hostingu.
 */

const EVK_VAULT_OPT  = 'evk_config_vault';
const EVK_VAULT_MAX  = 3;                 // ile zrzutów trzymamy w bazie
const EVK_VAULT_LIMIT = 4194304;          // 4 MB — powyżej tego zrzutu nie pakujemy do opcji

// =========================================================================
// ZAPIS
// =========================================================================

/**
 * Dopisz zrzut bieżącej konfiguracji do sejfu (najnowszy pierwszy, rotacja do EVK_VAULT_MAX).
 * Ciche — błąd sejfu nigdy nie może wywrócić zapisu, przy którym powstał.
 *
 * @param array $export Gotowy eksport (evk_tools_build_export) — żeby nie budować go dwa razy.
 */
function evk_vault_store(array $export, string $reason): void {
    if (defined('EVK_UNINSTALLING')) return;

    $counts = function_exists('evk_backups_counts') ? evk_backups_counts($export) : [];

    // Pustego stanu nie wkładamy do sejfu OBOK niepustych: sejf ma mało miejsca
    // (EVK_VAULT_MAX pozycji), więc seria błędnych zapisów wypchnęłaby z niego
    // wszystko, co da się odtworzyć. Kopie w uploads zapisują każdy stan.
    $empty = function_exists('evk_backups_is_empty') ? evk_backups_is_empty($counts) : false;

    if ($empty) return; // pustki nie ma po co archiwizować — sejf ma trzymać to, co da się odtworzyć

    $json = wp_json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > EVK_VAULT_LIMIT) return;

    $vault = evk_vault_list();
    array_unshift($vault, [
        'time'   => time(),
        'reason' => $reason,
        'counts' => $counts,
        'json'   => $json,
    ]);

    update_option(EVK_VAULT_OPT, array_slice($vault, 0, EVK_VAULT_MAX), false);
}

/** Zrzuty w sejfie, najnowsze pierwsze. */
function evk_vault_list(): array {
    $v = get_option(EVK_VAULT_OPT, []);
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

/** Najnowszy zrzut z danymi (albo null). */
function evk_vault_newest_usable(): ?array {
    foreach (evk_vault_list() as $i => $snap) {
        $counts = (array) ($snap['counts'] ?? []);
        if (!function_exists('evk_backups_is_empty') || !evk_backups_is_empty($counts)) {
            $snap['index'] = $i;
            return $snap;
        }
    }
    return null;
}

// =========================================================================
// WYKRYCIE PUSTKI
// =========================================================================

/**
 * Czy konfiguracja jest pusta? Czytane PROSTO z bazy — bez cache'u i bez rejestracji,
 * bo w momencie katastrofy żadnemu pośrednikowi nie można ufać.
 */
function evk_vault_config_is_empty(): bool {
    global $wpdb;

    if (!empty((array) get_option('evk_custom_post_types', []))) return false;
    if (!empty((array) get_option('evk_taxonomies', []))) return false;
    if (!empty((array) get_option('evk_rep_settings_pages', []))) return false;

    $groups = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'evk_field_group' AND post_status != 'trash'");
    return $groups === 0;
}

// =========================================================================
// ALARM + PRZYWRÓCENIE JEDNYM KLIKNIĘCIEM
// =========================================================================

add_action('admin_notices', function () {
    if (!evk_rep_can_manage()) return;
    if (get_transient('evk_vault_dismissed_' . get_current_user_id())) return;
    if (!evk_vault_config_is_empty()) return;

    $snap = evk_vault_newest_usable();
    if (!$snap) return;

    $c = (array) ($snap['counts'] ?? []);
    ?>
    <div class="notice notice-error" style="border-left-width:4px;padding:12px 14px;">
        <h2 style="margin:0 0 6px;font-size:15px;">
            <span class="dashicons dashicons-shield-alt" style="color:#b91c1c;vertical-align:text-bottom;"></span>
            Evoke FIELDS — konfiguracja jest pusta, ale w bazie leży kopia
        </h2>
        <p style="margin:0 0 10px;">
            Nie ma ani jednego typu treści, taksonomii, strony ustawień ani grupy pól.
            Najnowszy zrzut w sejfie pochodzi z <strong><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($snap['time'] ?? 0))); ?></strong>
            i zawiera <strong><?php echo (int) ($c['groups'] ?? 0); ?></strong> grup pól,
            <strong><?php echo (int) ($c['post_types'] ?? 0); ?></strong> typów treści,
            <strong><?php echo (int) ($c['taxonomies'] ?? 0); ?></strong> taksonomii i
            <strong><?php echo (int) ($c['settings_pages'] ?? 0); ?></strong> stron ustawień.
        </p>
        <form method="post" style="display:inline;" onsubmit="return confirm('Przywrócić konfigurację z sejfu?');">
            <?php wp_nonce_field('evk_vault', 'evk_vault_nonce'); ?>
            <input type="hidden" name="evk_vault_index" value="<?php echo (int) ($snap['index'] ?? 0); ?>">
            <button type="submit" name="evk_vault_restore" value="1" class="button button-primary">
                <span class="dashicons dashicons-backup" style="vertical-align:text-bottom;"></span> Przywróć konfigurację
            </button>
        </form>
        <form method="post" style="display:inline;margin-left:6px;">
            <?php wp_nonce_field('evk_vault', 'evk_vault_nonce'); ?>
            <button type="submit" name="evk_vault_dismiss" value="1" class="button">To normalne — ukryj na dobę</button>
        </form>
    </div>
    <?php
});

add_action('admin_init', function () {
    if (empty($_POST['evk_vault_restore'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_vault', 'evk_vault_nonce');

    $vault = evk_vault_list();
    $i     = (int) ($_POST['evk_vault_index'] ?? 0);
    if (!isset($vault[$i]['json'])) {
        evk_tools_set_notice('error', 'Nie znaleziono wskazanego zrzutu w sejfie.');
        evk_tools_redirect();
    }

    $data = json_decode((string) $vault[$i]['json'], true);
    if (!is_array($data) || ($data['plugin'] ?? '') !== 'evoke-fields') {
        evk_tools_set_notice('error', 'Zrzut w sejfie jest uszkodzony.');
        evk_tools_redirect();
    }

    $r = evk_tools_run_import($data, true, null);
    evk_tools_set_notice('success', sprintf(
        'Przywrócono konfigurację z sejfu (%s). Grupy: utworzono %d, zaktualizowano %d. Odśwież, aby menu się zaktualizowało.',
        wp_date('Y-m-d H:i', (int) ($vault[$i]['time'] ?? 0)), $r['created'], $r['updated']
    ));
    evk_tools_redirect();
});

add_action('admin_init', function () {
    if (empty($_POST['evk_vault_dismiss'])) return;
    if (!evk_rep_can_manage()) return;
    check_admin_referer('evk_vault', 'evk_vault_nonce');
    set_transient('evk_vault_dismissed_' . get_current_user_id(), 1, DAY_IN_SECONDS);
    evk_tools_redirect();
});

// =========================================================================
// NAPEŁNIANIE SEJFU — NIEZALEŻNIE OD ZMIAN STRUKTURY
// =========================================================================

/**
 * Sejf napełniany wyłącznie przy zmianach struktury byłby pusty dokładnie tam, gdzie
 * jest najbardziej potrzebny: na stabilnej witrynie, gdzie konfiguracji nikt nie
 * ruszał od miesięcy, a katastrofa przychodzi z zewnątrz (usunięcie starej kopii
 * wtyczki, migracja, pomyłka przy aktualizacji). Dlatego zrzut powstaje też:
 *
 *  - przy aktywacji wtyczki (pierwszy kontakt nowej wersji z istniejącymi danymi),
 *  - raz na dobę, jeśli w sejfie nie ma niczego świeższego.
 *
 * Koszt: jedno odczytanie opcji na żądanie panelu (transient gasi resztę).
 */
function evk_vault_heartbeat(): void {
    if (defined('EVK_UNINSTALLING')) return;
    if (get_transient('evk_vault_beat')) return;
    set_transient('evk_vault_beat', 1, HOUR_IN_SECONDS);

    if (evk_vault_config_is_empty()) return; // nie ma czego zabezpieczać

    $newest = evk_vault_list()[0]['time'] ?? 0;
    if ($newest && (time() - (int) $newest) < DAY_IN_SECONDS) return;

    evk_backups_write_now('heartbeat');
}
add_action('admin_init', 'evk_vault_heartbeat', 5);

/** Aktywacja — pierwszy kontakt tej wersji z danymi, które już są w bazie. */
function evk_vault_on_activate(): void {
    delete_transient('evk_vault_beat');
    if (!evk_vault_config_is_empty()) evk_backups_write_now('activate');
}
register_activation_hook(EVK_REP_FILE, 'evk_vault_on_activate');

// =========================================================================
// SEKCJA W NARZĘDZIACH
// =========================================================================

/** Sejf na stronie Narzędzia — podgląd i przywrócenie bez czekania na alarm. */
function evk_vault_render_section(): void {
    $vault = evk_vault_list();
    ?>
    <div class="evk-settings-group">
        <h2 class="evk-settings-group-title"><span class="dashicons dashicons-shield-alt" style="vertical-align:text-bottom;color:#2563eb;"></span> Sejf konfiguracji w bazie</h2>
        <div>
            <p style="margin-top:0;color:#475569;">
                Ostatnie <code><?php echo (int) EVK_VAULT_MAX; ?></code> zrzuty konfiguracji trzymane
                <strong>w bazie danych</strong>, nie w plikach. Przeżywają usunięcie wtyczki, zmianę nazwy
                jej katalogu i skasowanie katalogu kopii — czyli dokładnie te sytuacje, w których kopie
                w <code>uploads</code> bywają niedostępne. Zrzut powstaje przy każdej zmianie struktury,
                przy aktywacji wtyczki i raz na dobę.
            </p>
            <?php if (empty($vault)): ?>
                <p style="margin:0;color:#64748b;">Sejf jest pusty — pierwszy zrzut powstanie przy najbliższym wejściu do panelu.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:720px;">
                    <thead><tr><th>Data</th><th style="width:150px;">Moment</th><th style="width:200px;">Zawartość</th><th style="width:120px;">Akcje</th></tr></thead>
                    <tbody>
                    <?php foreach ($vault as $i => $snap): $c = (array) ($snap['counts'] ?? []); ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($snap['time'] ?? 0))); ?></td>
                            <td style="color:#475569;"><?php echo esc_html(evk_backups_reason_label((string) ($snap['reason'] ?? ''))); ?></td>
                            <td style="color:#166534;">
                                <?php echo (int) ($c['groups'] ?? 0); ?> grup,
                                <?php echo (int) ($c['post_types'] ?? 0); ?> CPT,
                                <?php echo (int) ($c['taxonomies'] ?? 0); ?> taks.
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Przywrócić konfigurację z tego zrzutu? Bieżąca zostanie nadpisana.');">
                                    <?php wp_nonce_field('evk_vault', 'evk_vault_nonce'); ?>
                                    <input type="hidden" name="evk_vault_index" value="<?php echo (int) $i; ?>">
                                    <button type="submit" name="evk_vault_restore" value="1" class="button button-small">Przywróć</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
