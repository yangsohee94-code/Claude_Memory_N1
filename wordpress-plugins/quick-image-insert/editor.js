( function () {
    var el = window.wp.element.createElement;
    var Fragment = window.wp.element.Fragment;
    var addFilter = window.wp.hooks.addFilter;
    var createBlock = window.wp.blocks.createBlock;
    var BlockControls = window.wp.blockEditor.BlockControls;
    var ToolbarGroup = window.wp.components.ToolbarGroup;
    var ToolbarButton = window.wp.components.ToolbarButton;

    var allowedBlocks = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/quote',
        'core/pullquote',
    ];

    // ── 숨길 버튼의 aria-label 목록 ──────────────────────────────
    var HIDE_LABELS = [
        // AI 버튼들
        'AI 도우미',
        'Rank Math AI',
        'Content AI',
        // 텍스트 정렬
        '텍스트 정렬',
        '왼쪽 정렬',
        '가운데 정렬',
        '오른쪽 정렬',
        // 이미지 블록
        '이미지 링크',
        '자르기',
        '이미지 자르기',
        '텍스트 오버레이 추가',
        '텍스트 추가',
        '대체 텍스트',
        '대체 텍스트 없음',
        '대체 텍스트 추가',
    ];

    // ── MutationObserver: 툴바가 바뀔 때마다 지정 버튼을 숨김 ───
    // CSS 대신 JS로 처리해서 동적 렌더링에도 확실히 적용됨
    wp.domReady( function () {
        function hideUnwantedButtons() {
            HIDE_LABELS.forEach( function ( label ) {
                // 정확 일치 + 부분 일치(단축키 등이 붙는 경우 대비)
                var selector = [
                    'button[aria-label="' + label + '"]',
                    'button[aria-label^="' + label + '"]',
                ].join( ', ' );

                document.querySelectorAll( selector ).forEach( function ( btn ) {
                    if ( btn.style.display === 'none' ) return;
                    btn.style.cssText += 'display:none!important';

                    // 해당 버튼만 들어있는 그룹 separator도 숨기기
                    var group = btn.closest( '.components-toolbar-group' );
                    if ( group ) {
                        var visible = Array.from(
                            group.querySelectorAll( 'button' )
                        ).filter( function ( b ) {
                            return b.style.display !== 'none' && getComputedStyle( b ).display !== 'none';
                        } );
                        if ( visible.length === 0 ) {
                            group.style.cssText += 'display:none!important';
                        }
                    }
                } );

                // Rank Math AI: 아이콘 클래스로도 탐색
                document.querySelectorAll(
                    '[class*="rank-math"][class*="ai"], [class*="rank-math"][class*="toolbar"]'
                ).forEach( function ( el ) {
                    el.style.cssText += 'display:none!important';
                } );
            } );
        }

        var observer = new MutationObserver( hideUnwantedButtons );
        observer.observe( document.body, { childList: true, subtree: true } );
        hideUnwantedButtons();
    } );

    // ── addFilter: 허용된 블록 툴바에 이미지 삽입 버튼 추가 ─────
    addFilter(
        'editor.BlockEdit',
        'quick-image-insert/toolbar',
        function ( BlockEdit ) {
            return function ( props ) {
                var baseEdit = el( BlockEdit, props );

                if ( allowedBlocks.indexOf( props.name ) === -1 ) {
                    return baseEdit;
                }

                // 버튼 클릭 당시의 clientId를 클로저로 고정
                var capturedClientId = props.clientId;

                function openMediaLibrary() {
                    // 미디어 열기 직전에 최신 인덱스를 한 번 더 확인
                    var snapshotClientId = capturedClientId;

                    var frame = wp.media( {
                        title: '미디어 / 파일 찾기 (여러 개 선택 가능)',
                        button: { text: '이미지 삽입' },
                        multiple: true,
                        frame: 'post',
                        state: 'insert',
                        library: { type: 'image' },
                    } );

                    frame.on( 'insert', function () {
                        var selection = frame.state().get( 'selection' );

                        // 스냅샷 clientId 기준으로 인덱스 계산
                        var insertIndex =
                            wp.data
                                .select( 'core/block-editor' )
                                .getBlockIndex( snapshotClientId ) + 1;

                        var imageBlocks = [];
                        var firstImageId = null;

                        selection.each( function ( attachment ) {
                            var data = attachment.toJSON();
                            // 첫 번째 이미지 ID 기록
                            if ( firstImageId === null ) {
                                firstImageId = data.id;
                            }
                            imageBlocks.push(
                                createBlock( 'core/image', {
                                    url: data.url,
                                    alt: data.alt || data.title || '',
                                    caption: '',
                                    id: data.id,
                                } )
                            );
                        } );

                        if ( imageBlocks.length === 0 ) return;

                        // 현재 블록 바로 아래에 순서대로 삽입
                        wp.data
                            .dispatch( 'core/block-editor' )
                            .insertBlocks( imageBlocks, insertIndex );

                        // 첫 번째 이미지를 대표이미지로 자동 설정
                        if ( firstImageId ) {
                            wp.data
                                .dispatch( 'core/editor' )
                                .editPost( { featured_media: firstImageId } );
                        }
                    } );

                    frame.open();
                }

                return el(
                    Fragment,
                    null,
                    baseEdit,
                    el(
                        BlockControls,
                        null,
                        el(
                            ToolbarGroup,
                            null,
                            el( ToolbarButton, {
                                icon: 'format-image',
                                label: '미디어 / 파일 찾기',
                                onClick: openMediaLibrary,
                                showTooltip: true,
                            } )
                        )
                    )
                );
            };
        }
    );
} )();
