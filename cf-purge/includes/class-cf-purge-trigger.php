<?php

defined( 'ABSPATH' ) || exit;

/**
 * Trigger — nasłuchuje zmian statusu wpisów i hook ACF, wykonuje deduplikację i inicjuje purge.
 */
class CF_Purge_Trigger {

    /** @var CF_Purge_Logger */
    private CF_Purge_Logger $logger;

    /** @var callable(): CF_Purge_Client|null Note: `callable` is not a valid PHP property type. */
    private $client_factory;

    /**
     * Kolekcja post_id zaplanowanych do purge w bieżącym żądaniu HTTP.
     * Klucz = post_id, wartość = true (zapobiega duplikatom).
     *
     * @var array<int, bool>
     */
    private static array $pending = [];

    /**
     * Flaga informująca, czy hook `shutdown` został już zarejestrowany.
     *
     * @var bool
     */
    private static bool $shutdown_registered = false;

    public function __construct( CF_Purge_Logger $logger, callable $client_factory ) {
        $this->logger         = $logger;
        $this->client_factory = $client_factory;
    }

    /**
     * Rejestracja hooków.
     */
    public function init(): void {
        add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );

        // ACF — uruchamiamy po zapisaniu pól (priorytet 20).
        if ( class_exists( 'ACF' ) || function_exists( 'acf_get_setting' ) ) {
            add_action( 'acf/save_post', [ $this, 'on_acf_save_post' ], 20 );
        } else {
            // Rejestrujemy hook z opóźnieniem w przypadku late-init ACF.
            add_action( 'plugins_loaded', function (): void {
                if ( function_exists( 'acf_get_setting' ) ) {
                    add_action( 'acf/save_post', [ $this, 'on_acf_save_post' ], 20 );
                }
            }, 20 );
        }
    }

    /**
     * Wyzwala purge przy zmianie statusu wpisu.
     *
     * @param string  $new_status Nowy status.
     * @param string  $old_status Stary status.
     * @param \WP_Post $post      Obiekt wpisu.
     */
    public function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
        // Ignoruj jeśli trwa autosave.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Ignoruj rewizje i autosave'y.
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // Ignoruj systemowe typy wpisów.
        if ( in_array( $post->post_type, [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' ], true ) ) {
            return;
        }

        $trigger_statuses = $this->get_trigger_statuses();

        // Publikacja lub aktualizacja opublikowanego wpisu.
        $should_purge = false;
        if ( in_array( $new_status, $trigger_statuses, true ) ) {
            $should_purge = true;
        }

        // Przejście publish → draft/trash (treść znika z frontu) — opcjonalne.
        if ( get_option( 'cf_purge_purge_on_unpublish', false ) && $old_status === 'publish' && $new_status !== 'publish' ) {
            $should_purge = true;
        }

        if ( ! $should_purge ) {
            return;
        }

        $this->schedule_purge( $post->ID );
    }

    /**
     * Wyzwala purge po zapisaniu pól ACF.
     *
     * @param int|string $post_id ID wpisu.
     */
    public function on_acf_save_post( $post_id ): void {
        // ACF może przekazywać 'options' lub 'user_{id}' — ignorujemy te przypadki.
        if ( ! is_numeric( $post_id ) ) {
            return;
        }

        $post_id = (int) $post_id;

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return;
        }

        $this->schedule_purge( $post_id );
    }

    /**
     * Dodaje post_id do kolejki purge i rejestruje hook `shutdown` (raz na żądanie).
     *
     * @param int $post_id
     */
    private function schedule_purge( int $post_id ): void {
        // Sprawdź, czy typ wpisu ma skonfigurowane reguły.
        if ( ! $this->has_rules_for_post( $post_id ) ) {
            return;
        }

        self::$pending[ $post_id ] = true;

        if ( ! self::$shutdown_registered ) {
            add_action( 'shutdown', [ $this, 'flush_pending' ] );
            self::$shutdown_registered = true;
        }
    }

    /**
     * Sprawdza, czy dla danego wpisu istnieją skonfigurowane reguły purge.
     *
     * @param int $post_id
     * @return bool
     */
    private function has_rules_for_post( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        $rules = get_option( 'cf_purge_rules', [] );
        if ( ! is_array( $rules ) ) {
            return false;
        }

        return ! empty( $rules[ $post->post_type ] );
    }

    /**
     * Wykonuje purge dla wszystkich zebranych post_id (wywoływane na `shutdown`).
     */
    public function flush_pending(): void {
        if ( empty( self::$pending ) ) {
            return;
        }

        $post_ids = array_keys( self::$pending );
        self::$pending = [];

        foreach ( $post_ids as $post_id ) {
            $this->execute_purge( $post_id );
        }
    }

    /**
     * Wykonuje właściwy purge dla podanego wpisu.
     *
     * @param int $post_id
     */
    private function execute_purge( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $rules = get_option( 'cf_purge_rules', [] );
        if ( ! is_array( $rules ) || empty( $rules[ $post->post_type ] ) ) {
            return;
        }

        $post_type_rules = $rules[ $post->post_type ];
        $is_dry_run      = $this->logger->is_dry_run();

        /** @var CF_Purge_Client|null $client */
        $client = null;
        if ( ! $is_dry_run ) {
            $client = call_user_func( $this->client_factory );
            if ( ! $client ) {
                return;
            }
        }

        foreach ( $post_type_rules as $rule ) {
            $mode   = sanitize_key( $rule['mode'] ?? '' );
            $values = is_array( $rule['values'] ) ? $rule['values'] : [];

            if ( ! in_array( $mode, [ 'tags', 'prefixes', 'files' ], true ) || empty( $values ) ) {
                continue;
            }

            if ( $is_dry_run ) {
                $dry_result = [
                    'success'  => true,
                    'requests' => [
                        [
                            'mode'    => $mode,
                            'count'   => count( $values ),
                            'success' => true,
                            'errors'  => [],
                            'dry_run' => true,
                            'body'    => [ $mode => $values ],
                        ],
                    ],
                ];
                $this->logger->log( $post_id, $mode, $values, $dry_result, true );
                continue;
            }

            $result = $client->purge( $mode, $values );
            $this->logger->log( $post_id, $mode, $values, $result, false );
        }
    }

    /**
     * Zwraca tablicę statusów wyzwalających purge (z opcji lub domyślnie ['publish']).
     *
     * @return array<string>
     */
    private function get_trigger_statuses(): array {
        $statuses = get_option( 'cf_purge_trigger_statuses', [ 'publish' ] );
        if ( ! is_array( $statuses ) || empty( $statuses ) ) {
            return [ 'publish' ];
        }
        return array_map( 'sanitize_key', $statuses );
    }
}
