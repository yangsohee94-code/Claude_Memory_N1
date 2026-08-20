<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php
global $wpdb;
$table = $wpdb->prefix . 'sns_share_queue';

$year  = intval( $_GET['cal_year']  ?? current_time( 'Y' ) );
$month = intval( $_GET['cal_month'] ?? current_time( 'n' ) );
$year  = max( 2024, min( 2099, $year ) );
$month = max( 1, min( 12, $month ) );

$prev_month = $month - 1 < 1  ? 12 : $month - 1;
$prev_year  = $month - 1 < 1  ? $year - 1 : $year;
$next_month = $month + 1 > 12 ? 1  : $month + 1;
$next_year  = $month + 1 > 12 ? $year + 1 : $year;

$start_gmt = get_gmt_from_date( sprintf( '%04d-%02d-01 00:00:00', $year, $month ) );
$end_gmt   = get_gmt_from_date( sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, cal_days_in_month( CAL_GREGORIAN, $month, $year ) ) );

$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT q.*, p.post_title
     FROM $table q
     LEFT JOIN {$wpdb->posts} p ON p.ID = q.post_id
     WHERE q.scheduled_at BETWEEN %s AND %s
     ORDER BY q.scheduled_at ASC",
    $start_gmt, $end_gmt
) );

// 날짜(로컬 기준)별로 그룹핑
$by_day = [];
foreach ( $rows as $row ) {
    $local_day = get_date_from_gmt( $row->scheduled_at, 'j' );
    $by_day[ (int) $local_day ][] = $row;
}

$platform_colors = [
    'twitter'   => '#1a1a1a',
    'threads'   => '#444',
    'pinterest' => '#E60023',
    'facebook'  => '#1877F2',
];
$platform_icons = [
    'twitter'   => '𝕏',
    'threads'   => '⊕',
    'pinterest' => '𝑷',
    'facebook'  => 'f',
];

$days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );
$first_dow     = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) ); // 0=Sun
$today_local   = (int) current_time( 'j' );
$today_month   = (int) current_time( 'n' );
$today_year    = (int) current_time( 'Y' );
?>

<div class="wrap sns-settings-page">
    <h1>📅 SNS 예약 달력</h1>

    <div class="sns-cal-nav">
        <a class="button" href="?page=sns-share-calendar&cal_year=<?php echo $prev_year; ?>&cal_month=<?php echo $prev_month; ?>">‹ 이전달</a>
        <strong class="sns-cal-month-label"><?php echo $year; ?>년 <?php echo $month; ?>월</strong>
        <a class="button" href="?page=sns-share-calendar&cal_year=<?php echo $next_year; ?>&cal_month=<?php echo $next_month; ?>">다음달 ›</a>
        <a class="button" href="?page=sns-share-calendar">오늘</a>
        <span class="sns-cal-legend">
            <?php foreach ( $platform_colors as $p => $c ) : ?>
                <span class="sns-cal-dot" style="background:<?php echo esc_attr($c); ?>"></span><?php echo esc_html( $platform_icons[$p] ); ?>
            <?php endforeach; ?>
        </span>
    </div>

    <table class="sns-calendar">
        <thead>
            <tr>
                <?php foreach ( ['일','월','화','수','목','금','토'] as $d ) : ?>
                    <th><?php echo $d; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $day = 1;
        $cell = 0;
        echo '<tr>';
        // 첫 주 빈칸
        for ( $i = 0; $i < $first_dow; $i++ ) {
            echo '<td class="sns-cal-empty"></td>';
            $cell++;
        }
        while ( $day <= $days_in_month ) {
            if ( $cell % 7 === 0 && $cell > 0 ) {
                echo '</tr><tr>';
            }
            $is_today = ( $day === $today_local && $month === $today_month && $year === $today_year );
            $items    = $by_day[ $day ] ?? [];
            echo '<td class="sns-cal-day' . ( $is_today ? ' sns-cal-today' : '' ) . '">';
            echo '<span class="sns-cal-daynum">' . $day . '</span>';
            foreach ( $items as $item ) {
                $time  = get_date_from_gmt( $item->scheduled_at, 'H:i' );
                $color = $platform_colors[ $item->platform ] ?? '#999';
                $icon  = $platform_icons[ $item->platform ] ?? '?';
                $title = esc_attr( $item->post_title . ' (' . $time . ') [' . $item->status . ']' );
                $cls   = 'sns-cal-item status-' . esc_attr( $item->status );
                echo '<span class="' . $cls . '" style="border-color:' . esc_attr($color) . '" title="' . $title . '">';
                echo '<span class="sns-cal-item-icon" style="color:' . esc_attr($color) . '">' . esc_html( $icon ) . '</span>';
                echo '<span class="sns-cal-item-time">' . esc_html( $time ) . '</span>';
                echo '</span>';
            }
            echo '</td>';
            $day++;
            $cell++;
        }
        // 마지막 주 빈칸
        while ( $cell % 7 !== 0 ) {
            echo '<td class="sns-cal-empty"></td>';
            $cell++;
        }
        echo '</tr>';
        ?>
        </tbody>
    </table>

    <?php if ( ! empty( $rows ) ) : ?>
    <div class="sns-cal-list">
        <h3>이 달 예약 목록 (<?php echo count($rows); ?>건)</h3>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
            <thead><tr>
                <th width="12%">날짜·시각</th>
                <th width="10%">플랫폼</th>
                <th>글 제목</th>
                <th width="10%">상태</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $local = get_date_from_gmt( $r->scheduled_at, 'm/d H:i' );
                $color = $platform_colors[ $r->platform ] ?? '#999';
                $icon  = $platform_icons[ $r->platform ] ?? '';
                $status_map = [ 'pending' => '⏳ 대기', 'sent' => '✅ 완료', 'failed' => '❌ 실패', 'cancelled' => '🚫 취소' ];
            ?>
            <tr>
                <td><?php echo esc_html( $local ); ?></td>
                <td style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html( $icon . ' ' . $r->platform ); ?></td>
                <td><a href="<?php echo esc_url( get_edit_post_link( $r->post_id ) ); ?>"><?php echo esc_html( $r->post_title ?: '(글 없음)' ); ?></a></td>
                <td><?php echo esc_html( $status_map[ $r->status ] ?? $r->status ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
.sns-cal-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
}
.sns-cal-month-label {
    font-size: 16px;
    min-width: 100px;
    text-align: center;
}
.sns-cal-legend {
    margin-left: 12px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sns-cal-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.sns-calendar {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    overflow: hidden;
}
.sns-calendar thead th {
    background: #f6f7f7;
    padding: 8px;
    text-align: center;
    font-size: 12px;
    border-bottom: 1px solid #c3c4c7;
}
.sns-calendar thead th:first-child { color: #c00; }
.sns-calendar thead th:last-child  { color: #00c; }
.sns-cal-day, .sns-cal-empty {
    vertical-align: top;
    padding: 6px;
    border: 1px solid #f0f0f1;
    min-height: 80px;
    height: 80px;
}
.sns-cal-today {
    background: #f0f6fc;
}
.sns-cal-daynum {
    font-size: 12px;
    font-weight: 600;
    color: #444;
    display: block;
    margin-bottom: 4px;
}
.sns-cal-today .sns-cal-daynum {
    background: #0073aa;
    color: #fff;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.sns-cal-item {
    display: flex;
    align-items: center;
    gap: 2px;
    border-left: 3px solid #ccc;
    padding: 1px 4px;
    margin-bottom: 2px;
    border-radius: 0 3px 3px 0;
    background: #fafafa;
    font-size: 10px;
    cursor: default;
    overflow: hidden;
}
.sns-cal-item.status-sent      { opacity: 0.5; }
.sns-cal-item.status-cancelled { opacity: 0.35; text-decoration: line-through; }
.sns-cal-item.status-failed    { background: #fff0f0; }
.sns-cal-item-icon { font-size: 11px; font-weight: 900; flex-shrink: 0; }
.sns-cal-item-time { color: #666; flex-shrink: 0; }
.sns-cal-empty { background: #fafafa; }
.sns-cal-list { margin-top: 24px; }
</style>
