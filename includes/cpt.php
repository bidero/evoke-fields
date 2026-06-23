<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Slugi zarezerwowane przez WordPress (i przez samą wtyczkę) — nie wolno ich
 * rejestrować jako własnych typów treści, bo nadpisałyby natywne typy.
 */
function evk_reserved_post_type_slugs() {
    return array(
        'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
        'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
        'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'action', 'author', 'order', 'theme', 'evk_field_group',
    );
}

add_action( 'admin_menu', 'evk_add_custom_post_types_submenu' );

function evk_add_custom_post_types_submenu() {
    add_submenu_page(
        'evk-repeater',
        __( 'Typy treści — rejestracja', 'evk-repeater' ),
        __( 'Typy treści', 'evk-repeater' ),
        'manage_options',
        'evk-cpt',
        'evk_render_custom_post_types_page'
    );
}

function evk_render_custom_post_types_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $available_supports = array(
        'title'           => __( 'Tytuł', 'evk-repeater' ),
        'editor'          => __( 'Edytor', 'evk-repeater' ),
        'notes'           => __( 'Notes', 'evk-repeater' ),
        'thumbnail'       => __( 'Miniatura', 'evk-repeater' ),
        'author'          => __( 'Autor', 'evk-repeater' ),
        'excerpt'         => __( 'Zajawka', 'evk-repeater' ),
        'comments'        => __( 'Komentarze', 'evk-repeater' ),
        'custom-fields'   => __( 'Pola własne', 'evk-repeater' ),
        'revisions'       => __( 'Wersje', 'evk-repeater' ),
        'page-attributes' => __( 'Atrybuty strony', 'evk-repeater' ),
    );

    $additional_options = array(
        'show_in_menu'      => __( 'Pokaż w menu', 'evk-repeater' ),
        'show_ui'           => __( 'Pokaż UI', 'evk-repeater' ),
        'show_in_nav_menus' => __( 'Pokaż w menu nawigacji', 'evk-repeater' ),
        'show_order'        => __( 'Pokaż kolejność', 'evk-repeater' ),
        'has_archive'       => __( 'Strona archiwum', 'evk-repeater' ),
        'show_in_rest'      => __( 'Pokaż w REST (Gutenberg/API)', 'evk-repeater' ),
        'hierarchical'      => __( 'Hierarchiczny', 'evk-repeater' ),
    );

    if ( isset( $_POST['evk_custom_post_types_nonce'] ) && wp_verify_nonce( $_POST['evk_custom_post_types_nonce'], 'evk_save_custom_post_types' ) ) {
        if ( isset( $_POST['custom_post_types'] ) && is_array( $_POST['custom_post_types'] ) ) {
            $custom_post_types = array();
            $skipped_slugs     = array();

            foreach ( $_POST['custom_post_types'] as $post_type ) {
                if ( ! empty( $post_type['name'] ) && ! empty( $post_type['slug'] ) ) {
                    $slug = substr( sanitize_title( remove_accents( $post_type['slug'] ) ), 0, 20 );
                    if ( $slug === '' || in_array( $slug, evk_reserved_post_type_slugs(), true ) ) {
                        $skipped_slugs[] = sanitize_text_field( $post_type['slug'] );
                        continue;
                    }
                    $supports = isset( $post_type['supports'] ) && is_array( $post_type['supports'] ) ? array_keys( $post_type['supports'] ) : array_keys( $available_supports );
                    $supports = array_intersect( array_keys( $available_supports ), $supports );

                    $show_in_menu      = isset( $post_type['show_in_menu'] ) ? 1 : 0;
                    $show_ui           = isset( $post_type['show_ui'] ) ? 1 : 0;
                    $show_in_nav_menus = isset( $post_type['show_in_nav_menus'] ) ? 1 : 0;
                    $show_order        = isset( $post_type['show_order'] ) ? 1 : 0;
                    $has_archive       = isset( $post_type['has_archive'] ) ? 1 : 0;
                    $show_in_rest      = isset( $post_type['show_in_rest'] ) ? 1 : 0;
                    $hierarchical      = isset( $post_type['hierarchical'] ) ? 1 : 0;
                    $private           = isset( $post_type['private'] ) ? 1 : 0;

                    $custom_post_types[] = array(
                        'name'               => sanitize_text_field( $post_type['name'] ),
                        'slug'               => $slug,
                        'private'            => $private,
                        'dashicon'           => sanitize_text_field( $post_type['dashicon'] ),
                        'supports'           => $supports,
                        'show_in_menu'       => $show_in_menu,
                        'show_ui'            => $show_ui,
                        'show_in_nav_menus'  => $show_in_nav_menus,
                        'show_order'         => $show_order,
                        'has_archive'        => $has_archive,
                        'show_in_rest'       => $show_in_rest,
                        'hierarchical'       => $hierarchical,
                        'singular'           => sanitize_text_field( $post_type['singular'] ?? '' ),
                        'menu_name'          => sanitize_text_field( $post_type['menu_name'] ?? '' ),
                        'add_new_item'       => sanitize_text_field( $post_type['add_new_item'] ?? '' ),
                        'all_items'          => sanitize_text_field( $post_type['all_items'] ?? '' ),
                    );
                }
            }

            update_option( 'evk_custom_post_types', $custom_post_types );
            echo '<div class="updated"><p>' . __( 'Zapisano typy treści.', 'evk-repeater' ) . '</p></div>';
            if ( ! empty( $skipped_slugs ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html( sprintf(
                    /* translators: %s = lista slugów */
                    __( 'Pominięto zarezerwowane / puste slugi: %s', 'evk-repeater' ),
                    implode( ', ', $skipped_slugs )
                ) ) . '</p></div>';
            }
        } else {
            update_option( 'evk_custom_post_types', array() );
            echo '<div class="updated"><p>' . __( 'Zapisano typy treści.', 'evk-repeater' ) . '</p></div>';
        }
    }

    $custom_post_types = get_option( 'evk_custom_post_types', array() );

    foreach ( $custom_post_types as &$post_type ) {
        if ( ! isset( $post_type['supports'] ) || ! is_array( $post_type['supports'] ) ) {
            $post_type['supports'] = array_keys( $available_supports );
        }
        if ( ! isset( $post_type['dashicon'] ) || empty( $post_type['dashicon'] ) ) {
            $post_type['dashicon'] = 'dashicons-admin-page';
        }
        $post_type['slug'] = substr( $post_type['slug'], 0, 20 );
        // Always default to enabled for missing/empty new fields
        $post_type['show_in_menu']      = ( isset( $post_type['show_in_menu'] ) && $post_type['show_in_menu'] !== '' ) ? $post_type['show_in_menu'] : 1;
        $post_type['show_ui']           = ( isset( $post_type['show_ui'] ) && $post_type['show_ui'] !== '' ) ? $post_type['show_ui'] : 1;
        $post_type['show_in_nav_menus'] = ( isset( $post_type['show_in_nav_menus'] ) && $post_type['show_in_nav_menus'] !== '' ) ? $post_type['show_in_nav_menus'] : 1;
        $post_type['show_order']        = isset( $post_type['show_order'] ) ? $post_type['show_order'] : 0;
        $post_type['has_archive']       = ( isset( $post_type['has_archive'] ) && $post_type['has_archive'] !== '' ) ? $post_type['has_archive'] : 1;
        $post_type['show_in_rest']      = ( isset( $post_type['show_in_rest'] ) && $post_type['show_in_rest'] !== '' ) ? $post_type['show_in_rest'] : 1;
        $post_type['hierarchical']      = ( isset( $post_type['hierarchical'] ) && $post_type['hierarchical'] !== '' ) ? $post_type['hierarchical'] : 1;
        $post_type['private']           = isset( $post_type['private'] ) ? $post_type['private'] : 0;
    }
    unset( $post_type );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Typy treści', 'evk-repeater' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'evk_save_custom_post_types', 'evk_custom_post_types_nonce' ); ?>
            <div id="custom-post-type-settings">
                <p><?php echo esc_html__( 'Zdefiniuj typy treści: nazwa, slug (maks. 20 znaków), widoczność, ikona i obsługiwane funkcje:', 'evk-repeater' ); ?></p>
                <?php foreach ( $custom_post_types as $index => $post_type ) : ?>
                    <div class="custom-post-type-row" data-index="<?php echo esc_attr( $index ); ?>">
                        <div class="evk-row-head">
                            <span class="evk-row-handle dashicons dashicons-menu" title="<?php echo esc_attr__( 'Przeciągnij', 'evk-repeater' ); ?>"></span>
                            <span class="evk-row-caret dashicons dashicons-arrow-down-alt2"></span>
                            <span class="evk-row-title" data-empty="<?php echo esc_attr__( 'Nowy typ treści', 'evk-repeater' ); ?>"><?php echo esc_html( $post_type['name'] ); ?></span>
                            <div class="evk-row-actions">
                                <button type="button" class="remove-post-type" title="<?php echo esc_attr__( 'Usuń typ treści', 'evk-repeater' ); ?>"><span class="dashicons dashicons-trash"></span></button>
                            </div>
                        </div>
                        <div class="evk-row-body">
                        <div class="post-type-name">
                            <label><?php echo esc_html__( 'Nazwa typu treści', 'evk-repeater' ); ?></label><br>
                            <input type="text" class="evk-row-name" name="custom_post_types[<?php echo esc_attr( $index ); ?>][name]" placeholder="<?php echo esc_attr__( 'Nazwa typu treści', 'evk-repeater' ); ?>" value="<?php echo esc_attr( $post_type['name'] ); ?>" />
                        </div>
                        <div class="post-type-slug">
                            <label><?php echo esc_html__( 'Slug (maks. 20 znaków)', 'evk-repeater' ); ?></label><br>
                            <input type="text" name="custom_post_types[<?php echo esc_attr( $index ); ?>][slug]" placeholder="<?php echo esc_attr__( 'slug-wpisu', 'evk-repeater' ); ?>" value="<?php echo esc_attr( $post_type['slug'] ); ?>" maxlength="20" />
                        </div>
                        <div class="post-type-icon">
                            <label><?php echo esc_html__( 'Dashicon', 'evk-repeater' ); ?></label><br>
                            <input type="hidden" name="custom_post_types[<?php echo esc_attr( $index ); ?>][dashicon]" value="<?php echo esc_attr( $post_type['dashicon'] ); ?>" class="dashicon-value-input" />
                            <button type="button" class="dashicon-picker-btn" title="<?php echo esc_attr__( 'Kliknij, aby wybrać ikonę', 'evk-repeater' ); ?>">
                                <span class="dashicons <?php echo esc_attr( $post_type['dashicon'] ); ?>"></span>
                                <span class="dashicon-btn-label"><?php echo esc_html( str_replace( 'dashicons-', '', $post_type['dashicon'] ) ); ?></span>
                            </button>
                        </div>
                        <!-- Supports Section -->
                        <div class="advanced-settings-wrap">
                            <button type="button" class="toggle-advanced-btn">Ustawienia zaawansowane ▼</button>
                            <div class="supports-section" style="display:none;">
                                <?php foreach ( $available_supports as $key => $label ) : ?>
                                    <label>
                                        <input type="checkbox" name="custom_post_types[<?php echo esc_attr( $index ); ?>][supports][<?php echo esc_attr( $key ); ?>]" <?php checked( in_array( $key, $post_type['supports'], true ), true ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>

                                <?php foreach ( $additional_options as $opt_key => $opt_label ) : ?>
                                    <label>
                                        <input type="checkbox" name="custom_post_types[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $opt_key ); ?>]" <?php checked( $opt_key === 'show_order' ? (isset($post_type[$opt_key]) && $post_type[$opt_key]) : (!isset($post_type[$opt_key]) || $post_type[$opt_key]), 1 ); ?> />
                                        <?php echo esc_html( $opt_label ); ?>
                                    </label>
                                <?php endforeach; ?>
                                <label>
                                    <input type="checkbox" name="custom_post_types[<?php echo esc_attr( $index ); ?>][private]" <?php checked( isset($post_type['private']) && $post_type['private'], 1 ); ?> />
                                    <?php echo esc_html__( 'Prywatny', 'evk-repeater' ); ?>
                                </label>
                                <div class="evk-cpt-labels">
                                    <p class="evk-cpt-labels-title"><?php echo esc_html__( 'Etykiety / przyciski', 'evk-repeater' ); ?> <span><?php echo esc_html__( '(opcjonalne — domyślnie z nazwy)', 'evk-repeater' ); ?></span></p>
                                    <label><?php echo esc_html__( 'Nazwa (l. pojedyncza)', 'evk-repeater' ); ?>
                                        <input type="text" name="custom_post_types[<?php echo esc_attr( $index ); ?>][singular]" value="<?php echo esc_attr( $post_type['singular'] ?? '' ); ?>" placeholder="np. Książka" />
                                    </label>
                                    <label><?php echo esc_html__( 'Nazwa w menu', 'evk-repeater' ); ?>
                                        <input type="text" name="custom_post_types[<?php echo esc_attr( $index ); ?>][menu_name]" value="<?php echo esc_attr( $post_type['menu_name'] ?? '' ); ?>" placeholder="np. Książki" />
                                    </label>
                                    <label><?php echo esc_html__( 'Przycisk „Dodaj nowy”', 'evk-repeater' ); ?>
                                        <input type="text" name="custom_post_types[<?php echo esc_attr( $index ); ?>][add_new_item]" value="<?php echo esc_attr( $post_type['add_new_item'] ?? '' ); ?>" placeholder="np. Dodaj książkę" />
                                    </label>
                                    <label><?php echo esc_html__( 'Etykieta „Wszystkie”', 'evk-repeater' ); ?>
                                        <input type="text" name="custom_post_types[<?php echo esc_attr( $index ); ?>][all_items]" value="<?php echo esc_attr( $post_type['all_items'] ?? '' ); ?>" placeholder="np. Wszystkie książki" />
                                    </label>
                                </div>
                            </div>
                        </div>
                        <!-- End of Supports Section -->
                        </div><!-- /.evk-row-body -->
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-custom-post-type-row" class="button"><span class="dashicons dashicons-plus-alt2"></span> <?php echo esc_html__( 'Dodaj typ treści', 'evk-repeater' ); ?></button>
            <br><br>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr__( 'Zapisz typy treści', 'evk-repeater' ); ?>"></p>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fieldContainer = document.getElementById('custom-post-type-settings');
            const addFieldButton = document.getElementById('add-custom-post-type-row');

            const availableSupports = {
                'title': '<?php echo esc_js( __( 'Tytuł', 'evk-repeater' ) ); ?>',
                'editor': '<?php echo esc_js( __( 'Edytor', 'evk-repeater' ) ); ?>',
                'notes': '<?php echo esc_js( __( 'Notes', 'evk-repeater' ) ); ?>',
                'thumbnail': '<?php echo esc_js( __( 'Miniatura', 'evk-repeater' ) ); ?>',
                'author': '<?php echo esc_js( __( 'Autor', 'evk-repeater' ) ); ?>',
                'excerpt': '<?php echo esc_js( __( 'Zajawka', 'evk-repeater' ) ); ?>',
                'comments': '<?php echo esc_js( __( 'Komentarze', 'evk-repeater' ) ); ?>',
                'custom-fields': '<?php echo esc_js( __( 'Pola własne', 'evk-repeater' ) ); ?>',
                'revisions': '<?php echo esc_js( __( 'Wersje', 'evk-repeater' ) ); ?>',
                'page-attributes': '<?php echo esc_js( __( 'Atrybuty strony', 'evk-repeater' ) ); ?>'
            };

            const additionalOptions = {
                'show_in_menu': '<?php echo esc_js( __( 'Pokaż w menu', 'evk-repeater' ) ); ?>',
                'show_ui': '<?php echo esc_js( __( 'Pokaż UI', 'evk-repeater' ) ); ?>',
                'show_in_nav_menus': '<?php echo esc_js( __( 'Pokaż w menu nawigacji', 'evk-repeater' ) ); ?>',
                'show_order': '<?php echo esc_js( __( 'Pokaż kolejność', 'evk-repeater' ) ); ?>',
                'has_archive': '<?php echo esc_js( __( 'Strona archiwum', 'evk-repeater' ) ); ?>',
                'show_in_rest': '<?php echo esc_js( __( 'Pokaż w REST (Gutenberg/API)', 'evk-repeater' ) ); ?>',
                'hierarchical': '<?php echo esc_js( __( 'Hierarchiczny', 'evk-repeater' ) ); ?>'
            };

            // Transliteracja: usuwa tylko znaki diakrytyczne (ł→l, ą→a, ż→z…), nie całe litery.
            function evkTranslit(value) {
                value = String(value).replace(/[łŁđĐøØßæÆœŒþ]/g, function (c) {
                    return { 'ł': 'l', 'Ł': 'L', 'đ': 'd', 'Đ': 'D', 'ø': 'o', 'Ø': 'O', 'ß': 'ss', 'æ': 'ae', 'Æ': 'AE', 'œ': 'oe', 'Œ': 'OE', 'þ': 'th' }[c];
                });
                return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }

            function slugify(value) {
                value = evkTranslit(value);
                value = value.toLowerCase();
                value = value.replace(/\s+/g, '-');
                value = value.replace(/[^a-z0-9\-]/g, '');
                value = value.replace(/-+/g, '-');
                return value.slice(0, 20); // enforce max-20 char slug
            }

            function attachSlugListener(input) {
                input.addEventListener('input', function() {
                    this.value = slugify(this.value);
                });
            }

            document.querySelectorAll('input[name*="[slug]"]').forEach(function(input) {
                input.setAttribute('maxlength', '20'); // ensure existing inputs observe max length
                attachSlugListener(input);
            });

            function updateFieldIndexes() {
                const rows = fieldContainer.querySelectorAll('.custom-post-type-row');
                rows.forEach((row, index) => {
                    row.dataset.index = index;
                    const inputs = row.querySelectorAll('input, select');
                    inputs.forEach(input => {
                        if (input.name) {
                            input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
                        }
                    });
                });
            }

            function generateSupportsHTML(index) {
                let inner = '';
                for (const [key, label] of Object.entries(availableSupports)) {
                    const isChecked = key !== 'comments';
                    inner += `
                        <label>
                            <input type="checkbox" name="custom_post_types[${index}][supports][${key}]" ${isChecked ? 'checked' : ''} />
                            ${label}
                        </label>
                    `;
                    if (key === 'page-attributes') {
                        for (const [optKey, optLabel] of Object.entries(additionalOptions)) {
                            const isChecked = optKey !== 'show_order';
                            inner += `
                                <label>
                                    <input type="checkbox" name="custom_post_types[${index}][${optKey}]" ${isChecked ? 'checked' : ''} />
                                    ${optLabel}
                                </label>
                            `;
                        }
                        inner += `
                            <label>
                                <input type="checkbox" name="custom_post_types[${index}][private]" />
                                <?php echo esc_html__( 'Prywatny', 'evk-repeater' ); ?>
                            </label>
                        `;
                        inner += `
                            <div class="evk-cpt-labels">
                                <p class="evk-cpt-labels-title"><?php echo esc_html__( 'Etykiety / przyciski', 'evk-repeater' ); ?> <span><?php echo esc_html__( '(opcjonalne — domyślnie z nazwy)', 'evk-repeater' ); ?></span></p>
                                <label><?php echo esc_html__( 'Nazwa (l. pojedyncza)', 'evk-repeater' ); ?>
                                    <input type="text" name="custom_post_types[${index}][singular]" placeholder="np. Książka" />
                                </label>
                                <label><?php echo esc_html__( 'Nazwa w menu', 'evk-repeater' ); ?>
                                    <input type="text" name="custom_post_types[${index}][menu_name]" placeholder="np. Książki" />
                                </label>
                                <label><?php echo esc_html__( 'Przycisk „Dodaj nowy”', 'evk-repeater' ); ?>
                                    <input type="text" name="custom_post_types[${index}][add_new_item]" placeholder="np. Dodaj książkę" />
                                </label>
                                <label><?php echo esc_html__( 'Etykieta „Wszystkie”', 'evk-repeater' ); ?>
                                    <input type="text" name="custom_post_types[${index}][all_items]" placeholder="np. Wszystkie książki" />
                                </label>
                            </div>
                        `;
                    }
                }
                return `<div class="advanced-settings-wrap">
                    <button type="button" class="toggle-advanced-btn">Ustawienia zaawansowane ▼</button>
                    <div class="supports-section" style="display:none;">${inner}</div>
                </div>`;
            }

            addFieldButton.addEventListener('click', function() {
                const newIndex = fieldContainer.querySelectorAll('.custom-post-type-row').length;
                const newRow = document.createElement('div');
                newRow.classList.add('custom-post-type-row');
                newRow.dataset.index = newIndex;
                newRow.innerHTML = `
                    <div class="evk-row-head">
                        <span class="evk-row-handle dashicons dashicons-menu" title="<?php echo esc_attr( esc_js( __( 'Przeciągnij', 'evk-repeater' ) ) ); ?>"></span>
                        <span class="evk-row-caret dashicons dashicons-arrow-down-alt2"></span>
                        <span class="evk-row-title" data-empty="<?php echo esc_js( __( 'Nowy typ treści', 'evk-repeater' ) ); ?>"></span>
                        <div class="evk-row-actions">
                            <button type="button" class="remove-post-type" title="<?php echo esc_attr( esc_js( __( 'Usuń typ treści', 'evk-repeater' ) ) ); ?>"><span class="dashicons dashicons-trash"></span></button>
                        </div>
                    </div>
                    <div class="evk-row-body">
                    <div class="post-type-name">
                        <label><?php echo esc_html__( 'Nazwa typu treści', 'evk-repeater' ); ?></label><br>
                        <input type="text" class="evk-row-name" name="custom_post_types[${newIndex}][name]" placeholder="<?php echo esc_attr__( 'Nazwa typu treści', 'evk-repeater' ); ?>" />
                    </div>
                    <div class="post-type-slug">
                        <label><?php echo esc_html__( 'Slug (maks. 20 znaków)', 'evk-repeater' ); ?></label><br>
                        <input type="text" name="custom_post_types[${newIndex}][slug]" placeholder="<?php echo esc_attr__( 'slug-wpisu', 'evk-repeater' ); ?>" maxlength="20" />
                    </div>
                    <div class="post-type-icon">
                        <label><?php echo esc_html__( 'Dashicon', 'evk-repeater' ); ?></label><br>
                        <input type="hidden" name="custom_post_types[${newIndex}][dashicon]" value="dashicons-admin-page" class="dashicon-value-input" />
                        <button type="button" class="dashicon-picker-btn" title="<?php echo esc_attr__( 'Kliknij, aby wybrać ikonę', 'evk-repeater' ); ?>">
                            <span class="dashicons dashicons-admin-page"></span>
                            <span class="dashicon-btn-label">admin-page</span>
                        </button>
                    </div>
                    <!-- Supports Section -->
                    ${generateSupportsHTML(newIndex)}
                    <!-- End of Supports Section -->
                    </div>
                `;
                fieldContainer.appendChild(newRow);

                const newSlugInput = newRow.querySelector('input[name*="[slug]"]');
                if (newSlugInput) {
                    attachSlugListener(newSlugInput);
                }
            });

            fieldContainer.addEventListener('click', function(event) {
                // Zwijanie/rozwijanie — klik w nagłówek (poza akcjami i uchwytem przeciągania)
                const head = event.target.closest('.evk-row-head');
                if (head && !event.target.closest('.evk-row-actions') && !event.target.closest('.evk-row-handle')) {
                    head.closest('.custom-post-type-row').classList.toggle('collapsed');
                    return;
                }

                if (event.target.closest('.remove-post-type')) {
                    if (confirm('<?php echo esc_js( __( 'Na pewno usunąć ten typ treści?', 'evk-repeater' ) ); ?>')) {
                        event.target.closest('.custom-post-type-row').remove();
                        updateFieldIndexes();
                    }
                    return;
                }

                const advBtn = event.target.closest('.toggle-advanced-btn');
                if (advBtn) {
                    const section = advBtn.nextElementSibling;
                    const isHidden = section.style.display === 'none';
                    section.style.display = isHidden ? 'flex' : 'none';
                    advBtn.textContent = isHidden
                        ? '<?php echo esc_js( __( 'Ustawienia zaawansowane ▲', 'evk-repeater' ) ); ?>'
                        : '<?php echo esc_js( __( 'Ustawienia zaawansowane ▼', 'evk-repeater' ) ); ?>';
                }
            });

            // Tytuł nagłówka = nazwa typu treści (na żywo)
            fieldContainer.addEventListener('input', function(event) {
                if (event.target.classList.contains('evk-row-name')) {
                    const row = event.target.closest('.custom-post-type-row');
                    const title = row && row.querySelector('.evk-row-title');
                    if (title) title.textContent = event.target.value.trim();
                }
            });

            // Sortowanie wierszy uchwytem (jQuery UI) — zamiast przycisków ↑/↓
            if (window.jQuery && jQuery.fn.sortable) {
                jQuery(fieldContainer).sortable({
                    items: '> .custom-post-type-row',
                    handle: '.evk-row-handle',
                    placeholder: 'evk-sort-placeholder',
                    forcePlaceholderSize: true,
                    update: updateFieldIndexes
                });
            }
        });
        </script>
        <?php evk_dashicon_picker_assets(); // modal + JS + CSS pickera (współdzielone) ?>

    </div>
    <?php
}

add_action( 'init', 'evk_register_custom_post_types' );

function evk_register_custom_post_types() {
    $custom_post_types = get_option( 'evk_custom_post_types', array() );

    foreach ( $custom_post_types as $post_type ) {
        $default_supports = array( 'title', 'editor', 'thumbnail', 'author', 'excerpt', 'comments', 'custom-fields', 'revisions', 'page-attributes' );
        $supports = isset( $post_type['supports'] ) && is_array( $post_type['supports'] ) ? $post_type['supports'] : $default_supports;

        $allowed_supports = array(
            'title',
            'editor',
            'notes',
            'thumbnail',
            'author',
            'excerpt',
            'comments',
            'custom-fields',
            'revisions',
            'page-attributes'
        );
        $supports = array_intersect( $supports, $allowed_supports );

        $enable_notes = in_array( 'notes', $supports, true );
        $supports = array_diff( $supports, array( 'notes' ) );

        $pt_name     = $post_type['name'];
        $pt_singular = ! empty( $post_type['singular'] )     ? $post_type['singular']     : $pt_name;
        $pt_menu     = ! empty( $post_type['menu_name'] )    ? $post_type['menu_name']    : $pt_name;
        $pt_add      = ! empty( $post_type['add_new_item'] ) ? $post_type['add_new_item'] : 'Dodaj: ' . $pt_singular;
        $pt_all      = ! empty( $post_type['all_items'] )    ? $post_type['all_items']    : $pt_name;
        $pt_labels = array(
            'name'                  => $pt_name,
            'singular_name'         => $pt_singular,
            'menu_name'             => $pt_menu,
            'name_admin_bar'        => $pt_singular,
            'add_new'               => $pt_add,
            'add_new_item'          => $pt_add,
            'new_item'              => 'Nowy: ' . $pt_singular,
            'edit_item'             => 'Edytuj: ' . $pt_singular,
            'view_item'             => 'Zobacz: ' . $pt_singular,
            'view_items'            => 'Zobacz: ' . $pt_name,
            'all_items'             => $pt_all,
            'search_items'          => 'Szukaj: ' . $pt_name,
            'parent_item_colon'     => 'Nadrzędny: ' . $pt_singular,
            'not_found'             => 'Nie znaleziono.',
            'not_found_in_trash'    => 'Brak w koszu.',
            'archives'              => 'Archiwum: ' . $pt_name,
            'attributes'            => 'Atrybuty: ' . $pt_singular,
            'insert_into_item'      => 'Wstaw do: ' . $pt_singular,
            'uploaded_to_this_item' => 'Przesłane do: ' . $pt_singular,
            'item_published'        => $pt_singular . ' — opublikowano.',
            'item_updated'          => $pt_singular . ' — zaktualizowano.',
        );

        $args = array(
            'label'             => $pt_name,
            'labels'            => $pt_labels,
            'public'            => ! (bool) $post_type['private'],
            'has_archive'       => ( isset($post_type['has_archive']) && $post_type['has_archive'] !== '' ) ? (bool)$post_type['has_archive'] : true,
            'supports'          => ! empty( $supports ) ? $supports : $default_supports,
            'show_in_rest'      => ( isset($post_type['show_in_rest']) && $post_type['show_in_rest'] !== '' ) ? (bool)$post_type['show_in_rest'] : true,
            'menu_position'     => 20,
            'menu_icon'         => ! empty( $post_type['dashicon'] ) ? $post_type['dashicon'] : 'dashicons-admin-page',
            'hierarchical'      => ( isset($post_type['hierarchical']) && $post_type['hierarchical'] !== '' ) ? (bool)$post_type['hierarchical'] : true,
            'show_in_menu'      => ( isset($post_type['show_in_menu']) && $post_type['show_in_menu'] !== '' ) ? (bool)$post_type['show_in_menu'] : true,
            'show_ui'           => ( isset($post_type['show_ui']) && $post_type['show_ui'] !== '' ) ? (bool)$post_type['show_ui'] : true,
            'show_in_nav_menus' => ( isset($post_type['show_in_nav_menus']) && $post_type['show_in_nav_menus'] !== '' ) ? (bool)$post_type['show_in_nav_menus'] : true,
        );

        $post_type_slug = substr( $post_type['slug'], 0, 20 );
        register_post_type( $post_type_slug, $args );

        if ( $enable_notes ) {
            add_post_type_support( $post_type_slug, 'editor', array( 'notes' => true ) );
        }

        // Add Order column if show_order is enabled
        if ( isset( $post_type['show_order'] ) && $post_type['show_order'] ) {
            add_filter( "manage_{$post_type_slug}_posts_columns", function( $columns ) {
                $new_columns = array();
                foreach ( $columns as $key => $value ) {
                    $new_columns[$key] = $value;
                    if ( $key === 'title' ) {
                        $new_columns['menu_order'] = __( 'Kolejność', 'evk-repeater' );
                    }
                }
                return $new_columns;
            } );

            add_action( "manage_{$post_type_slug}_posts_custom_column", function( $column_name, $post_id ) {
                if ( $column_name === 'menu_order' ) {
                    $post = get_post( $post_id );
                    echo esc_html( $post->menu_order );
                }
            }, 10, 2 );

            add_filter( "manage_edit-{$post_type_slug}_sortable_columns", function( $columns ) {
                $columns['menu_order'] = 'menu_order';
                return $columns;
            } );
        }
    }
}
