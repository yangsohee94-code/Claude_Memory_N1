<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Threads_API {

    private $access_token;
    private $user_id;

    public function __construct( $options ) {
        $this->access_token = $options['threads_access_token'] ?? '';
        $this->user_id      = $options['threads_user_id'] ?? '';
    }

    public function post( $content, $image_url = '', $post_url = '' ) {
        if ( ! $this->access_token || ! $this->user_id ) {
            return [ 'success' => false, 'message' => 'Threads 액세스 토큰이 설정되지 않았습니다.' ];
        }

        // Step 1: Create a media container
        $container_id = $this->create_container( $content, $image_url );
        if ( ! $container_id ) {
            return [ 'success' => false, 'message' => 'Threads 컨테이너 생성 실패' ];
        }

        // Step 2: Publish the container
        return $this->publish_container( $container_id );
    }

    private function create_container( $content, $image_url ) {
        $params = [
            'text'         => $content,
            'access_token' => $this->access_token,
        ];

        if ( $image_url ) {
            $params['media_type'] = 'IMAGE';
            $params['image_url']  = $image_url;
        } else {
            $params['media_type'] = 'TEXT';
        }

        $url      = "https://graph.threads.net/v1.0/{$this->user_id}/threads";
        $response = wp_remote_post( $url, [
            'body'    => $params,
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['id'] ?? null;
    }

    private function publish_container( $container_id ) {
        $url      = "https://graph.threads.net/v1.0/{$this->user_id}/threads_publish";
        $response = wp_remote_post( $url, [
            'body'    => [
                'creation_id'  => $container_id,
                'access_token' => $this->access_token,
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['id'] ) ) {
            return [ 'success' => true, 'message' => 'Threads 게시 완료 (ID: ' . $body['id'] . ')' ];
        }
        $error = $body['error']['message'] ?? '알 수 없는 오류';
        return [ 'success' => false, 'message' => "Threads 오류 ({$code}): {$error}" ];
    }
}
