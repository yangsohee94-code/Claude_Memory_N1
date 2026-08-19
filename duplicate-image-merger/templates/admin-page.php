<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap dim-wrap">
    <h1>🖼️ 중복 이미지 병합</h1>

    <!-- 상단 액션 바 -->
    <div class="dim-action-bar">
        <button id="dim-scan-btn" class="button button-primary button-large">
            🔍 중복 이미지 스캔
        </button>
        <button id="dim-auto-merge-btn" class="button button-large dim-btn-auto" disabled>
            ⚡ 자동 병합 (사용 중 이미지 기준)
        </button>
        <button id="dim-merge-selected-btn" class="button button-large dim-btn-merge" disabled>
            🔗 선택 항목 병합
        </button>

        <span class="dim-spacer"></span>

        <label class="dim-auto-schedule-wrap">
            <input type="checkbox" id="dim-auto-schedule"> 매일 자동 병합 예약
        </label>
    </div>

    <!-- 진행 상태 -->
    <div id="dim-progress" class="dim-progress" style="display:none;">
        <div class="dim-progress-inner">
            <div class="dim-progress-bar" id="dim-progress-bar"></div>
        </div>
        <span id="dim-progress-text">스캔 중...</span>
    </div>

    <!-- 요약 -->
    <div id="dim-summary" class="dim-summary" style="display:none;">
        <span>총 <strong id="dim-total-scanned">0</strong>개 이미지 스캔</span>
        <span>중복 그룹 <strong id="dim-group-count">0</strong>개</span>
        <span>중복 이미지 <strong id="dim-dup-count">0</strong>개</span>
        <span>절약 가능 용량 <strong id="dim-saved-size">0</strong></span>
    </div>

    <!-- 전체 선택 -->
    <div id="dim-select-all-wrap" class="dim-select-all-wrap" style="display:none;">
        <label>
            <input type="checkbox" id="dim-select-all">
            <strong>전체 선택 / 해제</strong>
        </label>
        <span id="dim-selected-count" class="dim-selected-count">0개 선택됨</span>
    </div>

    <!-- 결과 목록 -->
    <div id="dim-results" class="dim-results"></div>

    <!-- 알림 -->
    <div id="dim-notice" class="dim-notice" style="display:none;"></div>
</div>

<!-- 그룹 카드 템플릿 -->
<script type="text/html" id="dim-group-tmpl">
<div class="dim-group" data-hash="{{hash}}" data-group-idx="{{idx}}">
    <div class="dim-group-header">
        <label class="dim-group-check-wrap">
            <input type="checkbox" class="dim-group-select-all" data-group-idx="{{idx}}">
            그룹 전체 선택
        </label>
        <span class="dim-group-meta">{{count}}개 중복 · {{hash_short}}</span>
    </div>
    <div class="dim-group-items">{{items}}</div>
    <div class="dim-group-footer">
        <button class="button dim-merge-group-btn" data-group-idx="{{idx}}">
            이 그룹 병합
        </button>
    </div>
</div>
</script>

<script type="text/html" id="dim-item-tmpl">
<div class="dim-item {{used_class}}" data-id="{{id}}" data-group-idx="{{group_idx}}">
    <div class="dim-item-check">
        <input type="checkbox" class="dim-item-checkbox" value="{{id}}" data-group-idx="{{group_idx}}">
    </div>
    <div class="dim-item-thumb">
        <img src="{{url}}" alt="{{title}}" loading="lazy">
    </div>
    <div class="dim-item-info">
        <strong>{{title}}</strong>
        <span class="dim-item-date">{{date}}</span>
        <span class="dim-item-size">{{size}}</span>
        <span class="dim-item-id">ID: {{id}}</span>
        {{used_badge}}
    </div>
    <div class="dim-item-actions">
        <label>
            <input type="radio" name="dim-keep-{{group_idx}}" value="{{id}}" class="dim-keep-radio">
            원본으로 유지
        </label>
    </div>
</div>
</script>
