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
            'is_pro'   => $is_pro,
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

        // Detect SEO plugins
        $has_yoast    = defined( 'WPSEO_VERSION' );
        $has_rankmath = defined( 'RANK_MATH_VERSION' );
        $has_seo      = $has_yoast || $has_rankmath;

        // Expiry banner
        $expiry_banner = '';
        $expires       = get_option( 'woolens_auth_plan_expires', '' );
        $renew_url     = esc_url( rtrim( WOOLENS_SERVER_URL, '/' ) . '/buy-pro' );
        if ( ! empty( $expires ) ) {
            $diff      = strtotime( $expires ) - time();
            $days_left = (int) ceil( $diff / DAY_IN_SECONDS );
            if ( $diff <= 0 && ! $is_pro ) {
                $expiry_banner = '<div style="margin-top:6px;padding:7px 12px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;font-size:12px;color:#d63638;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;">'
                    . '<span>Pro plan expired — you are now on Free plan (10/day limit)</span>'
                    . '<a href="' . $renew_url . '" target="_blank" style="background:#d63638;color:#fff;padding:3px 10px;border-radius:3px;text-decoration:none;font-size:11px;white-space:nowrap;">Renew Pro</a>'
                    . '</div>';
            } elseif ( $is_pro && $diff > 0 && $days_left <= 3 ) {
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

        /* ── Generate Popup ── */
        #wl-popup-backdrop {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.5); z-index:999999;
            align-items:center; justify-content:center;
        }
        #wl-popup-backdrop.open { display:flex; }
        #wl-popup {
            background:#fff; border-radius:6px; width:380px; max-width:95vw;
            box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden;
        }
        #wl-popup-head {
            background:#f6f7f7; border-bottom:1px solid #c3c4c7;
            padding:12px 16px; font-size:14px; font-weight:600; color:#1d2327;
            display:flex; align-items:center; justify-content:space-between;
        }
        #wl-popup-body { padding:16px; }
        .wl-popup-item {
            display:flex; align-items:flex-start; gap:10px;
            padding:9px 0; border-bottom:1px solid #f0f0f1;
        }
        .wl-popup-item:last-child { border-bottom:none; }
        .wl-popup-item input[type=checkbox] { margin-top:2px; width:15px; height:15px; flex-shrink:0; cursor:pointer; }
        .wl-popup-item label { font-size:13px; color:#1d2327; cursor:pointer; line-height:1.4; }
        .wl-popup-item label small { display:block; font-size:11px; color:#646970; margin-top:1px; }
        .wl-popup-pro-badge { background:#fff8e5; color:#996800; font-size:10px; font-weight:700; padding:1px 6px; border-radius:3px; border:1px solid #f0c33c; margin-left:6px; vertical-align:middle; }
        #wl-popup-foot {
            padding:12px 16px; border-top:1px solid #c3c4c7;
            display:flex; gap:8px; justify-content:flex-end;
        }

        /* ── WhatsApp copy area ── */
        #wl-wa-area { display:none; margin-top:10px; }
        #wl-wa-text {
            width:100%; min-height:120px; font-size:12px; border:1px solid #c3c4c7;
            border-radius:4px; padding:8px 10px; resize:vertical; font-family:inherit;
            background:#f6f7f7; color:#1d2327;
        }
        #wl-wa-actions { display:flex; align-items:center; gap:8px; margin-top:6px; flex-wrap:wrap; }
        .wl-wa-check { display:flex; align-items:center; gap:5px; font-size:12px; color:#646970; }
        </style>

        <!-- Generate Popup -->
        <div id="wl-popup-backdrop">
            <div id="wl-popup">
                <div id="wl-popup-head">
                    <span>What do you want to generate?</span>
                    <button type="button" id="wl-popup-close" style="background:none;border:none;cursor:pointer;font-size:18px;color:#646970;line-height:1;padding:0;">&times;</button>
                </div>
                <div id="wl-popup-body">
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wl-chk-title" checked>
                        <label for="wl-chk-title">Title <small>Short product title under 60 characters</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wl-chk-desc" checked>
                        <label for="wl-chk-desc">Description <small>Full product description</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wl-chk-short" checked>
                        <label for="wl-chk-short">Short Description <small>1-2 sentence summary</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wl-chk-tags" checked>
                        <label for="wl-chk-tags">Tags <small>Relevant product keywords</small></label>
                    </div>
                    <?php if ( $is_pro ): ?>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wl-chk-seo" <?php echo $has_seo ? 'checked' : ''; ?>>
                        <label for="wl-chk-seo">
                            SEO Meta <span class="wl-popup-pro-badge">PRO</span>
                            <small>
                                <?php if ( $has_yoast ): ?>
                                    SEO title &amp; description for Yoast SEO
                                <?php elseif ( $has_rankmath ): ?>
                                    SEO title &amp; description for RankMath
                                <?php else: ?>
                                    SEO title &amp; description (install Yoast or RankMath to auto-save)
                                <?php endif; ?>
                            </small>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="wl-popup-foot">
                    <button type="button" id="wl-popup-cancel" class="button">Cancel</button>
                    <button type="button" id="wl-popup-generate" class="button button-primary">Generate</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($){

            /* ── Inject generate button ──────────────────────────── */
            $('#titlewrap').after(
                '<div id="wl-title-wrap">' +
                '<button type="button" id="wl-gen-both" class="button button-primary wl-btn">Generate with WooLens AI</button>' +
                '<div class="wl-note" id="wl-title-note"></div>' +
                '</div>'
            );

            /* ── Inject status bar + WhatsApp area ───────────────── */
            var isPro    = <?php echo $is_pro ? 'true' : 'false'; ?>;
            var used     = <?php echo (int) $used; ?>;
            var limit    = <?php echo (int) WOOLENS_Rate_Limiter::daily_limit(); ?>;
            var pct      = <?php echo (int) $pct; ?>;
            var barColor = <?php echo json_encode( $bar_color ); ?>;
            var model    = <?php echo json_encode( esc_js( WOOLENS_FREE_MODEL ) ); ?>;
            var hasSeo   = <?php echo $has_seo ? 'true' : 'false'; ?>;
            var hasYoast = <?php echo $has_yoast ? 'true' : 'false'; ?>;

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

            var waArea = isPro ?
                '<div id="wl-wa-area">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
                '<span style="font-size:12px;font-weight:600;color:#1d2327;">WhatsApp Format</span>' +
                '</div>' +
                '<div id="wl-wa-actions">' +
                '<label class="wl-wa-check"><input type="checkbox" id="wl-wa-price" checked> Include price</label>' +
                '<button type="button" id="wl-wa-copy" class="button button-small">Copy for WhatsApp</button>' +
                '<span id="wl-wa-copied" style="display:none;font-size:11px;color:#1d7e2f;font-weight:600;">Copied!</span>' +
                '</div>' +
                '<textarea id="wl-wa-text" readonly></textarea>' +
                '</div>' : '';

            $('#postdivrich, #postdiv').after(
                '<div id="wl-desc-wrap">' +
                statusHtml +
                <?php echo wp_json_encode( $expiry_banner ); ?> +
                '<div class="wl-note" id="wl-desc-note"></div>' +
                waArea +
                '</div>'
            );

            /* ── Popup open/close ────────────────────────────────── */
            $('#wl-gen-both').on('click', function(){
                var wl = window.WOOLENS;
                if (!wl.status.can_gen) {
                    note($('#wl-title-note'), wl.i18n.limit_hit, 'error');
                    return;
                }
                var thumbId = $('#_thumbnail_id').val();
                if (!thumbId || thumbId === '-1') {
                    note($('#wl-title-note'), wl.i18n.no_image, 'error');
                    return;
                }
                $('#wl-popup-backdrop').addClass('open');
            });

            $('#wl-popup-close, #wl-popup-cancel').on('click', function(){
                $('#wl-popup-backdrop').removeClass('open');
            });

            $('#wl-popup-backdrop').on('click', function(e){
                if ($(e.target).is('#wl-popup-backdrop')) {
                    $(this).removeClass('open');
                }
            });

            /* ── Helpers ─────────────────────────────────────────── */
            function note($el, msg, type) {
                $el.removeClass('error success').addClass(type).html(msg).show();
                if (type === 'success') setTimeout(function(){ $el.fadeOut(600); }, 3000);
            }

            function setBusy(busy) {
                $('#wl-gen-both').prop('disabled', busy).text(busy ? 'Analyzing image…' : 'Generate with WooLens AI');
                $('#wl-popup-generate').prop('disabled', busy).text(busy ? 'Generating…' : 'Generate');
            }

            function updateBar(newUsed) {
                var p = Math.min(100, Math.round((newUsed / limit) * 100));
                var c = p >= 100 ? '#d63638' : (p >= 70 ? '#dba617' : '#2271b1');
                $('#wl-bar').css({width: p + '%', background: c});
                $('#wl-usage-txt').text(newUsed + ' / ' + limit + ' today');
            }

            /* ── Build WhatsApp text ─────────────────────────────── */
            function buildWhatsApp(data) {
                var title    = $('#title').val() || '';
                var shortDesc = data.short_description || '';
                var desc      = data.description || '';
                var inclPrice = $('#wl-wa-price').is(':checked');

                // Strip HTML tags from description
                var tmp = document.createElement('div');
                tmp.innerHTML = desc;
                var plainDesc = tmp.textContent || tmp.innerText || '';

                // Use short desc if available, else first 150 chars of desc
                var body = shortDesc ? shortDesc : plainDesc.substring(0, 150).trim();

                // Strip HTML from body too
                var tmp2 = document.createElement('div');
                tmp2.innerHTML = body;
                body = tmp2.textContent || tmp2.innerText || body;

                var tags = data.tags ? data.tags.split(',').map(function(t){ return t.trim(); }).filter(Boolean) : [];

                var text = '*' + title + '*\n\n' + body;

                if (inclPrice) {
                    var regPrice  = jQuery('#_regular_price').val();
                    var salePrice = jQuery('#_sale_price').val();
                    if (salePrice && parseFloat(salePrice) > 0) {
                        text += '\n\nPrice: ' + salePrice + ' (was ' + regPrice + ')';
                    } else if (regPrice && parseFloat(regPrice) > 0) {
                        text += '\n\nPrice: ' + regPrice;
                    }
                }

                if (tags.length > 0) {
                    text += '\n\n' + tags.map(function(t){ return '#' + t.replace(/\s+/g,''); }).join(' ');
                }

                return text;
            }

            /* ── Generate ────────────────────────────────────────── */
            $('#wl-popup-generate').on('click', function(){
                var wl = window.WOOLENS;

                var wantTitle = $('#wl-chk-title').is(':checked');
                var wantDesc  = $('#wl-chk-desc').is(':checked');
                var wantShort = $('#wl-chk-short').is(':checked');
                var wantTags  = $('#wl-chk-tags').is(':checked');
                var wantSeo   = $('#wl-chk-seo').is(':checked');

                if (!wantTitle && !wantDesc && !wantShort && !wantTags && !wantSeo) {
                    alert('Please select at least one option.');
                    return;
                }

                $('#wl-popup-backdrop').removeClass('open');
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
                        thumbnail_id: $('#_thumbnail_id').val(),
                        want_tags:    wantTags  ? 1 : 0,
                        want_seo:     wantSeo   ? 1 : 0,
                    },
                    success: function(res) {
                        setBusy(false);
                        if (!res.success) {
                            note($('#wl-title-note'), res.data.message || 'Error. Please try again.', 'error');
                            return;
                        }
                        var d = res.data;

                        // Title
                        if (wantTitle && d.title) {
                            $('#title').val(d.title).trigger('input');
                            note($('#wl-title-note'), wl.i18n.success_title, 'success');
                        }

                        // Description
                        if (wantDesc && d.description && d.description.length > 0) {
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
                        } else if (wantDesc) {
                            var dbg = d._debug ? ' Debug: ' + d._debug : ' Empty description returned.';
                            note($('#wl-desc-note'), 'Description came back empty.' + dbg, 'error');
                        }

                        // Short description
                        if (wantShort && d.short_description && d.short_description.length > 0) {
                            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('excerpt')) {
                                tinyMCE.get('excerpt').setContent(d.short_description);
                            } else {
                                $('#excerpt').val(d.short_description);
                            }
                        }

                        // Tags
                        if (wantTags && d.tags) {
                            // WooCommerce tags input (tagsdiv-product_tag)
                            var $tagInput = $('#new-tag-product_tag');
                            var $tagList  = $('.tagchecklist[data-wp_taxonomy="product_tag"]');
                            if ($tagInput.length) {
                                // Clear existing tags first via the WP tag UI
                                $('.tagchecklist[data-wp_taxonomy="product_tag"] .ntdelbutton').each(function(){ $(this).click(); });
                                // Add new tags
                                var tags = d.tags.split(',');
                                tags.forEach(function(tag){
                                    tag = tag.trim();
                                    if (!tag) return;
                                    $tagInput.val(tag);
                                    $('#tagadd-product_tag').click();
                                });
                            }
                        }

                        // SEO Meta
                        if (wantSeo && d.seo_title) {
                            if (hasYoast) {
                                $('#yoast_wpseo_title').val(d.seo_title).trigger('input');
                                $('#yoast_wpseo_metadesc').val(d.seo_description).trigger('input');
                            } else if (window.rankMathEditor) {
                                // RankMath uses a JS API
                                wp.data && wp.data.dispatch('rank-math') &&
                                    wp.data.dispatch('rank-math').updateMeta('title', d.seo_title);
                                wp.data && wp.data.dispatch('rank-math') &&
                                    wp.data.dispatch('rank-math').updateMeta('description', d.seo_description);
                            }
                            if (!hasSeo) {
                                // Show copy area if no SEO plugin
                                note($('#wl-desc-note'),
                                    'SEO Meta generated! ' +
                                    '<strong>SEO Title:</strong> ' + d.seo_title + '<br>' +
                                    '<strong>SEO Desc:</strong> ' + d.seo_description +
                                    '<br><small>Install Yoast or RankMath to auto-save these.</small>',
                                    'success'
                                );
                            }
                        }

                        // WhatsApp area
                        if (isPro) {
                            var waText = buildWhatsApp(d);
                            $('#wl-wa-text').val(waText);
                            $('#wl-wa-area').show();
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
            });

            /* ── WhatsApp copy button ────────────────────────────── */
            if (isPro) {
                $(document).on('click', '#wl-wa-copy', function(){
                    var text = $('#wl-wa-text').val();
                    if (!text) return;
                    navigator.clipboard ? navigator.clipboard.writeText(text).then(function(){
                        $('#wl-wa-copied').show();
                        setTimeout(function(){ $('#wl-wa-copied').hide(); }, 2000);
                    }) : (function(){
                        var ta = document.getElementById('wl-wa-text');
                        ta.select(); document.execCommand('copy');
                        $('#wl-wa-copied').show();
                        setTimeout(function(){ $('#wl-wa-copied').hide(); }, 2000);
                    })();
                });

                $(document).on('change', '#wl-wa-price', function(){
                    var current = $('#wl-wa-text').val();
                    if (!current) return;
                    // Rebuild with current data
                    var fakeData = {
                        short_description: $('#excerpt').val() || '',
                        description: (typeof tinyMCE !== 'undefined' && tinyMCE.get('content'))
                            ? tinyMCE.get('content').getContent() : $('#content').val(),
                        tags: $('#tax-input-product_tag').val() || '',
                    };
                    $('#wl-wa-text').val(buildWhatsApp(fakeData));
                });
            }

        });
        </script>
        <?php
    }

    /* ── AJAX handler ─────────────────────────────────────────────── */
    public static function ajax_generate(): void {
        check_ajax_referer( 'woolens_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $uid       = get_current_user_id();
        $mode      = sanitize_key( $_POST['mode']       ?? 'both' );
        $pid       = absint(        $_POST['product_id'] ?? 0 );
        $want_tags = ! empty( $_POST['want_tags'] );
        $want_seo  = ! empty( $_POST['want_seo'] );

        $is_pro = WOOLENS_Settings_Page::is_pro( true );

        if ( ! $is_pro && ! WOOLENS_Rate_Limiter::can_generate( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Daily limit reached. Upgrade to WooLens AI Pro for unlimited generations.' ], 429 );
        }

        $api_key = WOOLENS_Settings_Page::get( 'woolens_gemini_key' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'Gemini API key not found. Go to WooLens AI → Settings and enter your key.' ], 400 );
        }
        $model = WOOLENS_FREE_MODEL;

        $thumb_id  = absint( $_POST['thumbnail_id'] ?? 0 );
        $image_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
        if ( empty( $image_url ) ) {
            wp_send_json_error( [ 'message' => 'Product image not found. Please set a featured image first.' ], 400 );
        }

        $result = WOOLENS_AI_Client::generate(
            $api_key,
            $model,
            $image_url,
            $mode,
            [
                'language' => $is_pro ? WOOLENS_Settings_Page::get( 'woolens_language', 'English' ) : 'English',
                'tone'     => WOOLENS_Settings_Page::get( 'woolens_tone', 'Professional' ),
                'is_pro'   => $is_pro,
                'want_tags' => $want_tags,
                'want_seo'  => $want_seo && $is_pro,
            ]
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        // Save to post
        if ( $pid ) {
            $update_args = [ 'ID' => $pid ];
            if ( ! empty( $result['title'] ) )       $update_args['post_title']   = sanitize_text_field( $result['title'] );
            if ( ! empty( $result['description'] ) )  $update_args['post_content'] = wp_kses_post( $result['description'] );
            if ( $is_pro && ! empty( $result['short_description'] ) ) $update_args['post_excerpt'] = wp_kses_post( $result['short_description'] );
            if ( count( $update_args ) > 1 ) wp_update_post( $update_args );

            // Save tags
            if ( $want_tags && ! empty( $result['tags'] ) ) {
                $tags = array_map( 'trim', explode( ',', $result['tags'] ) );
                $tags = array_filter( $tags );
                wp_set_post_terms( $pid, $tags, 'product_tag', false );
            }

            // Save SEO meta
            if ( $want_seo && $is_pro ) {
                if ( ! empty( $result['seo_title'] ) ) {
                    if ( defined( 'WPSEO_VERSION' ) ) {
                        update_post_meta( $pid, '_yoast_wpseo_title',    sanitize_text_field( $result['seo_title'] ) );
                        update_post_meta( $pid, '_yoast_wpseo_metadesc', sanitize_text_field( $result['seo_description'] ?? '' ) );
                    } elseif ( defined( 'RANK_MATH_VERSION' ) ) {
                        update_post_meta( $pid, 'rank_math_title',       sanitize_text_field( $result['seo_title'] ) );
                        update_post_meta( $pid, 'rank_math_description',  sanitize_text_field( $result['seo_description'] ?? '' ) );
                    }
                    // Always save to our own meta as fallback
                    update_post_meta( $pid, '_woolens_seo_title', sanitize_text_field( $result['seo_title'] ) );
                    update_post_meta( $pid, '_woolens_seo_desc',  sanitize_text_field( $result['seo_description'] ?? '' ) );
                }
            }

            // Elementor cleanup
            if ( get_post_meta( $pid, '_elementor_data', true ) ) {
                delete_post_meta( $pid, '_elementor_data' );
                delete_post_meta( $pid, '_elementor_edit_mode' );
                delete_post_meta( $pid, '_elementor_css' );
                if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            }

            // Cache clear
            clean_post_cache( $pid );
            wc_delete_product_transients( $pid );
            do_action( 'litespeed_purge_post', $pid );
            if ( function_exists( 'rocket_clean_post' ) )                rocket_clean_post( $pid );
            if ( function_exists( 'w3tc_flush_post' ) )                  w3tc_flush_post( $pid );
            if ( function_exists( 'wp_cache_post_edit' ) )               wp_cache_post_edit( $pid );
            if ( function_exists( 'wpfc_clear_post_cache_by_post_id' ) ) wpfc_clear_post_cache_by_post_id( $pid );
        }

        $new_count = WOOLENS_Rate_Limiter::increment( $uid );
        $limit     = WOOLENS_Rate_Limiter::FREE_DAILY_LIMIT;

        wp_send_json_success( [
            'title'             => $result['title'],
            'description'       => $result['description'],
            'short_description' => $is_pro ? ( $result['short_description'] ?? '' ) : '',
            'tags'              => $want_tags ? ( $result['tags'] ?? '' ) : '',
            'seo_title'         => ( $want_seo && $is_pro ) ? ( $result['seo_title'] ?? '' ) : '',
            'seo_description'   => ( $want_seo && $is_pro ) ? ( $result['seo_description'] ?? '' ) : '',
            'used'              => $new_count,
            'limit'             => $limit,
            'remaining'         => max( 0, $limit - $new_count ),
        ] );
    }
}
