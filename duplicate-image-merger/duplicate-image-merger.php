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

// 마지막 접속 기준 7일 비활동 시 만료 구현
// - send_headers 훅: REST/AJAX/Cron은 이전에 exit → 자동 제외
// - 잔여 유효기간 1일 미만일 때만 갱신 → 불필요한 DB 쓰기 최소화
// - 기존 세션 토큰 만료만 연장 → 토큰 누적 없음
add_action( 'send_headers', function () {
    if ( ! is_user_logged_in() ) return;

    $token = wp_get_session_token();
    if ( empty( $token ) ) return;

    $user_id = get_current_user_id();
    $manager = WP_Session_Tokens::get_instance( $user_id );
    $session = $manager->get( $token );
    if ( ! $session ) return;

    // 잔여 유효기간이 1일 초과이면 갱신 불필요
    if ( $session['expiration'] - time() > DAY_IN_SECONDS ) return;

    // 기존 토큰의 만료 시간만 7일 연장 (새 토큰 생성 없음)
    $session['expiration'] = time() + 7 * DAY_IN_SECONDS;
    $manager->update( $token, $session );

    // 브라우저 쿠키도 갱신 ($token 전달로 새 세션 생성 방지)
    wp_set_auth_cookie( $user_id, true, '', $token );
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
