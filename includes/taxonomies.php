<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Slugi taksonomii zarezerwowane przez WordPress — nie wolno ich nadpisywać.
 */
function evk_reserved_taxonomy_slugs() {
    return array(
        'category', 'post_tag', 'nav_menu', 'link_category', 'post_format',
        'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
    );
}

add_action( 'admin_menu', 'evk_add_taxonomy_submenu' );

function evk_add_taxonomy_submenu() {
    add_submenu_page(
        'evk-repeater',
        esc_html__( 'Taksonomie — rejestracja', 'evk-repeater' ),
        esc_html__( 'Taksonomie', 'evk-repeater' ),
        EVK_REP_CAP,
        'evk-tax',
        'evk_render_taxonomies_page'
    );
}

function evk_render_taxonomies_page() {

    if ( ! evk_rep_can_manage() ) {
        return;
    }

    if ( isset( $_POST['evk_taxonomies_nonce'] ) && wp_verify_nonce( $_POST['evk_taxonomies_nonce'], 'evk_save_taxonomies' ) ) {
        if ( ! isset( $_POST['taxonomies'] ) || ! is_array( $_POST['taxonomies'] ) || empty( $_POST['taxonomies'] ) ) {
            update_option( 'evk_taxonomies', array() );
            evk_rep_schedule_rewrite_flush();
            echo '<div class="updated"><p>' . esc_html__( 'Zapisano taksonomie.', 'evk-repeater' ) . '</p></div>';
        } else {
            $taxonomies    = array();
            $slugs_seen    = array();
            $skipped_slugs = array();

            // Ensure we iterate over ALL submitted taxonomies, regardless of index gaps
            // array_values() re-indexes the array sequentially, fixing deletion/reordering issues
            $submitted_taxonomies = array_values( $_POST['taxonomies'] );

            foreach ( $submitted_taxonomies as $taxonomy ) {
                // Validate required fields
                if ( empty( $taxonomy['name'] ) || empty( $taxonomy['slug'] ) ) {
                    continue; // Skip invalid entries
                }

                // Default to 'post' if no post types are selected (better UX - don't waste user's work)
                if ( ! isset( $taxonomy['post_types'] ) || ! is_array( $taxonomy['post_types'] ) || empty( $taxonomy['post_types'] ) ) {
                    $taxonomy['post_types'] = array( 'post' );
                }

                $sanitized_slug = substr( sanitize_title( remove_accents( $taxonomy['slug'] ) ), 0, 32 );

                // Skip reserved / empty slugs (would clash with native taxonomies)
                if ( $sanitized_slug === '' || in_array( $sanitized_slug, evk_reserved_taxonomy_slugs(), true ) ) {
                    $skipped_slugs[] = sanitize_text_field( $taxonomy['slug'] );
                    continue;
                }

                // Check for duplicate slugs
                if ( in_array( $sanitized_slug, $slugs_seen ) ) {
                    continue; // Skip duplicate slugs
                }

                $slugs_seen[] = $sanitized_slug;

                $taxonomies[] = array(
                    'name'         => sanitize_text_field( $taxonomy['name'] ),
                    'slug'         => $sanitized_slug,
                    'hierarchical' => isset( $taxonomy['hierarchical'] ) ? 1 : 0,
                    'post_types'   => array_map( 'sanitize_text_field', $taxonomy['post_types'] ),
                    'add_columns'  => isset( $taxonomy['add_columns'] ) ? 1 : 0,
                    'meta_box'     => in_array( $taxonomy['meta_box'] ?? '', array( 'select', 'radio' ), true ) ? $taxonomy['meta_box'] : 'default',
                    'dep_tax'      => sanitize_key( $taxonomy['dep_tax'] ?? '' ),
                    'dep_key'      => sanitize_key( $taxonomy['dep_key'] ?? '' ),
                );
            }

            update_option( 'evk_taxonomies', $taxonomies );
            evk_rep_schedule_rewrite_flush();

            echo '<div class="updated"><p>' . esc_html__( 'Zapisano taksonomie.', 'evk-repeater' ) . '</p></div>';
            if ( ! empty( $skipped_slugs ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html( sprintf(
                    /* translators: %s = lista slugów */
                    __( 'Pominięto zarezerwowane / puste slugi: %s', 'evk-repeater' ),
                    implode( ', ', $skipped_slugs )
                ) ) . '</p></div>';
            }
        }
    }


    // Retrieve existing taxonomies
    $taxonomies = get_option( 'evk_taxonomies', array() );

    // Retrieve all registered post types for association
    $registered_post_types = get_post_types( array( 'public' => true ), 'objects' );
    ?>
    <div class="wrap">
    <h1><?php esc_html_e( 'Taksonomie', 'evk-repeater' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'evk_save_taxonomies', 'evk_taxonomies_nonce' ); ?>
            <div id="taxonomy-settings">
                <p><?php esc_html_e( 'Zdefiniuj taksonomie: nazwa, slug, hierarchia i powiązane typy treści:', 'evk-repeater' ); ?></p>
                <?php foreach ( $taxonomies as $index => $taxonomy ) : ?>
                    <div class="taxonomy-row" data-index="<?php echo esc_attr( $index ); ?>">
                        <div class="evk-row-head">
                            <span class="evk-row-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Przeciągnij', 'evk-repeater' ); ?>"></span>
                            <span class="evk-row-caret dashicons dashicons-arrow-down-alt2"></span>
                            <span class="evk-row-title" data-empty="<?php esc_attr_e( 'Nowa taksonomia', 'evk-repeater' ); ?>"><?php echo esc_html( $taxonomy['name'] ); ?></span>
                            <div class="evk-row-actions">
                                <button type="button" class="remove-taxonomy" title="<?php esc_attr_e( 'Usuń taksonomię', 'evk-repeater' ); ?>"><span class="dashicons dashicons-trash"></span></button>
                            </div>
                        </div>
                        <div class="evk-row-body">
                            <div class="field-group">
                                <label><?php esc_html_e( 'Nazwa taksonomii', 'evk-repeater' ); ?></label><br>
                                <input type="text" class="evk-row-name" name="taxonomies[<?php echo esc_attr( $index ); ?>][name]" placeholder="Taxonomy Name" value="<?php echo esc_attr( $taxonomy['name'] ); ?>" />
                            </div>
                            <div class="field-group">
                                <label><?php esc_html_e( 'Slug taksonomii', 'evk-repeater' ); ?></label><br>
                                <input type="text" class="taxonomy-slug" name="taxonomies[<?php echo esc_attr( $index ); ?>][slug]" placeholder="taxonomy-slug" value="<?php echo esc_attr( $taxonomy['slug'] ); ?>" />
                            </div>
                            <div class="field-group evk-tax-pt-field">
                                <label><?php esc_html_e( 'Powiązane typy treści', 'evk-repeater' ); ?></label><br>
                                <div class="evk-sp-tab-groups evk-tax-pt-grid">
                                    <?php foreach ( $registered_post_types as $post_type ) : ?>
                                        <label class="evk-sp-group-pick">
                                            <input type="checkbox" name="taxonomies[<?php echo esc_attr( $index ); ?>][post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $taxonomy['post_types'], true ) ); ?>>
                                            <?php echo esc_html( $post_type->label ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="field-group">
                                <label><?php esc_html_e( 'Hierarchiczna', 'evk-repeater' ); ?></label><br>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="taxonomies[<?php echo esc_attr( $index ); ?>][hierarchical]" <?php checked( $taxonomy['hierarchical'], 1 ); ?> />
                                </div>
                            </div>
                            <div class="field-group">
                                <label><?php esc_html_e( 'Pokaż kolumny', 'evk-repeater' ); ?></label><br>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="taxonomies[<?php echo esc_attr( $index ); ?>][add_columns]" <?php checked( isset( $taxonomy['add_columns'] ) ? $taxonomy['add_columns'] : 0, 1 ); ?> />
                                </div>
                            </div>
                            <div class="field-group">
                                <label><?php esc_html_e( 'Metabox wyboru', 'evk-repeater' ); ?></label><br>
                                <select name="taxonomies[<?php echo esc_attr( $index ); ?>][meta_box]">
                                    <option value="default" <?php selected( $taxonomy['meta_box'] ?? 'default', 'default' ); ?>><?php esc_html_e( 'Checkboxy (WP, wielokrotny)', 'evk-repeater' ); ?></option>
                                    <option value="select" <?php selected( $taxonomy['meta_box'] ?? '', 'select' ); ?>><?php esc_html_e( 'Lista rozwijana (pojedynczy)', 'evk-repeater' ); ?></option>
                                    <option value="radio" <?php selected( $taxonomy['meta_box'] ?? '', 'radio' ); ?>><?php esc_html_e( 'Radio (pojedynczy)', 'evk-repeater' ); ?></option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label><?php esc_html_e( 'Filtruj opcje wg taksonomii', 'evk-repeater' ); ?></label><br>
                                <select name="taxonomies[<?php echo esc_attr( $index ); ?>][dep_tax]" style="margin-bottom:6px;">
                                    <option value=""><?php esc_html_e( '— bez filtrowania —', 'evk-repeater' ); ?></option>
                                    <?php foreach ( $taxonomies as $other ) : if ( ( $other['slug'] ?? '' ) === ( $taxonomy['slug'] ?? '' ) ) continue; ?>
                                    <option value="<?php echo esc_attr( $other['slug'] ); ?>" <?php selected( $taxonomy['dep_tax'] ?? '', $other['slug'] ); ?>><?php echo esc_html( $other['name'] . ' (' . $other['slug'] . ')' ); ?></option>
                                    <?php endforeach; ?>
                                </select><br>
                                <input type="text" name="taxonomies[<?php echo esc_attr( $index ); ?>][dep_key]" value="<?php echo esc_attr( $taxonomy['dep_key'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'klucz pola relacji (term meta)', 'evk-repeater' ); ?>">
                                <p class="description" style="margin:6px 0 0;"><?php esc_html_e( 'Wymaga metaboxa Lista/Radio. Klucz = pole typu Taksonomia (EVK) na termach TEJ taksonomii, wskazujące termy taksonomii nadrzędnej.', 'evk-repeater' ); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-taxonomy-row" class="button"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Dodaj taksonomię', 'evk-repeater' ); ?></button>
            <br><br>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Zapisz taksonomie', 'evk-repeater' ); ?>"></p>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fieldContainer = document.getElementById('taxonomy-settings');
            const addFieldButton = document.getElementById('add-taxonomy-row');

            // Function to update the name attributes with the correct index
            function updateFieldIndexes() {
                const rows = fieldContainer.querySelectorAll('.taxonomy-row');
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

            function sanitizeSlug(value, trimDashes = false) {
                // Litery bez rozk\u0142adu NFD (m.in. polskie \u0142) \u2014 mapujemy r\u0119cznie, by nie znika\u0142y
                value = String(value).replace(/[\u0142\u0141\u0111\u0110\u00f8\u00d8\u00df\u00e6\u00c6\u0153\u0152\u00fe]/g, function (c) {
                    return { '\u0142': 'l', '\u0141': 'L', '\u0111': 'd', '\u0110': 'D', '\u00f8': 'o', '\u00d8': 'O', '\u00df': 'ss', '\u00e6': 'ae', '\u00c6': 'AE', '\u0153': 'oe', '\u0152': 'OE', '\u00fe': 'th' }[c];
                });
                // Normalize string to decompose accented characters (usuwa tylko diakrytyki)
                value = value.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                // Convert to lowercase
                value = value.toLowerCase();
                // Replace spaces and underscores with dashes
                value = value.replace(/[\s_]+/g, "-");
                // Remove disallowed characters (only allow a-z, 0-9, and dashes)
                value = value.replace(/[^a-z0-9\-]/g, "");
                // Remove multiple consecutive dashes
                value = value.replace(/-+/g, "-");
                if (trimDashes) {
                    // Remove leading and trailing dashes
                    value = value.replace(/^-+|-+$/g, "");
                }
                return value;
            }

            // Listen for input events on any slug input (existing or new)
            fieldContainer.addEventListener('input', function(event) {
                if (event.target.classList.contains('taxonomy-slug')) {
                    event.target.value = sanitizeSlug(event.target.value, false);
                }
            });

            // Trim leading/trailing dashes when leaving the field
            fieldContainer.addEventListener('blur', function(event) {
                if (event.target.classList.contains('taxonomy-slug')) {
                    event.target.value = sanitizeSlug(event.target.value, true);
                }
            }, true);

            /**
             * Adds a new taxonomy row.
             */
            addFieldButton.addEventListener('click', function() {
                const newIndex = fieldContainer.querySelectorAll('.taxonomy-row').length;
                const newRow = document.createElement('div');
                newRow.classList.add('taxonomy-row');
                newRow.dataset.index = newIndex;
                newRow.innerHTML = `
                    <div class="evk-row-head">
                        <span class="evk-row-handle dashicons dashicons-menu" title="<?php echo esc_attr( esc_js( __( 'Przeciągnij', 'evk-repeater' ) ) ); ?>"></span>
                        <span class="evk-row-caret dashicons dashicons-arrow-down-alt2"></span>
                        <span class="evk-row-title" data-empty="<?php echo esc_js( __( 'Nowa taksonomia', 'evk-repeater' ) ); ?>"></span>
                        <div class="evk-row-actions">
                            <button type="button" class="remove-taxonomy" title="<?php echo esc_attr( esc_js( __( 'Usuń taksonomię', 'evk-repeater' ) ) ); ?>"><span class="dashicons dashicons-trash"></span></button>
                        </div>
                    </div>
                    <div class="evk-row-body">
                        <div class="field-group">
                            <label><?php esc_html_e( 'Nazwa taksonomii', 'evk-repeater' ); ?></label><br>
                            <input type="text" class="evk-row-name" name="taxonomies[${newIndex}][name]" placeholder="Taxonomy Name" />
                        </div>
                        <div class="field-group">
                            <label><?php esc_html_e( 'Slug taksonomii', 'evk-repeater' ); ?></label><br>
                            <input type="text" class="taxonomy-slug" name="taxonomies[${newIndex}][slug]" placeholder="taxonomy-slug" />
                        </div>
                        <div class="field-group evk-tax-pt-field">
                            <label><?php esc_html_e( 'Powiązane typy treści', 'evk-repeater' ); ?></label><br>
                            <div class="evk-sp-tab-groups evk-tax-pt-grid">
                                <?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) : ?>
                                    <label class="evk-sp-group-pick">
                                        <input type="checkbox" name="taxonomies[${newIndex}][post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>">
                                        <?php echo esc_html( $post_type->label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="field-group">
                            <label><?php esc_html_e( 'Hierarchiczna', 'evk-repeater' ); ?></label><br>
                            <div class="checkbox-container">
                                <input type="checkbox" name="taxonomies[${newIndex}][hierarchical]" />
                            </div>
                        </div>
                        <div class="field-group">
                            <label><?php esc_html_e( 'Pokaż kolumny', 'evk-repeater' ); ?></label><br>
                            <div class="checkbox-container">
                                <input type="checkbox" name="taxonomies[${newIndex}][add_columns]" />
                            </div>
                        </div>
                        <div class="field-group">
                            <label><?php esc_html_e( 'Metabox wyboru', 'evk-repeater' ); ?></label><br>
                            <select name="taxonomies[${newIndex}][meta_box]">
                                <option value="default"><?php echo esc_js( __( 'Checkboxy (WP, wielokrotny)', 'evk-repeater' ) ); ?></option>
                                <option value="select"><?php echo esc_js( __( 'Lista rozwijana (pojedynczy)', 'evk-repeater' ) ); ?></option>
                                <option value="radio"><?php echo esc_js( __( 'Radio (pojedynczy)', 'evk-repeater' ) ); ?></option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label><?php esc_html_e( 'Filtruj opcje wg taksonomii', 'evk-repeater' ); ?></label><br>
                            <select name="taxonomies[${newIndex}][dep_tax]" style="margin-bottom:6px;">
                                <option value=""><?php echo esc_js( __( '— bez filtrowania —', 'evk-repeater' ) ); ?></option>
                                <?php foreach ( (array) get_option( 'evk_taxonomies', array() ) as $other ) : ?>
                                <option value="<?php echo esc_attr( $other['slug'] ); ?>"><?php echo esc_html( $other['name'] . ' (' . $other['slug'] . ')' ); ?></option>
                                <?php endforeach; ?>
                            </select><br>
                            <input type="text" name="taxonomies[${newIndex}][dep_key]" placeholder="<?php echo esc_attr( esc_js( __( 'klucz pola relacji (term meta)', 'evk-repeater' ) ) ); ?>">
                        </div>
                    </div>
                `;
                fieldContainer.appendChild(newRow);
                updateFieldIndexes(); // Ensure indexes are correct after adding
            });

            /**
             * Handles collapse, removal and reordering of taxonomy rows.
             */
            fieldContainer.addEventListener('click', function(event) {
                // Zwijanie/rozwijanie — klik w nagłówek (poza akcjami i uchwytem przeciągania)
                const head = event.target.closest('.evk-row-head');
                if (head && !event.target.closest('.evk-row-actions') && !event.target.closest('.evk-row-handle')) {
                    head.closest('.taxonomy-row').classList.toggle('collapsed');
                    return;
                }

                if (event.target.closest('.remove-taxonomy')) {
                    const row = event.target.closest('.taxonomy-row');
                    if (row) {
                        row.remove();
                        updateFieldIndexes(); // Critical: reindex after removal
                    }
                    return;
                }
            });

            // Sortowanie wierszy uchwytem (jQuery UI) — zamiast przycisków ↑/↓
            if (window.jQuery && jQuery.fn.sortable) {
                jQuery(fieldContainer).sortable({
                    items: '> .taxonomy-row',
                    handle: '.evk-row-handle',
                    placeholder: 'evk-sort-placeholder',
                    forcePlaceholderSize: true,
                    update: updateFieldIndexes
                });
            }

            // Tytuł nagłówka = nazwa taksonomii (na żywo)
            fieldContainer.addEventListener('input', function(event) {
                if (event.target.classList.contains('evk-row-name')) {
                    const row = event.target.closest('.taxonomy-row');
                    const title = row && row.querySelector('.evk-row-title');
                    if (title) title.textContent = event.target.value.trim();
                }
            });

            // Initial index update on page load to ensure consistency
            updateFieldIndexes();
        });
        </script>

    </div>
    <?php
}

/**
 * Registers the custom taxonomies based on saved configurations.
 */
add_action( 'init', 'evk_register_custom_taxonomies' );

function evk_register_custom_taxonomies() {
    $taxonomies = get_option( 'evk_taxonomies', array() );

    foreach ( $taxonomies as $taxonomy ) {
        // Ensure associated post types exist
        $post_types = array_filter( $taxonomy['post_types'], function( $pt ) {
            return post_type_exists( $pt );
        });

        if ( empty( $post_types ) ) {
            continue; // Skip taxonomy if no valid post types are associated
        }

        $args = array(
            'labels' => array(
                'name'                       => $taxonomy['name'],
                'singular_name'              => $taxonomy['name'],
                'search_items'               => 'Szukaj: ' . $taxonomy['name'],
                'all_items'                  => 'Wszystkie: ' . $taxonomy['name'],
                'most_used'                  => 'Najczęściej używane',
                'parent_item'                => 'Nadrzędny: ' . $taxonomy['name'],
                'parent_item_colon'          => 'Nadrzędny (' . $taxonomy['name'] . '):',
                'edit_item'                  => 'Edytuj: ' . $taxonomy['name'],
                'view_item'                  => 'Zobacz: ' . $taxonomy['name'],
                'update_item'                => 'Zaktualizuj: ' . $taxonomy['name'],
                'add_new_item'               => 'Dodaj: ' . $taxonomy['name'],
                'new_item_name'              => 'Nazwa (' . $taxonomy['name'] . ')',
                'not_found'                  => 'Nie znaleziono.',
                'no_terms'                   => 'Brak elementów',
                'separate_items_with_commas' => 'Oddziel przecinkami',
                'add_or_remove_items'        => 'Dodaj lub usuń',
                'choose_from_most_used'      => 'Wybierz z najczęściej używanych',
                'back_to_items'              => '&larr; Wróć do: ' . $taxonomy['name'],
                'menu_name'                  => $taxonomy['name'],
            ),
            'hierarchical'      => (bool) $taxonomy['hierarchical'],
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => ( isset( $taxonomy['add_columns'] ) && $taxonomy['add_columns'] == 1 ) ? true : false,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => $taxonomy['slug'] ),
        );

        // Własny metabox wyboru (pojedynczy select/radio) zamiast checkboxów WP.
        if ( in_array( $taxonomy['meta_box'] ?? '', array( 'select', 'radio' ), true ) ) {
            $args['meta_box_cb'] = 'evk_tax_single_meta_box';
        }

        register_taxonomy( $taxonomy['slug'], $post_types, $args );
    }
}

// =========================================================================
// METABOX POJEDYNCZEGO WYBORU (select / radio) — zamiast checkboxów WP
// =========================================================================

/** Pełna konfiguracja taksonomii (wiersz opcji) po slugu. */
function evk_tax_mb_config( string $slug ): array {
    foreach ( (array) get_option( 'evk_taxonomies', array() ) as $t ) {
        if ( ( $t['slug'] ?? '' ) === $slug ) return (array) $t;
    }
    return array();
}

/** Styl metaboxa ('select'/'radio'/'default') z konfiguracji taksonomii. */
function evk_tax_meta_box_style( string $slug ): string {
    $t = evk_tax_mb_config( $slug );
    return in_array( $t['meta_box'] ?? '', array( 'select', 'radio' ), true ) ? $t['meta_box'] : 'default';
}

/** Callback meta_box_cb — WP przekazuje $box['args']['taxonomy']. */
function evk_tax_single_meta_box( $post, $box ) {
    $slug  = $box['args']['taxonomy'] ?? '';
    $tax   = get_taxonomy( $slug );
    if ( ! $tax ) return;
    $cfg   = evk_tax_mb_config( $slug );
    $style = evk_tax_meta_box_style( $slug );

    // Filtrowanie zależne: dep_tax = taksonomia nadrzędna, dep_key = pole relacji
    // (typu Taksonomia EVK) na termach TEJ taksonomii → ID termów nadrzędnych w term meta.
    $dep_tax = sanitize_key( $cfg['dep_tax'] ?? '' );
    $dep_key = sanitize_key( $cfg['dep_key'] ?? '' );
    $has_dep = $dep_tax !== '' && $dep_key !== '' && taxonomy_exists( $dep_tax );

    $dep_attr = function ( $term_id ) use ( $has_dep, $dep_key ): string {
        if ( ! $has_dep ) return '';
        $ids = get_term_meta( (int) $term_id, $dep_key, true );
        $ids = is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
        // Brak relacji = term „uniwersalny" — widoczny przy każdym wyborze rodzica.
        return ' data-evk-dep="' . esc_attr( implode( ',', $ids ) ) . '"';
    };

    $terms = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name' ) );
    if ( is_wp_error( $terms ) ) $terms = array();
    $sel = wp_get_object_terms( (int) $post->ID, $slug, array( 'fields' => 'ids' ) );
    $cur = ( ! is_wp_error( $sel ) && $sel ) ? (int) $sel[0] : 0;

    echo '<div class="evk-tax-mb" data-evk-tax="' . esc_attr( $slug ) . '"' . ( $has_dep ? ' data-evk-dep-tax="' . esc_attr( $dep_tax ) . '"' : '' ) . '>';
    wp_nonce_field( 'evk_tax_mb_' . $slug, 'evk_tax_mb_nonce_' . $slug );
    $name = 'evk_tax_mb[' . $slug . ']';

    if ( $style === 'radio' ) {
        echo '<ul class="evk-tax-radio" style="margin:6px 0 0;max-height:200px;overflow-y:auto;">';
        echo '<li><label><input type="radio" name="' . esc_attr( $name ) . '" value="0" ' . checked( $cur, 0, false ) . '> &mdash; ' . esc_html__( 'brak', 'evk-repeater' ) . ' &mdash;</label></li>';
        foreach ( $terms as $t ) {
            echo '<li><label><input type="radio" name="' . esc_attr( $name ) . '" value="' . (int) $t->term_id . '"' . $dep_attr( $t->term_id ) . ' ' . checked( $cur, (int) $t->term_id, false ) . '> ' . esc_html( $t->name ) . '</label></li>';
        }
        echo '</ul>';
    } else {
        echo '<select name="' . esc_attr( $name ) . '" style="width:100%;margin-top:6px;">';
        echo '<option value="0">&mdash; ' . esc_html__( 'brak', 'evk-repeater' ) . ' &mdash;</option>';
        foreach ( $terms as $t ) {
            echo '<option value="' . (int) $t->term_id . '"' . $dep_attr( $t->term_id ) . ' ' . selected( $cur, (int) $t->term_id, false ) . '>' . esc_html( $t->name ) . '</option>';
        }
        echo '</select>';
    }
    echo '<p style="margin:8px 0 0;"><a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . $slug ) ) . '" target="_blank">' . esc_html( $tax->labels->add_new_item ?? 'Dodaj' ) . '</a></p>';
    echo '</div>';
}

// Zapis: własny metabox nie idzie przez tax_input WP — przypisujemy termy sami.
add_action( 'save_post', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( empty( $_POST['evk_tax_mb'] ) || ! is_array( $_POST['evk_tax_mb'] ) ) return;

    foreach ( $_POST['evk_tax_mb'] as $slug => $tid ) {
        $slug = sanitize_key( $slug );
        // Tylko nasze taksonomie ze stylem select/radio (nie pozwól nadpisać innych przez POST).
        if ( evk_tax_meta_box_style( $slug ) === 'default' ) continue;
        $nonce = $_POST[ 'evk_tax_mb_nonce_' . $slug ] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'evk_tax_mb_' . $slug ) ) continue;
        $tax = get_taxonomy( $slug );
        if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) continue;
        $tid = (int) $tid;
        wp_set_object_terms( (int) $post_id, $tid > 0 ? array( $tid ) : array(), $slug, false );
    }
} );

// =========================================================================
// SORTOWANIE KOLUMNY TAKSONOMII (lista wpisów; klucz kolumny WP: taxonomy-{slug})
// =========================================================================

add_action( 'admin_init', function () {
    foreach ( (array) get_option( 'evk_taxonomies', array() ) as $t ) {
        if ( empty( $t['add_columns'] ) ) continue;
        $slug = (string) ( $t['slug'] ?? '' );
        if ( $slug === '' ) continue;
        foreach ( (array) ( $t['post_types'] ?? array() ) as $pt ) {
            add_filter( "manage_edit-{$pt}_sortable_columns", function ( $cols ) use ( $slug ) {
                $cols[ 'taxonomy-' . $slug ] = 'evk_tax_' . $slug;
                return $cols;
            } );
        }
    }
} );

add_filter( 'posts_clauses', function ( $clauses, $q ) {
    if ( ! is_admin() || ! $q->is_main_query() ) return $clauses;
    $ob = $q->get( 'orderby' );
    if ( ! is_string( $ob ) || strpos( $ob, 'evk_tax_' ) !== 0 ) return $clauses;
    $slug = substr( $ob, 8 );

    // Walidacja: tylko nasze taksonomie z włączoną kolumną.
    $ok = false;
    foreach ( (array) get_option( 'evk_taxonomies', array() ) as $t ) {
        if ( ( $t['slug'] ?? '' ) === $slug && ! empty( $t['add_columns'] ) ) { $ok = true; break; }
    }
    if ( ! $ok ) return $clauses;

    global $wpdb;
    // LEFT JOIN: wpisy bez termu ZOSTAJĄ na liście (NULL sortuje się na początku ASC) —
    // w przeciwieństwie do sortowania po mecie, nic nie znika.
    $clauses['join']   .= $wpdb->prepare(
        " LEFT JOIN {$wpdb->term_relationships} evk_tr ON {$wpdb->posts}.ID = evk_tr.object_id
          LEFT JOIN {$wpdb->term_taxonomy} evk_tt ON evk_tr.term_taxonomy_id = evk_tt.term_taxonomy_id AND evk_tt.taxonomy = %s
          LEFT JOIN {$wpdb->terms} evk_t ON evk_tt.term_id = evk_t.term_id",
        $slug
    );
    $clauses['groupby'] = "{$wpdb->posts}.ID";
    $order              = strtoupper( (string) $q->get( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';
    $clauses['orderby'] = "GROUP_CONCAT(DISTINCT evk_t.name ORDER BY evk_t.name ASC) {$order}, {$wpdb->posts}.post_title ASC";
    return $clauses;
}, 10, 2 );
?>
