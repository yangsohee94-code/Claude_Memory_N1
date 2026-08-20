<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Converter {

    public function can_convert() {
        return function_exists( 'imagewebp' ) || extension_loaded( 'imagick' );
    }

    /**
     * 전체 이미지 WebP 변환 (배치 처리)
     * @return array { converted, skipped, errors }
     */
    public function convert_all( $batch = 50, $offset = 0 ) {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            $batch, $offset
        ) );

        $result = [ 'converted' => 0, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 0, 'has_more' => count( $ids ) === $batch ];

        foreach ( $ids as $id ) {
            $r = $this->convert_to_webp( (int) $id );
            if ( $r === true )            $result['converted']++;
            elseif ( $r === 'skip' )      $result['skipped']++;
            elseif ( $r === 'unlink' )  { $result['converted']++; $result['unlink_failed']++; }
            else                          $result['errors'][] = "ID {$id}: {$r}";
        }

        return $result;
    }

    /**
     * 단일 첨부파일 → WebP 변환
     * @return true|'skip'|string(오류)
     */
    public function convert_to_webp( int $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) return 'skip';

        $mime = get_post_mime_type( $id );
        if ( $mime === 'image/webp' ) return 'skip';

        $webp_file = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $file );
        if ( $webp_file === $file ) return 'skip';

        // 이미 변환된 파일이 있으면 스킵
        if ( file_exists( $webp_file ) ) return 'skip';

        $ok = $this->do_convert( $file, $webp_file, $mime );
        if ( $ok !== true ) return $ok;

        $old_url  = wp_get_attachment_url( $id );
        $new_url  = str_replace( basename( $file ), basename( $webp_file ), $old_url );
        $upload   = wp_upload_dir();
        $rel_path = ltrim( str_replace( trailingslashit( $upload['basedir'] ), '', $webp_file ), '/' );

        // WordPress 메타 업데이트
        update_attached_file( $id, $webp_file );
        wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/webp' ] );

        $meta = wp_get_attachment_metadata( $id );
        if ( $meta ) {
            $meta['file'] = $rel_path;
            wp_update_attachment_metadata( $id, $meta );
        }

        // 본문 URL 교체
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
             WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like( basename( $file ) ) . '%'
        ) );

        // 원본 삭제 — 실패해도 변환 자체는 성공으로 처리 (별도 카운터)
        if ( ! @unlink( $file ) ) return 'unlink';

        return true;
    }

    private function do_convert( string $src, string $dest, string $mime ) {
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $img = new Imagick( $src );
                $img->setImageFormat( 'webp' );
                $img->setImageCompressionQuality( 82 );
                $img->writeImage( $dest );
                $img->destroy();
                return true;
            } catch ( Exception $e ) {
                // fall through to GD
            }
        }

        if ( ! function_exists( 'imagewebp' ) ) return 'WebP 미지원 서버';

        if ( $mime === 'image/jpeg' ) {
            $img = @imagecreatefromjpeg( $src );
        } elseif ( $mime === 'image/png' ) {
            $img = @imagecreatefrompng( $src );
        } elseif ( $mime === 'image/gif' ) {
            $img = @imagecreatefromgif( $src );
        } else {
            $img = false;
        }
        if ( ! $img ) return '이미지 로드 실패';

        $ok = imagewebp( $img, $dest, 82 );
        imagedestroy( $img );
        return $ok ? true : '변환 실패';
    }
}
