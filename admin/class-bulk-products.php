<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Bulk_Products {

    const MAX_PRODUCTS = 30;

    public static function init(): void {
        add_action( 'admin_menu',                 [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_woolens_bp_process', [ __CLASS__, 'ajax_process_product' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'woolens-ai',
            'Bulk Products — WooLens AI',
            'Bulk Products',
            'edit_products',
            'woolens-bulk-products',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Unauthorized.' );

        $cat_id = isset( $_GET['cat_id'] ) ? absint( $_GET['cat_id'] ) : 0;

        if ( $cat_id ) {
            $cat = get_term( $cat_id, 'product_cat' );
            if ( ! $cat || is_wp_error( $cat ) ) wp_die( 'Invalid category.' );
            self::render_builder( $cat_id, $cat );
        } else {
            self::render_category_grid();
        }
    }

    /* ── Category grid ────────────────────────────────────────────── */
    private static function render_category_grid(): void {
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );
        ?>
        <div class="wrap">
        <h1>Bulk Products</h1>
        <p class="description" style="margin-bottom:20px">
            Choose a category, upload images, set prices — WooLens AI writes titles &amp; descriptions and creates products automatically. Max <?php echo self::MAX_PRODUCTS; ?> products per run.
        </p>

        <style>
        .wlbp-cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; max-width:860px; }
        .wlbp-cat-card { background:#fff; border:1px solid #c3c4c7; border-radius:6px; color:inherit; transition:border-color .15s,box-shadow .15s; display:block; padding:14px 16px; }
        .wlbp-cat-card:hover { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
        .wlbp-cat-name { font-size:13px; font-weight:600; color:#1d2327; margin-bottom:4px; }
        .wlbp-cat-count { font-size:11px; color:#646970; margin-bottom:10px; }
        .wlbp-cat-actions { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
        .wlbp-cat-btn-add { font-size:11px; font-weight:600; color:#2271b1; text-decoration:none; padding:3px 8px; border:1px solid #2271b1; border-radius:3px; transition:all .15s; }
        .wlbp-cat-btn-add:hover { background:#2271b1; color:#fff; }
        .wlbp-cat-btn-view { font-size:11px; font-weight:500; color:#646970; text-decoration:none; padding:3px 8px; border:1px solid #c3c4c7; border-radius:3px; transition:all .15s; }
        .wlbp-cat-btn-view:hover { border-color:#2271b1; color:#2271b1; }
        </style>

        <?php if ( empty( $categories ) || is_wp_error( $categories ) ): ?>
            <div class="notice notice-warning inline"><p>
                No product categories found.
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ); ?>">Create a category</a> first.
            </p></div>
        <?php else: ?>
        <div class="wlbp-cat-grid">
            <?php foreach ( $categories as $cat ):
                $builder_url  = admin_url( 'admin.php?page=woolens-bulk-products&cat_id=' . $cat->term_id );
                $products_url = admin_url( 'edit.php?post_type=product&product_cat=' . urlencode( $cat->slug ) );
            ?>
            <div class="wlbp-cat-card">
                <div class="wlbp-cat-name"><?php echo esc_html( $cat->name ); ?></div>
                <div class="wlbp-cat-count"><?php echo (int) $cat->count; ?> product<?php echo $cat->count !== 1 ? 's' : ''; ?></div>
                <div class="wlbp-cat-actions">
                    <a href="<?php echo esc_url( $builder_url ); ?>" class="wlbp-cat-btn-add">+ Add</a>
                    <?php if ( $cat->count > 0 ): ?>
                    <a href="<?php echo esc_url( $products_url ); ?>" class="wlbp-cat-btn-view" target="_blank">View Products</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }

    /* ── Product builder ──────────────────────────────────────────── */
    private static function render_builder( int $cat_id, WP_Term $cat ): void {
        $is_pro       = WOOLENS_Settings_Page::is_pro();
        $nonce        = wp_create_nonce( 'woolens_bp_nonce' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        $back_url     = admin_url( 'admin.php?page=woolens-bulk-products' );
        $max          = self::MAX_PRODUCTS;
        $has_yoast    = defined( 'WPSEO_VERSION' );
        $has_rankmath = defined( 'RANK_MATH_VERSION' );
        $has_seo      = $has_yoast || $has_rankmath;
        ?>
        <div class="wrap">
        <h1>
            Bulk Products
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:6px">→ <?php echo esc_html( $cat->name ); ?></span>
        </h1>

        <?php if ( ! $is_pro ): ?>
            <div class="notice notice-error inline" style="margin-top:10px"><p>
                <strong>Pro required.</strong> Bulk product creation is a WooLens AI Pro feature.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=woolens-ai' ) . '#wl-upgrade' ); ?>" class="button button-primary" style="margin-left:10px">Upgrade to Pro</a>
            </p></div>
            <p style="margin-top:10px"><a href="<?php echo esc_url( $back_url ); ?>" class="button">← Back to Categories</a></p>
        <?php else: ?>

        <style>
        .wlbp-action-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:14px 0 16px; }
        .wlbp-progress { background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; padding:13px 16px; margin-bottom:12px; }
        .wlbp-bar-outer { height:8px; background:#dcdcde; border-radius:4px; overflow:hidden; margin:7px 0 4px; }
        .wlbp-bar-inner { height:100%; background:#2271b1; border-radius:4px; width:0%; transition:width .3s; }
        .wlbp-done-box { background:#edfaef; border:1px solid #1d7e2f; border-radius:4px; padding:13px 18px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
        .wlbp-row { display:grid; grid-template-columns:24px 120px 1fr 150px 28px; gap:10px; align-items:center; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:10px 12px; margin-bottom:8px; }
        .wlbp-row-num { font-size:12px; font-weight:700; color:#646970; text-align:center; }
        .wlbp-img-box { width:110px; height:82px; border:2px dashed #c3c4c7; border-radius:4px; position:relative; overflow:hidden; cursor:pointer; transition:border-color .15s; flex-shrink:0; }
        .wlbp-img-box:hover { border-color:#2271b1; }
        .wlbp-img-box input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; z-index:1; }
        .wlbp-img-preview { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; pointer-events:none; }
        .wlbp-img-preview img { width:100%; height:100%; object-fit:cover; display:block; }
        .wlbp-img-icon { display:flex; align-items:center; justify-content:center; color:#c3c4c7; }
        .wlbp-img-hint { font-size:10px; color:#646970; margin-top:2px; }
        .wlbp-prices { display:flex; flex-direction:column; gap:7px; }
        .wlbp-price-row { display:flex; align-items:center; gap:7px; }
        .wlbp-price-row label { font-size:11px; font-weight:600; color:#646970; min-width:78px; }
        .wlbp-price-row input { width:110px; border:1px solid #8c8f94 !important; border-radius:3px !important; padding:5px 7px !important; font-size:13px !important; box-shadow:none !important; }
        .wlbp-row-status { font-size:12px; }
        .wlbp-st-pending { color:#646970; }
        .wlbp-st-running { color:#2271b1; font-weight:600; }
        .wlbp-st-done    { color:#1d7e2f; font-weight:600; }
        .wlbp-st-done a  { color:#1d7e2f; }
        .wlbp-st-error   { color:#d63638; }
        .wlbp-remove { background:none; border:none; cursor:pointer; color:#d63638; font-size:18px; padding:2px; line-height:1; }
        .wlbp-remove:hover { color:#b32d2e; }
        .wlbp-remove:disabled { color:#c3c4c7; cursor:default; }
        </style>

        <!-- Progress (hidden until started) -->
        <div class="wlbp-progress" id="wlbp-progress" style="display:none">
            <div id="wlbp-prog-summ" style="font-size:13px;font-weight:600;color:#1d2327">Processing...</div>
            <div class="wlbp-bar-outer"><div class="wlbp-bar-inner" id="wlbp-bar"></div></div>
            <div id="wlbp-prog-sub" style="font-size:11px;color:#646970"></div>
        </div>

        <!-- Done box (hidden until complete) -->
        <div class="wlbp-done-box" id="wlbp-done-box" style="display:none">
            <span id="wlbp-done-msg" style="font-size:13px;font-weight:600;color:#1d7e2f"></span>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&product_cat=' . urlencode( $cat->slug ) ) ); ?>" class="button" target="_blank">View in Products</a>
        </div>

        <!-- Generate Popup -->
        <div id="wlbp-popup-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:6px;width:380px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.18);overflow:hidden;">
                <div style="background:#f6f7f7;border-bottom:1px solid #c3c4c7;padding:12px 16px;font-size:14px;font-weight:600;color:#1d2327;display:flex;align-items:center;justify-content:space-between;">
                    <span>What do you want to generate?</span>
                    <button type="button" id="wlbp-popup-close" style="background:none;border:none;cursor:pointer;font-size:18px;color:#646970;line-height:1;padding:0;">&times;</button>
                </div>
                <div style="padding:16px;">
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-title" checked>
                        <label for="wlbp-chk-title">Title <small>Short product title under 60 characters</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-desc" checked>
                        <label for="wlbp-chk-desc">Description <small>Full product description</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-short" checked>
                        <label for="wlbp-chk-short">Short Description <small>1-2 sentence summary</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-tags" checked>
                        <label for="wlbp-chk-tags">Tags <small>Relevant product keywords</small></label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-seo" <?php echo $has_seo ? 'checked' : ''; ?>>
                        <label for="wlbp-chk-seo">
                            SEO Meta <span class="wl-popup-pro-badge">PRO</span>
                            <small>
                                <?php if ( $has_yoast ): ?>SEO title &amp; description for Yoast SEO
                                <?php elseif ( $has_rankmath ): ?>SEO title &amp; description for RankMath
                                <?php else: ?>SEO title &amp; description (install Yoast or RankMath to auto-save)
                                <?php endif; ?>
                            </small>
                        </label>
                    </div>
                    <div class="wl-popup-item">
                        <input type="checkbox" id="wlbp-chk-wa" checked>
                        <label for="wlbp-chk-wa">
                            WhatsApp Message <span class="wl-popup-pro-badge">PRO</span>
                            <small>Saved to product — open product to copy</small>
                        </label>
                    </div>
                </div>
                <div style="padding:12px 16px;border-top:1px solid #c3c4c7;display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" id="wlbp-popup-cancel" class="button">Cancel</button>
                    <button type="button" id="wlbp-popup-start" class="button button-primary">Generate &amp; Create All</button>
                </div>
            </div>
        </div>

        <!-- Action bar -->
        <div class="wlbp-action-bar">
            <button id="wlbp-add" class="button" type="button">+ Add Product</button>
            <button id="wlbp-generate" class="button button-primary button-large" type="button" disabled>
                Generate &amp; Create All
            </button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="button">← Categories</a>
            <span id="wlbp-count" style="font-size:12px;color:#646970">0 / <?php echo $max; ?> products</span>
        </div>

        <!-- Rows -->
        <div id="wlbp-rows"></div>

        <?php endif; ?>
        </div>

        <script>
        (function () {
            var MAX      = <?php echo (int) $max; ?>;
            var AJAX_URL = '<?php echo esc_js( $ajax_url ); ?>';
            var NONCE    = '<?php echo esc_js( $nonce ); ?>';
            var CAT_ID   = '<?php echo esc_js( (string) $cat_id ); ?>';

            var rows    = [];
            var running = false;

            var container = document.getElementById('wlbp-rows');
            var addBtn    = document.getElementById('wlbp-add');
            var genBtn    = document.getElementById('wlbp-generate');
            var countEl   = document.getElementById('wlbp-count');
            var progWrap  = document.getElementById('wlbp-progress');
            var progBar   = document.getElementById('wlbp-bar');
            var progSumm  = document.getElementById('wlbp-prog-summ');
            var progSub   = document.getElementById('wlbp-prog-sub');
            var doneBox   = document.getElementById('wlbp-done-box');
            var doneMsg   = document.getElementById('wlbp-done-msg');

            function updateUI() {
                countEl.textContent = rows.length + ' / ' + MAX + ' products';
                addBtn.disabled     = rows.length >= MAX || running;
                var pending         = rows.filter(function (r) { return !r.done; });
                var allHaveImages   = pending.length > 0 && pending.every(function (r) { return r.file !== null; });
                genBtn.disabled     = !allHaveImages || running;
                rows.forEach(function (r) {
                    var btn = r.el.querySelector('.wlbp-remove');
                    if (btn) btn.disabled = running;
                });
            }

            function addRow() {
                if (rows.length >= MAX || running) return;

                var rowObj = { file: null, el: null, done: false };

                var div = document.createElement('div');
                div.className = 'wlbp-row';
                div.innerHTML =
                    '<div class="wlbp-row-num">' + (rows.length + 1) + '</div>' +
                    '<div class="wlbp-img-box">' +
                        '<div class="wlbp-img-preview">' +
                            '<span class="wlbp-img-icon"><span class="dashicons dashicons-upload" style="font-size:22px"></span></span>' +
                            '<span class="wlbp-img-hint">Click to upload</span>' +
                        '</div>' +
                        '<input type="file" accept="image/jpeg,image/png,image/webp,image/gif">' +
                    '</div>' +
                    '<div class="wlbp-prices">' +
                        '<div class="wlbp-price-row"><label>Regular (Rs.)</label><input type="number" class="wlbp-reg" placeholder="0" min="0" step="1"></div>' +
                        '<div class="wlbp-price-row"><label>Sale (Rs.)</label><input type="number" class="wlbp-sale" placeholder="Optional" min="0" step="1"></div>' +
                    '</div>' +
                    '<div class="wlbp-row-status wlbp-st-pending">Waiting</div>' +
                    '<button class="wlbp-remove" type="button" title="Remove">✕</button>';

                rowObj.el = div;
                rows.push(rowObj);
                container.appendChild(div);

                // File input
                var fileInput = div.querySelector('input[type=file]');
                fileInput.addEventListener('change', function () {
                    var file = this.files[0];
                    if (!file) return;
                    if (file.size > 4 * 1024 * 1024) {
                        alert('Image too large. Maximum size is 4MB.');
                        this.value = '';
                        return;
                    }
                    var found = rows.find(function (r) { return r.el === div; });
                    if (found) found.file = file;

                    var preview = div.querySelector('.wlbp-img-preview');
                    var reader  = new FileReader();
                    reader.onload = function (ev) {
                        preview.innerHTML = '<img src="' + ev.target.result + '">';
                    };
                    reader.readAsDataURL(file);
                    updateUI();
                });

                // Remove
                div.querySelector('.wlbp-remove').addEventListener('click', function () {
                    if (running) return;
                    var idx = rows.findIndex(function (r) { return r.el === div; });
                    if (idx === -1) return;
                    div.remove();
                    rows.splice(idx, 1);
                    rows.forEach(function (r, i) {
                        r.el.querySelector('.wlbp-row-num').textContent = i + 1;
                    });
                    updateUI();
                });

                updateUI();
            }

            addBtn.addEventListener('click', addRow);

            window.addEventListener('beforeunload', function (e) {
                var pending = rows.filter(function (r) { return !r.done && r.file; });
                if (pending.length > 0) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            var wantTags = true, wantSeo = false;
            var popup    = document.getElementById('wlbp-popup-backdrop');

            genBtn.addEventListener('click', function () {
                if (running) return;
                var pending = rows.filter(function (r) { return !r.done; });
                if (pending.length === 0) return;
                popup.style.display = 'flex';
            });

            document.getElementById('wlbp-popup-close').addEventListener('click', function () { popup.style.display = 'none'; });
            document.getElementById('wlbp-popup-cancel').addEventListener('click', function () { popup.style.display = 'none'; });
            popup.addEventListener('click', function (e) { if (e.target === popup) popup.style.display = 'none'; });

            var wantWa = true;
            document.getElementById('wlbp-popup-start').addEventListener('click', function () {
                wantTags = document.getElementById('wlbp-chk-tags').checked;
                wantSeo  = document.getElementById('wlbp-chk-seo').checked;
                wantWa   = document.getElementById('wlbp-chk-wa').checked;
                popup.style.display = 'none';

                if (running) return;

                var pending = rows.filter(function (r) { return !r.done; });
                if (pending.length === 0) return;

                running = true;
                updateUI();

                progWrap.style.display = '';
                doneBox.style.display  = 'none';
                progBar.style.width    = '0%';

                var created = 0, errors = 0, total = pending.length;
                var chain = Promise.resolve();

                pending.forEach(function (row, i) {
                    chain = chain.then(function () {
                        setStatus(row.el, 'running', 'Generating...');
                        progSumm.textContent = 'Processing ' + (i + 1) + ' of ' + total;
                        progSub.textContent  = row.file ? row.file.name : '';

                        return toBase64(row.file).then(function (b64full) {
                            var b64  = b64full.split(',')[1];
                            var mime = row.file.type;
                            var name = row.file.name;
                            var reg  = row.el.querySelector('.wlbp-reg').value;
                            var sale = row.el.querySelector('.wlbp-sale').value;

                            var fd = new FormData();
                            fd.append('action',        'woolens_bp_process');
                            fd.append('nonce',         NONCE);
                            fd.append('cat_id',        CAT_ID);
                            fd.append('image_data',    b64);
                            fd.append('image_mime',    mime);
                            fd.append('image_name',    name);
                            fd.append('regular_price', reg);
                            fd.append('sale_price',    sale);
                            fd.append('want_tags',     wantTags ? '1' : '0');
                            fd.append('want_seo',      wantSeo  ? '1' : '0');
                            fd.append('want_wa',       wantWa   ? '1' : '0');
                            fd.append('reg_price_val', reg);
                            fd.append('sale_price_val', sale);

                            return fetch(AJAX_URL, { method: 'POST', body: fd })
                                .then(function (res) { return res.json(); })
                                .then(function (json) {
                                    if (json.success) {
                                        created++;
                                        row.done = true;
                                        setStatus(row.el, 'done',
                                            '<a href="' + json.data.edit_url + '" target="_blank">' + escHtml(json.data.title) + '</a>');
                                    } else {
                                        errors++;
                                        setStatus(row.el, 'error', escHtml(json.data || 'Failed'));
                                    }
                                });
                        }).catch(function () {
                            errors++;
                            setStatus(row.el, 'error', 'Request failed');
                        }).then(function () {
                            var pct = Math.round(((i + 1) / total) * 100);
                            progBar.style.width  = pct + '%';
                            progSumm.textContent = (i + 1) + ' of ' + total + ' — ' + created + ' created, ' + errors + ' failed';
                            progSub.textContent  = '';
                            if (i < total - 1) return sleep(1200);
                        });
                    });
                });

                chain.then(function () {
                    running = false;
                    progWrap.style.display = 'none';
                    doneBox.style.display  = '';
                    doneMsg.textContent    = 'Complete — ' + created + ' product' + (created !== 1 ? 's' : '') + ' created' + (errors ? ', ' + errors + ' failed' : '') + '.';
                    updateUI();
                });
            });

            function setStatus(rowEl, type, html) {
                var el = rowEl.querySelector('.wlbp-row-status');
                if (!el) return;
                el.className = 'wlbp-row-status wlbp-st-' + type;
                el.innerHTML = html;
            }

            function toBase64(file) {
                return new Promise(function (resolve, reject) {
                    var r = new FileReader();
                    r.onload  = function (e) { resolve(e.target.result); };
                    r.onerror = reject;
                    r.readAsDataURL(file);
                });
            }

            function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

            function escHtml(s) {
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
        })();
        </script>
        <?php
    }

    /* ── AJAX: process one product ────────────────────────────────── */
    public static function ajax_process_product(): void {
        check_ajax_referer( 'woolens_bp_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        if ( ! WOOLENS_Settings_Page::is_pro( true ) ) {
            wp_send_json_error( 'Pro plan required.' );
        }

        $cat_id     = absint( $_POST['cat_id'] ?? 0 );
        $image_mime = sanitize_text_field( $_POST['image_mime'] ?? 'image/jpeg' );
        $image_name = sanitize_file_name( $_POST['image_name'] ?? 'product.jpg' );
        $reg_price  = sanitize_text_field( $_POST['regular_price'] ?? '' );
        $sale_price = sanitize_text_field( $_POST['sale_price'] ?? '' );

        // Sanitize base64: only valid base64 chars
        $image_data = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $_POST['image_data'] ?? '' );

        if ( ! $cat_id || empty( $image_data ) ) {
            wp_send_json_error( 'Missing required fields.' );
        }

        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        if ( ! in_array( $image_mime, $allowed_mimes, true ) ) {
            wp_send_json_error( 'Unsupported image type.' );
        }

        // ~4MB limit (base64 is ~133% of binary size)
        if ( strlen( $image_data ) > 5_600_000 ) {
            wp_send_json_error( 'Image too large (max 4MB).' );
        }

        $api_key   = WOOLENS_Settings_Page::get( 'woolens_gemini_key' );
        $language  = WOOLENS_Settings_Page::get( 'woolens_language', 'English' );
        $tone      = WOOLENS_Settings_Page::get( 'woolens_tone', 'Professional' );
        $want_tags = ! empty( $_POST['want_tags'] ) && $_POST['want_tags'] === '1';
        $want_seo  = ! empty( $_POST['want_seo']  ) && $_POST['want_seo']  === '1';
        $want_wa   = ! empty( $_POST['want_wa']   ) && $_POST['want_wa']   === '1';

        if ( empty( $api_key ) ) {
            wp_send_json_error( 'Gemini API key not configured. Please add it in WooLens AI settings.' );
        }

        // 1. Generate title + description via Gemini
        $result = WOOLENS_AI_Client::generate_from_base64(
            $api_key,
            WOOLENS_FREE_MODEL,
            $image_data,
            $image_mime,
            'both',
            [ 'language' => $language, 'tone' => $tone, 'is_pro' => true, 'want_tags' => $want_tags, 'want_seo' => $want_seo ]
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // 2. Sideload image into WP media library
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam( $image_name );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $tmp, base64_decode( $image_data ) );

        $attachment_id = media_handle_sideload(
            [ 'name' => $image_name, 'tmp_name' => $tmp ],
            0
        );

        if ( file_exists( $tmp ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $tmp );
        }

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( 'Image upload failed: ' . $attachment_id->get_error_message() );
        }

        // 3. Create WooCommerce product
        $product = new WC_Product_Simple();
        $product->set_name( $result['title'] ?: 'New Product' );
        $product->set_description( $result['description'] ?? '' );
        $product->set_short_description( $result['short_description'] ?? '' );
        $product->set_regular_price( $reg_price );

        if ( ! empty( $sale_price ) && is_numeric( $sale_price ) ) {
            $product->set_sale_price( $sale_price );
        }

        $product->set_category_ids( [ $cat_id ] );
        $product->set_image_id( $attachment_id );
        $product->set_status( 'publish' );
        $product_id = $product->save();

        if ( ! $product_id ) {
            wp_send_json_error( 'Failed to create product.' );
        }

        // Save tags
        if ( $want_tags && ! empty( $result['tags'] ) ) {
            $tags = array_filter( array_map( 'trim', explode( ',', $result['tags'] ) ) );
            wp_set_post_terms( $product_id, $tags, 'product_tag', false );
        }

        // Save SEO meta
        if ( $want_seo && ! empty( $result['seo_title'] ) ) {
            if ( defined( 'WPSEO_VERSION' ) ) {
                update_post_meta( $product_id, '_yoast_wpseo_title',    sanitize_text_field( $result['seo_title'] ) );
                update_post_meta( $product_id, '_yoast_wpseo_metadesc', sanitize_text_field( $result['seo_description'] ?? '' ) );
            } elseif ( defined( 'RANK_MATH_VERSION' ) ) {
                update_post_meta( $product_id, 'rank_math_title',       sanitize_text_field( $result['seo_title'] ) );
                update_post_meta( $product_id, 'rank_math_description',  sanitize_text_field( $result['seo_description'] ?? '' ) );
            }
            update_post_meta( $product_id, '_woolens_seo_title', sanitize_text_field( $result['seo_title'] ) );
            update_post_meta( $product_id, '_woolens_seo_desc',  sanitize_text_field( $result['seo_description'] ?? '' ) );
        }

        // Save WhatsApp message
        if ( $want_wa ) {
            $currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
            $wa_reg   = sanitize_text_field( $_POST['reg_price_val'] ?? $reg_price );
            $wa_sale  = sanitize_text_field( $_POST['sale_price_val'] ?? $sale_price );
            $short    = wp_strip_all_tags( $result['short_description'] ?? '' );
            $desc_txt = wp_strip_all_tags( $result['description'] ?? '' );
            $body     = $short ?: mb_substr( $desc_txt, 0, 150 );
            $wa       = '*' . ( $result['title'] ?: 'New Product' ) . '*' . "\n\n" . $body;
            if ( $wa_reg && is_numeric( $wa_reg ) && floatval( $wa_reg ) > 0 ) {
                if ( $wa_sale && is_numeric( $wa_sale ) && floatval( $wa_sale ) > 0 ) {
                    $wa .= "\n\nPrice: {$currency}{$wa_sale} (was {$currency}{$wa_reg})";
                } else {
                    $wa .= "\n\nPrice: {$currency}{$wa_reg}";
                }
            }
            update_post_meta( $product_id, '_woolens_wa_message', $wa );
        }

        wp_send_json_success( [
            'product_id' => $product_id,
            'title'      => $result['title'],
            'edit_url'   => (string) get_edit_post_link( $product_id, 'url' ),
        ] );
    }
}
