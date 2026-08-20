<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Pinterest_API {

    private $access_token;
    private $board_id;

    public function __construct( $options ) {
        $this->access_token = $options['pinterest_access_token'] ?? '';
        $this->board_id     = $options['pinterest_board_id'] ?? '';
    }

    public function post( $content, $image_url = '', $post_url = '' ) {
        if ( ! $this->access_token ) {
            return [ 'success' => false, 'message' => 'Pinterest 액세스 토큰이 설정되지 않았습니다.' ];
        }
        if ( ! $image_url ) {
            return [ 'success' => false, 'message' => 'Pinterest는 대표이미지가 필요합니다.' ];
        }

        $title = mb_substr( $content, 0, 100 );
        $description = mb_substr( $content, 0, 500 );

        $body = [
            'title'       => $title,
            'description' => $description,
            'link'        => $post_url,
            'media_source' => [
                'source_type' => 'image_url',
                'url'         => $image_url,
            ],
        ];
        if ( $this->board_id ) {
            $body['board_id'] = $this->board_id;
        }

        $response = wp_remote_post( 'https://api.pinterest.com/v5/pins', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 201 ) {
            return [ 'success' => true, 'message' => 'Pinterest 핀 게시 완료 (ID: ' . ( $data['id'] ?? '' ) . ')' ];
        }
        $error = $data['message'] ?? '알 수 없는 오류';
        return [ 'success' => false, 'message' => "Pinterest 오류 ({$code}): {$error}" ];
    }
}
