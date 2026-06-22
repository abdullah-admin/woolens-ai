<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Updater {

    private static $api_url = WOOLENS_SERVER_URL . '/api/plugin/version';

    public static function init() {
        add_action( 'admin_notices',                          [ __CLASS__, 'show_update_notice' ] );
        add_filter( 'pre_set_site_transient_update_plugins',  [ __CLASS__, 'inject_update' ] );
        add_filter( 'plugins_api',                            [ __CLASS__, 'plugin_info' ], 10, 3 );
    }

    /* ── Fetch latest version info (cached 12 hours) ─────────────────── */
    private static function get_remote_info() {
        $cached = get_transient( 'woolens_remote_version' );
        if ( $cached ) return $cached;

        $response = wp_remote_get( self::$api_url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['version'] ) ) return null;

        set_transient( 'woolens_remote_version', $body, 12 * HOUR_IN_SECONDS );
        return $body;
    }

    /* ── Admin notice if new version available ────────────────────────── */
    public static function show_update_notice() {
        if ( ! current_user_can( 'update_plugins' ) ) return;

        $info = self::get_remote_info();
        if ( ! $info ) return;

        if ( version_compare( $info['version'], WOOLENS_VERSION, '>' ) ) {
            $download = esc_url( $info['download_url'] ?? '' );
            echo '<div class="notice notice-warning is-dismissible">
                <p>
                    <strong>WooLens AI</strong> — Version ' . esc_html( $info['version'] ) . ' is available.
                    You are using ' . esc_html( WOOLENS_VERSION ) . '.
                    <a href="' . $download . '" target="_blank" style="margin-left:8px;font-weight:600;">Download Update</a>
                </p>
            </div>';
        }
    }

    /* ── Inject into WordPress update system ─────────────────────────── */
    public static function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $info = self::get_remote_info();
        if ( ! $info ) return $transient;

        if ( version_compare( $info['version'], WOOLENS_VERSION, '>' ) ) {
            $transient->response[ WOOLENS_BASENAME ] = (object) [
                'slug'        => 'woolens-ai',
                'plugin'      => WOOLENS_BASENAME,
                'new_version' => $info['version'],
                'url'         => WOOLENS_SERVER_URL,
                'package'     => $info['download_url'] ?? '',
                'icons'       => [],
                'banners'     => [],
                'tested'      => $info['tested'] ?? '6.7',
                'requires'    => $info['requires'] ?? '5.0',
            ];
        }

        return $transient;
    }

    /* ── Plugin info popup (when user clicks "View version details") ──── */
    public static function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== 'woolens-ai' ) return $result;

        $info = self::get_remote_info();
        if ( ! $info ) return $result;

        return (object) [
            'name'          => 'WooLens AI',
            'slug'          => 'woolens-ai',
            'version'       => $info['version'],
            'author'        => 'WooLens AI',
            'homepage'      => WOOLENS_SERVER_URL,
            'download_link' => $info['download_url'] ?? '',
            'tested'        => $info['tested'] ?? '6.7',
            'requires'      => $info['requires'] ?? '5.0',
            'sections'      => [
                'changelog' => $info['changelog'] ?? 'Bug fixes and improvements.',
            ],
        ];
    }
}
