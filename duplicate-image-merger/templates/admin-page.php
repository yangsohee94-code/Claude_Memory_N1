<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dim-wrap">
    <h1>🖼️ 중복 이미지 병합 & 최적화</h1>

    <!-- 자동 최적화 패널 -->
    <div class="dim-panel dim-panel-auto">
        <h2>⚡ 자동 최적화 예약 <span class="dim-badge">매일 1회</span></h2>
        <p class="dim-panel-desc">
            중복 병합 → WebP 변환 → 대표이미지 정합성 수정을 매일 자동 실행합니다.
        </p>
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

    <!-- 도구 버튼 -->
    <div class="dim-action-bar">
        <button id="dim-scan-btn" class="button button-primary">🔍 중복 이미지 스캔</button>
        <button id="dim-auto-merge-btn" class="button" disabled>🔗 자동 병합 (사용 중 기준)</button>
        <button id="dim-merge-selected-btn" class="button" disabled>✅ 선택 항목 병합</button>
        <span class="dim-spacer"></span>
        <button id="dim-webp-btn" class="button dim-btn-webp">🌐 전체 WebP 변환</button>
        <button id="dim-thumb-btn" class="button dim-btn-thumb">🖼 대표이미지 정합성 수정</button>
    </div>

    <!-- 진행 -->
    <div id="dim-progress" style="display:none;">
        <div class="dim-progress-inner"><div id="dim-progress-bar" class="dim-progress-bar"></div></div>
        <span id="dim-progress-text"></span>
    </div>

    <!-- 요약 -->
    <div id="dim-summary" style="display:none;" class="dim-summary">
        <span>스캔 <strong id="dim-total-scanned">0</strong>개</span>
        <span>중복 그룹 <strong id="dim-group-count">0</strong>개</span>
        <span>절약 가능 <strong id="dim-saved-size">0</strong></span>
    </div>

    <!-- 전체 선택 -->
    <div id="dim-select-all-wrap" style="display:none;" class="dim-select-all-wrap">
        <label><input type="checkbox" id="dim-select-all"> 전체 선택 / 해제</label>
        <span id="dim-selected-count" class="dim-muted">0개 선택됨</span>
    </div>

    <!-- 결과 -->
    <div id="dim-results"></div>
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
