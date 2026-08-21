<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Ajax {

    public function init() {
        $actions = [
            'scan', 'convert_webp', 'fix_thumbnails',
            'schedule', 'get_counts',
            'get_stats', 'get_no_thumb_posts', 'get_nonwebp', 'delete_images', 'scan_unused',
            'get_broken_img_posts', 'get_h2_no_img_posts', 'crop_image', 'auto_set_thumb',
            'get_upload_settings', 'save_upload_settings',
            'scan_broken_attachments', 'remove_broken_blocks',
            'fill_thumbnails', 'fix_og_images',
        ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_dim_{$a}", [ $this, "handle_{$a}" ] );
        }
    }

    public function handle_scan() {
        $this->auth();
        $scanner = new DIM_Scanner();
        wp_send_json_success( $scanner->scan_duplicates(
            absint( $_POST['batch']  ?? 100 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_convert_webp() {
        $this->auth();
        $converter = new DIM_Converter();

        // can_convert()는 실제 파일 쓰기 테스트를 하므로 5분 캐싱
        $cap = get_transient( 'dim_can_webp' );
        if ( $cap === false ) {
            $cap = $converter->can_convert() ? '1' : '0';
            set_transient( 'dim_can_webp', $cap, 5 * MINUTE_IN_SECONDS );
        }
        if ( $cap !== '1' ) {
            wp_send_json_error( [ 'message' => '이 서버는 WebP 변환을 지원하지 않습니다 (GD/Imagick 필요).' ] );
        }

        // 단건 변환 (JS convertSelected에서 single_id로 호출)
        $single_id = absint( $_POST['single_id'] ?? 0 );
        if ( $single_id ) {
            $r = $converter->convert_to_webp( $single_id );
            if ( $r === true ) {
                wp_send_json_success( [ 'converted' => 1, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 0 ] );
            } elseif ( $r === 'unlink' ) {
                wp_send_json_success( [ 'converted' => 1, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 1 ] );
            } elseif ( $r === 'skip' ) {
                wp_send_json_success( [ 'converted' => 0, 'skipped' => 1, 'errors' => [], 'unlink_failed' => 0 ] );
            } else {
                wp_send_json_success( [ 'converted' => 0, 'skipped' => 0, 'errors' => [ "ID {$single_id}: {$r}" ], 'unlink_failed' => 0 ] );
            }
            return;
        }

        // 배치 변환 (전체 변환 버튼, 50개씩)
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( $converter->convert_all(
            absint( $_POST['batch']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_fix_thumbnails() {
        $this->auth();
        wp_send_json_success( ( new DIM_Thumbnail() )->fix_all() );
    }

    public function handle_get_upload_settings() {
        $this->auth();
        wp_send_json_success( [
            'enabled' => (bool) get_option( 'dim_upload_rename_enabled', false ),
            'prefix'  => get_option( 'dim_upload_prefix', '' ),
            'counter' => (int) get_option( 'dim_upload_counter', 1 ),
        ] );
    }

    public function handle_save_upload_settings() {
        $this->auth();
        $enabled = filter_var( $_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $prefix  = sanitize_file_name( $_POST['prefix'] ?? '' );
        $counter = max( 1, absint( $_POST['counter'] ?? 1 ) );

        if ( $enabled && empty( $prefix ) ) {
            wp_send_json_error( [ 'message' => '접두사를 입력하세요.' ] );
        }

        update_option( 'dim_upload_rename_enabled', $enabled );
        update_option( 'dim_upload_prefix',         $prefix );
        update_option( 'dim_upload_counter',        $counter );

        wp_send_json_success( [
            'enabled' => $enabled,
            'prefix'  => $prefix,
            'counter' => $counter,
        ] );
    }

    public function handle_fix_og_images() {
        $this->auth();
        @set_time_limit( 120 );
        wp_send_json_success( ( new DIM_Thumbnail() )->fix_og_images() );
    }

    public function handle_fill_thumbnails() {
        $this->auth();
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( ( new DIM_Thumbnail() )->fill_all_thumbnails() );
    }

    public function handle_auto_set_thumb() {
        $this->auth();
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( [ 'message' => '잘못된 post_id' ] );
        $result = ( new DIM_Thumbnail() )->auto_set_from_content( $post_id );
        if ( $result['set'] ) {
            $url = wp_get_attachment_image_url( $result['attachment_id'], 'thumbnail' );
            wp_send_json_success( [ 'attachment_id' => $result['attachment_id'], 'url' => $url ] );
        } else {
            wp_send_json_error( [ 'message' => '설정할 유효한 이미지를 찾지 못했습니다.' ] );
        }
    }

    public function handle_schedule() {
        $this->auth();
        $on = filter_var( $_POST['enable'] ?? true, FILTER_VALIDATE_BOOLEAN );
        if ( $on ) {
            wp_clear_scheduled_hook( 'dim_optimize_cron' ); // 기존 예약 초기화 후 재등록
            wp_schedule_event( self::next_3am(), 'daily', 'dim_optimize_cron' );
            wp_send_json_success( [ 'message' => '매일 새벽 3시에 자동 최적화가 실행됩니다.' ] );
        } else {
            wp_clear_scheduled_hook( 'dim_optimize_cron' );
            wp_send_json_success( [ 'message' => '자동 최적화 예약이 해제되었습니다.' ] );
        }
    }

    /**
     * 다음 새벽 3시 타임스탬프 (WordPress 설정 시간대 기준)
     */
    private static function next_3am(): int {
        // current_time('timestamp')는 WordPress 시간대 기준 로컬 시각
        $now    = current_time( 'timestamp' );
        $target = mktime( 3, 0, 0, (int) wp_date( 'n', $now ), (int) wp_date( 'j', $now ), (int) wp_date( 'Y', $now ) );
        if ( $target <= $now ) {
            $target = strtotime( '+1 day', $target );
        }
        return $target;
    }

    public function handle_get_stats() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_storage_stats() );
    }

    public function handle_get_no_thumb_posts() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_posts_without_thumbnail(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_get_nonwebp() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_nonwebp_images(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_scan_unused() {
        $this->auth();
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( ( new DIM_Stats() )->get_unused_images(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_get_broken_img_posts() {
        $this->auth();
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( ( new DIM_Stats() )->get_posts_with_broken_images(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_get_h2_no_img_posts() {
        $this->auth();
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( ( new DIM_Stats() )->get_posts_h2_without_image(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_delete_images() {
        $this->auth();
        $ids = array_values( array_filter( array_map( 'absint', (array)( $_POST['ids'] ?? [] ) ) ) );
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => '삭제할 이미지가 없습니다.' ] );
        wp_send_json_success( ( new DIM_Stats() )->delete_attachments( $ids ) );
    }

    public function handle_get_counts() {
        $this->auth();
        global $wpdb;

        $total_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
        $total_nonwebp = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        wp_send_json_success( [
            'total_images'  => $total_images,
            'total_nonwebp' => $total_nonwebp,
        ] );
    }

    public function handle_crop_image() {
        $this->auth();
        @set_time_limit( 60 );

        $id = absint( $_POST['id'] ?? 0 );
        $x  = max( 0, (int)( $_POST['x'] ?? 0 ) );
        $y  = max( 0, (int)( $_POST['y'] ?? 0 ) );
        $w  = max( 1, (int)( $_POST['w'] ?? 0 ) );
        $h  = max( 1, (int)( $_POST['h'] ?? 0 ) );

        if ( ! $id ) wp_send_json_error( [ 'message' => '잘못된 ID' ] );

        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            wp_send_json_error( [ 'message' => '파일을 찾을 수 없습니다: ' . basename( $file ) ] );
        }

        $mime   = get_post_mime_type( $id );
        $result = $this->do_crop( $file, $mime, $x, $y, $w, $h );
        if ( $result !== true ) {
            wp_send_json_error( [ 'message' => $result ] );
        }

        // 메타데이터(썸네일 포함) 재생성
        $meta = wp_generate_attachment_metadata( $id, $file );
        wp_update_attachment_metadata( $id, $meta );

        // 브라우저 캐시 무력화를 위해 URL에 버전 파라미터 추가
        $url = add_query_arg( 'v', time(), wp_get_attachment_url( $id ) );

        wp_send_json_success( [ 'message' => '자르기 완료', 'url' => $url, 'w' => $w, 'h' => $h ] );
    }

    private function do_crop( string $file, string $mime, int $x, int $y, int $w, int $h ) {
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $img = new Imagick( $file );
                $iw  = $img->getImageWidth();
                $ih  = $img->getImageHeight();
                $x   = min( $x, $iw - 1 );
                $y   = min( $y, $ih - 1 );
                $w   = min( $w, $iw - $x );
                $h   = min( $h, $ih - $y );
                $img->cropImage( $w, $h, $x, $y );
                $img->setImagePage( $w, $h, 0, 0 );
                $img->writeImage( $file );
                $img->destroy();
                return true;
            } catch ( \Throwable $e ) {}
        }

        if ( $mime === 'image/jpeg' )      $src = @imagecreatefromjpeg( $file );
        elseif ( $mime === 'image/png' )   $src = @imagecreatefrompng( $file );
        elseif ( $mime === 'image/webp' )  $src = @imagecreatefromwebp( $file );
        elseif ( $mime === 'image/gif' )   $src = @imagecreatefromgif( $file );
        else return '지원하지 않는 형식: ' . $mime;

        if ( ! $src ) return '이미지 로드 실패';

        $iw = imagesx( $src );
        $ih = imagesy( $src );
        $x  = min( $x, $iw - 1 );
        $y  = min( $y, $ih - 1 );
        $w  = min( $w, $iw - $x );
        $h  = min( $h, $ih - $y );

        $dst = imagecreatetruecolor( $w, $h );
        if ( in_array( $mime, [ 'image/png', 'image/webp', 'image/gif' ] ) ) {
            imagealphablending( $dst, false );
            imagesavealpha( $dst, true );
            imagefill( $dst, 0, 0, imagecolorallocatealpha( $dst, 0, 0, 0, 127 ) );
        }
        imagecopy( $dst, $src, 0, 0, $x, $y, $w, $h );
        imagedestroy( $src );

        if ( $mime === 'image/jpeg' )     $ok = @imagejpeg( $dst, $file, 90 );
        elseif ( $mime === 'image/png' )  $ok = @imagepng( $dst, $file );
        elseif ( $mime === 'image/webp' ) $ok = @imagewebp( $dst, $file, 82 );
        elseif ( $mime === 'image/gif' )  $ok = @imagegif( $dst, $file );
        else                              $ok = false;
        imagedestroy( $dst );

        return $ok ? true : '파일 저장 실패';
    }

    public function handle_scan_broken_attachments() {
        $this->auth();
        @set_time_limit( 120 );
        wp_send_json_success( ( new DIM_Stats() )->scan_broken_attachments(
            absint( $_POST['batch']  ?? 100 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_remove_broken_blocks() {
        $this->auth();
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '256M' );
        wp_send_json_success( ( new DIM_Stats() )->remove_broken_image_blocks() );
    }

    private function auth() {
        if ( ! check_ajax_referer( 'dim_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '권한 없음' ], 403 );
        }
    }
}
