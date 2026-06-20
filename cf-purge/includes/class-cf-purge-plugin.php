<?php

defined( 'ABSPATH' ) || exit;

/**
 * Główny orchestrator pluginu — rejestruje wszystkie hooki i serwisy.
 */
class CF_Purge_Plugin {

    /** @var CF_Purge_Plugin|null */
    private static ?CF_Purge_Plugin $instance = null;

    /** @var CF_Purge_Logger */
    private CF_Purge_Logger $logger;

    /** @var CF_Purge_Trigger */
    private CF_Purge_Trigger $trigger;

    /** @var CF_Purge_Settings */
    private CF_Purge_Settings $settings;

    private function __construct() {}

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicjalizacja serwisów i hooków.
     */
    public function init(): void {
        $this->logger   = new CF_Purge_Logger();
        $this->settings = new CF_Purge_Settings( $this->logger );
        $this->trigger  = new CF_Purge_Trigger( $this->logger, [ $this, 'get_client' ] );

        $this->settings->init();
        $this->trigger->init();
    }

    /**
     * Buduje i zwraca klienta Cloudflare na podstawie zapisanej konfiguracji.
     *
     * @return CF_Purge_Client|null
     */
    public function get_client(): ?CF_Purge_Client {
        // Stała w wp-config.php ma pierwszeństwo nad bazą danych.
        if ( defined( 'CF_PURGE_API_TOKEN' ) && CF_PURGE_API_TOKEN ) {
            $api_token = CF_PURGE_API_TOKEN;
        } else {
            $api_token = get_option( 'cf_purge_api_token', '' );
        }

        $zone_id = get_option( 'cf_purge_zone_id', '' );

        if ( ! $api_token || ! $zone_id ) {
            return null;
        }

        return new CF_Purge_Client( $api_token, $zone_id );
    }

    /**
     * Zwraca instancję loggera.
     */
    public function get_logger(): CF_Purge_Logger {
        return $this->logger;
    }
}
