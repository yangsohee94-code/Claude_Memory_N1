<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap sns-settings-page">
    <h1>📋 SNS 공유 현황</h1>

    <?php
    global $wpdb;
    $table   = $wpdb->prefix . 'sns_share_queue';
    $per_page = 30;
    $page    = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $offset  = ( $page - 1 ) * $per_page;
    $total   = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $rows    = $wpdb->get_results( $wpdb->prepare(
        "SELECT q.*, p.post_title FROM $table q
         LEFT JOIN {$wpdb->posts} p ON p.ID = q.post_id
         ORDER BY q.scheduled_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ) );

    $platform_labels = [
        'twitter'   => '𝕏 X',
        'threads'   => '⊕ Threads',
        'pinterest' => '𝑷 Pinterest',
        'facebook'  => 'f Facebook',
    ];
    $status_labels = [
        'pending'    => '<span class="sns-status pending">⏳ 대기</span>',
        'processing' => '<span class="sns-status processing">🔄 게시중</span>',
        'sent'       => '<span class="sns-status sent">✅ 완료</span>',
        'failed'     => '<span class="sns-status failed">❌ 실패</span>',
        'cancelled'  => '<span class="sns-status cancelled">🚫 취소</span>',
    ];
    ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="5%">ID</th>
                <th width="25%">글 제목</th>
                <th width="12%">플랫폼</th>
                <th width="15%">예약 시각</th>
                <th width="10%">상태</th>
                <th>결과 / 내용</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( $rows ) : foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row->id ); ?></td>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>">
                        <?php echo esc_html( $row->post_title ?: '(글 없음)' ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $platform_labels[ $row->platform ] ?? $row->platform ); ?></td>
                <td><?php echo esc_html( get_date_from_gmt( $row->scheduled_at, 'Y/m/d H:i' ) ); ?></td>
                <td><?php echo $status_labels[ $row->status ] ?? esc_html( $row->status ); ?></td>
                <td>
                    <?php if ( $row->result ) : ?>
                        <code><?php echo esc_html( $row->result ); ?></code>
                    <?php else : ?>
                        <details>
                            <summary>내용 보기</summary>
                            <pre style="white-space:pre-wrap;font-size:11px;"><?php echo esc_html( mb_substr( $row->content, 0, 200 ) ); ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; else : ?>
            <tr><td colspan="6" style="text-align:center;padding:30px;">예약된 공유가 없습니다.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total > $per_page ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links( [
                'base'    => add_query_arg( 'paged', '%#%' ),
                'format'  => '',
                'current' => $page,
                'total'   => ceil( $total / $per_page ),
            ] );
            ?>
        </div>
    </div>
    <?php endif; ?>

    <p style="margin-top:20px;">
        <strong>전체 <?php echo number_format( $total ); ?>건</strong>
        &nbsp;|&nbsp;
        <?php
        $summary = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM $table GROUP BY status" );
        foreach ( $summary as $s ) {
            echo esc_html( $s->status ) . ': ' . number_format( $s->cnt ) . '건 &nbsp;';
        }
        ?>
    </p>
</div>
