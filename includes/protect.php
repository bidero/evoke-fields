<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — ochrona danych: pola wrażliwe (per-pole) + chronione typy (per-CPT).
 *
 * MODEL (ustalony z użytkownikiem):
 *  - Pole wrażliwe (flaga `sensitive` na polu): wartość renderuje się TYLKO w kontekście
 *    autoryzowanym — redakcja (uprawnienie do edycji wpisu) zawsze, a na froncie gość
 *    tylko na stronie tego konkretnego wpisu wejściem z pasującym `?key=`. Wszędzie
 *    indziej (pętle/rankingi, cudze strony, API tagów) → pustka. Pola niewrażliwe
 *    (imię, punkty…) renderują się normalnie dla wszystkich.
 *  - Typ chroniony (opcja CPT `protected`): pełna blokada typu — pojedynczy wpis daje
 *    404 bez klucza/uprawnienia; REST/wyszukiwarka/archiwa/sitemapa/oEmbed wyłączone;
 *    chronione typy znikają z frontowych pętli gości.
 *  - Klucz `_evk_access_key` (per-wpis) autoryzuje odsłonięcie danych TEGO wpisu; jest
 *    też tokenem bramki typu chronionego. Metabox: kopiuj link / przegeneruj / wyślij mailem.
 *
 * Granice (świadome, wypisane w UI): kod motywu z jawnym get_post_meta($id,…) / evk_rows(),
 * bezpośredni URL załącznika w polu wrażliwym oraz cache pełnostronicowy — poza zasięgiem.
 */

// =========================================================================
// KONFIGURACJA — typy chronione / pola wrażliwe
// =========================================================================

/** Slugi typów treści z włączoną opcją „Chroniony". Memo per-żądanie. */
function evk_cpt_protected_types(): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    foreach ((array) get_option('evk_custom_post_types', []) as $pt) {
        if (empty($pt['protected'])) continue;
        $slug = substr((string) ($pt['slug'] ?? ''), 0, 20);
        if ($slug !== '') $memo[] = $slug;
    }
    return $memo;
}

function evk_field_is_sensitive(array $field): bool {
    return !empty($field['sensitive']);
}

/** Czy typ treści ma jakiekolwiek pole wrażliwe (rekurencyjnie, też w repeaterze). */
function evk_post_type_has_sensitive(string $post_type): bool {
    static $memo = [];
    if (isset($memo[$post_type])) return $memo[$post_type];
    $found = false;
    $walk  = function (array $fields) use (&$walk, &$found) {
        foreach ($fields as $f) {
            if (!empty($f['sensitive'])) { $found = true; return; }
            if (($f['type'] ?? '') === 'repeater') $walk($f['sub_fields'] ?? []);
            if ($found) return;
        }
    };
    foreach (evk_rep_groups() as $g) {
        if (($g['object_type'] ?? 'post') !== 'post') continue;
        if (!in_array($post_type, (array) ($g['post_types'] ?? ['post']), true)) continue;
        $walk($g['fields'] ?? []);
        if ($found) break;
    }
    return $memo[$post_type] = $found;
}

/** Czy dany typ w ogóle potrzebuje klucza dostępu (chroniony LUB ma pola wrażliwe). */
function evk_post_type_needs_key(string $post_type): bool {
    return in_array($post_type, evk_cpt_protected_types(), true) || evk_post_type_has_sensitive($post_type);
}

// =========================================================================
// KLUCZ DOSTĘPU
// =========================================================================

const EVK_PROTECT_META = '_evk_access_key';

/** Nowy klucz: 20 znaków URL-safe (A-Za-z0-9), ~119 bitów entropii. */
function evk_protect_generate_key(): string {
    return wp_generate_password(20, false, false);
}

function evk_protect_get_key(int $post_id): string {
    $k = get_post_meta($post_id, EVK_PROTECT_META, true);
    return is_string($k) ? $k : '';
}

/** Zwraca klucz wpisu; tworzy, gdy brak (fail-closed: bez klucza wpis nieosiągalny). */
function evk_protect_ensure_key(int $post_id): string {
    $k = evk_protect_get_key($post_id);
    if ($k === '') {
        $k = evk_protect_generate_key();
        update_post_meta($post_id, EVK_PROTECT_META, $k);
    }
    return $k;
}

/** Pełny link do wpisu z kluczem w query. */
function evk_protect_url(int $post_id): string {
    $permalink = get_permalink($post_id);
    if (!$permalink) $permalink = home_url('/?p=' . $post_id);
    return add_query_arg('key', evk_protect_ensure_key($post_id), $permalink);
}

// Generuj klucz przy zapisie wpisu typu, który go potrzebuje.
add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    $pt = get_post_type($post_id);
    if (!$pt || !evk_post_type_needs_key($pt)) return;
    $post = get_post($post_id);
    if (!$post || $post->post_status === 'auto-draft') return;
    evk_protect_ensure_key((int) $post_id);
}, 20);

// =========================================================================
// AUTORYZACJA
// =========================================================================

/**
 * ID wpisu, dla którego bieżące żądanie frontu jest autoryzowane kluczem
 * (queried single + pasujący ?key). 0 = brak. Nie dotyczy panelu.
 */
function evk_protect_authorized_post(): int {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = 0;
    if (is_admin()) return $cache;
    $key = isset($_GET['key']) ? trim((string) wp_unslash($_GET['key'])) : '';
    if ($key === '') return $cache;
    $obj = get_queried_object();
    if ($obj instanceof WP_Post) {
        $saved = evk_protect_get_key((int) $obj->ID);
        if ($saved !== '' && hash_equals($saved, $key)) $cache = (int) $obj->ID;
    }
    return $cache;
}

/** Czy bieżący użytkownik może EDYTOWAĆ wpis (redakcja). Post_id 0 → ogólne edit_posts. */
function evk_protect_user_is_editor(int $post_id): bool {
    if ($post_id > 0) {
        $pt  = get_post_type($post_id);
        $obj = $pt ? get_post_type_object($pt) : null;
        if ($obj) return current_user_can($obj->cap->edit_post, $post_id);
    }
    return current_user_can('edit_posts');
}

/** Czy wolno pokazać WARTOŚĆ pola wrażliwego danego wpisu w bieżącym kontekście. */
function evk_protect_can_view_sensitive(int $post_id): bool {
    if (evk_protect_user_is_editor($post_id)) return true;          // redakcja — zawsze
    if (is_admin()) return current_user_can('edit_posts');           // panel — tylko uprawnieni
    return $post_id > 0 && evk_protect_authorized_post() === $post_id; // front — tylko z kluczem tego wpisu
}

/** Czy wolno w ogóle OGLĄDAĆ chroniony wpis (bramka typu chronionego). */
function evk_protect_can_view_post(int $post_id): bool {
    if (evk_protect_user_is_editor($post_id)) return true;
    return $post_id > 0 && evk_protect_authorized_post() === $post_id;
}

/**
 * Punkt wpięcia dla resolvera EVK (bricks.php): czy zablokować wartość pola.
 * True → resolver zwraca '' zamiast wartości.
 */
function evk_protect_field_blocked(array $field, int $post_id): bool {
    if (!evk_field_is_sensitive($field)) return false;
    return !evk_protect_can_view_sensitive($post_id);
}

// =========================================================================
// TYP CHRONIONY — rejestracja, bramka 404, pętle, sitemapa, oEmbed
// =========================================================================

// Wymuś bezpieczne argumenty rejestracji dla typów chronionych.
add_filter('register_post_type_args', function ($args, $post_type) {
    if (!in_array($post_type, evk_cpt_protected_types(), true)) return $args;
    $args['show_in_rest']        = false; // brak wycieku przez /wp-json (i klasyczny edytor)
    $args['exclude_from_search'] = true;  // poza wyszukiwarką witryny
    $args['has_archive']         = false; // bez publicznego archiwum
    $args['publicly_queryable']  = true;  // pojedynczy wpis nadal osiągalny (z kluczem)
    return $args;
}, 20, 2);

/** Zwróć prawdziwe 404 (ukrywa istnienie; poprawny status; nocache). */
function evk_protect_send_404(): void {
    global $wp_query;
    if ($wp_query) $wp_query->set_404();
    status_header(404);
    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    $tpl = get_query_template('404');
    if ($tpl) { include $tpl; }
    else      { wp_die(esc_html__('Nie znaleziono.', 'evk-repeater'), '', ['response' => 404]); }
    exit;
}

/** Wyłącz cache dla autoryzowanego widoku (żeby nie zapisać wersji z danymi). */
function evk_protect_nocache(): void {
    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
}

// Bramka frontu dla typów chronionych.
add_action('template_redirect', function () {
    $types = evk_cpt_protected_types();
    if (!$types) return;

    // Kanały RSS nigdy dla chronionych (wyciekałyby tytuł/treść).
    if (is_feed()) {
        $obj = get_queried_object();
        $qpt = (array) get_query_var('post_type');
        if (($obj instanceof WP_Post && in_array($obj->post_type, $types, true)) || array_intersect($qpt, $types)) {
            evk_protect_send_404();
        }
        return;
    }

    if (is_singular($types)) {
        $pid = (int) get_queried_object_id();
        if (evk_protect_can_view_post($pid)) { evk_protect_nocache(); return; }
        evk_protect_send_404();
    }

    if (is_post_type_archive($types)) {
        evk_protect_send_404();
    }
});

// Chronione typy znikają z frontowych zapytań gości (pętle Bricks, „ostatnie" itd.).
add_action('pre_get_posts', function ($q) {
    if (is_admin() || !($q instanceof WP_Query)) return;
    $types = evk_cpt_protected_types();
    if (!$types) return;
    if (current_user_can('edit_posts')) return; // redakcja widzi w pętlach
    if ($q->is_singular()) return;               // pojedynczy — obsługuje bramka wyżej

    $qpt = (array) $q->get('post_type');
    if (!$qpt) return; // ogólne zapytania (home/search) — chronią exclude_from_search + brak archiwum
    $keep = array_values(array_diff($qpt, $types));
    if ($keep === $qpt) return;             // zapytanie nie dotyka chronionych
    if (!$keep) $q->set('post__in', [0]);   // prosiło tylko o chronione → pusto
    else        $q->set('post_type', $keep);
});

// Poza sitemapą WordPressa.
add_filter('wp_sitemaps_post_types', function ($types) {
    foreach (evk_cpt_protected_types() as $slug) unset($types[$slug]);
    return $types;
});

// Bez oEmbed dla chronionych wpisów.
add_filter('oembed_response_data', function ($data, $post) {
    if ($post instanceof WP_Post && in_array($post->post_type, evk_cpt_protected_types(), true)) return [];
    return $data;
}, 10, 2);

// =========================================================================
// METABOX — link z kluczem: kopiuj / przegeneruj / wyślij mailem
// =========================================================================

add_action('add_meta_boxes', function ($post_type, $post) {
    if (!evk_post_type_needs_key((string) $post_type)) return;
    add_meta_box('evk_protect', 'Dostęp (klucz)', 'evk_protect_metabox', $post_type, 'side', 'high');
}, 10, 2);

function evk_protect_metabox(\WP_Post $post): void {
    $pid = (int) $post->ID;
    $key = evk_protect_ensure_key($pid);
    $url = evk_protect_url($pid);

    $cfg          = function_exists('evk_cpt_config') ? evk_cpt_config((string) $post->post_type) : [];
    $email_field  = $cfg['protect_email_field'] ?? '';
    $prefill      = $email_field !== '' ? (string) get_post_meta($pid, $email_field, true) : '';
    $is_protected = in_array($post->post_type, evk_cpt_protected_types(), true);

    wp_nonce_field('evk_protect_' . $pid, 'evk_protect_nonce');
    ?>
    <div class="evk-protect-box" data-post="<?php echo $pid; ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <p class="evk-protect-intro">
            <span class="dashicons dashicons-<?php echo $is_protected ? 'lock' : 'privacy'; ?>"></span>
            <?php echo $is_protected
                ? 'Ten typ jest chroniony — wpis jest dostępny na froncie tylko przez poniższy link.'
                : 'Link odsłania pola wrażliwe tego wpisu na jego stronie.'; ?>
        </p>

        <div class="evk-protect-field">
            <label class="evk-protect-label">Link dostępowy</label>
            <input type="text" class="evk-protect-url" readonly value="<?php echo esc_attr($url); ?>" onclick="this.select();">
        </div>
        <div class="evk-protect-actions">
            <button type="button" class="button button-primary evk-protect-copy">
                <span class="dashicons dashicons-admin-page"></span> Kopiuj link
            </button>
            <button type="button" class="button evk-protect-regen">
                <span class="dashicons dashicons-update"></span> Przegeneruj
            </button>
        </div>

        <div class="evk-protect-sep"></div>

        <div class="evk-protect-field">
            <label class="evk-protect-label">Wyślij link na e-mail</label>
            <input type="email" class="evk-protect-email" value="<?php echo esc_attr($prefill); ?>" placeholder="adres@e-mail">
        </div>
        <div class="evk-protect-send-row">
            <button type="button" class="button button-primary evk-protect-send">
                <span class="dashicons dashicons-email-alt"></span> Wyślij
            </button>
            <span class="evk-protect-msg"></span>
        </div>
    </div>
    <style>
    #evk_protect .inside{margin:0;padding:12px;}
    .evk-protect-intro{display:flex;gap:6px;align-items:flex-start;margin:0 0 12px;color:#475569;font-size:12px;line-height:1.5;}
    .evk-protect-intro .dashicons{color:#2563eb;flex:0 0 auto;font-size:18px;width:18px;height:18px;}
    .evk-protect-label{display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:5px;}
    .evk-protect-field{margin-bottom:10px;}
    .evk-protect-url,.evk-protect-email{width:100%;box-sizing:border-box;height:34px;font-size:13px;}
    .evk-protect-url{font-family:Menlo,Consolas,monospace;font-size:12px;background:#f8fafc;color:#0f172a;}
    .evk-protect-actions{display:flex;gap:8px;margin-bottom:4px;}
    .evk-protect-actions .button,.evk-protect-send .button,.evk-protect-send-row .button{height:34px;display:inline-flex;align-items:center;gap:4px;}
    .evk-protect-actions .evk-protect-copy{flex:1;justify-content:center;}
    .evk-protect-actions .button .dashicons,.evk-protect-send-row .button .dashicons{font-size:16px;width:16px;height:16px;line-height:1;}
    .evk-protect-sep{border-top:1px solid #e2e8f0;margin:14px 0;}
    .evk-protect-send-row{display:flex;align-items:center;gap:10px;margin-top:8px;}
    .evk-protect-msg{font-size:12px;}
    </style>
    <script>
    (function () {
        var box = document.currentScript.previousElementSibling;
        if (!box || !box.classList.contains('evk-protect-box')) box = document.querySelector('.evk-protect-box');
        if (!box) return;
        var ajax  = box.getAttribute('data-ajax');
        var pid   = box.getAttribute('data-post');
        var nonce = box.parentNode.querySelector('#evk_protect_nonce') ? box.parentNode.querySelector('#evk_protect_nonce').value : (document.getElementById('evk_protect_nonce') || {}).value;
        var urlEl = box.querySelector('.evk-protect-url');
        var msg   = box.querySelector('.evk-protect-msg');
        function post(action, extra, cb) {
            var body = new URLSearchParams();
            body.append('action', action); body.append('nonce', nonce); body.append('post', pid);
            for (var k in extra) body.append(k, extra[k]);
            fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ success: false }); });
        }
        box.querySelector('.evk-protect-copy').addEventListener('click', function () {
            urlEl.select(); urlEl.setSelectionRange(0, 99999);
            if (navigator.clipboard) navigator.clipboard.writeText(urlEl.value);
            else document.execCommand('copy');
            msg.style.color = '#166534'; msg.textContent = 'Skopiowano.';
            setTimeout(function () { msg.textContent = ''; }, 2000);
        });
        box.querySelector('.evk-protect-regen').addEventListener('click', function () {
            if (!confirm('Przegenerować klucz? Dotychczasowe linki przestaną działać.')) return;
            post('evk_protect_regen', {}, function (res) {
                if (res && res.success && res.data && res.data.url) {
                    urlEl.value = res.data.url;
                    msg.style.color = '#166534'; msg.textContent = 'Nowy klucz.';
                } else { msg.style.color = '#b91c1c'; msg.textContent = 'Błąd.'; }
                setTimeout(function () { msg.textContent = ''; }, 2500);
            });
        });
        box.querySelector('.evk-protect-send').addEventListener('click', function () {
            var email = box.querySelector('.evk-protect-email').value;
            msg.style.color = '#475569'; msg.textContent = 'Wysyłanie…';
            post('evk_protect_send', { email: email }, function (res) {
                if (res && res.success) { msg.style.color = '#166534'; msg.textContent = 'Wysłano.'; }
                else { msg.style.color = '#b91c1c'; msg.textContent = (res && res.data && res.data.error) || 'Błąd wysyłki.'; }
                setTimeout(function () { msg.textContent = ''; }, 3000);
            });
        });
    })();
    </script>
    <?php
}

/** Wspólna walidacja żądań AJAX metaboxu; zwraca ID wpisu albo kończy błędem. */
function evk_protect_ajax_guard(): int {
    $pid = (int) ($_POST['post'] ?? 0);
    if (!$pid) wp_send_json_error(['error' => 'Brak wpisu.'], 400);
    if (!check_ajax_referer('evk_protect_' . $pid, 'nonce', false)) wp_send_json_error(['error' => 'Token.'], 400);
    if (!current_user_can('edit_post', $pid)) wp_send_json_error(['error' => 'Brak uprawnień.'], 403);
    return $pid;
}

add_action('wp_ajax_evk_protect_regen', function () {
    $pid = evk_protect_ajax_guard();
    update_post_meta($pid, EVK_PROTECT_META, evk_protect_generate_key());
    wp_send_json_success(['url' => evk_protect_url($pid)]);
});

/**
 * Temat + treść e-maila z linkiem. Szablon z ustawień CPT (protect_email_subject /
 * protect_email_body) z placeholderami; puste = sensowne domyślne.
 * Placeholdery: {title} tytuł wpisu, {url}/{link} link z kluczem, {site} nazwa witryny.
 */
function evk_protect_email_template(int $post_id): array {
    $cfg     = function_exists('evk_cpt_config') ? evk_cpt_config((string) get_post_type($post_id)) : [];
    $subject = trim((string) ($cfg['protect_email_subject'] ?? ''));
    $body    = trim((string) ($cfg['protect_email_body'] ?? ''));

    $title = get_the_title($post_id) ?: ('#' . $post_id);
    $url   = evk_protect_url($post_id);

    if ($subject === '') $subject = 'Dostęp: {title}';
    if ($body === '')    $body = "Link dostępowy do „{title}\":\n\n{url}\n\nTraktuj ten link jak hasło — nie udostępniaj osobom niepowołanym.";

    $repl = [
        '{title}' => $title,
        '{url}'   => $url,
        '{link}'  => $url,
        '{site}'  => get_bloginfo('name'),
    ];
    return [strtr($subject, $repl), strtr($body, $repl)];
}

add_action('wp_ajax_evk_protect_send', function () {
    $pid   = evk_protect_ajax_guard();
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if ($email === '' || !is_email($email)) wp_send_json_error(['error' => 'Zły adres e-mail.'], 400);

    list($subject, $body) = evk_protect_email_template($pid);
    $ok = wp_mail($email, $subject, $body);
    if ($ok) wp_send_json_success(['sent' => true]);
    wp_send_json_error(['error' => 'Serwer nie wysłał wiadomości (sprawdź konfigurację poczty).'], 500);
});
