( function () {
    var el              = wp.element.createElement;
    var registerPlugin  = wp.plugins.registerPlugin;
    var BlockControls   = wp.blockEditor.BlockControls;
    var ToolbarGroup    = wp.components.ToolbarGroup;
    var ToolbarButton   = wp.components.ToolbarButton;
    var useSelect       = wp.data.useSelect;
    var createBlock     = wp.blocks.createBlock;

    var ALLOWED = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/quote',
        'core/pullquote',
    ];

    // ── 불필요한 툴바 버튼 숨기기 (MutationObserver) ─────────────
    var HIDE_LABELS = [
        'AI 도우미', 'Rank Math AI', 'Content AI',
        '텍스트 정렬', '왼쪽 정렬', '가운데 정렬', '오른쪽 정렬',
        '이미지 링크', '자르기', '이미지 자르기',
        '텍스트 오버레이 추가', '텍스트 추가',
        '대체 텍스트', '대체 텍스트 없음', '대체 텍스트 추가',
    ];

    wp.domReady( function () {
        function hide() {
            HIDE_LABELS.forEach( function ( label ) {
                document.querySelectorAll(
                    'button[aria-label="' + label + '"], button[aria-label^="' + label + ' "]'
                ).forEach( function ( btn ) {
                    btn.style.cssText += ';display:none!important';
                    var g = btn.closest( '.components-toolbar-group' );
                    if ( g ) {
                        var visible = Array.from( g.querySelectorAll( 'button' ) ).filter(
                            function ( b ) { return getComputedStyle( b ).display !== 'none'; }
                        );
                        if ( ! visible.length ) g.style.cssText += ';display:none!important';
                    }
                } );
            } );
            document.querySelectorAll(
                '[class*="rank-math"][class*="ai"],[class*="rank-math"][class*="toolbar"]'
            ).forEach( function ( n ) { n.style.cssText += ';display:none!important'; } );
        }
        new MutationObserver( hide ).observe( document.body, { childList: true, subtree: true } );
        hide();
    } );

    // ── 이미지 삽입 버튼 ─────────────────────────────────────────
    registerPlugin( 'quick-image-insert', {
        render: function () {
            // 현재 선택된 블록 감시
            var selectedBlock = useSelect( function ( select ) {
                return select( 'core/block-editor' ).getSelectedBlock();
            } );

            if ( ! selectedBlock || ALLOWED.indexOf( selectedBlock.name ) === -1 ) {
                return null;
            }

            function handleClick() {
                // 버튼을 누른 순간의 clientId + index를 바로 확인
                var clientId = wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
                var baseIndex = wp.data.select( 'core/block-editor' ).getBlockIndex( clientId );

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

                    // H2 블록 개수 = 삽입 한도
                    var h2Count = wp.data.select( 'core/block-editor' ).getBlocks()
                        .filter( function ( b ) {
                            return b.name === 'core/heading' && b.attributes.level === 2;
                        } ).length;

                    var all = [];
                    selection.each( function ( a ) { all.push( a.toJSON() ); } );
                    if ( ! all.length ) return;

                    var limit    = h2Count > 0 ? h2Count : all.length;
                    var toInsert = all.slice( 0, limit );
                    var toDelete = all.slice( limit );

                    // 초과 이미지 → 미디어 영구 삭제
                    toDelete.forEach( function ( d ) {
                        wp.apiFetch( { path: '/wp/v2/media/' + d.id + '?force=true', method: 'DELETE' } )
                          .catch( function ( e ) { console.warn( '[QII] 삭제 실패 ID=' + d.id, e ); } );
                    } );

                    // 현재 블록 바로 아래에 이미지 삽입
                    var blocks = toInsert.map( function ( d ) {
                        return createBlock( 'core/image', {
                            url: d.url, alt: d.alt || d.title || '', caption: '', id: d.id,
                        } );
                    } );

                    wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks, baseIndex + 1 );

                    // 첫 번째 이미지를 대표이미지로 설정
                    wp.data.dispatch( 'core/editor' ).editPost( { featured_media: toInsert[ 0 ].id } );
                } );

                frame.open();
            }

            return el( BlockControls, null,
                el( ToolbarGroup, null,
                    el( ToolbarButton, {
                        icon: 'format-image',
                        label: '미디어 / 파일 찾기',
                        onClick: handleClick,
                        showTooltip: true,
                    } )
                )
            );
        },
    } );
} )();
