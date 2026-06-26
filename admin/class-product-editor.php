<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Product_Editor {

    public static function init(): void {
        add_action( 'admin_enqueue_scripts',    [ __CLASS__, 'enqueue' ] );
        add_action( 'admin_footer',             [ __CLASS__, 'inject_buttons' ] );
        add_action( 'wp_ajax_woolens_generate', [ __CLASS__, 'ajax_generate' ] );
    }

    /* ── Enqueue ──────────────────────────────────────────────────── */
    public static function enqueue( string $hook ): void {
        global $post;
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;
        if ( ! isset( $post ) || get_post_type( $post ) !== 'product' ) return;

        $uid    = get_current_user_id();
        $is_pro = WOOLENS_Settings_Page::is_pro();
        $status = WOOLENS_Rate_Limiter::status( $uid );
        if ( $is_pro ) {
            $status['is_pro']    = true;
            $status['can_gen']   = true;
            $status['remaining'] = 9999;
        }

        wp_localize_script( 'jquery', 'WOOLENS', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'woolens_nonce' ),
            'status'   => $status,
            'limit'    => WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT,
            'model'    => WOOLENS_FREE_MODEL,
            'i18n'     => [
                'generating'       => 'Analyzing image…',
                'limit_hit'        => 'Daily limit reached. Upgrade to Pro for unlimited generations.',
                'no_image'         => 'Please set a featured image first — WooLens AI reads the image to write content.',
                'success_title'    => 'Title generated!',
                'success_desc'     => 'Description generated!',
                'success_short'    => 'Short description generated!',
            ],
        ] );
    }

    /* ── Inject buttons ───────────────────────────────────────────── */
    public static function inject_buttons(): void {
        global $post;
        if ( ! isset( $post ) || get_post_type( $post ) !== 'product' ) return;

        $uid    = get_current_user_id();
        $is_pro = WOOLENS_Settings_Page::is_pro();
        $status = WOOLENS_Rate_Limiter::status( $uid );
        $used   = (int) $status['used'];
        $limit  = WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT;
        $pct    = $is_pro ? 0 : min( 100, $used ? round( $used / $limit * 100 ) : 0 );
        $bar_color = $pct >= 100 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#2271b1' );

        // Expiry calculation
        $expiry_banner = '';
        if ( $is_pro ) {
            $expires   = get_option( 'woolens_auth_plan_expires', '' );
            $renew_url = esc_url( rtrim( WOOLENS_SERVER_URL, '/' ) . '/buy-pro' );
            if ( ! empty( $expires ) ) {
                $diff      = strtotime( $expires ) - time();
                $days_left = (int) ceil( $diff / DAY_IN_SECONDS );
                if ( $diff <= 0 ) {
                    $expiry_banner = '<div style="margin-top:6px;padding:7px 12px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;font-size:12px;color:#d63638;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;">'
                        . '<span>Pro plan expired — you are now on Free plan (10/day limit)</span>'
                        . '<a href="' . $renew_url . '" target="_blank" style="background:#d63638;color:#fff;padding:3px 10px;border-radius:3px;text-decoration:none;font-size:11px;white-space:nowrap;">Renew Pro</a>'
                        . '</div>';
                } elseif ( $days_left <= 3 ) {
                    $days_text = $days_left == 1 ? 'tomorrow' : 'in ' . $days_left . ' days';
                    $clr       = $days_left == 1 ? '#d63638' : '#996800';
                    $bg        = $days_left == 1 ? '#fcf0f1' : '#fffbeb';
                    $bd        = $days_left == 1 ? '#d63638' : '#f0c33c';
                    $expiry_banner = '<div style="margin-top:6px;padding:7px 12px;background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:4px;font-size:12px;color:' . $clr . ';font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;">'
                        . '<span>Pro expires ' . $days_text . '!</span>'
                        . '<a href="' . $renew_url . '" target="_blank" style="background:#2271b1;color:#fff;padding:3px 10px;border-radius:3px;text-decoration:none;font-size:11px;white-space:nowrap;">Renew Pro</a>'
                        . '</div>';
                }
            }
        }
        ?>
        <style>
        .wl-btn { display:inline-flex !important; align-items:center; gap:5px; font-size:12px !important; white-space:nowrap; }
        .wl-btn:disabled { cursor:not-allowed !important; opacity:.6; animation:wl-pulse 1.2s ease-in-out infinite; }
        @keyframes wl-pulse { 0%,100%{opacity:1} 50%{opacity:.45} }
        #wl-title-wrap { display:flex; align-items:center; gap:8px; margin:6px 0 2px; }
        #wl-desc-wrap { margin-top:10px; }
        .wl-status { display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap; }
        .wl-brand { color:#50575e; font-size:11px; font-weight:600; }
        .wl-model-tag { background:#f6f7f7; color:#50575e; font-size:10px; padding:2px 8px; border-radius:3px; font-family:monospace; border:1px solid #c3c4c7; }
        .wl-usage-txt { font-size:11px; color:#646970; }
        .wl-bar { width:120px; height:5px; background:#dcdcde; border-radius:3px; overflow:hidden; }
        .wl-bar-fill { height:100%; border-radius:3px; transition:width .4s; }
        .wl-note { display:none; margin-top:6px; padding:6px 12px; border-radius:4px; font-size:12px; line-height:1.5; }
        .wl-note.error   { background:#fcf0f1; color:#d63638; border-left:3px solid #d63638; }
        .wl-note.success { background:#edfaef; color:#1d7e2f; border-left:3px solid #1d7e2f; }
        </style>

        <script>
        jQuery(function($){

            /* ── Inject generate button ──────────────────────────── */
            $('#titlewrap').after(
                '<div id="wl-title-wrap">' +
                '<button type="button" id="wl-gen-both" class="button button-primary wl-btn">Generate title and description</button>' +
                '<div class="wl-note" id="wl-title-note"></div>' +
                '</div>'
            );

            /* ── Inject status bar below description ─────────────── */
            var isPro    = <?php echo $is_pro ? 'true' : 'false'; ?>;
            var used     = <?php echo (int) $used; ?>;
            var limit    = <?php echo (int) WOOLENS_Rate_Limiter::daily_limit(); ?>;
            var pct      = <?php echo (int) $pct; ?>;
            var barColor = <?php echo json_encode( $bar_color ); ?>;
            var model    = <?php echo json_encode( esc_js( WOOLENS_FREE_MODEL ) ); ?>;

            var statusHtml =
                '<div class="wl-status">' +
                '<span class="wl-brand">WooLens AI</span>' +
                '<span class="wl-model-tag">' + model + '</span>';

            if (!isPro) {
                statusHtml +=
                    '<span class="wl-usage-txt" id="wl-usage-txt">' + used + ' / ' + limit + ' today</span>' +
                    '<div class="wl-bar"><div class="wl-bar-fill" id="wl-bar" style="width:' + pct + '%;background:' + barColor + '"></div></div>';
            } else {
                statusHtml += '<span class="wl-usage-txt" style="color:#1d7e2f">Pro — unlimited</span>';
            }
            statusHtml += '</div>';

            $('#postdivrich, #postdiv').after(
                '<div id="wl-desc-wrap">' +
                statusHtml +
                <?php echo wp_json_encode( $expiry_banner ); ?> +
                '<div class="wl-note" id="wl-desc-note"></div>' +
                '</div>'
            );

            /* ── Helpers ─────────────────────────────────────────── */
            function note($el, msg, type) {
                $el.removeClass('error success').addClass(type).html(msg).show();
                if (type === 'success') setTimeout(function(){ $el.fadeOut(600); }, 3000);
            }

            function setBusy(busy) {
                $('#wl-gen-both').prop('disabled', busy).text(busy ? 'Analyzing image…' : 'Generate title and description');
            }

            function updateBar(newUsed) {
                var p = Math.min(100, Math.round((newUsed / limit) * 100));
                var c = p >= 100 ? '#d63638' : (p >= 70 ? '#dba617' : '#2271b1');
                $('#wl-bar').css({width: p + '%', background: c});
                $('#wl-usage-txt').text(newUsed + ' / ' + limit + ' today');
            }

            /* ── Generate ────────────────────────────────────────── */
            function doGenerate() {
                var wl = window.WOOLENS;

                // Limit check
                if (!wl.status.can_gen) {
                    note($('#wl-title-note'), wl.i18n.limit_hit, 'error');
                    return;
                }

                // Image check
                var thumbId = $('#_thumbnail_id').val();
                if (!thumbId || thumbId === '-1') {
                    note($('#wl-title-note'), wl.i18n.no_image, 'error');
                    return;
                }

                setBusy(true);
                $('#wl-title-note, #wl-desc-note').hide();

                $.ajax({
                    url:    wl.ajax_url,
                    method: 'POST',
                    data: {
                        action:       'woolens_generate',
                        nonce:        wl.nonce,
                        mode:         'both',
                        product_id:   $('#post_ID').val(),
                        thumbnail_id: thumbId,
                    },
                    success: function(res) {
                        setBusy(false);
                        if (!res.success) {
                            note($('#wl-title-note'), res.data.message || 'Error. Please try again.', 'error');
                            return;
                        }
                        var d = res.data;
                        // Fill title
                        if (d.title) {
                            $('#title').val(d.title).trigger('input');
                            note($('#wl-title-note'), wl.i18n.success_title, 'success');
                        }
                        // Fill description
                        if (d.description && d.description.length > 0) {
                            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                                var ed = tinyMCE.get('content');
                                ed.setContent(d.description);
                                ed.fire('change');
                                ed.save();
                                $('#content').val(ed.getContent());
                            } else {
                                $('#content').val(d.description);
                            }
                            note($('#wl-desc-note'), wl.i18n.success_desc, 'success');
                        } else {
                            var dbg = d._debug ? ' Debug: ' + d._debug : ' Empty description returned.';
                            note($('#wl-desc-note'), 'Description came back empty.' + dbg, 'error');
                        }
                        // Fill short description (Pro only)
                        if (d.short_description && d.short_description.length > 0) {
                            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('excerpt')) {
                                tinyMCE.get('excerpt').setContent(d.short_description);
                            } else {
                                $('#excerpt').val(d.short_description);
                            }
                            note($('#wl-desc-note'), wl.i18n.success_short, 'success');
                        }
                        // Update usage
                        wl.status.used      = d.used;
                        wl.status.remaining = d.remaining;
                        wl.status.can_gen   = isPro || d.remaining > 0;
                        if (!isPro) updateBar(d.used);
                    },
                    error: function(xhr) {
                        setBusy(false);
                        var msg = 'Request failed. Please try again.';
                        try { msg = JSON.parse(xhr.responseText).data.message; } catch(e){}
                        note($('#wl-title-note'), msg, 'error');
                    }
                });
            }

            $('#wl-gen-both').on('click', doGenerate);
        });
        </script>
        <?php
    }

    /* ── AJAX handler ─────────────────────────────────────────────── */
    public static function ajax_generate(): void {
        // Security
        check_ajax_referer( 'woolens_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $uid  = get_current_user_id();
        $mode = sanitize_key( $_POST['mode']       ?? 'both' );
        $pid  = absint(        $_POST['product_id'] ?? 0 );

        // Fresh pro check (bypass cache) — must run before rate limit
        $is_pro = WOOLENS_Settings_Page::is_pro( true );

        // Rate limit — pro users bypass, free users checked against daily limit
        if ( ! $is_pro && ! WOOLENS_Rate_Limiter::can_generate( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Daily limit reached. Upgrade to WooLens AI Pro for unlimited generations.' ], 429 );
        }

        // API key
        $api_key = WOOLENS_Settings_Page::get( 'woolens_gemini_key' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'Gemini API key not found. Go to WooLens AI → Settings and enter your key.' ], 400 );
        }
        $model  = WOOLENS_FREE_MODEL;

        // Product image — use thumbnail_id sent from editor (reflects unsaved changes too)
        $thumb_id  = absint( $_POST['thumbnail_id'] ?? 0 );
        $image_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
        if ( empty( $image_url ) ) {
            wp_send_json_error( [ 'message' => 'Product image not found. Please set a featured image first — WooLens AI reads the image to generate content.' ], 400 );
        }

        // Call Gemini
        $result = WOOLENS_AI_Client::generate(
            $api_key,
            $model,
            $image_url,
            $mode,
            [
                'language' => $is_pro ? WOOLENS_Settings_Page::get( 'woolens_language', 'English' ) : 'English',
                'tone'     => WOOLENS_Settings_Page::get( 'woolens_tone',     'Professional' ),
                'is_pro'   => $is_pro,
            ]
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        // Save generated content — WC metabox hooks bail (nonce absent in AJAX) so wp_update_post is safe
        if ( $pid ) {
            $update_args = [ 'ID' => $pid ];
            if ( ! empty( $result['title'] ) )                                $update_args['post_title']   = sanitize_text_field( $result['title'] );
            if ( ! empty( $result['description'] ) )                          $update_args['post_content'] = wp_kses_post( $result['description'] );
            if ( $is_pro && ! empty( $result['short_description'] ) )         $update_args['post_excerpt'] = wp_kses_post( $result['short_description'] );
            if ( count( $update_args ) > 1 ) {
                wp_update_post( $update_args );
            }

            // Remove Elementor's post-specific canvas data — if present it overrides post_content on frontend.
            // Products built or imported with Elementor store their content in _elementor_data meta,
            // causing the_content() to render Elementor's copy instead of post_content.
            if ( get_post_meta( $pid, '_elementor_data', true ) ) {
                delete_post_meta( $pid, '_elementor_data' );
                delete_post_meta( $pid, '_elementor_edit_mode' );
                delete_post_meta( $pid, '_elementor_css' );
                if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            }

            // Clear object + transient caches, then explicitly purge page caches for common plugins
            clean_post_cache( $pid );
            wc_delete_product_transients( $pid );
            do_action( 'litespeed_purge_post', $pid );
            if ( function_exists( 'rocket_clean_post' ) )                     rocket_clean_post( $pid );
            if ( function_exists( 'w3tc_flush_post' ) )                       w3tc_flush_post( $pid );
            if ( function_exists( 'wp_cache_post_edit' ) )                    wp_cache_post_edit( $pid );
            if ( function_exists( 'wpfc_clear_post_cache_by_post_id' ) )      wpfc_clear_post_cache_by_post_id( $pid );
        }

        // Increment usage
        $new_count = WOOLENS_Rate_Limiter::increment( $uid );
        $limit     = WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT;

        wp_send_json_success( [
            'title'             => $result['title'],
            'description'       => $result['description'],
            'short_description' => $is_pro ? ( $result['short_description'] ?? '' ) : '',
            'used'              => $new_count,
            'limit'             => $limit,
            'remaining'         => max( 0, $limit - $new_count ),
        ] );
    }
}
