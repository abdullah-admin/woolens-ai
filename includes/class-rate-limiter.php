<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOOLENS_Rate_Limiter {

    const FREE_DAILY_LIMIT = 10;

    public static function daily_limit(): int {
        return self::FREE_DAILY_LIMIT;
    }

    public static function get_usage( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'woolens_usage';
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT count FROM $table WHERE user_id = %d AND used_date = %s",
            $user_id,
            current_time( 'Y-m-d' )
        ) );
        return (int) $count;
    }

    public static function can_generate( int $user_id ): bool {
        if ( get_user_meta( $user_id, 'woolens_pro', true ) ) return true;
        return self::get_usage( $user_id ) < self::daily_limit();
    }

    public static function increment( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'woolens_usage';
        $today = current_time( 'Y-m-d' );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (user_id, used_date, count)
             VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $user_id, $today
        ) );
        return self::get_usage( $user_id );
    }

    /** Reset usage for a specific user */
    public static function reset_user( int $user_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'woolens_usage';
        $result = $wpdb->delete( $table, [
            'user_id'   => $user_id,
            'used_date' => current_time( 'Y-m-d' ),
        ], [ '%d', '%s' ] );
        return $result !== false;
    }

    /** Reset ALL users today */
    public static function reset_all(): bool {
        global $wpdb;
        $table  = $wpdb->prefix . 'woolens_usage';
        $result = $wpdb->delete( $table, [
            'used_date' => current_time( 'Y-m-d' ),
        ], [ '%s' ] );
        return $result !== false;
    }

    public static function status( int $user_id ): array {
        $is_pro    = (bool) get_user_meta( $user_id, 'woolens_pro', true );
        $used      = self::get_usage( $user_id );
        $limit     = $is_pro ? 0 : self::daily_limit();
        return [
            'is_pro'    => $is_pro,
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => $is_pro ? 9999 : max( 0, $limit - $used ),
            'can_gen'   => self::can_generate( $user_id ),
        ];
    }
}
