<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php $options = get_option( 'sns_scheduler_options', [] ); ?>

<div class="wrap sns-settings-page">
    <h1>📣 SNS 공유 스케줄러 설정</h1>
    <p class="description">각 SNS 계정을 연결하면 글 발행 후 자동으로 공유를 예약할 수 있습니다.</p>

    <?php settings_errors(); ?>

    <script>
    function snsToggleVisible(btn) {
        var wrap  = btn.closest('.sns-secret-wrap');
        var input = wrap.querySelector('input');
        var isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.title  = isHidden ? '숨기기' : '보기';
        btn.innerHTML = isHidden ? '🙈' : '👁';
    }
    </script>

    <form method="post" action="options.php">
        <?php settings_fields( 'sns_scheduler_options' ); ?>

        <!-- X (Twitter) -->
        <div class="sns-card">
            <h2 class="sns-card-title" data-platform="twitter">
                <span class="sns-card-icon" style="background:#000">𝕏</span>
                X (Twitter)
                <span class="sns-status-badge <?php echo ! empty( $options['twitter_api_key'] ) ? 'connected' : 'disconnected'; ?>">
                    <?php echo ! empty( $options['twitter_api_key'] ) ? '✅ 연결됨' : '❌ 미연결'; ?>
                </span>
            </h2>
            <details <?php echo ! empty( $options['twitter_api_key'] ) ? '' : 'open'; ?>>
                <summary>API 설정 보기/접기</summary>
                <div class="sns-fields">
                    <p class="sns-guide">
                        <a href="https://developer.twitter.com/en/portal/dashboard" target="_blank">Twitter Developer Portal</a>에서 앱을 만들고
                        API Key와 Access Token을 발급받으세요. (Free tier: 월 1,500건 쓰기)
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>API Key (Consumer Key)</th>
                            <td><input type="text" name="sns_scheduler_options[twitter_api_key]"
                                value="<?php echo esc_attr( $options['twitter_api_key'] ?? '' ); ?>"
                                class="regular-text" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" /></td>
                        </tr>
                        <tr>
                            <th>API Secret (Consumer Secret)</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[twitter_api_secret]"
                                    value="<?php echo esc_attr( $options['twitter_api_secret'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>Access Token</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[twitter_access_token]"
                                    value="<?php echo esc_attr( $options['twitter_access_token'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>Access Token Secret</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[twitter_access_secret]"
                                    value="<?php echo esc_attr( $options['twitter_access_secret'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>공유 문구 템플릿</th>
                            <td>
                                <textarea name="sns_scheduler_options[twitter_content_template]"
                                    class="large-text" rows="4"
                                    placeholder="✨ {title}&#10;&#10;{excerpt}&#10;&#10;{hashtags}"
                                ><?php echo esc_textarea( $options['twitter_content_template'] ?? '' ); ?></textarea>
                                <p class="description">사용 가능 변수: <code>{title}</code> <code>{excerpt}</code> <code>{url}</code> <code>{hashtags}</code></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>
        </div>

        <!-- Threads -->
        <div class="sns-card">
            <h2 class="sns-card-title">
                <span class="sns-card-icon" style="background:#000">⊕</span>
                Threads
                <span class="sns-status-badge <?php echo ! empty( $options['threads_access_token'] ) ? 'connected' : 'disconnected'; ?>">
                    <?php echo ! empty( $options['threads_access_token'] ) ? '✅ 연결됨' : '❌ 미연결'; ?>
                </span>
            </h2>
            <details <?php echo ! empty( $options['threads_access_token'] ) ? '' : 'open'; ?>>
                <summary>API 설정 보기/접기</summary>
                <div class="sns-fields">
                    <p class="sns-guide">
                        <a href="https://developers.facebook.com/apps/" target="_blank">Meta for Developers</a>에서 Threads API 앱을 생성하고
                        Long-Lived Access Token을 발급받으세요.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>Access Token</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[threads_access_token]"
                                    value="<?php echo esc_attr( $options['threads_access_token'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>Threads User ID</th>
                            <td><input type="text" name="sns_scheduler_options[threads_user_id]"
                                value="<?php echo esc_attr( $options['threads_user_id'] ?? '' ); ?>"
                                class="regular-text" placeholder="숫자로 된 사용자 ID" /></td>
                        </tr>
                        <tr>
                            <th>공유 문구 템플릿</th>
                            <td>
                                <textarea name="sns_scheduler_options[threads_content_template]"
                                    class="large-text" rows="5"
                                    placeholder="✨ {title}&#10;&#10;{excerpt}&#10;&#10;{hashtags}&#10;&#10;👇 프로필 링크에서 확인하세요!"
                                ><?php echo esc_textarea( $options['threads_content_template'] ?? '' ); ?></textarea>
                                <p class="description">사용 가능 변수: <code>{title}</code> <code>{excerpt}</code> <code>{url}</code> <code>{hashtags}</code></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>
        </div>

        <!-- Pinterest -->
        <div class="sns-card">
            <h2 class="sns-card-title">
                <span class="sns-card-icon" style="background:#E60023">𝑷</span>
                Pinterest
                <span class="sns-status-badge <?php echo ! empty( $options['pinterest_access_token'] ) ? 'connected' : 'disconnected'; ?>">
                    <?php echo ! empty( $options['pinterest_access_token'] ) ? '✅ 연결됨' : '❌ 미연결'; ?>
                </span>
            </h2>
            <details <?php echo ! empty( $options['pinterest_access_token'] ) ? '' : 'open'; ?>>
                <summary>API 설정 보기/접기</summary>
                <div class="sns-fields">
                    <p class="sns-guide">
                        <a href="https://developers.pinterest.com/apps/" target="_blank">Pinterest Developer</a>에서 앱을 만들고
                        Access Token을 발급받으세요. 대표이미지가 있는 글만 핀 생성이 가능합니다.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>Access Token</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[pinterest_access_token]"
                                    value="<?php echo esc_attr( $options['pinterest_access_token'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>Board ID (선택)</th>
                            <td><input type="text" name="sns_scheduler_options[pinterest_board_id]"
                                value="<?php echo esc_attr( $options['pinterest_board_id'] ?? '' ); ?>"
                                class="regular-text" placeholder="비워두면 기본 보드에 게시됩니다" /></td>
                        </tr>
                        <tr>
                            <th>공유 문구 템플릿</th>
                            <td>
                                <textarea name="sns_scheduler_options[pinterest_content_template]"
                                    class="large-text" rows="4"
                                    placeholder="{title}&#10;&#10;{excerpt}&#10;&#10;{hashtags}"
                                ><?php echo esc_textarea( $options['pinterest_content_template'] ?? '' ); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>
        </div>

        <!-- Facebook -->
        <div class="sns-card">
            <h2 class="sns-card-title">
                <span class="sns-card-icon" style="background:#1877F2">f</span>
                Facebook
                <span class="sns-status-badge <?php echo ! empty( $options['facebook_page_access_token'] ) ? 'connected' : 'disconnected'; ?>">
                    <?php echo ! empty( $options['facebook_page_access_token'] ) ? '✅ 연결됨' : '❌ 미연결'; ?>
                </span>
            </h2>
            <details <?php echo ! empty( $options['facebook_page_access_token'] ) ? '' : 'open'; ?>>
                <summary>API 설정 보기/접기</summary>
                <div class="sns-fields">
                    <p class="sns-guide">
                        <a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer</a>에서
                        페이지 관리자 권한으로 Page Access Token을 발급받으세요.
                        <strong>Long-lived token</strong> 사용을 권장합니다 (60일).
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>Page Access Token</th>
                            <td><span class="sns-secret-wrap">
                                <input type="password" name="sns_scheduler_options[facebook_page_access_token]"
                                    value="<?php echo esc_attr( $options['facebook_page_access_token'] ?? '' ); ?>"
                                    class="regular-text" />
                                <button type="button" class="sns-eye-btn" onclick="snsToggleVisible(this)" title="보기">👁</button>
                            </span></td>
                        </tr>
                        <tr>
                            <th>Facebook Page ID</th>
                            <td><input type="text" name="sns_scheduler_options[facebook_page_id]"
                                value="<?php echo esc_attr( $options['facebook_page_id'] ?? '' ); ?>"
                                class="regular-text" placeholder="숫자로 된 페이지 ID" /></td>
                        </tr>
                        <tr>
                            <th>공유 문구 템플릿</th>
                            <td>
                                <textarea name="sns_scheduler_options[facebook_content_template]"
                                    class="large-text" rows="5"
                                    placeholder="📌 {title}&#10;&#10;{excerpt}&#10;&#10;더 자세한 내용은 아래 링크를 클릭하세요 👇&#10;{url}&#10;&#10;{hashtags}"
                                ><?php echo esc_textarea( $options['facebook_content_template'] ?? '' ); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>
        </div>

        <?php submit_button( '💾 설정 저장' ); ?>
    </form>
</div>
