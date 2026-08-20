<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php
$post_status  = get_post_status( $post->ID );
$is_published = ( $post_status === 'publish' );
$options      = get_option( 'sns_scheduler_options', [] );

$platforms = [
    'twitter'   => [ 'label' => 'X (Twitter)', 'icon' => '𝕏', 'color' => '#000000', 'configured' => ! empty( $options['twitter_api_key'] ) ],
    'threads'   => [ 'label' => 'Threads',     'icon' => '⊕', 'color' => '#000000', 'configured' => ! empty( $options['threads_access_token'] ) ],
    'pinterest' => [ 'label' => 'Pinterest',   'icon' => '𝑷', 'color' => '#E60023', 'configured' => ! empty( $options['pinterest_access_token'] ) ],
    'facebook'  => [ 'label' => 'Facebook',    'icon' => 'f',  'color' => '#1877F2', 'configured' => ! empty( $options['facebook_page_access_token'] ) ],
];
?>

<div class="sns-share-wrap">

    <?php if ( ! $is_published ) : ?>
        <p class="sns-notice">✏️ 글을 <strong>발행</strong>하면 SNS 공유 버튼이 활성화됩니다.</p>
    <?php else : ?>
        <p class="sns-desc">버튼 클릭 시 4시간 간격으로 예약됩니다.</p>
    <?php endif; ?>

    <div class="sns-buttons">
        <?php foreach ( $platforms as $key => $info ) : ?>
            <div class="sns-platform-row">
                <button
                    class="sns-btn <?php echo $key; ?> <?php echo ! $is_published ? 'disabled' : ''; ?>"
                    data-platform="<?php echo esc_attr( $key ); ?>"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    <?php disabled( ! $is_published ); ?>
                    style="--sns-color: <?php echo esc_attr( $info['color'] ); ?>"
                    title="<?php echo ! $info['configured'] ? '⚠️ API 미설정 - 설정 페이지에서 연결하세요' : ''; ?>"
                >
                    <span class="sns-icon"><?php echo esc_html( $info['icon'] ); ?></span>
                    <span class="sns-label"><?php echo esc_html( $info['label'] ); ?></span>
                    <?php if ( ! $info['configured'] ) : ?>
                        <span class="sns-badge-unset">미설정</span>
                    <?php endif; ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="sns-result-msg" class="sns-msg" style="display:none;"></div>

    <div id="sns-preview-box" style="display:none;">
        <p class="sns-preview-title">📝 예약 미리보기</p>
        <pre id="sns-preview-content"></pre>
    </div>

    <div id="sns-queue-wrap">
        <p class="sns-queue-title">📋 예약 현황 <button id="sns-refresh-queue" class="button button-small">새로고침</button></p>
        <div id="sns-queue-list"><em>로딩 중...</em></div>
    </div>

    <p class="sns-settings-link">
        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=sns-share-scheduler' ) ); ?>">⚙️ SNS 계정 설정</a>
    </p>
</div>
