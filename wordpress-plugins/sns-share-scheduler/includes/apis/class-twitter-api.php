<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Twitter_API {

    private $api_key;
    private $api_secret;
    private $access_token;
    private $access_secret;

    public function __construct( $options ) {
        $this->api_key      = $options['twitter_api_key'] ?? '';
        $this->api_secret   = $options['twitter_api_secret'] ?? '';
        $this->access_token = $options['twitter_access_token'] ?? '';
        $this->access_secret = $options['twitter_access_secret'] ?? '';
    }

    public function post( $content, $image_url = '', $post_url = '' ) {
        if ( ! $this->api_key || ! $this->access_token ) {
            return [ 'success' => false, 'message' => 'X(Twitter) API 키가 설정되지 않았습니다.' ];
        }

        $media_id = null;
        if ( $image_url ) {
            $media_id = $this->upload_image( $image_url );
        }

        $body = [ 'text' => $content ];
        if ( $media_id ) {
            $body['media'] = [ 'media_ids' => [ $media_id ] ];
        }

        $url      = 'https://api.twitter.com/2/tweets';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => $this->build_oauth_header( 'POST', $url ),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        return $this->parse_response( $response, 'data.id' );
    }

    private function upload_image( $image_url ) {
        $image_data = wp_remote_get( $image_url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $image_data ) ) return null;

        $media_url  = 'https://upload.twitter.com/1.1/media/upload.json';
        $body_params = [ 'media_data' => base64_encode( wp_remote_retrieve_body( $image_data ) ) ];
        $response    = wp_remote_post( $media_url, [
            'headers' => [
                // Form-encoded body params must be included in the OAuth base string.
                'Authorization' => $this->build_oauth_header( 'POST', $media_url, $body_params ),
            ],
            'body'    => $body_params,
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['media_id_string'] ?? null;
    }

    private function build_oauth_header( $method, $url, $params = [] ) {
        $oauth = [
            'oauth_consumer_key'     => $this->api_key,
            'oauth_nonce'            => bin2hex( random_bytes( 16 ) ),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_token'            => $this->access_token,
            'oauth_version'          => '1.0',
        ];

        $base_params = array_merge( $oauth, $params );
        ksort( $base_params );
        $param_string = http_build_query( $base_params );

        $base_string = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );
        $signing_key = rawurlencode( $this->api_secret ) . '&' . rawurlencode( $this->access_secret );
        $oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

        $parts = [];
        foreach ( $oauth as $k => $v ) {
            $parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
        }
        return 'OAuth ' . implode( ', ', $parts );
    }

    private function parse_response( $response, $success_key = '' ) {
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            return [ 'success' => true, 'message' => 'X 게시 완료 (ID: ' . ( $body['data']['id'] ?? '' ) . ')' ];
        }
        $error = $body['detail'] ?? $body['errors'][0]['message'] ?? '알 수 없는 오류';
        return [ 'success' => false, 'message' => "X 오류 ({$code}): {$error}" ];
    }
}
