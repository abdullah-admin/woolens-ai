<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Settings_Page {

    const SLUG    = 'woolens-ai';
    const OPT_GRP = 'woolens_options';

    public static function init(): void {
        add_action( 'admin_menu',  [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_filter( 'plugin_action_links_' . WOOLENS_BASENAME, [ __CLASS__, 'plugin_links' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'WooLens AI',
            'WooLens AI',
            'manage_options',
            self::SLUG,
            [ __CLASS__, 'render_page' ],
            'dashicons-camera-alt',
            58
        );
    }

    public static function register_settings(): void {
        $fields = [
            'woolens_gemini_key' => '',
            'woolens_language'   => 'English',
            'woolens_tone'       => 'Professional',
        ];
        foreach ( $fields as $key => $default ) {
            register_setting( self::OPT_GRP, $key, [
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => $default,
            ] );
        }
    }

    public static function get( string $key, string $default = '' ): string {
        return (string) get_option( $key, $default );
    }

    /* ── Pro check via API (cached 1 hour) ───────────────────────── */
    public static function is_pro( bool $force_fresh = false ): bool {
        $token = get_option( 'woolens_auth_token', '' );
        if ( empty( $token ) ) return false;

        // Check transient cache first (10 min TTL) — skipped when force_fresh
        $cache_key = 'woolens_pro_' . substr( md5( $token ), 0, 16 );
        if ( ! $force_fresh ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) return $cached === '1';
        }

        $res = wp_remote_post( rtrim( WOOLENS_SERVER_URL, '/' ) . '/api/license/check', [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'token' => $token, 'site' => home_url() ] ),
        ] );

        if ( is_wp_error( $res ) ) return false;

        $data   = json_decode( wp_remote_retrieve_body( $res ), true );
        $is_pro = ! empty( $data['is_pro'] );

        set_transient( $cache_key, $is_pro ? '1' : '0', 10 * MINUTE_IN_SECONDS );
        update_user_meta( get_current_user_id(), 'woolens_pro', $is_pro ? '1' : '' );

        if ( ! empty( $data['plan_label'] ) )
            update_option( 'woolens_auth_plan_label', sanitize_text_field( $data['plan_label'] ) );
        if ( isset( $data['sites_limit'] ) )
            update_option( 'woolens_auth_sites_limit', (int) $data['sites_limit'] );
        if ( isset( $data['sites_used'] ) )
            update_option( 'woolens_auth_sites_used', (int) $data['sites_used'] );

        return $is_pro;
    }

    /* ── Account section handlers ─────────────────────────────────── */
    private static function handle_account_actions(): void {
        // Connect account (login)
        if (
            isset( $_POST['woolens_connect_account'] ) &&
            check_admin_referer( 'woolens_account_nonce' )
        ) {
            $email    = sanitize_email(      $_POST['woolens_email']    ?? '' );
            $password = sanitize_text_field( $_POST['woolens_password'] ?? '' );

            if ( empty( $email ) || empty( $password ) ) {
                add_settings_error( self::OPT_GRP, 'account_empty', 'Please fill in email and password.', 'error' );
                return;
            }

            $res = wp_remote_post( rtrim( WOOLENS_SERVER_URL, '/' ) . '/api/auth/token', [
                'timeout' => 10,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'email' => $email, 'password' => $password ] ),
            ] );

            if ( is_wp_error( $res ) ) {
                add_settings_error( self::OPT_GRP, 'account_conn', 'Could not reach the server: ' . esc_html( $res->get_error_message() ), 'error' );
                return;
            }

            $code = wp_remote_retrieve_response_code( $res );
            $data = json_decode( wp_remote_retrieve_body( $res ), true );

            if ( $code !== 200 || empty( $data['token'] ) ) {
                $msg = $data['error'] ?? 'Login failed. Please check your email and password.';
                add_settings_error( self::OPT_GRP, 'account_fail', esc_html( $msg ), 'error' );
                return;
            }

            update_option( 'woolens_auth_token',        $data['token'] );
            update_option( 'woolens_auth_name',         sanitize_text_field( $data['name']         ?? '' ) );
            update_option( 'woolens_auth_email',        sanitize_email(      $data['email']        ?? '' ) );
            update_option( 'woolens_auth_plan',         sanitize_text_field( $data['plan']         ?? 'free' ) );
            update_option( 'woolens_auth_plan_expires', sanitize_text_field( $data['plan_expires'] ?? '' ) );

            // Clear pro cache so next request re-checks
            delete_transient( 'woolens_pro_' . substr( md5( $data['token'] ), 0, 16 ) );

            add_settings_error( self::OPT_GRP, 'account_ok', 'Account connected successfully! Welcome, ' . esc_html( $data['name'] ?? $email ) . '.', 'success' );
        }

        // Disconnect account (logout)
        if (
            isset( $_POST['woolens_disconnect_account'] ) &&
            check_admin_referer( 'woolens_account_nonce' )
        ) {
            $old_token = get_option( 'woolens_auth_token', '' );
            if ( $old_token ) delete_transient( 'woolens_pro_' . substr( md5( $old_token ), 0, 16 ) );

            delete_option( 'woolens_auth_token' );
            delete_option( 'woolens_auth_name' );
            delete_option( 'woolens_auth_email' );
            delete_option( 'woolens_auth_plan' );
            delete_option( 'woolens_auth_plan_expires' );

            add_settings_error( self::OPT_GRP, 'account_removed', 'Account disconnected.', 'success' );
        }

        // Refresh Pro status — call API fresh, update plan_expires, reset transient
        if (
            isset( $_POST['woolens_refresh_status'] ) &&
            check_admin_referer( 'woolens_account_nonce' )
        ) {
            $token = get_option( 'woolens_auth_token', '' );
            if ( $token ) {
                $cache_key = 'woolens_pro_' . substr( md5( $token ), 0, 16 );
                $refreshed = false;

                $res = wp_remote_post( rtrim( WOOLENS_SERVER_URL, '/' ) . '/api/license/check', [
                        'timeout' => 8,
                        'headers' => [ 'Content-Type' => 'application/json' ],
                        'body'    => wp_json_encode( [ 'token' => $token, 'site' => home_url() ] ),
                    ] );
                if ( ! is_wp_error( $res ) ) {
                    $d = json_decode( wp_remote_retrieve_body( $res ), true );
                    if ( isset( $d['is_pro'] ) ) {
                        $is_pro = ! empty( $d['is_pro'] );
                        set_transient( $cache_key, $is_pro ? '1' : '0', HOUR_IN_SECONDS );
                        update_user_meta( get_current_user_id(), 'woolens_pro', $is_pro ? '1' : '' );
                        if ( ! empty( $d['plan_expires'] ) )
                            update_option( 'woolens_auth_plan_expires', sanitize_text_field( $d['plan_expires'] ) );
                        if ( ! empty( $d['plan_label'] ) )
                            update_option( 'woolens_auth_plan_label', sanitize_text_field( $d['plan_label'] ) );
                        if ( isset( $d['sites_limit'] ) )
                            update_option( 'woolens_auth_sites_limit', (int) $d['sites_limit'] );
                        if ( isset( $d['sites_used'] ) )
                            update_option( 'woolens_auth_sites_used', (int) $d['sites_used'] );
                        $refreshed = true;
                    }
                }

                if ( ! $refreshed ) {
                    delete_transient( $cache_key );
                }
            }
            add_settings_error( self::OPT_GRP, 'status_refreshed', 'Plan status refreshed.', 'success' );
        }

    }

    /* ── Settings Page ────────────────────────────────────────────── */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        self::handle_account_actions();

        $is_pro       = self::is_pro();
        $uid          = get_current_user_id();
$status       = WOOLENS_Rate_Limiter::status( $uid );
        $used         = (int) $status['used'];
        $limit        = WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT;
        $pct          = $is_pro ? 0 : min( 100, $used ? round( $used / $limit * 100 ) : 0 );
        $bar_color    = $pct >= 100 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#2271b1' );

        $auth_token        = get_option( 'woolens_auth_token',        '' );
        $auth_name         = get_option( 'woolens_auth_name',         '' );
        $auth_email        = get_option( 'woolens_auth_email',        '' );
        $auth_plan         = get_option( 'woolens_auth_plan',         'free' );
        $auth_plan_expires = get_option( 'woolens_auth_plan_expires', '' );
        $auth_plan_label   = get_option( 'woolens_auth_plan_label',   '' );
        $auth_sites_limit  = (int) get_option( 'woolens_auth_sites_limit', 3 );
        $auth_sites_used   = (int) get_option( 'woolens_auth_sites_used',  0 );
        $server_url        = WOOLENS_SERVER_URL;
        $connected         = ! empty( $auth_token );
        ?>
        <style>
        .wl-wrap { max-width: 680px; }
        .wl-badge-free { display:inline-block; background:#f0f6fc; color:#50575e; font-size:11px; padding:2px 8px; border-radius:3px; font-weight:600; margin-left:8px; vertical-align:middle; border:1px solid #c3c4c7; }
        .wl-badge-pro  { display:inline-block; background:#fff8e5; color:#996800; font-size:11px; padding:2px 8px; border-radius:3px; font-weight:600; margin-left:8px; vertical-align:middle; border:1px solid #f0c33c; }
        .wl-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; margin-bottom:16px; overflow:hidden; }
        .wl-card-head { background:#f6f7f7; border-bottom:1px solid #c3c4c7; padding:10px 16px; font-size:13px; font-weight:600; color:#1d2327; }
        .wl-card-body { padding:16px; }
        .wl-field { margin-bottom:14px; }
        .wl-field:last-child { margin-bottom:0; }
        .wl-label { display:block; font-size:12px; font-weight:600; color:#1d2327; margin-bottom:5px; }
        .wl-input, .wl-select { width:100%; max-width:400px; border:1px solid #8c8f94 !important; border-radius:4px !important; padding:6px 8px !important; font-size:13px !important; color:#2c3338; background:#fff; box-shadow:none !important; }
        .wl-input:focus, .wl-select:focus { border-color:#2271b1 !important; box-shadow:0 0 0 1px #2271b1 !important; outline:none !important; }
        .wl-hint { font-size:11px; color:#646970; margin-top:4px; line-height:1.5; }
        .wl-hint a { color:#2271b1; }
        .wl-hint code { background:#f6f7f7; padding:1px 5px; border-radius:3px; font-size:11px; font-family:monospace; border:1px solid #c3c4c7; }
        .wl-model-locked { display:flex; align-items:center; gap:8px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; padding:8px 12px; max-width:400px; }
        .wl-model-locked span { font-size:13px; font-weight:600; color:#1d2327; font-family:monospace; }
        .wl-usage-bar { height:6px; background:#dcdcde; border-radius:3px; max-width:300px; overflow:hidden; margin:8px 0 4px; }
        .wl-usage-fill { height:100%; border-radius:3px; }
        .wl-upgrade { background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; padding:18px 20px; margin-top:16px; }
        .wl-upgrade h3 { margin:0 0 10px; color:#1d2327; font-size:14px; }
        .wl-upgrade ul { margin:0 0 14px; padding-left:18px; font-size:13px; color:#1d2327; line-height:2; }
        .wl-account-info { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .wl-account-avatar { width:38px; height:38px; border-radius:50%; background:#2271b1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:700; flex-shrink:0; }
        .wl-account-detail { flex:1; }
        .wl-account-name { font-size:14px; font-weight:600; color:#1d2327; }
        .wl-account-email { font-size:12px; color:#646970; margin-top:1px; }
        .wl-pw-wrap { position:relative; display:inline-block; max-width:400px; width:100%; }
        .wl-pw-wrap .wl-input { padding-right:34px !important; max-width:100% !important; width:100% !important; }
        .wl-pw-toggle { position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:2px; color:#646970; line-height:0; }
        .wl-pw-toggle:hover { color:#2271b1; }
        .wl-pw-toggle .dashicons { font-size:16px; width:16px; height:16px; vertical-align:middle; }
        </style>

        <div class="wrap wl-wrap">

            <h1 class="wp-heading-inline">WooLens AI</h1>
            <span class="<?php echo $is_pro ? 'wl-badge-pro' : 'wl-badge-free'; ?>">
                <?php echo $is_pro ? 'PRO' : 'Free'; ?>
            </span>
            <p class="description" style="margin-top:6px;margin-bottom:20px">From image to words — instantly.</p>
            <hr class="wp-header-end">

            <?php settings_errors( self::OPT_GRP ); ?>

            <!-- WooLens Account -->
            <div class="wl-card" style="<?php echo $connected ? ( $is_pro ? 'border-color:#1d7e2f' : '' ) : 'border-color:#c3c4c7'; ?>">
                <div class="wl-card-head" style="<?php echo $connected && $is_pro ? 'background:#edfaef;border-color:#1d7e2f;color:#1d7e2f' : ''; ?>">
                    WooLens Account
                    <?php if ( $connected ): ?>
                        <span style="font-size:10px;font-weight:400;margin-left:8px">Connected</span>
                    <?php endif; ?>
                </div>
                <div class="wl-card-body">

                    <?php if ( $connected ): ?>
                        <!-- Connected state -->
                        <div class="wl-account-info" style="margin-bottom:14px">
                            <div class="wl-account-avatar"><?php echo esc_html( strtoupper( substr( $auth_name ?: $auth_email, 0, 1 ) ) ); ?></div>
                            <div class="wl-account-detail">
                                <div class="wl-account-name"><?php echo esc_html( $auth_name ); ?></div>
                                <div class="wl-account-email"><?php echo esc_html( $auth_email ); ?></div>
                            </div>
                            <span class="<?php echo $is_pro ? 'wl-badge-pro' : 'wl-badge-free'; ?>" style="margin-left:0">
                                <?php echo $is_pro ? 'PRO' : 'Free'; ?>
                            </span>
                        </div>

                        <?php if ( $is_pro ): ?>
                            <p style="color:#1d7e2f;font-weight:500;font-size:13px;margin:0 0 10px">
                                Pro plan active — unlimited generations, all languages enabled.
                            </p>
                            <table style="border-collapse:collapse;font-size:12px;margin-bottom:12px">
                                <?php if ( $auth_plan_label ): ?>
                                <tr>
                                    <td style="color:#646970;padding:3px 16px 3px 0;white-space:nowrap">Plan</td>
                                    <td style="color:#1d2327;font-weight:600"><?php echo esc_html( $auth_plan_label ); ?> Pro</td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td style="color:#646970;padding:3px 16px 3px 0;white-space:nowrap">Sites</td>
                                    <td style="color:#1d2327;font-weight:600">
                                        <?php echo $auth_sites_used; ?> of <?php echo $auth_sites_limit; ?> connected
                                        <?php if ( $auth_sites_used >= $auth_sites_limit ): ?>
                                            <span style="color:#d63638;font-size:11px;margin-left:4px">(Limit reached)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ( $auth_plan_expires ): ?>
                                <tr>
                                    <td style="color:#646970;padding:3px 16px 3px 0;white-space:nowrap">Expires</td>
                                    <td style="color:#1d2327;font-weight:600"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $auth_plan_expires ) ) ); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        <?php else: ?>
                            <p style="color:#646970;font-size:13px;margin:0 0 12px">
                                Free plan: <?php echo WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT; ?> generations/day, English only.
                            </p>
                            <a href="<?php echo esc_url( rtrim( $server_url, '/' ) . '/buy-pro' ); ?>"
                               target="_blank" class="button button-primary" style="margin-bottom:12px">
                                Upgrade to Pro
                            </a><br>
                        <?php endif; ?>

                        <form method="post" style="margin-top:4px;display:flex;gap:8px;flex-wrap:wrap">
                            <?php wp_nonce_field( 'woolens_account_nonce' ); ?>
                            <button type="submit" name="woolens_refresh_status" value="1" class="button">
                                🔄 Refresh Plan Status
                            </button>
                            <button type="submit" name="woolens_disconnect_account" value="1"
                                    class="button"
                                    onclick="return confirm('Disconnect account? Pro features will be disabled.')">
                                Disconnect Account
                            </button>
                        </form>

                    <?php else: ?>
                        <!-- Login form -->
                        <form method="post">
                            <?php wp_nonce_field( 'woolens_account_nonce' ); ?>
                            <div class="wl-field">
                                <label class="wl-label" for="wl_email">Email</label>
                                <input name="woolens_email" id="wl_email" type="email"
                                       class="wl-input" placeholder="you@example.com" autocomplete="email">
                            </div>
                            <div class="wl-field">
                                <label class="wl-label" for="wl_password">Password</label>
                                <div class="wl-pw-wrap">
                                    <input name="woolens_password" id="wl_password" type="password"
                                           class="wl-input" placeholder="Your account password" autocomplete="current-password">
                                    <button type="button" class="wl-pw-toggle" onclick="wlTogglePw('wl_password',this)" title="Show password">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                </div>
                                <p style="margin:4px 0 0;text-align:right">
                                    <a href="<?php echo esc_url( rtrim( $server_url, '/' ) . '/forgot-password' ); ?>"
                                       target="_blank" style="font-size:12px;color:#3b82f6;text-decoration:none;font-weight:500;">
                                        Forgot Password?
                                    </a>
                                </p>
                            </div>
                            <button type="submit" name="woolens_connect_account" value="1" class="button button-primary">
                                Connect Account
                            </button>
                            <p class="wl-hint" style="margin-top:10px">
                                Don't have an account?
                                <a href="<?php echo esc_url( rtrim( $server_url, '/' ) . '/signup' ); ?>" target="_blank">Sign Up</a>
                            </p>
                        </form>
                    <?php endif; ?>

                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPT_GRP ); ?>

                <!-- Gemini API Key -->
                <div class="wl-card">
                    <div class="wl-card-head">Gemini API Key</div>
                    <div class="wl-card-body">
                        <div class="wl-field">
                            <label class="wl-label" for="woolens_gemini_key">Google AI Studio API Key</label>
                            <div class="wl-pw-wrap">
                                <input name="woolens_gemini_key" id="woolens_gemini_key" type="password"
                                       value="<?php echo esc_attr( self::get('woolens_gemini_key') ); ?>"
                                       class="wl-input" autocomplete="off" placeholder="AQ...">
                                <button type="button" class="wl-pw-toggle" onclick="wlTogglePw('woolens_gemini_key',this)" title="Show key">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                            <p class="wl-hint">
                                Get your free key at
                                <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a>
                                — No credit card required.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- AI Model -->
                <div class="wl-card">
                    <div class="wl-card-head">AI Model</div>
                    <div class="wl-card-body">
                        <div class="wl-field">
                            <label class="wl-label">Active Model</label>
                            <div class="wl-model-locked">
                                <span><?php echo esc_html( WOOLENS_FREE_MODEL ); ?></span>
                            </div>
                            <p class="wl-hint">WooLens AI uses <code><?php echo esc_html( WOOLENS_FREE_MODEL ); ?></code> for all plans.</p>
                        </div>
                    </div>
                </div>

                <!-- Generation Settings -->
                <div class="wl-card">
                    <div class="wl-card-head">Generation Settings</div>
                    <div class="wl-card-body">

                        <div class="wl-field">
                            <label class="wl-label">Output Language</label>
                            <?php $lang = self::get('woolens_language','English'); ?>
                            <?php if ( $is_pro ): ?>
                                <select name="woolens_language" class="wl-select">
                                    <?php foreach(['English','Roman Urdu','Urdu','Arabic','Hindi','French','Spanish','German','Turkish','Bengali','Malay'] as $l): ?>
                                        <option <?php selected($lang,$l); ?>><?php echo esc_html($l); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="woolens_language" value="English">
                                <select class="wl-select" disabled><option>English</option></select>
                                <p class="wl-hint">Free plan: English only. <a href="#wl-upgrade">Upgrade to Pro</a> for Roman Urdu, Urdu, Arabic, Hindi &amp; more.</p>
                            <?php endif; ?>
                        </div>

                        <div class="wl-field">
                            <label class="wl-label">Writing Tone</label>
                            <?php $tone = self::get('woolens_tone','Professional'); ?>
                            <select name="woolens_tone" class="wl-select">
                                <?php foreach(['Professional','Friendly','Persuasive','Minimal','Luxury'] as $t): ?>
                                    <option <?php selected($tone,$t); ?>><?php echo esc_html($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>

                <!-- Simulate Pro (debug only) -->
                <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ): ?>
                <?php endif; ?>

                <p><button type="submit" class="button button-primary">Save Settings</button></p>
            </form>

            <!-- Today's Usage -->
            <div class="wl-card">
                <div class="wl-card-head">Today's Usage</div>
                <div class="wl-card-body">
                    <?php if ( $is_pro ): ?>
                        <p style="color:#1d7e2f;font-weight:500;margin:0">Pro plan — unlimited generations</p>
                    <?php else: ?>
                        <p style="font-size:13px;color:#1d2327;margin:0 0 4px">
                            <strong><?php echo $used; ?></strong> / <?php echo $limit; ?> generations used today
                        </p>
                        <div class="wl-usage-bar">
                            <div class="wl-usage-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>"></div>
                        </div>
                        <p class="wl-hint">Resets daily at midnight</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upgrade -->
            <?php if ( ! $is_pro ): ?>
            <div class="wl-upgrade" id="wl-upgrade">
                <h3>Upgrade to WooLens AI Pro</h3>
                <ul>
                    <li>Unlimited daily AI generations</li>
                    <li>Multi-language support: Roman Urdu, Urdu, Arabic, Hindi, French, Spanish &amp; more</li>
                    <li>Structured HTML descriptions with bullets &amp; taglines</li>
                    <li>Bulk product creation — upload images, AI writes &amp; creates products automatically</li>
                    <li>Connect up to 25 sites (based on plan)</li>
                    <li>Priority support</li>
                </ul>
                <?php if ( $connected && ! empty( $server_url ) ): ?>
                    <a href="<?php echo esc_url( rtrim( $server_url, '/' ) . '/buy-pro' ); ?>" target="_blank" class="button button-primary">Get Pro</a>
                <?php else: ?>
                    <p class="wl-hint">Connect your WooLens account above to upgrade.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <script>
        function wlTogglePw(id, btn) {
            var inp = document.getElementById(id);
            if (!inp) return;
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.className = show ? 'dashicons dashicons-hidden' : 'dashicons dashicons-visibility';
            }
            btn.title = show ? 'Hide' : 'Show';
        }
        </script>
        <?php
    }

    public static function plugin_links( array $links ): array {
        $url = admin_url( 'admin.php?page=' . self::SLUG );
        array_unshift( $links, '<a href="' . esc_url($url) . '">Settings</a>' );
        return $links;
    }
}
