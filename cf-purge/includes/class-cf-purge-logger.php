<?php

defined( 'ABSPATH' ) || exit;

/**
 * Logger — opcjonalne zapisywanie historii purge do opcji WordPressa.
 */
class CF_Purge_Logger {

    private const OPTION_KEY   = 'cf_purge_log';
    private const MAX_ENTRIES  = 100;

    /**
     * Czy logowanie jest włączone.
     */
    public function is_enabled(): bool {
        return (bool) get_option( 'cf_purge_enable_logging', false );
    }

    /**
     * Czy tryb dry-run jest włączony.
     */
    public function is_dry_run(): bool {
        return (bool) get_option( 'cf_purge_dry_run', false );
    }

    /**
     * Zapisuje wpis logu.
     *
     * @param int    $post_id  ID wpisu WordPress.
     * @param string $mode     tags|prefixes|files.
     * @param array  $values   Wartości wysłane do purge.
     * @param array  $result   Wynik z CF_Purge_Client::purge().
     * @param bool   $dry_run  Czy to był dry-run.
     */
    public function log( int $post_id, string $mode, array $values, array $result, bool $dry_run = false ): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $entry = [
            'timestamp' => current_time( 'mysql' ),
            'post_id'   => $post_id,
            'post_type' => get_post_type( $post_id ),
            'mode'      => $mode,
            'values'    => $values,
            'result'    => $result,
            'dry_run'   => $dry_run,
        ];

        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        array_unshift( $log, $entry );

        // Ogranicz do MAX_ENTRIES.
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Pobiera wpisy logu.
     *
     * @param int $limit Maksymalna liczba wpisów do zwrócenia.
     * @return array
     */
    public function get_log( int $limit = 50 ): array {
        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) {
            return [];
        }
        return array_slice( $log, 0, $limit );
    }

    /**
     * Czyści log.
     */
    public function clear_log(): void {
        delete_option( self::OPTION_KEY );
    }
}
