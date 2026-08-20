<?php
/**
 * Plugin Name: Quick Image Insert
 * Description: 구텐베르크 에디터 툴바에 이미지 빠른 삽입 버튼을 추가합니다.
 * Version: 1.0.0
 * Author: Sohee Yang
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function qii_enqueue_editor_assets() {
    wp_enqueue_script(
        'quick-image-insert',
        plugin_dir_url( __FILE__ ) . 'editor.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-block-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'editor.js' ),
        true
    );
}
add_action( 'enqueue_block_editor_assets', 'qii_enqueue_editor_assets' );
