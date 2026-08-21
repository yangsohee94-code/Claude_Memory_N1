<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Stats {

    /**
     * 이미지 용량 통계
     */
    public function get_storage_stats() {
        global $wpdb;

        // N+1 방지: ID·MIME·파일경로를 단일 JOIN 으로 한꺼번에 가져옴
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, m.meta_value AS rel_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m
                   ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'"
        );

        $upload_base = wp_upload_dir()['basedir'];

        $stats = [
            'total_count' => 0, 'total_size' => 0,
            'webp_count'  => 0, 'webp_size'  => 0,
            'other_count' => 0, 'other_size' => 0,
            'by_type'     => [],
        ];

        foreach ( $rows as $row ) {
            if ( ! $row->rel_path ) continue;
            $file = trailingslashit( $upload_base ) . $row->rel_path;
            if ( ! file_exists( $file ) ) continue;
            $size = (int) filesize( $file );
            $type = str_replace( 'image/', '', $row->post_mime_type );

            $stats['total_count']++;
            $stats['total_size'] += $size;

            if ( $row->post_mime_type === 'image/webp' ) {
                $stats['webp_count']++;
                $stats['webp_size'] += $size;
            } else {
                $stats['other_count']++;
                $stats['other_size'] += $size;
            }

            if ( ! isset( $stats['by_type'][ $type ] ) ) {
                $stats['by_type'][ $type ] = [ 'count' => 0, 'size' => 0 ];
            }
            $stats['by_type'][ $type ]['count']++;
            $stats['by_type'][ $type ]['size'] += $size;
        }

        arsort( $stats['by_type'] );
        return $stats;
    }

    /**
     * 대표이미지 없는 발행된 글/페이지
     */
    public function get_posts_without_thumbnail( $limit = 50, $offset = 0 ) {
        global $wpdb;

        // meta_value > 0 조건: 0/-1 등 잘못된 썸네일 ID가 남아있는 글도 목록에 포함
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_date, p.post_content
             FROM {$wpdb->posts} p
             WHERE p.post_type IN ('post','page')
               AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_thumbnail_id'
                   AND meta_value > 0
               )
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_thumbnail_id'
                   AND meta_value > 0
               )"
        );

        // 글당 이미지 수 집계: Gutenberg 블록 수 + post_parent 첨부 수의 최대값
        $post_ids = array_column( $rows, 'ID' );
        $att_counts = [];
        if ( $post_ids ) {
            $ph    = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $a_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_parent, COUNT(*) AS cnt
                     FROM {$wpdb->posts}
                     WHERE post_type = 'attachment'
                       AND post_mime_type LIKE 'image/%%'
                       AND post_parent IN ({$ph})
                     GROUP BY post_parent",
                    ...$post_ids
                )
            );
            foreach ( $a_rows as $ar ) {
                $att_counts[ (int) $ar->post_parent ] = (int) $ar->cnt;
            }
        }

        $items = [];
        foreach ( $rows as $row ) {
            $block_count = $row->post_content
                ? (int) preg_match_all( '/<!-- wp:image/', $row->post_content )
                : 0;
            $att_count   = $att_counts[ (int) $row->ID ] ?? 0;
            $items[] = [
                'ID'          => $row->ID,
                'post_title'  => $row->post_title,
                'post_type'   => $row->post_type,
                'post_date'   => $row->post_date,
                'image_count' => max( $block_count, $att_count ),
            ];
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'has_more' => count( $rows ) === $limit,
        ];
    }

    /**
     * WebP가 아닌 이미지 목록
     */
    public function get_nonwebp_images( $limit = 50, $offset = 0 ) {
        global $wpdb;

        // N+1 방지: 파일 경로·썸네일 URL을 JOIN 으로 한 번에 가져옴
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date, p.guid,
                    m.meta_value AS rel_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m
                   ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
             ORDER BY p.ID DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $upload_base = wp_upload_dir()['basedir'];
        $upload_url  = wp_upload_dir()['baseurl'];

        $items = [];
        foreach ( $rows as $row ) {
            $abs       = $row->rel_path ? trailingslashit( $upload_base ) . $row->rel_path : '';
            $file_size = ( $abs && file_exists( $abs ) ) ? (int) filesize( $abs ) : 0;
            $thumb_url = $row->rel_path
                ? trailingslashit( $upload_url ) . $row->rel_path
                : $row->guid;
            $items[] = [
                'id'        => (int) $row->ID,
                'title'     => $row->post_title,
                'type'      => str_replace( 'image/', '', $row->post_mime_type ),
                'url'       => $thumb_url,
                'file_size' => $file_size,
                'date'      => $row->post_date,
                'edit_url'  => get_edit_post_link( $row->ID, 'raw' ),
            ];
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        return [ 'items' => $items, 'total' => $total, 'has_more' => count( $rows ) === $limit ];
    }

    /**
     * 미사용 이미지 목록
     * 사용 기준: ① _thumbnail_id ② post_parent(기존 글) ③ post_content URL ④ Gutenberg 블록 ID
     */
    public function get_unused_images( $limit = 50, $offset = 0 ) {
        global $wpdb;

        // ① 썸네일로 쓰이는 ID 목록
        $thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT CAST(meta_value AS UNSIGNED)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value REGEXP '^[0-9]+$'"
        );
        $thumb_in = empty( $thumb_ids ) ? '0' : implode( ',', array_map( 'absint', $thumb_ids ) );

        // ② 후보: 썸네일 아님 + (post_parent=0 또는 부모 글이 삭제/휴지통)
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%%'
               AND p.ID NOT IN ({$thumb_in})
               AND (
                   p.post_parent = 0
                   OR NOT EXISTS (
                       SELECT 1 FROM {$wpdb->posts} pp
                       WHERE pp.ID = p.post_parent
                         AND pp.post_status NOT IN ('trash','auto-draft')
                   )
               )
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $candidates ) ) {
            return [ 'items' => [], 'total' => 0, 'total_size' => 0, 'has_more' => false ];
        }

        // ③ 본문 파일명·Gutenberg ID 캐시 (5분)
        $content_data   = $this->build_content_index();
        $used_filenames = $content_data['filenames'];
        $used_ids       = $content_data['ids'];

        $items = [];
        foreach ( $candidates as $row ) {
            $id = (int) $row->ID;
            if ( isset( $used_ids[ $id ] ) ) continue;

            $file = get_attached_file( $id );
            if ( ! $file ) continue;
            if ( isset( $used_filenames[ basename( $file ) ] ) ) continue;

            $items[] = [
                'id'        => $id,
                'title'     => $row->post_title,
                'type'      => str_replace( 'image/', '', $row->post_mime_type ),
                'url'       => wp_get_attachment_image_url( $id, 'thumbnail' ) ?: wp_get_attachment_url( $id ),
                'file_size' => ( file_exists( $file ) ) ? (int) filesize( $file ) : 0,
                'date'      => $row->post_date,
                'edit_url'  => get_edit_post_link( $id, 'raw' ),
            ];
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%%'
               AND p.ID NOT IN ({$thumb_in})
               AND (
                   p.post_parent = 0
                   OR NOT EXISTS (
                       SELECT 1 FROM {$wpdb->posts} pp
                       WHERE pp.ID = p.post_parent
                         AND pp.post_status NOT IN ('trash','auto-draft')
                   )
               )"
        );

        $total_size = array_sum( array_column( $items, 'file_size' ) );

        return [
            'items'      => $items,
            'total'      => $total,
            'total_size' => $total_size,
            'has_more'   => ( $offset + $limit ) < $total,
        ];
    }

    /**
     * 본문에서 이미지 파일명·Gutenberg 블록 ID 인덱스 빌드 (5분 캐시)
     */
    private function build_content_index() {
        $cached = get_transient( 'dim_content_index_v2' );
        if ( $cached !== false ) return $cached;

        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','private','inherit','future','pending')
               AND (post_content LIKE '%/uploads/%' OR post_content LIKE '%\"id\":%')"
        );

        $filenames = [];
        $ids       = [];
        foreach ( $rows as $content ) {
            // URL 기반 (클래식 에디터 / src 속성)
            preg_match_all( '/\/uploads\/[^"\'>\s\)]+\.(jpe?g|png|gif|webp)/i', $content, $um );
            foreach ( $um[0] as $path ) $filenames[ basename( $path ) ] = true;

            // Gutenberg 블록 이미지 ID ("id":123)
            preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $im );
            foreach ( $im[1] as $bid ) $ids[ (int) $bid ] = true;
        }

        $result = [ 'filenames' => $filenames, 'ids' => $ids ];
        set_transient( 'dim_content_index_v2', $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * 본문에 오류 이미지(파일 없음/첨부 삭제됨)가 있는 발행된 글 목록
     * Gutenberg 블록 "id":N 과 클래식 에디터 /uploads/ URL 둘 다 감지
     */
    public function get_posts_with_broken_images( $limit = 50, $offset = 0 ) {
        global $wpdb;

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_type, post_date, post_content
             FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND (post_content LIKE '%%\"id\":%%' OR post_content LIKE '%%/uploads/%%')
             ORDER BY post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $posts ) ) {
            return [ 'items' => [], 'total_scanned' => 0, 'has_more' => false ];
        }

        // 배치 내 모든 참조 이미지 ID 수집
        $att_to_posts = []; // att_id => [post_id, ...]
        foreach ( $posts as $post ) {
            preg_match_all( '/"id"\s*:\s*(\d+)/', $post->post_content, $m );
            foreach ( $m[1] as $att_id ) {
                $att_to_posts[ (int) $att_id ][] = $post->ID;
            }
        }

        $broken_post_ids  = [];
        $broken_post_imgs = []; // post_id => [att_id, ...]
        $att_names        = []; // att_id  => filename

        if ( ! empty( $att_to_posts ) ) {
            $all_ids      = array_keys( $att_to_posts );
            $placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );

            // 단일 JOIN 쿼리로 ID 존재 여부 + 파일 경로 동시 조회
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, m.meta_value AS rel_path
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m
                       ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
                 WHERE p.ID IN ({$placeholders})
                   AND p.post_type = 'attachment'
                   AND p.post_mime_type LIKE 'image/%%'",
                ...$all_ids
            ) );

            $found_ids   = [];
            $upload_base = wp_upload_dir()['basedir'];
            foreach ( $rows as $row ) {
                $att_id = (int) $row->ID;
                $found_ids[ $att_id ] = true;
                if ( $row->rel_path ) {
                    $att_names[ $att_id ] = basename( $row->rel_path );
                }
                $abs = $row->rel_path ? trailingslashit( $upload_base ) . $row->rel_path : '';
                if ( ! $abs || ! file_exists( $abs ) ) {
                    foreach ( $att_to_posts[ $att_id ] ?? [] as $post_id ) {
                        $broken_post_ids[ $post_id ]    = true;
                        $broken_post_imgs[ $post_id ][] = $att_id;
                    }
                }
            }

            // DB에 아예 없는 ID → 즉시 오류
            foreach ( $all_ids as $aid ) {
                if ( ! isset( $found_ids[ $aid ] ) ) {
                    foreach ( $att_to_posts[ $aid ] ?? [] as $post_id ) {
                        $broken_post_ids[ $post_id ]    = true;
                        $broken_post_imgs[ $post_id ][] = $aid;
                    }
                }
            }

            // 이름 미확보 broken ID 보완: ① _wp_attached_file 재조회 ② guid/post_title
            $unnamed = [];
            foreach ( $broken_post_imgs as $aids ) {
                foreach ( $aids as $aid ) {
                    if ( ! isset( $att_names[ $aid ] ) ) $unnamed[ $aid ] = true;
                }
            }
            if ( ! empty( $unnamed ) ) {
                $u_ids = array_keys( $unnamed );
                $u_phs = implode( ',', array_fill( 0, count( $u_ids ), '%d' ) );

                // ① postmeta 직접 조회 (mime 필터 없이)
                $meta_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE post_id IN ({$u_phs}) AND meta_key = '_wp_attached_file'",
                    ...$u_ids
                ) );
                foreach ( $meta_rows as $mr ) {
                    if ( $mr->meta_value ) {
                        $att_names[ (int) $mr->post_id ] = basename( $mr->meta_value );
                    }
                }

                // ② 여전히 미확보 → guid(URL) 또는 post_title 사용
                $still = array_values( array_filter( $u_ids, fn( $id ) => ! isset( $att_names[ $id ] ) ) );
                if ( ! empty( $still ) ) {
                    $s_phs  = implode( ',', array_fill( 0, count( $still ), '%d' ) );
                    $p_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT ID, post_title, guid FROM {$wpdb->posts} WHERE ID IN ({$s_phs})",
                        ...$still
                    ) );
                    foreach ( $p_rows as $r ) {
                        $aid = (int) $r->ID;
                        $fn  = $r->guid ? basename( (string) parse_url( $r->guid, PHP_URL_PATH ) ) : '';
                        $att_names[ $aid ] = $fn ?: ( $r->post_title ?: '' );
                    }
                }
            }
        }

        $items = [];
        foreach ( $posts as $post ) {
            if ( isset( $broken_post_ids[ $post->ID ] ) ) {
                $raw_ids    = array_values( array_unique( $broken_post_imgs[ $post->ID ] ?? [] ) );
                $broken_ids = array_map( function( $id ) use ( $att_names ) {
                    return [
                        'id'   => $id,
                        'name' => $att_names[ $id ] ?? '',
                    ];
                }, $raw_ids );
                $items[] = [
                    'id'         => (int) $post->ID,
                    'title'      => $post->post_title,
                    'type'       => $post->post_type,
                    'date'       => $post->post_date,
                    'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
                    'broken_ids' => $broken_ids,
                ];
            }
        }

        $total_with_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND (post_content LIKE '%\"id\":%' OR post_content LIKE '%/uploads/%')"
        );

        return [
            'items'         => $items,
            'total_scanned' => count( $posts ),
            'has_more'      => ( $offset + $limit ) < $total_with_images,
        ];
    }

    /**
     * H2 바로 다음 블록이 이미지가 아닌 글 목록
     * Gutenberg: wp:heading level:2 뒤에 wp:image/gallery/cover/media-text 없으면 플래그
     * 클래식 에디터: <h2> 뒤 300자 안에 <img>/<figure> 없으면 플래그
     */
    public function get_posts_h2_without_image( $limit = 50, $offset = 0 ) {
        global $wpdb;

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_type, post_date, post_content
             FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND post_content LIKE '%%<h2%%'
             ORDER BY post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $items = [];
        foreach ( $posts as $post ) {
            if ( $this->has_h2_without_image( $post->post_content ) ) {
                $items[] = [
                    'id'       => (int) $post->ID,
                    'title'    => $post->post_title,
                    'type'     => $post->post_type,
                    'date'     => $post->post_date,
                    'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
                ];
            }
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND post_content LIKE '%<h2%'"
        );

        return [
            'items'         => $items,
            'total_scanned' => count( $posts ),
            'has_more'      => ( $offset + $limit ) < $total,
        ];
    }

    /**
     * post_content 안에 "H2 다음 이미지 없음" 패턴이 있는지 확인
     */
    private function has_h2_without_image( $content ) {
        // Gutenberg 블록 방식
        if ( strpos( $content, '<!-- wp:heading' ) !== false ) {
            // 블록 단위로 분리
            $blocks = preg_split( '/(?=<!-- wp:)/', $content );
            $n      = count( $blocks );
            for ( $i = 0; $i < $n; $i++ ) {
                // "level":2 는 기본값이라 생략되는 경우가 대부분 → 내부 HTML의 <h2 로 판단
                if ( ! preg_match( '/<!-- wp:heading/', $blocks[ $i ] )
                     || ! preg_match( '/<h2[\s>]/i', $blocks[ $i ] ) ) continue;
                // 다음 비어있지 않은 블록 찾기
                $next = '';
                for ( $j = $i + 1; $j < $n; $j++ ) {
                    $next = trim( $blocks[ $j ] );
                    if ( $next !== '' ) break;
                }
                // 이미지 계열 블록이 아니면 오류
                if ( ! preg_match( '/^<!-- wp:(image|gallery|media-text|cover)\b/', $next ) ) {
                    return true;
                }
            }
            return false;
        }

        // 클래식 에디터: <h2> 뒤 300자 안에 <img> 또는 <figure> 없으면 플래그
        preg_match_all( '/<h2[^>]*>[\s\S]*?<\/h2>([\s\S]{0,300})/i', $content, $m );
        foreach ( $m[1] as $after ) {
            if ( ! preg_match( '/<(img|figure)\b/i', $after ) ) return true;
        }
        return false;
    }

    /**
     * 미디어 라이브러리에서 파일이 없는 엑박 attachment 레코드 스캔
     * 배치 단위로 호출 — has_more가 false가 될 때까지 반복
     */
    public function scan_broken_attachments( $batch = 100, $offset = 0 ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.guid,
                    m.meta_value AS rel_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m
                   ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%%'
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            $batch, $offset
        ) );

        if ( empty( $rows ) ) {
            return [ 'items' => [], 'scanned' => 0, 'has_more' => false ];
        }

        $upload_base = wp_upload_dir()['basedir'];
        $items = [];
        foreach ( $rows as $row ) {
            $abs = $row->rel_path
                ? trailingslashit( $upload_base ) . $row->rel_path
                : '';
            if ( $abs && file_exists( $abs ) ) continue;

            $name = $row->rel_path
                ? basename( $row->rel_path )
                : ( $row->guid ? basename( (string) parse_url( $row->guid, PHP_URL_PATH ) ) : '' );

            $items[] = [
                'id'   => (int) $row->ID,
                'name' => $name ?: $row->post_title,
                'date' => substr( $row->post_date, 0, 10 ),
            ];
        }

        return [
            'items'    => $items,
            'scanned'  => count( $rows ),
            'has_more' => count( $rows ) === $batch,
        ];
    }

    /**
     * 글 본문의 엑박 Gutenberg 이미지 블록 자동 제거
     * — 파일이 없는 attachment ID를 참조하는 <!-- wp:image {"id":NNN} --> 블록을 삭제
     */
    public function remove_broken_image_blocks() {
        global $wpdb;

        $upload_base    = wp_upload_dir()['basedir'];
        $posts_updated  = 0;
        $blocks_removed = 0;
        $start_time     = microtime( true );

        // ① 대상 post ID 목록을 먼저 수집 (내용 변경으로 LIKE 조건이 바뀌어도 OFFSET이 흔들리지 않음)
        $post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post','page')
               AND post_content LIKE '%<!-- wp:image%'
             ORDER BY ID ASC"
        );
        if ( empty( $post_ids ) ) {
            return [ 'posts_updated' => 0, 'blocks_removed' => 0, 'truncated' => false ];
        }

        // ② 안정적인 ID 기반 배치 처리 (50개씩)
        // 부정형 전방탐색 (?!-->) — Gutenberg 블록 오프너는 한 줄이므로 JSON 중첩 깊이 무관하게 매칭
        $block_pattern = '/<!-- wp:image ((?:(?!-->).)+?)\s*\/?\s*-->.*?<!-- \/wp:image -->/s';

        foreach ( array_chunk( $post_ids, 50 ) as $chunk ) {
            // 300초 제한 중 260초 경과 시 안전하게 중단하고 부분 결과 반환
            if ( microtime( true ) - $start_time > 260 ) {
                return [
                    'posts_updated'  => $posts_updated,
                    'blocks_removed' => $blocks_removed,
                    'truncated'      => true,
                ];
            }

            $ph    = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $posts = $wpdb->get_results(
                $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$ph})", ...$chunk )
            );
            if ( empty( $posts ) ) continue;

            // ③ 이 배치에서 참조된 이미지 ID만 추출 (wp:image 오프너 내부만)
            $all_ids = [];
            foreach ( $posts as $p ) {
                if ( preg_match_all( '/<!-- wp:image (?:(?!-->).)*?"id":(\d+)/i', $p->post_content, $m ) ) {
                    foreach ( $m[1] as $id ) $all_ids[] = (int) $id;
                }
            }

            $valid_ids = [];
            if ( ! empty( $all_ids ) ) {
                $all_ids = array_unique( $all_ids );
                $iph     = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.ID, m.meta_value AS rel_path
                         FROM {$wpdb->posts} p
                         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
                         WHERE p.ID IN ({$iph}) AND p.post_type = 'attachment'",
                        ...$all_ids
                    )
                );
                foreach ( $rows as $row ) {
                    $abs = $row->rel_path ? trailingslashit( $upload_base ) . $row->rel_path : '';
                    if ( $abs && file_exists( $abs ) ) {
                        $valid_ids[ (int) $row->ID ] = true;
                    }
                }
            }

            // ④ 각 글에서 엑박 블록 제거
            foreach ( $posts as $p ) {
                $removed     = 0;
                $new_content = preg_replace_callback(
                    $block_pattern,
                    function ( $match ) use ( $valid_ids, &$removed ) {
                        $attrs = json_decode( $match[1], true );
                        $id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
                        if ( $id && ! isset( $valid_ids[ $id ] ) ) {
                            $removed++;
                            return '';
                        }
                        return $match[0];
                    },
                    $p->post_content
                );

                if ( $new_content === null || $removed === 0 ) continue;

                // $wpdb->update + 명시적 post_modified 갱신 → save_post 훅 미발동
                $now     = current_time( 'mysql' );
                $now_gmt = current_time( 'mysql', 1 );
                $wpdb->update(
                    $wpdb->posts,
                    [
                        'post_content'      => $new_content,
                        'post_modified'     => $now,
                        'post_modified_gmt' => $now_gmt,
                    ],
                    [ 'ID' => $p->ID ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                clean_post_cache( $p->ID );
                $posts_updated++;
                $blocks_removed += $removed;
            }
        }

        return [ 'posts_updated' => $posts_updated, 'blocks_removed' => $blocks_removed, 'truncated' => false ];
    }

    /**
     * 특정 글의 Gutenberg 이미지 블록 중 파일이 없는(엑박) attachment ID 목록 반환
     *
     * @param int $post_id
     * @return array { broken_images: [{id, name}, ...] }
     */
    public function get_post_broken_images( int $post_id ): array {
        global $wpdb;

        $content = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id
        ) );

        if ( ! $content ) return [ 'broken_images' => [] ];

        preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $m );
        $att_ids = array_values( array_unique( array_map( 'intval', $m[1] ) ) );
        if ( empty( $att_ids ) ) return [ 'broken_images' => [] ];

        $ph          = implode( ',', array_fill( 0, count( $att_ids ), '%d' ) );
        $upload_base = wp_upload_dir()['basedir'];

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.guid, m.meta_value AS rel_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.ID IN ({$ph}) AND p.post_type = 'attachment'",
            ...$att_ids
        ) );

        $found  = [];
        $broken = [];

        foreach ( $rows as $row ) {
            $id          = (int) $row->ID;
            $found[ $id ] = true;
            $abs = $row->rel_path ? trailingslashit( $upload_base ) . $row->rel_path : '';
            if ( ! $abs || ! file_exists( $abs ) ) {
                $name     = $row->rel_path
                    ? basename( $row->rel_path )
                    : ( $row->guid ? basename( (string) parse_url( $row->guid, PHP_URL_PATH ) ) : $row->post_title );
                $broken[] = [ 'id' => $id, 'name' => $name ];
            }
        }

        // DB에 아예 없는 ID
        foreach ( $att_ids as $id ) {
            if ( ! isset( $found[ $id ] ) ) {
                $broken[] = [ 'id' => $id, 'name' => '' ];
            }
        }

        return [ 'broken_images' => $broken ];
    }

    /**
     * 여러 첨부파일 삭제
     */
    public function delete_attachments( array $ids ) {
        $deleted = 0;
        $errors  = [];
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( wp_delete_attachment( $id, true ) ) {
                $deleted++;
            } else {
                $errors[] = "ID {$id} 삭제 실패";
            }
        }
        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }
}
