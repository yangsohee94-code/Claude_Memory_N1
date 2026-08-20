<?php
/**
 * Plugin Name: Duplicate Image Merger
 * Plugin URI:  https://github.com/yangsohee94-code/claude_memory_n1
 * Description: 중복 이미지 병합 · WebP 변환 · 대표이미지 정합성 자동 최적화
 * Version:     1.2.6
 * Author:      Claude Memory N1
 * License:     GPL-2.0+
 * Text Domain: duplicate-image-merger
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DIM_VERSION',    '1.2.7' );
define( 'DIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

foreach ( [ 'scanner', 'merger', 'converter', 'thumbnail', 'stats', 'admin', 'ajax' ] as $c ) {
    require_once DIM_PLUGIN_DIR . "includes/class-dim-{$c}.php";
}

add_action( 'plugins_loaded', function () {
    ( new DIM_Admin() )->init();
    ( new DIM_Ajax()  )->init();
} );

// 업로드 즉시 WebP 자동 변환
add_action( 'add_attachment', function ( $attachment_id ) {
    $mime = get_post_mime_type( $attachment_id );
    if ( in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true ) ) {
        $converter = new DIM_Converter();
        if ( $converter->can_convert() ) {
            $converter->convert_to_webp( $attachment_id );
        }
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dim_optimize_cron' );
} );

// 관리자 로그인 세션 7일 유지
// "로그인 상태 유지" 체크 여부와 무관하게 강제 적용
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    if ( user_can( $user_id, 'manage_options' ) ) {
        return 7 * DAY_IN_SECONDS;
    }
    return $expiration;
}, 10, 3 );

add_action( 'login_form', function () {
    // 로그인 폼에서 "로그인 상태 유지" 기본 체크
    add_filter( 'login_form_defaults', function ( $defaults ) {
        $defaults['rememberme'] = true;
        return $defaults;
    } );
} );
