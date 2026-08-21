<?php
/**
 * Plugin Name: Duplicate Image Merger
 * Plugin URI:  https://github.com/yangsohee94-code/claude_memory_n1
 * Description: 중복 이미지 병합 · WebP 변환 · 대표이미지 정합성 자동 최적화
 * Version:     1.1.0
 * Author:      Claude Memory N1
 * License:     GPL-2.0+
 * Text Domain: duplicate-image-merger
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── 세션 만료: 7일 비활동 시 만료 ────────────────────────────────────────────
// 쿠키 유효기간을 7일로 설정 (remember me 여부 무관)
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    return 7 * DAY_IN_SECONDS; // 604800초
}, 10, 3 );

// 로그인 상태일 때 매 페이지 로드마다 쿠키를 갱신 → 마지막 접속 기준 7일로 초기화
// send_headers 훅은 parse_request 이후에 실행되므로
// REST API·AJAX·Cron 요청은 그 이전에 exit → 별도 체크 없이 자동 제외
add_action( 'send_headers', function () {
    if ( ! is_user_logged_in() ) return;

    $user_id = get_current_user_id();
    wp_set_auth_cookie( $user_id, true, is_ssl() );
} );
// ─────────────────────────────────────────────────────────────────────────────

define( 'DIM_VERSION',    '1.1.0' );
define( 'DIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

foreach ( [ 'scanner', 'merger', 'converter', 'thumbnail', 'admin', 'ajax' ] as $c ) {
    require_once DIM_PLUGIN_DIR . "includes/class-dim-{$c}.php";
}

add_action( 'plugins_loaded', function () {
    ( new DIM_Admin() )->init();
    ( new DIM_Ajax()  )->init();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dim_optimize_cron' );
} );
