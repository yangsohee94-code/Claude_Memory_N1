<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Scheduler {

    const QUEUE_INTERVAL = 4 * HOUR_IN_SECONDS;

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'wp_ajax_sns_preview_content', [ $this, 'ajax_preview_content' ] );
        add_action( 'wp_ajax_sns_schedule_share', [ $this, 'ajax_schedule_share' ] );
        add_action( 'wp_ajax_sns_get_queue', [ $this, 'ajax_get_queue' ] );
        add_action( 'wp_ajax_sns_cancel_queue_item', [ $this, 'ajax_cancel_queue_item' ] );
        add_action( 'sns_scheduler_process_queue', [ $this, 'process_queue' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        if ( ! wp_next_scheduled( 'sns_scheduler_process_queue' ) ) {
            wp_schedule_event( time(), 'hourly', 'sns_scheduler_process_queue' );
        }
    }

    public function add_meta_box() {
        add_meta_box(
            'sns-share-scheduler',
            '📣 SNS 예약 공유',
            [ $this, 'render_meta_box' ],
            'post',
            'side',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'sns_share_nonce', 'sns_share_nonce_field' );
        include SNS_SCHEDULER_PATH . 'templates/meta-box.php';
    }

    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) return;
        wp_enqueue_style( 'sns-share-scheduler', SNS_SCHEDULER_URL . 'assets/css/sns-share.css', [], SNS_SCHEDULER_VERSION );
        wp_enqueue_script( 'sns-share-scheduler', SNS_SCHEDULER_URL . 'assets/js/sns-share.js', [ 'jquery' ], SNS_SCHEDULER_VERSION, true );
        wp_localize_script( 'sns-share-scheduler', 'snsShare', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'postId'  => get_the_ID(),
            'nonce'   => wp_create_nonce( 'sns_share_nonce' ),
        ] );
    }

    // Step 1: 문구 자동 생성만 반환 (예약 미확정)
    public function ajax_preview_content() {
        check_ajax_referer( 'sns_share_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '권한이 없습니다.' );

        $post_id  = intval( $_POST['post_id'] ?? 0 );
        $platform = sanitize_key( $_POST['platform'] ?? '' );

        if ( ! $post_id || ! in_array( $platform, [ 'twitter', 'threads', 'pinterest', 'facebook' ] ) ) {
            wp_send_json_error( '잘못된 요청입니다.' );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            wp_send_json_error( '발행된 글에서만 공유할 수 있습니다.' );
        }

        $data = SNS_Content_Generator::generate( $post_id, $platform );
        wp_send_json_success( [ 'content' => $data['content'] ] );
    }

    // Step 2: 사용자 확정 문구로 예약 등록
    public function ajax_schedule_share() {
        check_ajax_referer( 'sns_share_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '권한이 없습니다.' );

        $post_id  = intval( $_POST['post_id'] ?? 0 );
        $platform = sanitize_key( $_POST['platform'] ?? '' );
        $content  = sanitize_textarea_field( $_POST['content'] ?? '' );

        if ( ! $post_id || ! in_array( $platform, [ 'twitter', 'threads', 'pinterest', 'facebook' ] ) ) {
            wp_send_json_error( '잘못된 요청입니다.' );
        }
        if ( ! $content ) {
            wp_send_json_error( '공유 문구를 입력해주세요.' );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            wp_send_json_error( '발행된 글에서만 공유할 수 있습니다.' );
        }

        $data      = SNS_Content_Generator::generate( $post_id, $platform );
        $scheduled = $this->get_next_slot( $platform );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'sns_share_queue', [
            'post_id'      => $post_id,
            'platform'     => $platform,
            'content'      => $content,
            'image_url'    => $data['image_url'],
            'post_url'     => $data['post_url'],
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', $scheduled ),
            'status'       => 'pending',
        ] );

        $local_time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $scheduled ), 'Y년 m월 d일 H:i' );
        wp_send_json_success( [
            'message'      => "{$local_time}에 예약 완료",
            'scheduled_at' => $local_time,
        ] );
    }

    public function ajax_get_queue() {
        check_ajax_referer( 'sns_share_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '권한이 없습니다.' );
        $post_id = intval( $_POST['post_id'] ?? 0 );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, platform, scheduled_at, status, result FROM {$wpdb->prefix}sns_share_queue
             WHERE post_id = %d ORDER BY scheduled_at ASC LIMIT 20",
            $post_id
        ) );

        $items = [];
        foreach ( $rows as $row ) {
            $local = get_date_from_gmt( $row->scheduled_at, 'Y/m/d H:i' );
            $items[] = [
                'id'           => $row->id,
                'platform'     => $row->platform,
                'scheduled_at' => $local,
                'status'       => $row->status,
                'result'       => $row->result,
            ];
        }
        wp_send_json_success( $items );
    }

    public function ajax_cancel_queue_item() {
        check_ajax_referer( 'sns_share_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $id = intval( $_POST['item_id'] ?? 0 );
        global $wpdb;

        // Ownership check: verify the queue item's post can be edited by the current user.
        $item_post_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}sns_share_queue WHERE id = %d",
            $id
        ) );
        if ( ! $item_post_id || ! current_user_can( 'edit_post', $item_post_id ) ) {
            wp_send_json_error( '권한이 없습니다.' );
        }

        $wpdb->update(
            $wpdb->prefix . 'sns_share_queue',
            [ 'status' => 'cancelled' ],
            [ 'id' => $id, 'status' => 'pending' ]
        );
        wp_send_json_success();
    }

    private function get_next_slot( $platform ) {
        return self::get_next_slot_static( $platform );
    }

    public static function get_next_slot_static( $platform ) {
        global $wpdb;
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(scheduled_at) FROM {$wpdb->prefix}sns_share_queue
             WHERE platform = %s AND status = 'pending' AND scheduled_at > %s",
            $platform,
            current_time( 'mysql', true )
        ) );

        if ( $last ) {
            return strtotime( $last ) + self::QUEUE_INTERVAL;
        }
        return time() + 5 * MINUTE_IN_SECONDS;
    }

    public function process_queue() {
        global $wpdb;
        $now  = current_time( 'mysql', true );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sns_share_queue
             WHERE status = 'pending' AND scheduled_at <= %s
             ORDER BY scheduled_at ASC LIMIT 10",
            $now
        ) );

        foreach ( $rows as $row ) {
            $result = $this->dispatch( $row );
            $wpdb->update(
                $wpdb->prefix . 'sns_share_queue',
                [
                    'status' => $result['success'] ? 'sent' : 'failed',
                    'result' => $result['message'],
                ],
                [ 'id' => $row->id ]
            );
        }
    }

    private function dispatch( $row ) {
        $options = get_option( 'sns_scheduler_options', [] );
        try {
            switch ( $row->platform ) {
                case 'twitter':
                    $api = new SNS_Twitter_API( $options );
                    return $api->post( $row->content, $row->image_url, $row->post_url );
                case 'threads':
                    $api = new SNS_Threads_API( $options );
                    return $api->post( $row->content, $row->image_url, $row->post_url );
                case 'pinterest':
                    $api = new SNS_Pinterest_API( $options );
                    return $api->post( $row->content, $row->image_url, $row->post_url );
                case 'facebook':
                    $api = new SNS_Facebook_API( $options );
                    return $api->post( $row->content, $row->image_url, $row->post_url );
                default:
                    return [ 'success' => false, 'message' => '알 수 없는 플랫폼' ];
            }
        } catch ( Exception $e ) {
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }
}
