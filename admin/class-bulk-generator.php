<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Bulk_Generator {

    const TRANSIENT_PREFIX = 'woolens_bulk_';
    const MAX_PRODUCTS     = 30;
    const DELAY_MS         = 1500; // ms between requests to avoid rate limits

    public static function init(): void {
        add_filter( 'bulk_actions-edit-product',        [ __CLASS__, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_menu',                       [ __CLASS__, 'register_page' ] );
        add_action( 'wp_ajax_woolens_bulk_generate',    [ __CLASS__, 'ajax_bulk_generate' ] );
    }

    /* ── Register bulk action in products list ────────────────────── */
    public static function register_bulk_action( array $actions ): array {
        $actions['woolens_bulk_generate'] = 'Generate with WooLens AI';
        return $actions;
    }

    /* ── Intercept bulk action, store IDs in transient, redirect ──── */
    public static function handle_bulk_action( string $redirect, string $action, array $post_ids ): string {
        if ( $action !== 'woolens_bulk_generate' ) return $redirect;
        if ( ! current_user_can( 'edit_products' ) ) return $redirect;

        // Sanitize IDs, enforce max limit
        $post_ids = array_slice( array_map( 'absint', $post_ids ), 0, self::MAX_PRODUCTS );

        // Accept only valid product posts
        $product_ids = [];
        foreach ( $post_ids as $id ) {
            if ( $id > 0 && get_post_type( $id ) === 'product' ) {
                $product_ids[] = $id;
            }
        }

        if ( empty( $product_ids ) ) return $redirect;

        // Store IDs server-side — never pass them via URL
        $bulk_key = bin2hex( random_bytes( 16 ) );
        set_transient(
            self::TRANSIENT_PREFIX . $bulk_key,
            [
                'ids' => $product_ids,
                'uid' => get_current_user_id(),
            ],
            30 * MINUTE_IN_SECONDS
        );

        return admin_url( 'admin.php?page=woolens-bulk&bulk_key=' . rawurlencode( $bulk_key ) );
    }

    /* ── Hidden admin page (not in sidebar menu) ──────────────────── */
    public static function register_page(): void {
        add_submenu_page(
            null,
            'WooLens AI — Bulk Generate',
            'Bulk Generate',
            'edit_products',
            'woolens-bulk',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ── Progress page ────────────────────────────────────────────── */
    public static function render_page(): void {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Unauthorized.' );

        $bulk_key = sanitize_key( $_GET['bulk_key'] ?? '' );
        if ( empty( $bulk_key ) ) wp_die( 'Missing bulk key.' );

        $data = get_transient( self::TRANSIENT_PREFIX . $bulk_key );
        if ( ! $data || ! is_array( $data ) ) {
            wp_die( 'Session expired or invalid. Please go back and try again.' );
        }

        // Must be the same user who triggered the action
        if ( (int) $data['uid'] !== get_current_user_id() ) {
            wp_die( 'Unauthorized.' );
        }

        $is_pro       = WOOLENS_Settings_Page::is_pro();
        $products_url = admin_url( 'edit.php?post_type=product' );

        // Build product data for rendering
        $products = [];
        foreach ( $data['ids'] as $pid ) {
            $post = get_post( $pid );
            if ( ! $post || $post->post_type !== 'product' ) continue;
            $thumb_id  = (int) get_post_thumbnail_id( $pid );
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
            $products[] = [
                'id'        => $pid,
                'title'     => $post->post_title ?: '(No title)',
                'thumb_id'  => $thumb_id,
                'thumb_url' => (string) $thumb_url,
                'edit_url'  => (string) get_edit_post_link( $pid, 'url' ),
            ];
        }

        $total         = count( $products );
        $nonce         = wp_create_nonce( 'woolens_bulk_nonce' );
        $products_json = wp_json_encode( $products );
        $ajax_url      = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        .wl-bulk-wrap { max-width:680px; }
        .wl-bulk-progress { background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; padding:14px 16px; margin:14px 0; }
        .wl-bulk-bar-outer { height:8px; background:#dcdcde; border-radius:4px; overflow:hidden; margin:8px 0 5px; }
        .wl-bulk-bar-inner { height:100%; background:#2271b1; border-radius:4px; width:0%; transition:width .3s; }
        .wl-bulk-summary { font-size:13px; font-weight:600; color:#1d2327; }
        .wl-bulk-sub { font-size:11px; color:#646970; }
        .wl-product-list { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:0 16px; margin-top:16px; }
        .wl-product-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f0f0f1; }
        .wl-product-row:last-child { border-bottom:none; }
        .wl-product-thumb { width:40px; height:40px; object-fit:cover; border-radius:3px; border:1px solid #c3c4c7; flex-shrink:0; }
        .wl-product-thumb-empty { width:40px; height:40px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:3px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; color:#c3c4c7; }
        .wl-product-name { flex:1; font-size:13px; color:#1d2327; font-weight:500; min-width:0; }
        .wl-product-name a { font-size:11px; font-weight:400; margin-left:6px; color:#2271b1; }
        .wl-product-status { font-size:12px; min-width:160px; text-align:right; white-space:nowrap; }
        .wl-st-pending  { color:#646970; }
        .wl-st-running  { color:#2271b1; font-weight:600; }
        .wl-st-done     { color:#1d7e2f; font-weight:600; }
        .wl-st-error    { color:#d63638; }
        .wl-done-box { background:#edfaef; border:1px solid #1d7e2f; border-radius:4px; padding:14px 16px; margin:14px 0; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .wl-error-box { background:#fcf0f1; border-left:4px solid #d63638; padding:12px 16px; border-radius:2px; margin-bottom:16px; }
        </style>

        <div class="wrap">
        <h1>WooLens AI — Bulk Generate</h1>

        <?php if ( ! $is_pro ): ?>
            <div class="wl-error-box">
                <strong>Pro feature required.</strong> Bulk generation is only available on WooLens AI Pro.
                <a href="<?php echo esc_url( admin_url('admin.php?page=woolens-ai') ); ?>#wl-upgrade"
                   class="button button-primary" style="margin-left:12px">Upgrade to Pro</a>
            </div>
            <a href="<?php echo esc_url($products_url); ?>" class="button">Back to Products</a>
        <?php else: ?>

        <div class="wl-bulk-wrap">
            <p class="description" style="margin-bottom:16px">
                <strong><?php echo $total; ?></strong> product<?php echo $total !== 1 ? 's' : ''; ?> selected.
                WooLens AI will process them one by one and save titles and descriptions automatically.
            </p>

            <!-- Start / Cancel -->
            <div id="wl-action-row">
                <button id="wl-bulk-start" class="button button-primary button-large">
                    Start Generating (<?php echo $total; ?> products)
                </button>
                <a href="<?php echo esc_url($products_url); ?>" class="button button-large" style="margin-left:8px">
                    Cancel
                </a>
            </div>

            <!-- Progress bar (hidden until start) -->
            <div class="wl-bulk-progress" id="wl-bulk-progress" style="display:none">
                <div class="wl-bulk-summary" id="wl-bulk-summary">Starting…</div>
                <div class="wl-bulk-bar-outer">
                    <div class="wl-bulk-bar-inner" id="wl-bulk-bar"></div>
                </div>
                <div class="wl-bulk-sub" id="wl-bulk-sub"></div>
            </div>

            <!-- Done summary (hidden until complete) -->
            <div class="wl-done-box" id="wl-bulk-done" style="display:none">
                <span id="wl-done-text" style="font-size:13px;font-weight:500;color:#1d7e2f"></span>
                <a href="<?php echo esc_url($products_url); ?>" class="button button-primary">
                    Back to Products
                </a>
            </div>

            <!-- Product rows -->
            <div class="wl-product-list">
                <?php foreach ( $products as $p ): ?>
                <div class="wl-product-row" id="wl-row-<?php echo (int) $p['id']; ?>">
                    <?php if ( $p['thumb_url'] ): ?>
                        <img src="<?php echo esc_url( $p['thumb_url'] ); ?>" class="wl-product-thumb" alt="">
                    <?php else: ?>
                        <div class="wl-product-thumb-empty">&#9974;</div>
                    <?php endif; ?>
                    <span class="wl-product-name">
                        <?php echo esc_html( $p['title'] ); ?>
                        <a href="<?php echo esc_url( $p['edit_url'] ); ?>" target="_blank">Edit</a>
                    </span>
                    <span class="wl-product-status wl-st-pending"
                          id="wl-status-<?php echo (int) $p['id']; ?>">
                        Pending
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function($){
            var products  = <?php echo $products_json; ?>;
            var total     = products.length;
            var done      = 0;
            var succeeded = 0;
            var failed    = 0;
            var running   = false;
            var stopped   = false;
            var AJAX_URL  = <?php echo wp_json_encode( $ajax_url ); ?>;
            var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
            var BULK_KEY  = <?php echo wp_json_encode( $bulk_key ); ?>;
            var DELAY     = <?php echo (int) self::DELAY_MS; ?>;

            function setStatus(id, text, cls) {
                $('#wl-status-' + id)
                    .removeClass('wl-st-pending wl-st-running wl-st-done wl-st-error')
                    .addClass('wl-st-' + cls)
                    .text(text);
            }

            function updateBar() {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                var color = pct >= 100 ? '#1d7e2f' : '#2271b1';
                $('#wl-bulk-bar').css({ width: pct + '%', background: color });
                $('#wl-bulk-summary').text(done + ' / ' + total + ' processed (' + pct + '%)');
                $('#wl-bulk-sub').text(succeeded + ' generated, ' + failed + ' skipped');
            }

            function finish(reason) {
                $('#wl-bulk-start').prop('disabled', false).text('Done');
                $('#wl-bulk-done').show();
                var msg = succeeded + ' product' + (succeeded !== 1 ? 's' : '') + ' generated, '
                        + failed + ' skipped.';
                if (reason) msg += ' ' + reason;
                $('#wl-done-text').text(msg);
            }

            function processProduct(index) {
                if (stopped || index >= products.length) {
                    finish();
                    return;
                }

                var p = products[index];
                setStatus(p.id, 'Generating…', 'running');

                $.ajax({
                    url:     AJAX_URL,
                    method:  'POST',
                    timeout: 45000,
                    data: {
                        action:     'woolens_bulk_generate',
                        nonce:      NONCE,
                        bulk_key:   BULK_KEY,
                        product_id: p.id,
                    },
                    success: function(res) {
                        done++;
                        if (res.success) {
                            succeeded++;
                            setStatus(p.id, 'Done', 'done');
                            updateBar();
                            setTimeout(function(){ processProduct(index + 1); }, DELAY);
                        } else {
                            failed++;
                            var msg = (res.data && res.data.message) ? res.data.message : 'Error';
                            var shortMsg = msg.length > 45 ? msg.substring(0, 45) + '…' : msg;
                            setStatus(p.id, shortMsg, 'error');
                            updateBar();

                            // Rate limit hit — stop entirely
                            if (msg.indexOf('limit') !== -1 || msg.indexOf('429') !== -1) {
                                stopped = true;
                                finish('Daily limit reached.');
                                return;
                            }
                            // Any other error — skip, continue next
                            setTimeout(function(){ processProduct(index + 1); }, DELAY);
                        }
                    },
                    error: function(xhr) {
                        done++; failed++;
                        var msg = 'Network error';
                        try { msg = JSON.parse(xhr.responseText).data.message; } catch(e){}
                        setStatus(p.id, msg.length > 45 ? msg.substring(0,45)+'…' : msg, 'error');
                        updateBar();
                        setTimeout(function(){ processProduct(index + 1); }, DELAY);
                    }
                });
            }

            $('#wl-bulk-start').on('click', function(){
                if (running) return;
                running = true;
                $(this).prop('disabled', true).text('Generating…');
                $('#wl-bulk-progress').show();
                $('#wl-bulk-done').hide();
                updateBar();
                processProduct(0);
            });

        })(jQuery);
        </script>

        <?php endif; // is_pro ?>
        </div>
        <?php
    }

    /* ── AJAX: generate + save one product ───────────────────────── */
    public static function ajax_bulk_generate(): void {
        // Security checks first
        check_ajax_referer( 'woolens_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $uid        = get_current_user_id();
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $bulk_key   = sanitize_key( $_POST['bulk_key'] ?? '' );

        if ( $product_id <= 0 || empty( $bulk_key ) ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
        }

        // Validate session: must belong to this user and contain this product
        $data = get_transient( self::TRANSIENT_PREFIX . $bulk_key );
        if (
            ! $data ||
            ! is_array( $data ) ||
            (int) $data['uid'] !== $uid ||
            ! in_array( $product_id, $data['ids'], true )
        ) {
            wp_send_json_error( [ 'message' => 'Session expired or unauthorized.' ], 403 );
        }

        // Pro check (fresh, bypass cache)
        $is_pro = WOOLENS_Settings_Page::is_pro( true );
        if ( ! $is_pro ) {
            wp_send_json_error( [ 'message' => 'Bulk generation requires Pro.' ], 403 );
        }

        // Rate limit — pro users bypass (bulk is pro-only so this is a secondary guard)
        if ( ! $is_pro && ! WOOLENS_Rate_Limiter::can_generate( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Daily limit reached. Upgrade to Pro for unlimited.' ], 429 );
        }

        // API key
        $api_key = WOOLENS_Settings_Page::get( 'woolens_gemini_key' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'Gemini API key missing. Go to WooLens AI Settings.' ], 400 );
        }

        $model = WOOLENS_FREE_MODEL;

        // Verify product exists and is a product post type
        $post = get_post( $product_id );
        if ( ! $post || $post->post_type !== 'product' ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ], 404 );
        }

        // Image
        $thumb_id  = (int) get_post_thumbnail_id( $product_id );
        $image_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
        if ( empty( $image_url ) ) {
            wp_send_json_error( [ 'message' => 'No image — skipped.' ], 400 );
        }

        // Call Gemini — bulk is always Pro, so always use pro structured format
        $result = WOOLENS_AI_Client::generate(
            $api_key,
            $model,
            $image_url,
            'both',
            [
                'language' => WOOLENS_Settings_Page::get( 'woolens_language', 'English' ),
                'tone'     => WOOLENS_Settings_Page::get( 'woolens_tone', 'Professional' ),
                'is_pro'   => true,
            ]
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        // Build update array — only include non-empty fields
        $update = [ 'ID' => $product_id ];
        if ( ! empty( $result['title'] ) ) {
            $update['post_title'] = sanitize_text_field( $result['title'] );
        }
        if ( ! empty( $result['description'] ) ) {
            $update['post_content'] = wp_kses_post( $result['description'] );
        }
        if ( ! empty( $result['short_description'] ) ) {
            $update['post_excerpt'] = wp_kses_post( $result['short_description'] );
        }

        $saved = wp_update_post( $update, true );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( [ 'message' => 'Failed to save: ' . $saved->get_error_message() ], 500 );
        }

        // Remove Elementor's post-specific canvas data — if present it overrides post_content on frontend
        if ( get_post_meta( $product_id, '_elementor_data', true ) ) {
            delete_post_meta( $product_id, '_elementor_data' );
            delete_post_meta( $product_id, '_elementor_edit_mode' );
            delete_post_meta( $product_id, '_elementor_css' );
            if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        }

        // Clear object + transient caches, then purge page caches for common plugins
        clean_post_cache( $product_id );
        wc_delete_product_transients( $product_id );
        do_action( 'litespeed_purge_post', $product_id );
        if ( function_exists( 'rocket_clean_post' ) )                 rocket_clean_post( $product_id );
        if ( function_exists( 'w3tc_flush_post' ) )                   w3tc_flush_post( $product_id );
        if ( function_exists( 'wp_cache_post_edit' ) )                wp_cache_post_edit( $product_id );
        if ( function_exists( 'wpfc_clear_post_cache_by_post_id' ) )  wpfc_clear_post_cache_by_post_id( $product_id );

        // Increment usage after successful save
        $new_count = WOOLENS_Rate_Limiter::increment( $uid );

        wp_send_json_success( [
            'title'             => $result['title'],
            'description'       => $result['description'],
            'short_description' => $result['short_description'] ?? '',
            'used'              => $new_count,
        ] );
    }
}
