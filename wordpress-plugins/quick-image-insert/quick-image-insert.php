<?php
/**
 * Plugin Name: Quick Image Insert
 * Description: 툴바에 미디어/파일 찾기 버튼 추가 + 불필요한 툴바 버튼 제거
 * Version: 1.1.0
 * Author: Sohee Yang
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── JS + CSS 에셋 로드 ─────────────────────────────────────────────
function qii_enqueue_editor_assets() {
    wp_enqueue_script(
        'quick-image-insert',
        plugin_dir_url( __FILE__ ) . 'editor.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-block-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'editor.js' ),
        true
    );

    wp_enqueue_style(
        'quick-image-insert-editor',
        plugin_dir_url( __FILE__ ) . 'editor.css',
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'editor.css' )
    );
}
add_action( 'enqueue_block_editor_assets', 'qii_enqueue_editor_assets' );

// ── 텍스트 블록: 텍스트 정렬(textAlign) 지원 제거 ─────────────────
add_filter( 'block_type_metadata_settings', function ( $settings, $metadata ) {
    $text_blocks = [ 'core/heading', 'core/paragraph', 'core/list', 'core/quote' ];

    if ( in_array( $metadata['name'] ?? '', $text_blocks, true ) ) {
        if ( isset( $settings['supports']['typography'] ) ) {
            $settings['supports']['typography']['textAlign'] = false;
        } else {
            $settings['supports']['typography'] = [ 'textAlign' => false ];
        }
    }
    return $settings;
}, 10, 2 );

// ── 이미지 블록: 블록 정렬(align) 지원 제거 ───────────────────────
add_filter( 'block_type_metadata_settings', function ( $settings, $metadata ) {
    if ( ( $metadata['name'] ?? '' ) === 'core/image' ) {
        $settings['supports']['align'] = false;
    }
    return $settings;
}, 10, 2 );
