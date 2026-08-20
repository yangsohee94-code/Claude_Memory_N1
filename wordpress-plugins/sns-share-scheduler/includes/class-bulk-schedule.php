<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Bulk_Schedule {

    const PLATFORMS = [ 'twitter', 'threads', 'pinterest', 'facebook' ];

    public function __construct() {
        add_filter( 'bulk_actions-edit-post', [ $this, 'add_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-post', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ $this, 'show_result_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_bulk_actions( $actions ) {
        $labels = [
            'twitter'   => '𝕏 X (Twitter)',
            'threads'   => '⊕ Threads',
            'pinterest' => '𝑷 Pinterest',
            'facebook'  => 'f Facebook',
        ];
        foreach ( $labels as $key => $label ) {
            $actions[ 'sns_bulk_' . $key ] = '📅 SNS 예약: ' . $label;
        }
        return $actions;
    }

    public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        $prefix = 'sns_bulk_';
        if ( strpos( $action, $prefix ) !== 0 ) {
            return $redirect_url;
        }

        $platform = str_replace( $prefix, '', $action );
        if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
            return $redirect_url;
        }

        // Sort posts by date ascending (oldest first)
        usort( $post_ids, function ( $a, $b ) {
            return get_post_time( 'U', true, $a ) - get_post_time( 'U', true, $b );
        } );

        $scheduled = 0;
        $skipped   = 0;

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                $skipped++;
                continue;
            }

            $data      = SNS_Content_Generator::generate( $post_id, $platform );
            $slot      = SNS_Scheduler::get_next_slot_static( $platform );

            global $wpdb;
            $wpdb->insert( $wpdb->prefix . 'sns_share_queue', [
                'post_id'      => $post_id,
                'platform'     => $platform,
                'content'      => $data['content'],
                'image_url'    => $data['image_url'],
                'post_url'     => $data['post_url'],
                'scheduled_at' => date( 'Y-m-d H:i:s', $slot ),
                'status'       => 'pending',
            ] );
            $scheduled++;
        }

        $redirect_url = add_query_arg( [
            'sns_bulk_done'     => 1,
            'sns_platform'      => $platform,
            'sns_scheduled'     => $scheduled,
            'sns_skipped'       => $skipped,
        ], $redirect_url );

        return $redirect_url;
    }

    public function show_result_notice() {
        if ( empty( $_GET['sns_bulk_done'] ) ) return;

        $platform  = sanitize_key( $_GET['sns_platform'] ?? '' );
        $scheduled = intval( $_GET['sns_scheduled'] ?? 0 );
        $skipped   = intval( $_GET['sns_skipped'] ?? 0 );

        $platform_labels = [
            'twitter'   => 'X (Twitter)',
            'threads'   => 'Threads',
            'pinterest' => 'Pinterest',
            'facebook'  => 'Facebook',
        ];
        $label = $platform_labels[ $platform ] ?? $platform;

        if ( $scheduled > 0 ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>✅ <strong>' . esc_html( $label ) . '</strong>에 ' . esc_html( $scheduled ) . '개 글이 4시간 간격으로 순차 예약되었습니다.';
            if ( $skipped ) {
                echo ' (' . esc_html( $skipped ) . '개는 미발행 상태로 건너뜀)';
            }
            echo ' &nbsp;<a href="' . esc_url( admin_url( 'edit.php?page=sns-share-status' ) ) . '">예약 현황 보기 →</a>';
            echo '</p></div>';
        } elseif ( $skipped > 0 ) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>⚠️ 선택한 글이 모두 미발행 상태입니다. 발행된 글만 SNS 공유가 가능합니다.</p>';
            echo '</div>';
        }
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'edit.php' ) return;
        wp_enqueue_style( 'sns-admin-style', SNS_SCHEDULER_URL . 'assets/css/sns-admin.css', [], SNS_SCHEDULER_VERSION );
    }
}
