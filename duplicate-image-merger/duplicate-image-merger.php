<?php
/**
 * Plugin Name: Duplicate Image Merger
 * Plugin URI:  https://github.com/yangsohee94-code/claude_memory_n1
 * Description: 중복 이미지 병합 · WebP 변환 · 대표이미지 정합성 자동 최적화
 * Version:     1.3.17
 * Author:      Claude Memory N1
 * License:     GPL-2.0+
 * Text Domain: duplicate-image-merger
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DIM_VERSION',    '1.3.17' );
define( 'DIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

foreach ( [ 'scanner', 'merger', 'converter', 'thumbnail', 'stats', 'admin', 'ajax' ] as $c ) {
    require_once DIM_PLUGIN_DIR . "includes/class-dim-{$c}.php";
}

add_action( 'plugins_loaded', function () {
    ( new DIM_Admin() )->init();
    ( new DIM_Ajax()  )->init();
} );

// ── 업로드 파일명 자동 변경 ─────────────────────────────────────────
// 관리자 "업로드 설정" 탭에서 접두사를 지정하면
// nightlab_20260821_1.jpg 형태로 자동 변경.
// 기능 OFF 시에는 해시 접미사(-xxxxxxxx)를 붙여 파일명 충돌만 방지한다.
function dim_rename_upload_file( $file ) {
    if ( strpos( $file['type'] ?? '', 'image/' ) !== 0 ) return $file;

    $info = pathinfo( $file['name'] );
    $ext  = isset( $info['extension'] ) ? '.' . strtolower( $info['extension'] ) : '';

    if ( get_option( 'dim_upload_rename_enabled', false ) ) {
        // ① 파일명 안전 확보: 저장 시 sanitize_title_with_dashes만 적용됐을 수 있으므로 재확인
        $prefix = sanitize_file_name( get_option( 'dim_upload_prefix', 'image' ) ) ?: 'image';

        // ② 원자적 카운터 증가 — 동시 업로드 시 중복 방지
        // 옵션이 아직 없으면 INSERT 후 증가 (첫 실행 보호)
        global $wpdb;
        $exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'dim_upload_counter'" );
        if ( ! $exists ) {
            add_option( 'dim_upload_counter', 1, '', 'no' );
        }
        $wpdb->query( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = 'dim_upload_counter'" );
        $counter = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'dim_upload_counter'" );
        wp_cache_delete( 'dim_upload_counter', 'options' );

        // ③ WordPress 설정 시간대 기준 날짜 사용
        $date         = wp_date( 'Ymd' );
        $file['name'] = $prefix . '_' . $date . '_' . $counter . $ext;
    } else {
        $tmp  = $file['tmp_name'] ?? '';
        $hash = $tmp && file_exists( $tmp )
            ? substr( md5_file( $tmp ), 0, 8 )
            : substr( md5( uniqid( '', true ) ), 0, 8 );
        $base         = sanitize_file_name( $info['filename'] );
        $file['name'] = $base . '-' . $hash . $ext;
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter',   'dim_rename_upload_file' );
add_filter( 'wp_handle_sideload_prefilter', 'dim_rename_upload_file' );

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

// ── 관리자 세션 영속 관리 ──────────────────────────────────────────
// 목표: 탭 닫아도 만료 없음 / 마지막 접속 후 7일 미접속 시에만 만료

// ① 쿠키 유효기간 항상 7일로 고정 (remember 여부 무관)
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    if ( user_can( $user_id, 'manage_options' ) ) {
        return 7 * DAY_IN_SECONDS;
    }
    return $expiration;
}, 10, 3 );

// ② 로그인 시 "remember=true" 강제 설정 → 브라우저 닫아도 쿠키 유지
add_action( 'wp_login', function ( $user_login, $user ) {
    if ( ! user_can( $user, 'manage_options' ) ) return;
    wp_clear_auth_cookie();
    wp_set_auth_cookie( $user->ID, true, is_ssl() );
}, 10, 2 );

// ③ 관리자 페이지 접속 시 하루 1회 쿠키 갱신 (슬라이딩 만료)
//    마지막 접속 시점을 기준으로 7일이 리셋됨
add_action( 'admin_init', function () {
    if ( ! is_user_logged_in() ) return;
    $user_id = get_current_user_id();
    if ( ! user_can( $user_id, 'manage_options' ) ) return;

    $last = (int) get_user_meta( $user_id, '_dim_session_refreshed', true );
    if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) return;

    wp_set_auth_cookie( $user_id, true, is_ssl() );
    update_user_meta( $user_id, '_dim_session_refreshed', time() );
} );

// ④ 로그인 폼에서 "로그인 상태 유지" 기본 체크 표시 (시각적 일관성)
add_filter( 'login_form_defaults', function ( $defaults ) {
    $defaults['rememberme'] = true;
    return $defaults;
} );
