<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_AI_Client {

    const TOKENS = [
        'title'       => 500,
        'description' => 1200,
        'both'        => 1000,  // free — plain paragraph
        'both_pro'    => 1800,  // pro — HTML with bullets
    ];

    const PROMPTS = [
        'title'       => 'Analyze this product image. Write a short product title under 60 characters. Language: %s. Tone: %s. Respond with ONLY raw JSON: {"title":"Your Product Title Here","description":"","short_description":""}',
        'description' => 'Analyze this product image. Write a product description between 80 and 120 words, and a short description of 1-2 sentences under 150 characters. Language: %s. Tone: %s. Respond with ONLY raw JSON: {"title":"","description":"Your full product description here.","short_description":"Brief 1-2 sentence summary."}',

        // Free: clean SEO paragraph
        'both'        => 'Analyze this product image. Write: (1) a product title under 60 characters, (2) a short catchy tagline under 10 words that captures the product\'s main appeal, (3) a single SEO-friendly paragraph of 80 to 120 words with natural keyword placement — no bullet points or HTML, (4) a short description of 1-2 sentences under 150 characters. Language: %s. Tone: %s. Respond with ONLY raw JSON: {"title":"Your Product Title","tagline":"Short catchy heading","description":"Your SEO paragraph here.","short_description":"Brief 1-2 sentence summary."}',

        // Pro: structured HTML — intro + bullets + summary
        'both_pro'    => 'Analyze this product image. Write: (1) a product title under 60 characters, (2) a short catchy tagline under 10 words that captures the product\'s main appeal, (3) a structured HTML description with three parts: an opening SEO paragraph (2-3 sentences, include main product keywords naturally) inside <p> tags, then a <ul> list of 4 to 5 key product features each as <li><strong>Feature Label:</strong> feature detail</li>, then a closing summary sentence inside a <p> tag (do NOT write a call-to-action like "order now"), (4) a short description of 1-2 sentences under 150 characters. Language: %s. Tone: %s. Respond with ONLY raw JSON: {"title":"Your Product Title","tagline":"Short catchy heading","description":"<p>Intro paragraph.</p><ul><li><strong>Label:</strong> detail</li></ul><p>Summary sentence.</p>","short_description":"Brief 1-2 sentence summary."}',
    ];

    public static function generate_from_base64(
        string $api_key,
        string $model,
        string $b64,
        string $mime,
        string $mode = 'both',
        array  $opts = []
    ) {
        $language   = sanitize_text_field( $opts['language'] ?? 'English' );
        $tone       = sanitize_text_field( $opts['tone']     ?? 'Professional' );
        $is_pro     = (bool) ( $opts['is_pro'] ?? false );
        $prompt_key = ( $mode === 'both' && $is_pro ) ? 'both_pro' : $mode;
        $prompt     = sprintf( self::PROMPTS[ $prompt_key ] ?? self::PROMPTS['both'], $language, $tone );
        $tokens     = self::TOKENS[ $prompt_key ] ?? 1000;

        return self::call_gemini( $api_key, $model, $prompt, [ 'b64' => $b64, 'mime' => $mime ], $tokens );
    }

    public static function generate(
        string $api_key,
        string $model,
        string $image_url,
        string $mode = 'both',
        array  $opts = []
    ) {
        $language = sanitize_text_field( $opts['language'] ?? 'English' );
        $tone     = sanitize_text_field( $opts['tone']     ?? 'Professional' );
        $is_pro   = (bool) ( $opts['is_pro'] ?? false );

        // Pro gets structured HTML description; free gets plain SEO paragraph
        $prompt_key = ( $mode === 'both' && $is_pro ) ? 'both_pro' : $mode;
        $prompt     = sprintf( self::PROMPTS[ $prompt_key ] ?? self::PROMPTS['both'], $language, $tone );
        $tokens     = self::TOKENS[ $prompt_key ] ?? 1000;

        $img = self::fetch_image( $image_url );
        if ( is_wp_error( $img ) ) return $img;

        return self::call_gemini( $api_key, $model, $prompt, $img, $tokens );
    }

    private static function call_gemini(
        string $api_key,
        string $model,
        string $prompt,
        array  $img,
        int    $tokens
    ) {
        $config = [
            'maxOutputTokens' => $tokens,
            'temperature'     => 0.1,
        ];

        $body = [
            'contents' => [ [ 'parts' => [
                [ 'text' => $prompt ],
                [ 'inline_data' => [ 'mime_type' => $img['mime'], 'data' => $img['b64'] ] ],
            ] ] ],
            'generationConfig' => $config,
        ];

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
                    . rawurlencode( $model ) . ':generateContent?key=' . $api_key;

        $res = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'request_failed', 'Connection failed. Please check your internet and try again.' );
        }

        $http_code   = wp_remote_retrieve_response_code( $res );
        $raw_body    = wp_remote_retrieve_body( $res );
        $data        = json_decode( $raw_body, true );

        // Always log full raw response for debugging
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'WooLens AI [HTTP ' . $http_code . '] raw: ' . substr( $raw_body, 0, 1000 ) );
        }

        if ( $http_code !== 200 ) {
            return self::handle_api_error( $http_code, $data, $model );
        }

        // Extract text from response
        $raw_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ( empty( $raw_text ) ) {
            // Check for safety block
            $finish_reason = $data['candidates'][0]['finishReason'] ?? '';
            if ( $finish_reason === 'SAFETY' ) {
                return new WP_Error( 'safety_block', 'Gemini blocked this image (safety filter). Please try a different image.' );
            }
            return new WP_Error( 'empty_response', 'Gemini returned an empty response. Please try again.' );
        }

        return self::parse_json( $raw_text );
    }

    /* ── JSON parser ─────────────────────────────────────────────── */
    private static function parse_json( string $raw ) {
        // Step 1: Remove markdown code fences using str_replace (no regex needed)
        $clean = str_replace( array( '```json', '```' ), '', $raw );
        $clean = trim( $clean );

        // Step 2: Direct JSON parse
        $parsed = json_decode( $clean, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
            return self::format_result( $parsed );
        }

        // Step 3: Extract first { ... } block via regex
        if ( preg_match( '/{.*}/s', $clean, $m ) ) {
            $parsed = json_decode( $m[0], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
                return self::format_result( $parsed );
            }
        }

        // Step 4: Regex extract title and description individually
        $title = '';
        $desc  = '';
        if ( preg_match( '/"title"\s*:\s*"([^"]+)"/i', $clean, $m ) ) {
            $title = $m[1];
        }
        if ( preg_match( '/"description"\s*:\s*"([^"]+)"/i', $clean, $m ) ) {
            $desc = $m[1];
        }
        if ( $title || $desc ) {
            return self::format_result( array( 'title' => $title, 'description' => $desc ) );
        }

        return new WP_Error( 'parse_error',
            'Gemini returned an unexpected format. Raw: ' . substr( $raw, 0, 150 )
        );
    }

    private static function format_result( array $parsed ): array {
        $title      = sanitize_text_field( $parsed['title']             ?? '' );
        $tagline    = sanitize_text_field( $parsed['tagline']           ?? '' );
        $desc       = wp_kses_post(        $parsed['description']       ?? '' );
        $short_desc = wp_kses_post(        $parsed['short_description'] ?? '' );

        if ( empty( $title ) && empty( $desc ) ) {
            return array(
                'title'             => '',
                'description'       => '',
                'short_description' => '',
                '_debug'            => 'Both fields empty after parse. Keys found: ' . implode( ', ', array_keys( $parsed ) ),
            );
        }

        // Prepend tagline as bold heading before the description
        if ( ! empty( $tagline ) && ! empty( $desc ) ) {
            $desc = '<p><strong>' . esc_html( $tagline ) . '</strong></p>' . $desc;
        }

        return array(
            'title'             => $title,
            'description'       => $desc,
            'short_description' => $short_desc,
        );
    }

    /* ── API error handler ────────────────────────────────────────── */
    private static function handle_api_error( int $code, ?array $data, string $model ): WP_Error {
        $msg = $data['error']['message'] ?? '';
        if ( $code === 400 ) {
            if ( stripos( $msg, 'model' ) !== false )
                return new WP_Error( 'invalid_model', "Model '{$model}' is not supported. Please check your settings." );
            if ( stripos( $msg, 'key' ) !== false || stripos( $msg, 'API_KEY' ) !== false )
                return new WP_Error( 'invalid_key', 'Invalid API key. Please enter a valid key in WooLens AI Settings.' );
            return new WP_Error( 'bad_request', 'Bad request: ' . ( $msg ?: 'Unknown.' ) );
        }
        if ( $code === 403 ) return new WP_Error( 'forbidden',    'API key is unauthorized.' );
        if ( $code === 404 ) return new WP_Error( 'not_found',    "Model '{$model}' was not found." );
        if ( $code === 429 ) return new WP_Error( 'rate_limited', 'Gemini Error 429: ' . ( $msg ?: 'Rate limit or quota issue.' ) . ' | Full: ' . json_encode($data) );
        if ( $code >= 500 )  return new WP_Error( 'server_error', 'Gemini server error. Please try again.' );
        return new WP_Error( 'api_error', "API Error {$code}: " . ( $msg ?: 'Unknown.' ) );
    }

    /* ── Image fetch & validate ───────────────────────────────────── */
    private static function fetch_image( string $url ) {
        $site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
        $image_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $site_host !== $image_host )
            return new WP_Error( 'image_host', 'Image must be hosted on your own site.' );

        $res = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $res ) )
            return new WP_Error( 'image_fetch', 'Could not fetch the image.' );

        $body = wp_remote_retrieve_body( $res );
        if ( empty( $body ) )
            return new WP_Error( 'image_empty', 'Image is empty.' );

        $mime    = trim( explode( ';', wp_remote_retrieve_header( $res, 'content-type' ) )[0] );
        $allowed = [ 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp' ];
        if ( ! in_array( $mime, $allowed, true ) )
            return new WP_Error( 'image_type', "Image type '{$mime}' is not supported. Please use JPEG, PNG, or WebP." );

        if ( strlen( $body ) > 4 * 1024 * 1024 )
            return new WP_Error( 'image_size', 'Image is larger than 4MB.' );

        return [ 'b64' => base64_encode( $body ), 'mime' => $mime ];
    }
}
