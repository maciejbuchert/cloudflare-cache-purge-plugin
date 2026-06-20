<?php

defined( 'ABSPATH' ) || exit;

/**
 * Strona ustawień pluginu — Settings API, renderowanie UI, sanityzacja.
 */
class CF_Purge_Settings {

    private const PAGE_SLUG        = 'cf-purge-settings';
    private const OPTION_GROUP     = 'cf_purge_options';
    private const MIN_TOKEN_LENGTH = 20;
    private const MAX_TOKEN_LENGTH = 200;
    private const PLACEHOLDER_PATTERN = '/\{([A-Za-z0-9_.:-]+)\}/';

    /** @var CF_Purge_Logger */
    private CF_Purge_Logger $logger;

    public function __construct( CF_Purge_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Rejestracja hooków dla panelu administracyjnego.
     */
    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cf_purge_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_cf_purge_clear_log', [ $this, 'ajax_clear_log' ] );
        add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
    }

    /**
     * Dodaje stronę ustawień do menu WordPress.
     */
    public function register_menu(): void {
        add_options_page(
            esc_html__( 'Cloudflare Cache Purge', 'cf-purge' ),
            esc_html__( 'Cloudflare Purge', 'cf-purge' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Rejestruje sekcje i pola Settings API.
     */
    public function register_settings(): void {
        // --- Sekcja: Połączenie ---
        add_settings_section(
            'cf_purge_connection',
            esc_html__( 'Połączenie z Cloudflare', 'cf-purge' ),
            '__return_empty_string',
            self::PAGE_SLUG
        );

        // API Token.
        register_setting( self::OPTION_GROUP, 'cf_purge_api_token', [
            'sanitize_callback' => [ $this, 'sanitize_api_token' ],
        ] );
        add_settings_field(
            'cf_purge_api_token',
            esc_html__( 'API Token', 'cf-purge' ),
            [ $this, 'render_api_token_field' ],
            self::PAGE_SLUG,
            'cf_purge_connection'
        );

        // Zone ID.
        register_setting( self::OPTION_GROUP, 'cf_purge_zone_id', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        add_settings_field(
            'cf_purge_zone_id',
            esc_html__( 'Zone ID', 'cf-purge' ),
            [ $this, 'render_zone_id_field' ],
            self::PAGE_SLUG,
            'cf_purge_connection'
        );

        // --- Sekcja: Reguły purge ---
        add_settings_section(
            'cf_purge_rules_section',
            esc_html__( 'Reguły purge per typ treści', 'cf-purge' ),
            [ $this, 'render_rules_section_description' ],
            self::PAGE_SLUG
        );

        register_setting( self::OPTION_GROUP, 'cf_purge_rules', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_rules' ],
        ] );
        add_settings_field(
            'cf_purge_rules',
            esc_html__( 'Reguły', 'cf-purge' ),
            [ $this, 'render_rules_field' ],
            self::PAGE_SLUG,
            'cf_purge_rules_section'
        );

        // --- Sekcja: Opcje dodatkowe ---
        add_settings_section(
            'cf_purge_extra',
            esc_html__( 'Opcje dodatkowe', 'cf-purge' ),
            '__return_empty_string',
            self::PAGE_SLUG
        );

        // Logowanie.
        register_setting( self::OPTION_GROUP, 'cf_purge_enable_logging', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
        add_settings_field(
            'cf_purge_enable_logging',
            esc_html__( 'Włącz logowanie', 'cf-purge' ),
            [ $this, 'render_enable_logging_field' ],
            self::PAGE_SLUG,
            'cf_purge_extra'
        );

        // Dry-run.
        register_setting( self::OPTION_GROUP, 'cf_purge_dry_run', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
        add_settings_field(
            'cf_purge_dry_run',
            esc_html__( 'Tryb dry-run', 'cf-purge' ),
            [ $this, 'render_dry_run_field' ],
            self::PAGE_SLUG,
            'cf_purge_extra'
        );

        // Statusy wyzwalające.
        register_setting( self::OPTION_GROUP, 'cf_purge_trigger_statuses', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_trigger_statuses' ],
        ] );
        add_settings_field(
            'cf_purge_trigger_statuses',
            esc_html__( 'Statusy wyzwalające', 'cf-purge' ),
            [ $this, 'render_trigger_statuses_field' ],
            self::PAGE_SLUG,
            'cf_purge_extra'
        );

        // Purge przy cofaniu publikacji.
        register_setting( self::OPTION_GROUP, 'cf_purge_purge_on_unpublish', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
        add_settings_field(
            'cf_purge_purge_on_unpublish',
            esc_html__( 'Purge przy cofnięciu publikacji', 'cf-purge' ),
            [ $this, 'render_purge_on_unpublish_field' ],
            self::PAGE_SLUG,
            'cf_purge_extra'
        );
    }

    // -------------------------------------------------------------------------
    // Renderowanie pól
    // -------------------------------------------------------------------------

    /**
     * Pole API Token — maskowane, ze wsparciem dla stałej w wp-config.
     */
    public function render_api_token_field(): void {
        if ( defined( 'CF_PURGE_API_TOKEN' ) && CF_PURGE_API_TOKEN ) {
            echo '<p class="description">' . esc_html__( 'API Token jest zdefiniowany w wp-config.php (CF_PURGE_API_TOKEN). Wartość z bazy danych jest ignorowana.', 'cf-purge' ) . '</p>';
            return;
        }

        $value = get_option( 'cf_purge_api_token', '' );
        $masked = $value ? str_repeat( '*', 20 ) : '';
        ?>
        <input
            type="password"
            name="cf_purge_api_token"
            id="cf_purge_api_token"
            value="<?php echo esc_attr( $masked ); ?>"
            class="regular-text"
            autocomplete="new-password"
            placeholder="<?php esc_attr_e( '****** z uprawnieniem Zone.Cache Purge', 'cf-purge' ); ?>"
        >
        <p class="description">
            <?php esc_html_e( 'Wpisz token tylko jeśli chcesz go zmienić. Pozostaw puste, by zachować dotychczasowy.', 'cf-purge' ); ?>
        </p>
        <?php
    }

    /**
     * Pole Zone ID.
     */
    public function render_zone_id_field(): void {
        $value = get_option( 'cf_purge_zone_id', '' );
        ?>
        <input
            type="text"
            name="cf_purge_zone_id"
            id="cf_purge_zone_id"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e( 'np. 023e105f4ecef8ad9ca31a8372d0c353', 'cf-purge' ); ?>"
        >
        <button type="button" id="cf-purge-test-connection" class="button button-secondary" style="margin-left:8px;">
            <?php esc_html_e( 'Testuj połączenie', 'cf-purge' ); ?>
        </button>
        <span id="cf-purge-connection-result" style="margin-left:8px;"></span>
        <?php
    }

    /**
     * Opis sekcji reguł — ostrzeżenie o Enterprise.
     */
    public function render_rules_section_description(): void {
        ?>
        <div class="notice notice-warning inline" style="margin:0 0 12px 0;">
            <p>
                <strong><?php esc_html_e( 'Uwaga:', 'cf-purge' ); ?></strong>
                <?php esc_html_e( 'Tryby purge "tags" i "prefixes" wymagają planu Cloudflare Enterprise. Na planach Free/Pro/Business działa wyłącznie tryb "files" (do 30 URL-i) lub "purge_everything".', 'cf-purge' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Repeater reguł purge.
     */
    public function render_rules_field(): void {
        $rules      = get_option( 'cf_purge_rules', [] );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $modes      = [
            'prefixes' => esc_html__( 'prefixes (Enterprise)', 'cf-purge' ),
            'tags'     => esc_html__( 'tags (Enterprise)', 'cf-purge' ),
            'files'    => esc_html__( 'files (wszystkie plany)', 'cf-purge' ),
        ];

        // Spłaszcz reguły do tablicy [{post_type, mode, values}].
        $flat_rules = [];
        if ( is_array( $rules ) ) {
            foreach ( $rules as $post_type => $type_rules ) {
                if ( is_array( $type_rules ) ) {
                    foreach ( $type_rules as $rule ) {
                        $flat_rules[] = [
                            'post_type' => $post_type,
                            'mode'      => $rule['mode'] ?? 'prefixes',
                            'values'    => implode( "\n", is_array( $rule['values'] ) ? $rule['values'] : [] ),
                        ];
                    }
                }
            }
        }
        ?>
        <div id="cf-purge-rules-wrapper">
            <?php foreach ( $flat_rules as $i => $rule ) : ?>
                <?php $this->render_rule_row( $i, $rule, $post_types, $modes ); ?>
            <?php endforeach; ?>
        </div>
        <button type="button" id="cf-purge-add-rule" class="button button-secondary" style="margin-top:8px;">
            <?php esc_html_e( '+ Dodaj regułę', 'cf-purge' ); ?>
        </button>

        <!-- Szablon dla nowych reguł (hidden) -->
        <script type="text/html" id="cf-purge-rule-template">
            <?php $this->render_rule_row( '__INDEX__', [ 'post_type' => '', 'mode' => 'prefixes', 'values' => '' ], $post_types, $modes ); ?>
        </script>
        <?php
    }

    /**
     * Renderuje pojedynczy wiersz reguły purge.
     *
     * @param int|string $index     Indeks wiersza.
     * @param array      $rule      Dane reguły.
     * @param array      $post_types Typy wpisów (WP_Post_Type[]).
     * @param array      $modes     Dostępne tryby.
     */
    private function render_rule_row( $index, array $rule, array $post_types, array $modes ): void {
        $placeholders = [
            'prefixes' => "www.example.com/aktualnosci\nwww.example.com",
            'tags'     => "projects-listing\nhome",
            'files'    => "https://www.example.com/aktualnosci/{postId}\nhttps://www.example.com/{slug}",
        ];
        $current_mode = $rule['mode'] ?? 'prefixes';
        $placeholder  = $placeholders[ $current_mode ] ?? '';
        ?>
        <div class="cf-purge-rule" style="border:1px solid #ddd;padding:12px;margin-bottom:8px;background:#f9f9f9;">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="width:140px;padding:4px 8px;">
                        <label><?php esc_html_e( 'Typ wpisu', 'cf-purge' ); ?></label>
                    </th>
                    <td style="padding:4px 8px;">
                        <select name="cf_purge_rules[<?php echo esc_attr( (string) $index ); ?>][post_type]" class="cf-purge-post-type">
                            <option value=""><?php esc_html_e( '— wybierz —', 'cf-purge' ); ?></option>
                            <?php foreach ( $post_types as $pt ) : ?>
                                <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $rule['post_type'], $pt->name ); ?>>
                                    <?php echo esc_html( $pt->label . ' (' . $pt->name . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding:4px 8px;">
                        <label><?php esc_html_e( 'Tryb purge', 'cf-purge' ); ?></label>
                    </th>
                    <td style="padding:4px 8px;">
                        <select name="cf_purge_rules[<?php echo esc_attr( (string) $index ); ?>][mode]" class="cf-purge-mode">
                            <?php foreach ( $modes as $mode_value => $mode_label ) : ?>
                                <option value="<?php echo esc_attr( $mode_value ); ?>" <?php selected( $current_mode, $mode_value ); ?>>
                                    <?php echo esc_html( $mode_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding:4px 8px;">
                        <label><?php esc_html_e( 'Wartości', 'cf-purge' ); ?></label>
                    </th>
                    <td style="padding:4px 8px;">
                        <textarea
                            name="cf_purge_rules[<?php echo esc_attr( (string) $index ); ?>][values]"
                            class="cf-purge-values large-text"
                            rows="4"
                            placeholder="<?php echo esc_attr( $placeholder ); ?>"
                        ><?php echo esc_textarea( $rule['values'] ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Jedna wartość na linię.', 'cf-purge' ); ?>
                            <?php esc_html_e( 'Możesz używać placeholderów, np. {postId}, {slug} lub {nazwa_pola_acf}.', 'cf-purge' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <button type="button" class="button cf-purge-remove-rule" style="margin-top:4px;color:#a00;">
                <?php esc_html_e( '✕ Usuń regułę', 'cf-purge' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Pole: włącz logowanie.
     */
    public function render_enable_logging_field(): void {
        $value = get_option( 'cf_purge_enable_logging', false );
        ?>
        <label>
            <input type="checkbox" name="cf_purge_enable_logging" value="1" <?php checked( $value ); ?>>
            <?php esc_html_e( 'Zapisuj historię purge (ostatnie 100 wpisów)', 'cf-purge' ); ?>
        </label>
        <?php
    }

    /**
     * Pole: tryb dry-run.
     */
    public function render_dry_run_field(): void {
        $value = get_option( 'cf_purge_dry_run', false );
        ?>
        <label>
            <input type="checkbox" name="cf_purge_dry_run" value="1" <?php checked( $value ); ?>>
            <?php esc_html_e( 'Nie wysyłaj requestów do Cloudflare — tylko loguj co by zostało wysłane (wymaga włączonego logowania)', 'cf-purge' ); ?>
        </label>
        <?php
    }

    /**
     * Pole: statusy wyzwalające purge.
     */
    public function render_trigger_statuses_field(): void {
        $selected = get_option( 'cf_purge_trigger_statuses', [ 'publish' ] );
        if ( ! is_array( $selected ) ) {
            $selected = [ 'publish' ];
        }

        $statuses = [
            'publish' => esc_html__( 'Opublikowany (publish)', 'cf-purge' ),
            'future'  => esc_html__( 'Zaplanowany (future)', 'cf-purge' ),
            'private' => esc_html__( 'Prywatny (private)', 'cf-purge' ),
        ];

        foreach ( $statuses as $status_key => $status_label ) :
            ?>
            <label style="display:block;margin-bottom:4px;">
                <input
                    type="checkbox"
                    name="cf_purge_trigger_statuses[]"
                    value="<?php echo esc_attr( $status_key ); ?>"
                    <?php checked( in_array( $status_key, $selected, true ) ); ?>
                >
                <?php echo esc_html( $status_label ); ?>
            </label>
            <?php
        endforeach;
    }

    /**
     * Pole: purge przy cofnięciu publikacji.
     */
    public function render_purge_on_unpublish_field(): void {
        $value = get_option( 'cf_purge_purge_on_unpublish', false );
        ?>
        <label>
            <input type="checkbox" name="cf_purge_purge_on_unpublish" value="1" <?php checked( $value ); ?>>
            <?php esc_html_e( 'Wykonaj purge gdy wpis przechodzi ze statusu "publish" do draft/trash', 'cf-purge' ); ?>
        </label>
        <?php
    }

    // -------------------------------------------------------------------------
    // Renderowanie strony ustawień
    // -------------------------------------------------------------------------

    /**
     * Renderuje pełną stronę ustawień.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Brak uprawnień.', 'cf-purge' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Cloudflare Cache Purge', 'cf-purge' ); ?></h1>

            <form method="post" action="options.php" id="cf-purge-settings-form">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( esc_html__( 'Zapisz ustawienia', 'cf-purge' ) );
                ?>
            </form>

            <?php if ( $this->logger->is_enabled() ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Historia purge', 'cf-purge' ); ?></h2>
                <?php $this->render_log_table(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderuje tabelę z historią purge.
     */
    private function render_log_table(): void {
        $log = $this->logger->get_log( 50 );

        if ( empty( $log ) ) {
            echo '<p>' . esc_html__( 'Brak wpisów w historii.', 'cf-purge' ) . '</p>';
        } else {
            ?>
            <button type="button" id="cf-purge-clear-log" class="button button-secondary" style="margin-bottom:8px;">
                <?php esc_html_e( 'Wyczyść historię', 'cf-purge' ); ?>
            </button>
            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Data', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Post ID', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Typ', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Tryb', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Liczba wartości', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cf-purge' ); ?></th>
                        <th><?php esc_html_e( 'Błędy', 'cf-purge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                            <td><?php echo esc_html( (string) ( $entry['post_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $entry['post_type'] ?? '' ); ?></td>
                            <td><code><?php echo esc_html( $entry['mode'] ?? '' ); ?></code></td>
                            <td><?php echo esc_html( (string) count( $entry['values'] ?? [] ) ); ?></td>
                            <td>
                                <?php if ( ! empty( $entry['dry_run'] ) ) : ?>
                                    <span style="color:#666;"><?php esc_html_e( 'dry-run', 'cf-purge' ); ?></span>
                                <?php elseif ( ! empty( $entry['result']['success'] ) ) : ?>
                                    <span style="color:green;">✔ <?php esc_html_e( 'OK', 'cf-purge' ); ?></span>
                                <?php else : ?>
                                    <span style="color:#a00;">✗ <?php esc_html_e( 'Błąd', 'cf-purge' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $errors = [];
                                if ( ! empty( $entry['result']['requests'] ) ) {
                                    foreach ( $entry['result']['requests'] as $req ) {
                                        if ( ! empty( $req['errors'] ) ) {
                                            foreach ( $req['errors'] as $err ) {
                                                $errors[] = esc_html( '[' . ( $err['code'] ?? 0 ) . '] ' . ( $err['message'] ?? '' ) );
                                            }
                                        }
                                    }
                                }
                                echo wp_kses( implode( '<br>', $errors ), [ 'br' => [] ] );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
    }

    // -------------------------------------------------------------------------
    // Sanityzacja
    // -------------------------------------------------------------------------

    /**
     * Sanityzacja API Token.
     * Jeśli pole zawiera zamaskowaną wartość (same '*'), zachowaj oryginalną wartość z bazy.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_api_token( string $value ): string {
        $value = trim( $value );

        // Pole pozostawione puste lub z maską — nie nadpisuj.
        if ( empty( $value ) || preg_match( '/^\*+$/', $value ) ) {
            return get_option( 'cf_purge_api_token', '' );
        }

        // Validate token length: allow range to accommodate varying Cloudflare token formats.
        if ( strlen( $value ) < self::MIN_TOKEN_LENGTH || strlen( $value ) > self::MAX_TOKEN_LENGTH ) {
            add_settings_error(
                'cf_purge_api_token',
                'invalid_token',
                esc_html__( 'API Token wydaje się nieprawidłowy (oczekiwana długość 20–200 znaków).', 'cf-purge' )
            );
        }

        // Walidacja znaków: tylko alfanumeryczne, myślniki, podkreślenia.
        if ( ! preg_match( '/^[A-Za-z0-9\-_]+$/', $value ) ) {
            add_settings_error(
                'cf_purge_api_token',
                'invalid_token_chars',
                esc_html__( 'API Token zawiera niedozwolone znaki.', 'cf-purge' )
            );
        }

        return $value;
    }

    /**
     * Sanityzacja reguł purge z repeatera.
     *
     * @param array $input Surowe dane z formularza.
     * @return array Znormalizowane reguły wg struktury [post_type => [['mode'=>..,'values'=>[...]]]]
     */
    public function sanitize_rules( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $valid_modes = [ 'tags', 'prefixes', 'files' ];
        $result      = [];

        foreach ( $input as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $post_type = sanitize_key( $row['post_type'] ?? '' );
            $mode      = sanitize_key( $row['mode'] ?? '' );
            $raw_values = $row['values'] ?? '';

            if ( ! $post_type || ! in_array( $mode, $valid_modes, true ) ) {
                continue;
            }

            // Parsuj textarea → tablica wartości (obsługa \r\n, \r, \n).
            $values = array_filter(
                array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw_values ) ),
                fn( $v ) => $v !== ''
            );
            $values = array_values( $values );

            // Sanityzuj wartości zależnie od trybu.
            $sanitized_values = [];
            $has_warning      = false;

            foreach ( $values as $v ) {
                switch ( $mode ) {
                    case 'files':
                        // Pełny URL ze schematem.
                        $sanitized = $this->sanitize_file_rule_value( $v );
                        if ( ! preg_match( '#^https?://#', $sanitized ) ) {
                            $has_warning = true;
                        }
                        break;
                    case 'prefixes':
                        // Bez schematu.
                        $sanitized = sanitize_text_field( $v );
                        if ( preg_match( '#^https?://#i', $sanitized ) ) {
                            $has_warning = true;
                        }
                        break;
                    case 'tags':
                        // Tagi cache — tylko alfanumeryczne i myślniki.
                        $sanitized = sanitize_text_field( $v );
                        break;
                    default:
                        $sanitized = sanitize_text_field( $v );
                }

                if ( $sanitized ) {
                    $sanitized_values[] = $sanitized;
                }
            }

            if ( $has_warning ) {
                if ( $mode === 'files' ) {
                    add_settings_error(
                        'cf_purge_rules',
                        'invalid_files_format',
                        sprintf(
                            /* translators: %s: post type name */
                            esc_html__( 'Tryb "files" dla typu "%s": wartości powinny zaczynać się od https://...', 'cf-purge' ),
                            esc_html( $post_type )
                        )
                    );
                } elseif ( $mode === 'prefixes' ) {
                    add_settings_error(
                        'cf_purge_rules',
                        'invalid_prefixes_format',
                        sprintf(
                            /* translators: %s: post type name */
                            esc_html__( 'Tryb "prefixes" dla typu "%s": wartości NIE powinny zawierać schematu (https://). Użyj formatu: example.com/ścieżka', 'cf-purge' ),
                            esc_html( $post_type )
                        )
                    );
                }
            }

            if ( ! isset( $result[ $post_type ] ) ) {
                $result[ $post_type ] = [];
            }

            $result[ $post_type ][] = [
                'mode'   => $mode,
                'values' => $sanitized_values,
            ];
        }

        return $result;
    }

    /**
     * Sanityzuje URL dla trybu files, zachowując placeholdery typu {postId}.
     *
     * @param string $value Wartość reguły.
     * @return string
     */
    private function sanitize_file_rule_value( string $value ): string {
        $placeholders = [];
        $masked_value = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function ( array $matches ) use ( &$placeholders ): string {
                $index                  = count( $placeholders );
                $token                  = 'cfpurgeplaceholder' . $index . hash( 'sha256', $matches[0] . $index );
                $placeholders[ $token ] = $matches[0];
                return $token;
            },
            $value
        );

        if ( ! is_string( $masked_value ) ) {
            return '';
        }

        $sanitized = esc_url_raw( $masked_value );

        foreach ( $placeholders as $token => $placeholder ) {
            $sanitized = str_replace( $token, $placeholder, $sanitized );
        }

        return $sanitized;
    }

    /**
     * Sanityzacja tablicy statusów wyzwalających.
     *
     * @param array|null $input
     * @return array
     */
    public function sanitize_trigger_statuses( $input ): array {
        if ( ! is_array( $input ) ) {
            return [ 'publish' ];
        }
        $allowed = [ 'publish', 'future', 'private' ];
        $cleaned = array_filter(
            array_map( 'sanitize_key', $input ),
            fn( $s ) => in_array( $s, $allowed, true )
        );
        return array_values( $cleaned ) ?: [ 'publish' ];
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    /**
     * Obsługa AJAX „Testuj połączenie".
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'cf_purge_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Brak uprawnień.', 'cf-purge' ) ] );
        }

        // Stała ma pierwszeństwo.
        if ( defined( 'CF_PURGE_API_TOKEN' ) && CF_PURGE_API_TOKEN ) {
            $api_token = CF_PURGE_API_TOKEN;
        } else {
            $api_token = sanitize_text_field( wp_unslash( $_POST['api_token'] ?? '' ) );
            if ( empty( $api_token ) ) {
                $api_token = get_option( 'cf_purge_api_token', '' );
            }
        }

        $zone_id = sanitize_text_field( wp_unslash( $_POST['zone_id'] ?? '' ) );
        if ( empty( $zone_id ) ) {
            $zone_id = get_option( 'cf_purge_zone_id', '' );
        }

        if ( ! $api_token || ! $zone_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Uzupełnij API Token i Zone ID przed testem.', 'cf-purge' ) ] );
        }

        $client = new CF_Purge_Client( $api_token, $zone_id );
        $info   = $client->get_zone_info();

        if ( ! $info['success'] ) {
            $error_msg = ! empty( $info['errors'][0]['message'] )
                ? $info['errors'][0]['message']
                : esc_html__( 'Nieznany błąd.', 'cf-purge' );

            wp_send_json_error( [ 'message' => $error_msg ] );
        }

        $is_enterprise = stripos( $info['plan'] ?? '', 'enterprise' ) !== false;

        wp_send_json_success( [
            'name'         => $info['name'],
            'plan'         => $info['plan'],
            'is_enterprise' => $is_enterprise,
            'notice'       => $is_enterprise
                ? ''
                : esc_html__( 'Twój plan nie jest Enterprise. Tryby "tags" i "prefixes" nie będą działać — użyj trybu "files".', 'cf-purge' ),
        ] );
    }

    /**
     * Obsługa AJAX czyszczenia logu.
     */
    public function ajax_clear_log(): void {
        check_ajax_referer( 'cf_purge_clear_log', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Brak uprawnień.', 'cf-purge' ) ] );
        }

        $this->logger->clear_log();
        wp_send_json_success( [ 'message' => esc_html__( 'Historia wyczyszczona.', 'cf-purge' ) ] );
    }

    /**
     * Wyświetla admin notices (np. po błędach API).
     */
    public function display_admin_notices(): void {
        // Notices generowane przez Settings API są wyświetlane automatycznie.
    }

    // -------------------------------------------------------------------------
    // Asety
    // -------------------------------------------------------------------------

    /**
     * Ładuje JS/CSS tylko na stronie ustawień pluginu.
     *
     * @param string $hook
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'cf-purge-admin',
            CF_PURGE_PLUGIN_URL . 'assets/admin.js',
            [ 'jquery' ],
            CF_PURGE_VERSION,
            true
        );

        wp_localize_script( 'cf-purge-admin', 'cfPurge', [
            'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
            'nonce_test'         => wp_create_nonce( 'cf_purge_test_connection' ),
            'nonce_clear_log'    => wp_create_nonce( 'cf_purge_clear_log' ),
            'i18n'               => [
                'testing'          => esc_html__( 'Testowanie…', 'cf-purge' ),
                'connected'        => esc_html__( 'Połączono:', 'cf-purge' ),
                'error'            => esc_html__( 'Błąd:', 'cf-purge' ),
                'unknownError'     => esc_html__( 'Nieznany błąd.', 'cf-purge' ),
                'requestFailed'    => esc_html__( 'Żądanie nie powiodło się.', 'cf-purge' ),
                'confirmClearLog'  => esc_html__( 'Czy na pewno wyczyścić historię purge?', 'cf-purge' ),
                'logCleared'       => esc_html__( 'Historia wyczyszczona.', 'cf-purge' ),
                'placeholders'     => [
                    'prefixes' => "www.example.com/aktualnosci\nwww.example.com",
                    'tags'     => "projects-listing\nhome",
                    'files'    => "https://www.example.com/aktualnosci/{postId}\nhttps://www.example.com/{slug}",
                ],
            ],
        ] );
    }
}
