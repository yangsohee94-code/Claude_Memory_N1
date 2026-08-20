<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dim-wrap">
    <h1>🖼️ 이미지 최적화 관리</h1>

    <!-- 탭 -->
    <div class="dim-tabs">
        <button class="dim-tab active" data-tab="duplicate">중복 이미지 병합</button>
        <button class="dim-tab" data-tab="nonwebp">WebP 변환</button>
        <button class="dim-tab" data-tab="nothumb">대표이미지 없는 글</button>
        <button class="dim-tab" data-tab="stats">용량 현황</button>
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

    <!-- 전체 알림 -->
    <div id="dim-notice" style="display:none;" class="dim-notice"></div>
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
    <a href="{{edit_url}}" target="_blank" class="button button-small">편집</a>
</div>
</script>

<script type="text/html" id="dim-nothumb-item-tmpl">
<div class="dim-nt-item">
    <span class="dim-nt-type">{{type}}</span>
    <a href="{{edit_url}}" target="_blank"><strong>{{title}}</strong></a>
    <span class="dim-muted">{{date}}</span>
</div>
</script>
