<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke FIELDS — współdzielony wybór ikony (dashicon).
 *
 * Jedno źródło prawdy dla pickera używanego w „Typach treści" i „Stronach
 * ustawień". API:
 *   - evk_dashicon_picker_field($name, $value, $label)  — pole (przycisk + hidden input)
 *   - evk_dashicon_picker_assets()                       — modal + JS + CSS (raz na żądanie)
 *   - evk_dashicon_list()                                — lista dostępnych ikon
 *
 * Markup pola musi pasować do JS: w obrębie `.post-type-icon` ukryty
 * `.dashicon-value-input` bezpośrednio przed `.dashicon-picker-btn`.
 */

function evk_dashicon_list(): array {
    return [
        'dashicons-admin-appearance','dashicons-admin-collapse','dashicons-admin-comments',
        'dashicons-admin-customizer','dashicons-admin-generic','dashicons-admin-home',
        'dashicons-admin-links','dashicons-admin-media','dashicons-admin-multisite',
        'dashicons-admin-network','dashicons-admin-page','dashicons-admin-plugins',
        'dashicons-admin-post','dashicons-admin-settings','dashicons-admin-site',
        'dashicons-admin-site-alt','dashicons-admin-site-alt2','dashicons-admin-site-alt3',
        'dashicons-admin-tools','dashicons-admin-users',
        'dashicons-dismiss','dashicons-info','dashicons-no','dashicons-no-alt','dashicons-warning',
        'dashicons-arrow-down','dashicons-arrow-down-alt','dashicons-arrow-down-alt2',
        'dashicons-arrow-left','dashicons-arrow-left-alt','dashicons-arrow-left-alt2',
        'dashicons-arrow-right','dashicons-arrow-right-alt','dashicons-arrow-right-alt2',
        'dashicons-arrow-up','dashicons-arrow-up-alt','dashicons-arrow-up-alt2',
        'dashicons-controls-back','dashicons-controls-forward','dashicons-controls-pause',
        'dashicons-controls-play','dashicons-controls-repeat','dashicons-controls-skipback',
        'dashicons-controls-skipforward','dashicons-controls-stop',
        'dashicons-controls-volumeoff','dashicons-controls-volumeon',
        'dashicons-awards','dashicons-medal','dashicons-ribbon',
        'dashicons-star-empty','dashicons-star-filled','dashicons-star-half',
        'dashicons-businessman','dashicons-businessperson','dashicons-businesswoman',
        'dashicons-groups','dashicons-nametag','dashicons-id','dashicons-id-alt',
        'dashicons-building','dashicons-home','dashicons-location','dashicons-location-alt',
        'dashicons-megaphone','dashicons-store','dashicons-bank','dashicons-palmtree',
        'dashicons-cart','dashicons-money','dashicons-money-alt','dashicons-products',
        'dashicons-tickets','dashicons-tickets-alt',
        'dashicons-book','dashicons-book-alt','dashicons-format-aside','dashicons-format-audio',
        'dashicons-format-chat','dashicons-format-gallery','dashicons-format-image',
        'dashicons-format-links','dashicons-format-quote','dashicons-format-standard',
        'dashicons-format-status','dashicons-format-video','dashicons-text','dashicons-text-page',
        'dashicons-album','dashicons-camera','dashicons-camera-alt','dashicons-images-alt',
        'dashicons-images-alt2','dashicons-media-archive','dashicons-media-audio',
        'dashicons-media-code','dashicons-media-default','dashicons-media-document',
        'dashicons-media-interactive','dashicons-media-spreadsheet','dashicons-media-text',
        'dashicons-media-video','dashicons-playlist-audio','dashicons-playlist-video',
        'dashicons-video-alt','dashicons-video-alt2','dashicons-video-alt3',
        'dashicons-email','dashicons-email-alt','dashicons-email-alt2',
        'dashicons-facebook','dashicons-facebook-alt','dashicons-instagram',
        'dashicons-linkedin','dashicons-pinterest','dashicons-reddit','dashicons-rss',
        'dashicons-share','dashicons-share-alt','dashicons-share-alt2',
        'dashicons-twitch','dashicons-twitter','dashicons-twitter-alt',
        'dashicons-xing','dashicons-youtube','dashicons-google',
        'dashicons-wordpress','dashicons-wordpress-alt',
        'dashicons-analytics','dashicons-art','dashicons-backup','dashicons-calendar',
        'dashicons-calendar-alt','dashicons-chart-area','dashicons-chart-bar',
        'dashicons-chart-line','dashicons-chart-pie','dashicons-clipboard','dashicons-clock',
        'dashicons-cloud','dashicons-cloud-saved','dashicons-cloud-upload','dashicons-coffee',
        'dashicons-color-picker','dashicons-columns','dashicons-dashboard',
        'dashicons-database','dashicons-database-add','dashicons-database-export',
        'dashicons-database-import','dashicons-database-remove','dashicons-database-view',
        'dashicons-desktop','dashicons-download','dashicons-edit','dashicons-edit-large',
        'dashicons-edit-page','dashicons-ellipsis','dashicons-embed-audio',
        'dashicons-embed-generic','dashicons-embed-photo','dashicons-embed-post',
        'dashicons-embed-video','dashicons-excerpt-view','dashicons-exit',
        'dashicons-external','dashicons-feedback','dashicons-filter','dashicons-flag',
        'dashicons-food','dashicons-games','dashicons-gear','dashicons-gifts',
        'dashicons-hammer','dashicons-heart','dashicons-hidden','dashicons-hobbies',
        'dashicons-image-crop','dashicons-image-filter','dashicons-image-flip-horizontal',
        'dashicons-image-flip-vertical','dashicons-image-rotate','dashicons-image-rotate-left',
        'dashicons-image-rotate-right','dashicons-index-card','dashicons-keyboard-hide',
        'dashicons-laptop','dashicons-layout','dashicons-leftright','dashicons-lightbulb',
        'dashicons-list-view','dashicons-lock','dashicons-marker','dashicons-menu',
        'dashicons-menu-alt','dashicons-menu-alt2','dashicons-menu-alt3','dashicons-microphone',
        'dashicons-migrate','dashicons-minus','dashicons-move','dashicons-music',
        'dashicons-networking','dashicons-open-folder','dashicons-paperclip','dashicons-pdf',
        'dashicons-performance','dashicons-pets','dashicons-phone','dashicons-plus',
        'dashicons-plus-alt','dashicons-plus-alt2','dashicons-portfolio','dashicons-post-status',
        'dashicons-pressthis','dashicons-printer','dashicons-privacy','dashicons-randomize',
        'dashicons-remove','dashicons-rest-api','dashicons-reusable-block','dashicons-saved',
        'dashicons-screenoptions','dashicons-search','dashicons-shield','dashicons-shield-alt',
        'dashicons-shortcode','dashicons-slides','dashicons-smartphone','dashicons-smiley',
        'dashicons-sort','dashicons-sos','dashicons-speaker','dashicons-superhero',
        'dashicons-superhero-alt','dashicons-tablet','dashicons-tag','dashicons-tagcloud',
        'dashicons-thumbs-down','dashicons-thumbs-up','dashicons-tide','dashicons-totop',
        'dashicons-translation','dashicons-trash','dashicons-trophy','dashicons-undo',
        'dashicons-unlock','dashicons-update','dashicons-update-alt','dashicons-upload',
        'dashicons-vault','dashicons-visibility','dashicons-woo','dashicons-woo-alt',
        'dashicons-yes','dashicons-yes-alt',
        'dashicons-welcome-add-page','dashicons-welcome-comments','dashicons-welcome-learn-more',
        'dashicons-welcome-view-site','dashicons-welcome-widgets-menus','dashicons-welcome-write-blog',
        'dashicons-buddicons-activity','dashicons-buddicons-bbpress-logo',
        'dashicons-buddicons-community','dashicons-buddicons-forums','dashicons-buddicons-friends',
        'dashicons-buddicons-groups','dashicons-buddicons-pm','dashicons-buddicons-replies',
        'dashicons-buddicons-topics','dashicons-buddicons-tracking',
        'dashicons-grid-view','dashicons-html',
    ];
}

/**
 * Pole wyboru ikony. $name = nazwa ukrytego inputa (trafia do $_POST).
 */
function evk_dashicon_picker_field(string $name, string $value = '', string $label = 'Ikona'): void {
    $value = $value !== '' ? $value : 'dashicons-admin-generic';
    ?>
    <div class="post-type-icon evk-icon-field">
        <label><?php echo esc_html($label); ?></label><br>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="dashicon-value-input">
        <button type="button" class="dashicon-picker-btn" title="<?php echo esc_attr__('Kliknij, aby wybrać ikonę', 'evk-repeater'); ?>">
            <span class="dashicons <?php echo esc_attr($value); ?>"></span>
            <span class="dashicon-btn-label"><?php echo esc_html(str_replace('dashicons-', '', $value)); ?></span>
        </button>
    </div>
    <?php
}

/**
 * Modal + JS + CSS pickera. Emitowane najwyżej raz na żądanie.
 */
function evk_dashicon_picker_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <!-- Dashicon Picker Modal -->
    <div id="dashicon-picker-modal" style="display:none;">
        <div class="dashicon-picker-backdrop"></div>
        <div class="dashicon-picker-dialog">
            <div class="dashicon-picker-header">
                <input type="text" id="dashicon-search" placeholder="<?php echo esc_attr__('Szukaj ikon…', 'evk-repeater'); ?>" autocomplete="off" />
                <button type="button" id="dashicon-picker-close">&#x2715;</button>
            </div>
            <div class="dashicon-picker-grid" id="dashicon-picker-grid"></div>
        </div>
    </div>

    <script>
    (function() {
        const allDashicons = <?php echo wp_json_encode(array_values(evk_dashicon_list())); ?>;

        const modal   = document.getElementById('dashicon-picker-modal');
        const grid    = document.getElementById('dashicon-picker-grid');
        const search  = document.getElementById('dashicon-search');
        let currentInput = null;

        function renderIcons(q) {
            q = (q || '').toLowerCase().replace(/^dashicons-/, '');
            grid.innerHTML = '';
            const current = currentInput ? currentInput.value : '';
            allDashicons.forEach(function(icon) {
                if (q && icon.indexOf(q) === -1) return;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dashicon-item' + (icon === current ? ' selected' : '');
                btn.title = icon;
                btn.innerHTML =
                    '<span class="dashicons ' + icon + '"></span>' +
                    '<span class="dashicon-item-label">' + icon.replace('dashicons-', '') + '</span>';
                btn.addEventListener('click', function() { selectIcon(icon); });
                grid.appendChild(btn);
            });
        }

        function selectIcon(icon) {
            if (currentInput) {
                currentInput.value = icon;
                const pickerBtn = currentInput.nextElementSibling;
                pickerBtn.querySelector('.dashicons').className = 'dashicons ' + icon;
                pickerBtn.querySelector('.dashicon-btn-label').textContent = icon.replace('dashicons-', '');
            }
            closePicker();
        }

        function openPicker(input) {
            currentInput = input;
            search.value = '';
            renderIcons('');
            modal.style.display = 'flex';
            search.focus();
            setTimeout(function() {
                const sel = grid.querySelector('.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function closePicker() {
            modal.style.display = 'none';
            currentInput = null;
        }

        search.addEventListener('input', function() { renderIcons(this.value); });
        document.getElementById('dashicon-picker-close').addEventListener('click', closePicker);
        modal.querySelector('.dashicon-picker-backdrop').addEventListener('click', closePicker);
        modal.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePicker(); });

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.dashicon-picker-btn');
            if (btn) {
                const input = btn.closest('.post-type-icon').querySelector('.dashicon-value-input');
                openPicker(input);
            }
        });
    })();
    </script>

    <style>
        /* Dashicon picker button */
        .post-type-icon .dashicon-picker-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            background: #ffffff;
            border: 1px solid #e2e2e2;
            border-radius: 5px;
            cursor: pointer;
            height: 40px;
            min-width: 160px;
            max-width: 220px;
            font-size: 13px;
            transition: border-color 0.15s;
        }
        .post-type-icon .dashicon-picker-btn:hover {
            border-color: #000;
        }
        .post-type-icon .dashicon-picker-btn .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .dashicon-btn-label {
            font-size: 11px;
            color: #555;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* Modal */
        #dashicon-picker-modal {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .dashicon-picker-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.55);
        }
        .dashicon-picker-dialog {
            position: relative;
            background: #fff;
            border-radius: 8px;
            width: 660px;
            max-width: 95vw;
            max-height: 82vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 12px 48px rgba(0,0,0,0.25);
        }
        .dashicon-picker-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e2e2;
            flex-shrink: 0;
        }
        #dashicon-search {
            flex: 1;
            height: 36px;
            padding: 0 10px;
            border: 1px solid #e2e2e2;
            border-radius: 5px;
            font-size: 13px;
            background: #f9f9f9;
        }
        #dashicon-search:focus {
            outline: none;
            border-color: var(--wp-admin-theme-color);
            background: #fff;
        }
        #dashicon-picker-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #666;
            padding: 4px 8px;
            border-radius: 4px;
            line-height: 1;
            flex-shrink: 0;
        }
        #dashicon-picker-close:hover {
            background: #f0f0f0;
            color: #000;
        }
        .dashicon-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(78px, 1fr));
            gap: 4px;
            padding: 14px;
            overflow-y: auto;
        }
        .dashicon-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            padding: 10px 4px;
            background: none;
            border: 1px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.12s, border-color 0.12s;
        }
        .dashicon-item:hover {
            background: #f0f7ff;
            border-color: var(--wp-admin-theme-color, #2271b1);
        }
        .dashicon-item.selected {
            background: #e8f3fb;
            border-color: var(--wp-admin-theme-color, #2271b1);
        }
        .dashicon-item .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }
        .dashicon-item.selected .dashicons {
            color: var(--wp-admin-theme-color, #2271b1);
        }
        .dashicon-item-label {
            font-size: 9px;
            color: #666;
            text-align: center;
            max-width: 72px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.2;
        }
    </style>
    <?php
}
