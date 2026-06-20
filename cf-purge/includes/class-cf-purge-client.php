<?php

defined( 'ABSPATH' ) || exit;

/**
 * Klient Cloudflare API — odpowiada za wysyłanie requestów purge cache.
 */
class CF_Purge_Client {

    private const API_BASE = 'https://api.cloudflare.com/client/v4';
    private const BATCH_SIZE = 30;

    /** @var string */
    private string $api_token;

    /** @var string */
    private string $zone_id;

    public function __construct( string $api_token, string $zone_id ) {
        $this->api_token = $api_token;
        $this->zone_id   = $zone_id;
    }

    /**
     * Wykonuje purge cache dla podanego trybu i wartości.
     * Automatycznie dzieli na batch'e po max 30 elementów.
     *
     * @param string $mode   tags|prefixes|files
     * @param array  $values Tablica wartości do purge.
     * @return array{success: bool, requests: list<array{mode: string, count: int, success: bool, errors: list<array{code: int, message: string}>}>}
     */
    public function purge( string $mode, array $values ): array {
        $result   = [
            'success'  => true,
            'requests' => [],
        ];
        $batches  = array_chunk( $values, self::BATCH_SIZE );

        foreach ( $batches as $batch ) {
            $body     = [ $mode => $batch ];
            $response = $this->request( 'POST', '/zones/' . $this->zone_id . '/purge_cache', $body );

            $req_result = [
                'mode'    => $mode,
                'count'   => count( $batch ),
                'success' => $response['success'],
                'errors'  => $response['errors'] ?? [],
            ];

            $result['requests'][] = $req_result;

            if ( ! $response['success'] ) {
                $result['success'] = false;
            }
        }

        return $result;
    }

    /**
     * Pobiera informacje o strefie Cloudflare (używane do weryfikacji połączenia i wykrywania planu).
     *
     * @return array{success: bool, name?: string, plan?: string, errors?: list<array{code: int, message: string}>}
     */
    public function get_zone_info(): array {
        $response = $this->request( 'GET', '/zones/' . $this->zone_id, null );

        if ( ! $response['success'] ) {
            return [
                'success' => false,
                'errors'  => $response['errors'] ?? [],
            ];
        }

        $result_data = $response['result'] ?? [];

        return [
            'success' => true,
            'name'    => $result_data['name'] ?? '',
            'plan'    => $result_data['plan']['name'] ?? '',
        ];
    }

    /**
     * Wysyła request do Cloudflare API.
     *
     * @param string     $method GET|POST
     * @param string     $path   Ścieżka API, np. /zones/{id}/purge_cache
     * @param array|null $body   Dane JSON do wysłania (null = brak body)
     * @return array{success: bool, result?: array, errors?: list<array{code: int, message: string}>}
     */
    private function request( string $method, string $path, ?array $body ): array {
        $url     = self::API_BASE . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type'  => 'application/json',
        ];

        $args = [
            'method'  => strtoupper( $method ),
            'headers' => $headers,
            'timeout' => 10,
        ];

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $raw_response = wp_remote_request( $url, $args );

        if ( is_wp_error( $raw_response ) ) {
            return [
                'success' => false,
                'errors'  => [
                    [
                        'code'    => 0,
                        'message' => $raw_response->get_error_message(),
                    ],
                ],
            ];
        }

        $http_code   = wp_remote_retrieve_response_code( $raw_response );
        $raw_body    = wp_remote_retrieve_body( $raw_response );
        $parsed_body = json_decode( $raw_body, true );

        if ( ! is_array( $parsed_body ) ) {
            return [
                'success' => false,
                'errors'  => [
                    [
                        'code'    => $http_code,
                        'message' => __( 'Nieprawidłowa odpowiedź JSON od Cloudflare API.', 'cf-purge' ),
                    ],
                ],
            ];
        }

        return $parsed_body;
    }
}
