<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Deactivation {

    public static function init(): void {
        add_action( 'admin_footer-plugins.php', [ __CLASS__, 'render_popup' ] );
        add_action( 'wp_ajax_woolens_deactivation_feedback', [ __CLASS__, 'ajax_feedback' ] );
    }

    public static function render_popup(): void {
        if ( ! current_user_can( 'deactivate_plugins' ) ) return;
        ?>
        <style>
        #wl-deact-backdrop {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.5); z-index:999999;
            align-items:center; justify-content:center;
        }
        #wl-deact-backdrop.open { display:flex; }
        #wl-deact-box {
            background:#fff; border-radius:6px; width:420px; max-width:95vw;
            box-shadow:0 8px 32px rgba(0,0,0,.2); overflow:hidden;
        }
        #wl-deact-head {
            background:#f6f7f7; border-bottom:1px solid #c3c4c7;
            padding:13px 16px; font-size:14px; font-weight:600; color:#1d2327;
        }
        #wl-deact-body { padding:16px; }
        #wl-deact-body p { font-size:13px; color:#646970; margin:0 0 14px; }
        .wl-deact-reason {
            display:flex; align-items:flex-start; gap:9px;
            padding:8px 0; border-bottom:1px solid #f0f0f1; cursor:pointer;
        }
        .wl-deact-reason:last-of-type { border-bottom:none; }
        .wl-deact-reason input { margin-top:2px; flex-shrink:0; cursor:pointer; }
        .wl-deact-reason label { font-size:13px; color:#1d2327; cursor:pointer; }
        #wl-deact-other-wrap { display:none; margin-top:10px; }
        #wl-deact-other {
            width:100%; border:1px solid #8c8f94; border-radius:4px;
            padding:6px 8px; font-size:13px; resize:vertical;
        }
        #wl-deact-foot {
            padding:12px 16px; border-top:1px solid #c3c4c7;
            display:flex; gap:8px; justify-content:flex-end; align-items:center;
        }
        #wl-deact-skip { font-size:12px; color:#646970; text-decoration:none; margin-right:auto; }
        #wl-deact-skip:hover { color:#d63638; }
        </style>

        <div id="wl-deact-backdrop">
            <div id="wl-deact-box">
                <div id="wl-deact-head">Quick feedback before you go</div>
                <div id="wl-deact-body">
                    <p>Could you tell us why you're deactivating? This helps us improve.</p>

                    <?php
                    $reasons = [
                        'no_longer_needed'  => 'No longer needed',
                        'better_plugin'     => 'Found a better plugin',
                        'not_working'       => 'Plugin is not working',
                        'missing_feature'   => 'Missing a feature I need',
                        'too_expensive'     => 'Too expensive',
                        'temporary'         => 'Temporary deactivation',
                        'other'             => 'Other',
                    ];
                    foreach ( $reasons as $val => $label ):
                    ?>
                    <div class="wl-deact-reason">
                        <input type="radio" name="wl_reason" id="wl-reason-<?php echo esc_attr($val); ?>"
                               value="<?php echo esc_attr($val); ?>">
                        <label for="wl-reason-<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></label>
                    </div>
                    <?php endforeach; ?>

                    <div id="wl-deact-other-wrap">
                        <textarea id="wl-deact-other" rows="2" placeholder="Please tell us more..."></textarea>
                    </div>
                </div>
                <div id="wl-deact-foot">
                    <a href="#" id="wl-deact-skip">Skip &amp; Deactivate</a>
                    <button type="button" id="wl-deact-cancel" class="button">Cancel</button>
                    <button type="button" id="wl-deact-submit" class="button button-primary">Submit &amp; Deactivate</button>
                </div>
            </div>
        </div>

        <script>
        (function($){
            var deactUrl = '';

            // Intercept WooLens AI deactivate link
            $(document).on('click', 'tr[data-slug="woolens-ai"] .deactivate a, tr[data-plugin="woolens-ai/woolens-ai.php"] .deactivate a', function(e){
                e.preventDefault();
                deactUrl = $(this).attr('href');
                $('#wl-deact-backdrop').addClass('open');
            });

            // Show/hide other textarea
            $('input[name="wl_reason"]').on('change', function(){
                if ($(this).val() === 'other') {
                    $('#wl-deact-other-wrap').show();
                    $('#wl-deact-other').focus();
                } else {
                    $('#wl-deact-other-wrap').hide();
                }
            });

            // Cancel
            $('#wl-deact-cancel').on('click', function(){
                $('#wl-deact-backdrop').removeClass('open');
            });

            // Skip — just deactivate without sending feedback
            $('#wl-deact-skip').on('click', function(e){
                e.preventDefault();
                $('#wl-deact-backdrop').removeClass('open');
                window.location.href = deactUrl;
            });

            // Submit feedback then deactivate
            $('#wl-deact-submit').on('click', function(){
                var reason = $('input[name="wl_reason"]:checked').val() || '';
                var other  = $('#wl-deact-other').val().trim();
                var url    = deactUrl;

                $(this).prop('disabled', true).text('Sending…');

                $.post(ajaxurl, {
                    action:  'woolens_deactivation_feedback',
                    nonce:   <?php echo wp_json_encode( wp_create_nonce( 'woolens_deact_nonce' ) ); ?>,
                    reason:  reason,
                    details: other,
                }, function(){
                    window.location.href = url;
                }).fail(function(){
                    window.location.href = url;
                });
            });

            // Click outside to cancel
            $('#wl-deact-backdrop').on('click', function(e){
                if ($(e.target).is('#wl-deact-backdrop')) {
                    $(this).removeClass('open');
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_feedback(): void {
        check_ajax_referer( 'woolens_deact_nonce', 'nonce' );

        $reason     = sanitize_text_field( $_POST['reason']  ?? '' );
        $details    = sanitize_text_field( $_POST['details'] ?? '' );
        $site       = home_url();
        $version    = WOOLENS_VERSION;
        $user       = wp_get_current_user();
        $user_name  = $user->display_name ?? '';
        $user_email = $user->user_email   ?? '';

        wp_remote_post( rtrim( WOOLENS_SERVER_URL, '/' ) . '/api/plugin/feedback', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( compact( 'reason', 'details', 'site', 'version', 'user_name', 'user_email' ) ),
        ] );

        wp_send_json_success();
    }
}
