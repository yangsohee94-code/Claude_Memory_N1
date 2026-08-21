<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dim-wrap">
    <h1>🖼️ 이미지 최적화 관리</h1>

    <!-- 탭 -->
    <div class="dim-tabs">
        <button class="dim-tab active" data-tab="duplicate">중복 이미지 병합</button>
        <button class="dim-tab" data-tab="nonwebp">WebP 변환</button>
        <button class="dim-tab" data-tab="nothumb">대표이미지 없는 글</button>
        <button class="dim-tab" data-tab="stats">용량 현황</button>
        <button class="dim-tab" data-tab="unused">미사용 이미지</button>
        <button class="dim-tab" data-tab="brokenimg">이미지 오류 글</button>
        <button class="dim-tab" data-tab="h2noimg">H2 이미지 누락</button>
    </div>

    <!-- ① 중복 이미지 탭 -->
    <div class="dim-tab-content active" id="dim-tab-duplicate">

        <div class="dim-panel dim-panel-auto">
            <h2>⚡ 자동 최적화 예약 <span class="dim-badge">매일 새벽 3시</span></h2>
            <p class="dim-panel-desc">중복 병합 → WebP 변환 → 대표이미지 정합성 수정을 매일 자동 실행합니다. 업로드 시 WebP 자동 변환도 항상 활성화됩니다.</p>
            <div class="dim-panel-row">
                <label class="dim-toggle">
                    <input type="checkbox" id="dim-schedule-toggle">
                    <span class="dim-toggle-slider"></span>
                </label>
                <span id="dim-schedule-label">불러오는 중...</span>
                <span id="dim-last-run" class="dim-muted"></span>
            </div>
            <button id="dim-run-now-btn" class="button button-primary">지금 전체 최적화 실행</button>
        </div>

        <div class="dim-action-bar">
            <button id="dim-scan-btn" class="button button-primary">🔍 중복 이미지 스캔</button>
            <button id="dim-auto-merge-btn" class="button" disabled>🔗 자동 병합 (사용 중 기준)</button>
            <button id="dim-merge-selected-btn" class="button" disabled>✅ 선택 항목 병합</button>
            <span class="dim-spacer"></span>
            <button id="dim-thumb-btn" class="button dim-btn-thumb">🖼 대표이미지 정합성 수정</button>
        </div>

        <div id="dim-progress-dup" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-summary" style="display:none;" class="dim-summary">
            <span>스캔 <strong id="dim-total-scanned">0</strong>개</span>
            <span>중복 그룹 <strong id="dim-group-count">0</strong>개</span>
            <span>절약 가능 <strong id="dim-saved-size">0</strong></span>
        </div>

        <div id="dim-select-all-wrap" style="display:none;" class="dim-select-all-wrap">
            <label><input type="checkbox" id="dim-select-all"> 전체 선택 / 해제</label>
            <span id="dim-selected-count" class="dim-muted">0개 선택됨</span>
        </div>

        <div id="dim-results"></div>
    </div>

    <!-- ② WebP 변환 탭 -->
    <div class="dim-tab-content" id="dim-tab-nonwebp">
        <div class="dim-action-bar">
            <button id="dim-load-nonwebp-btn" class="button button-primary">📋 WebP 아닌 이미지 불러오기</button>
            <button id="dim-convert-selected-btn" class="button dim-btn-webp" disabled>🌐 선택 항목 WebP 변환</button>
            <button id="dim-convert-all-btn" class="button dim-btn-webp">⚡ 전체 WebP 변환</button>
            <span class="dim-spacer"></span>
            <button id="dim-delete-nonwebp-btn" class="button dim-btn-danger" disabled>🗑 선택 항목 삭제</button>
            <button id="dim-delete-all-nonwebp-btn" class="button dim-btn-danger">🗑 전체 삭제</button>
        </div>

        <div id="dim-progress-webp" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-nonwebp-select-wrap" style="display:none;" class="dim-select-all-wrap">
            <label><input type="checkbox" id="dim-nonwebp-select-all"> 전체 선택 / 해제</label>
            <span id="dim-nonwebp-selected-count" class="dim-muted">0개 선택됨</span>
        </div>

        <div id="dim-nonwebp-list"></div>
        <div id="dim-nonwebp-more-wrap" style="display:none;text-align:center;margin:12px 0;">
            <button id="dim-nonwebp-more-btn" class="button">더 보기</button>
        </div>
    </div>

    <!-- ③ 대표이미지 없는 글 탭 -->
    <div class="dim-tab-content" id="dim-tab-nothumb">
        <div class="dim-action-bar">
            <button id="dim-load-nothumb-btn" class="button button-primary">📋 대표이미지 없는 글 불러오기</button>
        </div>

        <div id="dim-progress-nothumb" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-nothumb-summary" style="display:none;" class="dim-summary">
            <span>대표이미지 없는 글 <strong id="dim-nothumb-count">0</strong>개</span>
        </div>

        <div id="dim-nothumb-list"></div>
        <div id="dim-nothumb-more-wrap" style="display:none;text-align:center;margin:12px 0;">
            <button id="dim-nothumb-more-btn" class="button">더 보기</button>
        </div>
    </div>

    <!-- ④ 용량 현황 탭 -->
    <div class="dim-tab-content" id="dim-tab-stats">
        <div class="dim-action-bar">
            <button id="dim-load-stats-btn" class="button button-primary">📊 용량 분석 시작</button>
        </div>

        <div id="dim-progress-stats" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar" style="width:60%;"></div></div>
            <span class="dim-progress-text">분석 중... (이미지 수에 따라 시간이 걸릴 수 있습니다)</span>
        </div>

        <div id="dim-stats-result" style="display:none;">
            <div class="dim-stats-grid">
                <div class="dim-stat-card dim-stat-total">
                    <div class="dim-stat-label">전체 이미지</div>
                    <div class="dim-stat-value" id="stat-total-count">-</div>
                    <div class="dim-stat-sub" id="stat-total-size">-</div>
                </div>
                <div class="dim-stat-card dim-stat-webp">
                    <div class="dim-stat-label">WebP 이미지</div>
                    <div class="dim-stat-value" id="stat-webp-count">-</div>
                    <div class="dim-stat-sub" id="stat-webp-size">-</div>
                </div>
                <div class="dim-stat-card dim-stat-other">
                    <div class="dim-stat-label">변환 필요 이미지</div>
                    <div class="dim-stat-value" id="stat-other-count">-</div>
                    <div class="dim-stat-sub" id="stat-other-size">-</div>
                </div>
                <div class="dim-stat-card dim-stat-save">
                    <div class="dim-stat-label">WebP 변환 시 예상 절약</div>
                    <div class="dim-stat-value" id="stat-save-pct">-</div>
                    <div class="dim-stat-sub">평균 35% 용량 감소 기준</div>
                </div>
            </div>

            <div class="dim-chart-wrap">
                <h3>파일 형식별 현황</h3>
                <div id="dim-type-chart"></div>
            </div>

            <div class="dim-usage-bar-wrap">
                <h3>WebP 전환율</h3>
                <div class="dim-usage-bar">
                    <div class="dim-usage-fill" id="dim-webp-pct-bar"></div>
                </div>
                <div class="dim-usage-labels">
                    <span class="dim-usage-webp">WebP <span id="dim-webp-pct-label">0%</span></span>
                    <span class="dim-usage-other">기타 <span id="dim-other-pct-label">100%</span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ⑤ 미사용 이미지 탭 -->
    <div class="dim-tab-content" id="dim-tab-unused">
        <div class="dim-panel-desc" style="padding:12px 0 0;">
            <strong>미사용 이미지</strong> — 어떤 글/페이지에도 삽입되지 않고, 대표이미지로도 지정되지 않은 이미지입니다.<br>
            <span class="dim-muted">※ Elementor 등 페이지 빌더 전용 이미지는 일부 미탐지될 수 있습니다.</span>
        </div>
        <div class="dim-action-bar" style="margin-top:8px;">
            <button id="dim-scan-unused-btn" class="button button-primary">🔍 미사용 이미지 스캔</button>
            <span class="dim-spacer"></span>
            <button id="dim-delete-unused-btn" class="button dim-btn-danger" disabled>🗑 선택 항목 삭제</button>
            <button id="dim-delete-all-unused-btn" class="button dim-btn-danger">🗑 전체 삭제</button>
        </div>

        <div id="dim-progress-unused" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-unused-summary" style="display:none;" class="dim-summary">
            <span>미사용 후보 <strong id="dim-unused-total">0</strong>개</span>
            <span>삭제 시 절약 <strong id="dim-unused-size">0 B</strong></span>
        </div>

        <div id="dim-unused-select-wrap" style="display:none;" class="dim-select-all-wrap">
            <label><input type="checkbox" id="dim-unused-select-all"> 전체 선택 / 해제</label>
            <span id="dim-unused-selected-count" class="dim-muted">0개 선택됨</span>
        </div>

        <div id="dim-unused-list"></div>
        <div id="dim-unused-more-wrap" style="display:none;text-align:center;margin:12px 0;">
            <button id="dim-unused-more-btn" class="button">더 보기</button>
        </div>
    </div>

    <!-- ⑥ 이미지 오류 글 탭 -->
    <div class="dim-tab-content" id="dim-tab-brokenimg">
        <div class="dim-panel-desc" style="padding:12px 0 0;">
            <strong>이미지 오류 글</strong> — 본문에 삽입된 이미지가 실제로 존재하지 않는 글입니다 (파일 삭제·미디어 라이브러리에서 제거된 경우).<br>
            <span class="dim-muted">※ Gutenberg 블록 이미지 기준으로 스캔합니다. 각 글을 클릭해 직접 교체하세요.</span>
        </div>
        <div class="dim-action-bar" style="margin-top:8px;">
            <button id="dim-scan-brokenimg-btn" class="button button-primary">🔍 이미지 오류 글 스캔</button>
        </div>

        <div id="dim-progress-brokenimg" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-brokenimg-summary" style="display:none;" class="dim-summary">
            <span>스캔 <strong id="dim-brokenimg-scanned">0</strong>개</span>
            <span>오류 이미지 있는 글 <strong id="dim-brokenimg-count">0</strong>개</span>
            <button id="dim-brokenimg-sort-btn" class="button button-small" style="display:none;margin-left:auto;">오류 많은 순 ↓</button>
        </div>

        <div id="dim-brokenimg-list"></div>
        <div id="dim-brokenimg-more-wrap" style="display:none;text-align:center;margin:12px 0;">
            <button id="dim-brokenimg-more-btn" class="button">더 보기</button>
        </div>
    </div>

    <!-- ⑦ H2 아래 이미지 없는 글 탭 -->
    <div class="dim-tab-content" id="dim-tab-h2noimg">
        <div class="dim-panel-desc" style="padding:12px 0 0;">
            <strong>H2 이미지 누락</strong> — H2 제목 바로 다음에 이미지가 없는 글입니다.<br>
            <span class="dim-muted">정상: H2 → 이미지 → H2 → 이미지 &nbsp;/&nbsp; 비정상: H2 → H2 또는 H2 → 텍스트</span>
        </div>
        <div class="dim-action-bar" style="margin-top:8px;">
            <button id="dim-scan-h2noimg-btn" class="button button-primary">🔍 H2 이미지 누락 글 스캔</button>
        </div>

        <div id="dim-progress-h2noimg" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar"></div></div>
            <span class="dim-progress-text"></span>
        </div>

        <div id="dim-h2noimg-summary" style="display:none;" class="dim-summary">
            <span>스캔 <strong id="dim-h2noimg-scanned">0</strong>개</span>
            <span>H2 이미지 누락 글 <strong id="dim-h2noimg-count">0</strong>개</span>
        </div>

        <div id="dim-h2noimg-list"></div>
        <div id="dim-h2noimg-more-wrap" style="display:none;text-align:center;margin:12px 0;">
            <button id="dim-h2noimg-more-btn" class="button">더 보기</button>
        </div>
    </div>

    <!-- 전체 알림 -->
    <div id="dim-notice" style="display:none;" class="dim-notice"></div>
</div>

<!-- 이미지 자르기 모달 -->
<div id="dim-crop-modal" style="display:none;">
    <div id="dim-crop-dialog">
        <h3>✂ 이미지 자르기 <button id="dim-crop-close">×</button></h3>
        <div id="dim-crop-img-wrap">
            <img id="dim-crop-img" src="" alt="">
            <div id="dim-crop-sel"></div>
        </div>
        <div id="dim-crop-footer">
            <span id="dim-crop-info">드래그하여 자를 영역을 선택하세요</span>
            <button id="dim-crop-apply" class="button button-primary" disabled>자르기 적용</button>
            <button id="dim-crop-cancel" class="button">취소</button>
        </div>
        <div id="dim-crop-progress" class="dim-progress-wrap" style="display:none;">
            <div class="dim-progress-inner"><div class="dim-progress-bar" style="width:60%;"></div></div>
            <span class="dim-progress-text">저장 중...</span>
        </div>
    </div>
</div>

<!-- 그룹 템플릿 -->
<script type="text/html" id="dim-group-tmpl">
<div class="dim-group" data-group-idx="{{idx}}">
    <div class="dim-group-header">
        <label><input type="checkbox" class="dim-group-select-all" data-group-idx="{{idx}}"> 그룹 전체 선택</label>
        <span class="dim-muted">{{count}}개 · {{hash_short}}</span>
    </div>
    <div class="dim-group-items">{{items}}</div>
    <div class="dim-group-footer">
        <button class="button dim-merge-group-btn" data-group-idx="{{idx}}">이 그룹 병합</button>
    </div>
</div>
</script>

<script type="text/html" id="dim-item-tmpl">
<div class="dim-item {{used_class}}" data-id="{{id}}">
    <input type="checkbox" class="dim-item-checkbox" value="{{id}}" data-group-idx="{{group_idx}}">
    <img src="{{url}}" alt="" loading="lazy">
    <div class="dim-item-info">
        <strong>{{title}}</strong>
        <span class="dim-muted">{{date}} · {{size}}</span>
        <span class="dim-muted">ID: {{id}}</span>
        {{used_badge}}
    </div>
    <label class="dim-keep-label">
        <input type="radio" name="dim-keep-{{group_idx}}" value="{{id}}" class="dim-keep-radio">
        원본 유지
    </label>
</div>
</script>

<script type="text/html" id="dim-nonwebp-item-tmpl">
<div class="dim-nw-item">
    <input type="checkbox" class="dim-nw-checkbox" value="{{id}}">
    <img src="{{url}}" alt="" loading="lazy">
    <div class="dim-item-info">
        <strong>{{title}}</strong>
        <span class="dim-muted">{{type}} · {{size}} · {{date}}</span>
        <span class="dim-muted">ID: {{id}}</span>
    </div>
    <button class="button button-small dim-btn-crop dim-open-crop" data-id="{{id}}" data-url="{{url}}">✂ 자르기</button>
    <a href="{{edit_url}}" target="_blank" class="button button-small">편집</a>
</div>
</script>

<script type="text/html" id="dim-unused-item-tmpl">
<div class="dim-nw-item">
    <input type="checkbox" class="dim-unused-checkbox" value="{{id}}">
    <img src="{{url}}" alt="" loading="lazy">
    <div class="dim-item-info">
        <strong>{{title}}</strong>
        <span class="dim-muted">{{type}} · {{size}} · {{date}}</span>
        <span class="dim-muted">ID: {{id}}</span>
        <span class="dim-badge-unused">미사용</span>
    </div>
    <a href="{{edit_url}}" target="_blank" class="button button-small">편집</a>
</div>
</script>

<script type="text/html" id="dim-nothumb-item-tmpl">
<div class="dim-nt-item" data-post-id="{{post_id}}">
    <span class="dim-nt-num">{{num}}</span>
    <span class="dim-nt-type">{{type}}</span>
    <a href="{{edit_url}}" target="_blank"><strong>{{title}}</strong></a>
    <span class="dim-muted">{{date}}</span>
    <button class="button button-small dim-auto-thumb-btn" data-post-id="{{post_id}}" style="margin-left:auto;">🖼 썸네일 설정</button>
    <span class="dim-auto-thumb-result" style="font-size:11px;"></span>
</div>
</script>

<script type="text/html" id="dim-brokenimg-item-tmpl">
<div class="dim-nt-item">
    <span class="dim-nt-num">{{num}}</span>
    <span class="dim-nt-type">{{type}}</span>
    <a href="{{edit_url}}" target="_blank"><strong>{{title}}</strong></a>
    <span class="dim-muted">{{date}}</span>
    <span class="dim-err-count">⚠ 이미지 오류 {{broken_count}}개</span>
    <span class="dim-broken-ids">{{broken_ids_html}}</span>
</div>
</script>
