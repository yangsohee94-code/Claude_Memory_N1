<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 글 목록에서 여러 글을 선택해 전체 플랫폼에 일괄 예약하는 기능.
 *
 * 각 플랫폼은 독립적인 큐를 가지며 플랫폼별로 4시간 간격 유지:
 *   - 글 A → 모든 플랫폼 +5분 (각 플랫폼 큐가 비어있을 때)
 *   - 글 B → 모든 플랫폼 +4시간 (각 플랫폼에 이미 A가 있으면)
 *   - 글 C → 모든 플랫폼 +8시간, ...
 *
 * 플랫폼 간 시각은 서로 영향을 주지 않음 (X 큐 ≠ Threads 큐).
 */
class SNS_Bulk_Schedule {

    const PLATFORMS = [ 'twitter', 'threads', 'pinterest', 'facebook' ];

    public function __construct() {
        add_filter( 'bulk_actions-edit-post', [ $this, 'add_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-post', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ $this, 'show_result_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_bulk_actions( $actions ) {
        // 전체 플랫폼 동시 예약 (플랫폼별 독립 4시간 간격)
        $actions['sns_bulk_all'] = '📅 SNS 일괄 예약 (X·Threads·Pinterest·Facebook)';
        return $actions;
    }

    public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        if ( $action !== 'sns_bulk_all' ) {
            return $redirect_url;
        }

        // 발행일 오름차순 정렬 (오래된 글 먼저)
        usort( $post_ids, function ( $a, $b ) {
            return get_post_time( 'U', true, $a ) - get_post_time( 'U', true, $b );
        } );

        $scheduled = 0;
        $skipped   = 0;

        global $wpdb;

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                $skipped++;
                continue;
            }

            // 각 플랫폼은 독립적으로 자신의 큐에서 다음 슬롯 계산
            foreach ( self::PLATFORMS as $platform ) {
                $data = SNS_Content_Generator::generate( $post_id, $platform );
                $slot = SNS_Scheduler::get_next_slot_static( $platform );

                $wpdb->insert( $wpdb->prefix . 'sns_share_queue', [
                    'post_id'      => $post_id,
                    'platform'     => $platform,
                    'content'      => $data['content'],
                    'image_url'    => $data['image_url'],
                    'post_url'     => $data['post_url'],
                    'scheduled_at' => gmdate( 'Y-m-d H:i:s', $slot ),
                    'status'       => 'pending',
                ] );
            }
            $scheduled++;
        }

        $redirect_url = add_query_arg( [
            'sns_bulk_done'  => 1,
            'sns_scheduled'  => $scheduled,
            'sns_skipped'    => $skipped,
        ], $redirect_url );

        return $redirect_url;
    }

    public function show_result_notice() {
        if ( empty( $_GET['sns_bulk_done'] ) ) return;

        $scheduled = intval( $_GET['sns_scheduled'] ?? 0 );
        $skipped   = intval( $_GET['sns_skipped'] ?? 0 );

        if ( $scheduled > 0 ) {
            $total = $scheduled * count( self::PLATFORMS );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '✅ <strong>' . esc_html( $scheduled ) . '개 글</strong>을 X·Threads·Pinterest·Facebook에 일괄 예약했습니다. ';
            echo '(총 ' . esc_html( $total ) . '건, 플랫폼별 4시간 간격)';
            if ( $skipped ) {
                echo ' &nbsp;⚠️ 미발행 ' . esc_html( $skipped ) . '개 건너뜀';
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
