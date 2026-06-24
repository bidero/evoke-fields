<?php
if (!defined('ABSPATH')) exit;

/**
 * Strony ustawień (options pages).
 * Definicja: opcja evk_rep_settings_pages = [ slug => ['label','slug','icon','capability','parent','tabs'=>[ ['label','groups'=>[gkey,...]] ]] ].
 * Wartości grup zapisywane do opcji evk_rep_opt_{gkey} (single: fkey=>wartość; grupa-repeater: tablica wierszy).
 */

// =========================================================================
// DANE / HELPERY
// =========================================================================

function evk_rep_settings_pages(): array {
    $p = get_option('evk_rep_settings_pages', null);
    return is_array($p) ? $p : [];
}

/** Pobierz wartość opcji grupy. Bez $field_key zwraca całą tablicę grupy. */
function evk_rep_get_option(string $group_key, string $field_key = '', $default = '') {
    $vals = get_option('evk_rep_opt_' . $group_key, []);
    if (!is_array($vals)) $vals = [];
    if ($field_key === '') return $vals;
    return array_key_exists($field_key, $vals) ? $vals[$field_key] : $default;
}

function evk_rep_group_on_page(string $gkey, array $page): bool {
    foreach ((array) ($page['tabs'] ?? []) as $tab) {
        if (in_array($gkey, (array) ($tab['groups'] ?? []), true)) return true;
    }
    return false;
}

// =========================================================================
// MENU: builder stron + zdefiniowane strony
// =========================================================================

add_action('admin_menu', function () {
    $hook = add_submenu_page('evk-repeater', 'Strony ustawień', 'Strony ustawień', 'manage_options', 'evk-settings', 'evk_rep_settings_builder_page');

    // Enqueue assetów buildera tylko na jego ekranie. Hook suffix zależy od
    // tytułu menu nadrzędnego (WP przepuszcza go przez sanitize_title), więc go
    // NIE zgadujemy — używamy wartości zwróconej przez add_submenu_page().
    add_action('admin_enqueue_scripts', function ($current) use ($hook) {
        if (!$hook || $current !== $hook) return;
        wp_enqueue_style('evk-rep-builder', EVK_REP_URL . 'assets/builder.css', [], EVK_REP_VERSION);
        wp_enqueue_style('evk-admin', EVK_REP_URL . 'assets/evk-admin.css', [], EVK_REP_VERSION);
        wp_enqueue_script('evk-rep-settings', EVK_REP_URL . 'assets/settings.js', ['jquery'], EVK_REP_VERSION, true);
        evk_rep_mark_admin_body();

        // Szablony HTML przekazane bezpośrednio jako JS — pewniejsze niż DOM lookup
        add_action('admin_footer', function () {
            $groups = evk_rep_groups();
            ob_start(); evk_rep_settings_page_card('__PINDEX__', '', [], $groups); $page_tpl = ob_get_clean();
            ob_start(); evk_rep_settings_tab_block('__PINDEX__', '__TINDEX__', [], $groups); $tab_tpl = ob_get_clean();
            echo '<script>var evkSpTpl={page:' . wp_json_encode($page_tpl) . ',tab:' . wp_json_encode($tab_tpl) . '};</script>';
            evk_dashicon_picker_assets(); // modal + JS pickera ikon (działa też dla kart dodanych dynamicznie)
        });
    });

    foreach (evk_rep_settings_pages() as $slug => $page) {
        $slug   = (string) $slug;
        $cap    = $page['capability'] ?? 'manage_options';
        $icon   = $page['icon'] ?? 'dashicons-admin-generic';
        $title  = $page['label'] ?? $slug;
        $parent = trim((string) ($page['parent'] ?? ''));
        $cb     = function () use ($slug) { evk_rep_render_settings_page($slug); };
        if ($parent !== '') {
            add_submenu_page($parent, $title, $title, $cap, $slug, $cb);
        } else {
            add_menu_page($title, $title, $cap, $slug, $cb, $icon, null);
        }
    }
}, 20);

add_action('admin_enqueue_scripts', function ($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page !== '' && isset(evk_rep_settings_pages()[$page])) {
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('evk-rep-admin', EVK_REP_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable'], EVK_REP_VERSION, true);
        wp_enqueue_style('evk-rep-admin', EVK_REP_URL . 'assets/admin.css', [], EVK_REP_VERSION);
        wp_enqueue_style('evk-admin', EVK_REP_URL . 'assets/evk-admin.css', [], EVK_REP_VERSION);
        evk_rep_admin_localize();
        evk_rep_mark_admin_body();
    }
});

// =========================================================================
// BUILDER STRON USTAWIEŃ
// =========================================================================

function evk_rep_settings_tab_block($pindex, $tindex, array $tab, array $groups): void {
    $base = 'evk_settings_pages[' . $pindex . '][tabs][' . $tindex . ']';
    $sel  = (array) ($tab['groups'] ?? []);
    ?>
    <div class="evk-sp-tab">
        <div class="evk-sp-tab-head">
            <span class="dashicons dashicons-menu evk-sp-tab-handle" title="Przeciągnij"></span>
            <input type="text" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr($tab['label'] ?? ''); ?>" placeholder="Nazwa zakładki" class="evk-sp-tab-label">
            <button type="button" class="evk-sp-remove-tab" title="Usuń zakładkę"><span class="dashicons dashicons-trash"></span></button>
        </div>
        <div class="evk-sp-tab-groups">
            <?php if (empty($groups)): ?>
                <em class="evk-sp-nogroups">Brak grup pól — dodaj je w <a href="edit.php?post_type=evk_field_group">Grupy pól</a>.</em>
            <?php else: foreach ($groups as $gk => $g): ?>
                <label class="evk-sp-group-pick">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[groups][]" value="<?php echo esc_attr($gk); ?>" <?php checked(in_array($gk, $sel, true)); ?>>
                    <?php echo esc_html($g['label'] ?? $gk); ?>
                </label>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php
}

function evk_rep_settings_page_card($pindex, string $slug, array $page, array $groups): void {
    $base = 'evk_settings_pages[' . $pindex . ']';
    $tabs = $page['tabs'] ?? [];
    ?>
    <div class="evk-b-group evk-settings-page" data-pindex="<?php echo esc_attr($pindex); ?>">

        <div class="evk-b-group-head">
            <span class="evk-b-handle evk-b-ghandle dashicons dashicons-admin-settings" title="Strona ustawień"></span>
            <input type="text" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr($page['label'] ?? ''); ?>" placeholder="Nazwa strony (np. Ustawienia motywu)" class="evk-b-group-label">
            <input type="text" name="<?php echo esc_attr($base); ?>[slug]" value="<?php echo esc_attr($slug); ?>" placeholder="slug-strony" class="evk-b-key evk-sp-slug">
            <button type="button" class="evk-b-group-remove evk-sp-remove-page" title="Usuń stronę"><span class="dashicons dashicons-trash"></span></button>
        </div>

        <div class="evk-b-group-body">

            <div class="evk-b-section">
                <div class="evk-b-section-title">Konfiguracja strony</div>
                <div class="evk-sp-meta">
                    <?php evk_dashicon_picker_field($base . '[icon]', $page['icon'] ?? 'dashicons-admin-generic', 'Ikona menu'); ?>
                    <label>
                        Uprawnienie
                        <input type="text" name="<?php echo esc_attr($base); ?>[capability]" value="<?php echo esc_attr($page['capability'] ?? 'manage_options'); ?>" placeholder="manage_options">
                    </label>
                    <label>
                        Menu nadrzędne (slug)
                        <input type="text" name="<?php echo esc_attr($base); ?>[parent]" value="<?php echo esc_attr($page['parent'] ?? ''); ?>" placeholder="puste = osobne menu główne">
                    </label>
                    <label class="evk-sp-meta-check">
                        <input type="checkbox" name="<?php echo esc_attr($base); ?>[hide_title]" value="1" <?php checked(!empty($page['hide_title'])); ?>>
                        Ukryj tytuł strony (nagłówek H1)
                    </label>
                </div>
            </div>

            <div class="evk-b-section">
                <div class="evk-b-section-title">Zakładki</div>
                <div class="evk-sp-tabs">
                    <?php $ti = 0; foreach ($tabs as $tab) { evk_rep_settings_tab_block($pindex, $ti, (array) $tab, $groups); $ti++; } ?>
                </div>
                <button type="button" class="evk-sp-add-tab">
                    <span class="dashicons dashicons-plus-alt2"></span> Dodaj zakładkę
                </button>
            </div>

        </div>
    </div>
    <?php
}

function evk_rep_settings_builder_page(): void {
    $groups = evk_rep_groups();
    $pages  = evk_rep_settings_pages();
    ?>
    <div class="wrap evk-b-wrap">
        <h1>Strony ustawień</h1>
        <p class="evk-b-intro">Twórz strony ustawień (zapis do opcji witryny). Każda strona ma zakładki, a w zakładkach umieszczasz wybrane grupy pól. Po zapisaniu odśwież stronę, aby pojawiło się menu.</p>
        <form method="post">
            <?php wp_nonce_field('evk_rep_settings_builder', 'evk_rep_settings_builder_nonce'); ?>
            <div id="evk-settings-pages">
                <?php $pi = 0; foreach ($pages as $slug => $page) { evk_rep_settings_page_card($pi, (string) $slug, (array) $page, $groups); $pi++; } ?>
            </div>
            <button type="button" class="button evk-sp-add-page"><span class="dashicons dashicons-plus-alt2"></span> Dodaj stronę ustawień</button>
            <p class="submit"><button type="submit" name="evk_rep_settings_builder_save" value="1" class="button button-primary">Zapisz strony</button></p>
        </form>
    </div>
    <?php
    // Szablony JS generowane w admin_footer (patrz admin_enqueue_scripts)
}

add_action('admin_init', function () {
    if (empty($_POST['evk_rep_settings_builder_save'])) return;
    if (!current_user_can('manage_options')) return;
    if (!wp_verify_nonce($_POST['evk_rep_settings_builder_nonce'] ?? '', 'evk_rep_settings_builder')) return;

    $raw = isset($_POST['evk_settings_pages']) && is_array($_POST['evk_settings_pages']) ? wp_unslash($_POST['evk_settings_pages']) : [];
    $out = [];
    foreach ($raw as $p) {
        if (!is_array($p)) continue;
        $slug = sanitize_title(remove_accents((string) ($p['slug'] ?? '')));
        if ($slug === '') $slug = sanitize_title(remove_accents((string) ($p['label'] ?? '')));
        if ($slug === '' || $slug === 'evk-settings' || $slug === 'evk-repeater') continue;
        $tabs = [];
        foreach ((array) ($p['tabs'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $glist = array_values(array_filter(array_map('sanitize_key', (array) ($t['groups'] ?? []))));
            $tabs[] = ['label' => sanitize_text_field($t['label'] ?? ''), 'groups' => $glist];
        }
        $out[$slug] = [
            'label'      => sanitize_text_field($p['label'] ?? $slug) ?: $slug,
            'slug'       => $slug,
            'icon'       => sanitize_text_field($p['icon'] ?? '') ?: 'dashicons-admin-generic',
            'capability' => sanitize_text_field($p['capability'] ?? '') ?: 'manage_options',
            'parent'     => sanitize_text_field($p['parent'] ?? ''),
            'hide_title' => !empty($p['hide_title']) ? 1 : 0,
            'tabs'       => $tabs,
        ];
    }
    update_option('evk_rep_settings_pages', $out);
    add_action('admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>Strony ustawień zapisane. Odśwież stronę, aby zaktualizować menu.</p></div>';
    });
});

// =========================================================================
// RENDER STRONY USTAWIEŃ (pola w trybie option)
// =========================================================================

function evk_rep_render_option_group(string $gk, array $group): void {
    $fields = $group['fields'] ?? [];
    $stored = get_option('evk_rep_opt_' . $gk, []);
    if (!is_array($stored)) $stored = [];
    if (evk_rep_is_repeater($group)) {
        $rows = array_values($stored);
        evk_rep_render_repeater_widget('evk_opt[' . $gk . ']', $fields, $rows, $group['title_field'] ?? '', 0, !empty($group['collapsed']));
    } else {
        evk_rep_render_field_list($fields, ['mode' => 'option', 'name_base' => 'evk_opt[' . $gk . ']', 'values' => $stored, 'depth' => -1, 'uid' => 'o_' . $gk]);
    }
}

function evk_rep_render_settings_page(string $slug): void {
    $pages = evk_rep_settings_pages();
    if (!isset($pages[$slug])) { echo '<div class="wrap"><p>Nie znaleziono strony.</p></div>'; return; }
    $page = $pages[$slug];
    if (!current_user_can($page['capability'] ?? 'manage_options')) wp_die('Brak uprawnień.');

    $tabs   = $page['tabs'] ?? [];
    $count  = count($tabs);
    $active = isset($_GET['tab']) ? (int) $_GET['tab'] : 0;
    if ($active < 0 || $active >= $count) $active = 0;
    $groups = evk_rep_groups();
    $url    = admin_url('admin.php');
    ?>
    <div class="wrap evk-settings-wrap">
        <h1<?php echo !empty($page['hide_title']) ? ' class="screen-reader-text"' : ''; ?>><?php echo esc_html($page['label'] ?? $slug); ?></h1>
        <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success is-dismissible"><p>Zapisano zmiany.</p></div><?php endif; ?>

        <?php if ($count > 1): ?>
        <h2 class="nav-tab-wrapper evk-settings-tabs">
            <?php foreach ($tabs as $i => $t): ?>
                <a href="<?php echo esc_url(add_query_arg(['page' => $slug, 'tab' => $i], $url)); ?>" class="nav-tab <?php echo $i === $active ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(($t['label'] ?? '') !== '' ? $t['label'] : 'Zakładka ' . ($i + 1)); ?></a>
            <?php endforeach; ?>
        </h2>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <p>Ta strona nie ma jeszcze zakładek. Dodaj je w „Strony ustawień".</p>
        <?php else:
            $tab     = $tabs[$active];
            $tgroups = (array) ($tab['groups'] ?? []);
        ?>
        <form method="post" class="evk-settings-form">
            <?php wp_nonce_field('evk_settings_save_' . $slug, 'evk_settings_nonce'); ?>
            <input type="hidden" name="evk_settings_page" value="<?php echo esc_attr($slug); ?>">
            <input type="hidden" name="evk_settings_tab" value="<?php echo esc_attr($active); ?>">
            <?php
            $rendered = 0;
            foreach ($tgroups as $gk) {
                if (!isset($groups[$gk])) continue;
                $g        = $groups[$gk];
                $seamless = !empty($g['seamless']);
                echo '<div class="evk-settings-group' . ($seamless ? ' evk-settings-group--seamless' : '') . '">';
                if (!$seamless && empty($g['hide_title'])) {
                    echo '<h2 class="evk-settings-group-title">' . esc_html($g['label'] ?? $gk) . '</h2>';
                }
                evk_rep_render_option_group($gk, $g);
                echo '</div>';
                $rendered++;
            }
            if ($rendered === 0) echo '<p>Brak przypisanych grup pól w tej zakładce.</p>';
            ?>
            <p class="submit"><button type="submit" class="button button-primary">Zapisz zmiany</button></p>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

add_action('admin_init', function () {
    if (empty($_POST['evk_settings_page'])) return;
    $slug  = sanitize_key($_POST['evk_settings_page']);
    $pages = evk_rep_settings_pages();
    if (!isset($pages[$slug])) return;
    $page = $pages[$slug];
    if (!current_user_can($page['capability'] ?? 'manage_options')) return;
    if (!wp_verify_nonce($_POST['evk_settings_nonce'] ?? '', 'evk_settings_save_' . $slug)) return;

    $raw    = isset($_POST['evk_opt']) && is_array($_POST['evk_opt']) ? wp_unslash($_POST['evk_opt']) : [];
    $groups = evk_rep_groups();

    // Iterujemy po grupach z aktywnej zakładki (schemat), a NIE po $_POST['evk_opt'].
    // Repeater z usuniętymi wszystkimi wierszami nie wysyła żadnych pól, więc nie ma
    // go w $_POST — iteracja po POST pomijała go i stare wiersze wracały. Teraz pusty
    // repeater zapisuje się jako pusta tablica.
    $tab_index  = (int) ($_POST['evk_settings_tab'] ?? 0);
    $tabs       = (array) ($page['tabs'] ?? []);
    $tab_groups = isset($tabs[$tab_index]) ? (array) ($tabs[$tab_index]['groups'] ?? []) : [];

    foreach ($tab_groups as $gk) {
        $gk = sanitize_key($gk);
        if (!isset($groups[$gk])) continue;
        $g     = $groups[$gk];
        $gdata = isset($raw[$gk]) && is_array($raw[$gk]) ? $raw[$gk] : [];
        if (evk_rep_is_repeater($g)) {
            $clean = evk_rep_sanitize_rows($g['fields'] ?? [], $gdata);
        } else {
            $clean = evk_rep_sanitize_group_values($g['fields'] ?? [], $gdata);
        }
        update_option('evk_rep_opt_' . $gk, $clean);
    }
    wp_safe_redirect(add_query_arg(['page' => $slug, 'tab' => (int) ($_POST['evk_settings_tab'] ?? 0), 'updated' => 1], admin_url('admin.php')));
    exit;
});
