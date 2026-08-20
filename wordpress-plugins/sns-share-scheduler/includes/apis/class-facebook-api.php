<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Facebook_API {

    private $page_access_token;
    private $page_id;

    public function __construct( $options ) {
        $this->page_access_token = $options['facebook_page_access_token'] ?? '';
        $this->page_id           = $options['facebook_page_id'] ?? '';
    }

    public function post( $content, $image_url = '', $post_url = '' ) {
        if ( ! $this->page_access_token || ! $this->page_id ) {
            return [ 'success' => false, 'message' => 'Facebook 페이지 액세스 토큰이 설정되지 않았습니다.' ];
        }

        if ( $image_url ) {
            return $this->post_with_photo( $content, $image_url, $post_url );
        }
        return $this->post_text( $content, $post_url );
    }

    private function post_with_photo( $content, $image_url, $post_url ) {
        $url  = "https://graph.facebook.com/v20.0/{$this->page_id}/photos";
        $body = [
            'url'          => $image_url,
            'caption'      => $content . "\n\n" . $post_url,
            'access_token' => $this->page_access_token,
        ];

        $response = wp_remote_post( $url, [ 'body' => $body, 'timeout' => 30 ] );
        return $this->parse_response( $response, 'Facebook' );
    }

    private function post_text( $content, $post_url ) {
        $url  = "https://graph.facebook.com/v20.0/{$this->page_id}/feed";
        $body = [
            'message'      => $content,
            'link'         => $post_url,
            'access_token' => $this->page_access_token,
        ];

        $response = wp_remote_post( $url, [ 'body' => $body, 'timeout' => 30 ] );
        return $this->parse_response( $response, 'Facebook' );
    }

    private function parse_response( $response, $platform ) {
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['id'] ) ) {
            return [ 'success' => true, 'message' => "{$platform} 게시 완료 (ID: {$body['id']})" ];
        }
        $error = $body['error']['message'] ?? '알 수 없는 오류';
        return [ 'success' => false, 'message' => "{$platform} 오류 ({$code}): {$error}" ];
    }
}
