/* DIM — Gutenberg 편집기 오른쪽 패널: 이미지 관리 */
(function ($) {
    'use strict';

    if ( typeof wp === 'undefined' || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) {
        return;
    }

    var el         = wp.element.createElement;
    var useState   = wp.element.useState;
    var useSelect  = wp.data.useSelect;
    var register   = wp.plugins.registerPlugin;
    var Button     = wp.components.Button;
    var Spinner    = wp.components.Spinner;

    // WordPress 6.6+ moved PluginDocumentSettingPanel to wp.editor
    var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel )
             || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

    if ( ! Panel ) return;

    function countH2( blocks ) {
        var n = 0;
        for ( var i = 0; i < blocks.length; i++ ) {
            if ( blocks[i].name === 'core/heading' && blocks[i].attributes.level === 2 ) n++;
            if ( blocks[i].innerBlocks && blocks[i].innerBlocks.length ) n += countH2( blocks[i].innerBlocks );
        }
        return n;
    }

    function DimImagePanel() {
        var postId = useSelect( function ( select ) {
            return select( 'core/editor' ).getCurrentPostId();
        } );

        var h2Count = useSelect( function ( select ) {
            return countH2( select( 'core/block-editor' ).getBlocks() );
        } );

        var _s1 = useState( false ),  scanning      = _s1[0], setScanning      = _s1[1];
        var _s2 = useState( false ),  removing      = _s2[0], setRemoving      = _s2[1];
        var _s3 = useState( null ),   brokenImages  = _s3[0], setBrokenImages  = _s3[1];
        var _s4 = useState( '' ),     message       = _s4[0], setMessage       = _s4[1];
        var _s5 = useState( true ),   msgOk         = _s5[0], setMsgOk         = _s5[1];

        function scanPost() {
            if ( ! postId ) return;
            setScanning( true );
            setMessage( '' );
            setBrokenImages( null );

            $.post( DIM_EDITOR.ajax_url, {
                action:  'dim_editor_scan_post',
                nonce:   DIM_EDITOR.nonce,
                post_id: postId
            }, function ( res ) {
                setScanning( false );
                if ( res.success ) {
                    var imgs = res.data.broken_images || [];
                    setBrokenImages( imgs );
                    if ( imgs.length === 0 ) {
                        setMsgOk( true );
                        setMessage( '✅ 오류 이미지가 없습니다.' );
                    } else {
                        setMsgOk( false );
                        setMessage( '⚠ 오류 이미지 ' + imgs.length + '개 발견' );
                    }
                } else {
                    setMsgOk( false );
                    setMessage( '❌ ' + ( ( res.data && res.data.message ) || '스캔 오류' ) );
                }
            }, 'json' ).fail( function () {
                setScanning( false );
                setMsgOk( false );
                setMessage( '❌ 요청 실패' );
            } );
        }

        function removeBroken() {
            if ( ! brokenImages || brokenImages.length === 0 ) return;
            setRemoving( true );

            var ids    = brokenImages.map( function ( img ) { return img.id; } );
            var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
            wp.data.dispatch( 'core/block-editor' ).resetBlocks( filterBlocks( blocks, ids ) );

            setRemoving( false );
            setBrokenImages( [] );
            setMsgOk( true );
            setMessage( '✅ 오류 블록 제거 완료 — "업데이트" 버튼을 눌러 저장하세요.' );
        }

        function filterBlocks( blocks, ids ) {
            var out = [];
            for ( var i = 0; i < blocks.length; i++ ) {
                var b = blocks[ i ];
                if ( b.name === 'core/image' && ids.indexOf( b.attributes.id ) !== -1 ) continue;
                if ( b.innerBlocks && b.innerBlocks.length ) {
                    b = Object.assign( {}, b, { innerBlocks: filterBlocks( b.innerBlocks, ids ) } );
                }
                out.push( b );
            }
            return out;
        }

        var hasBroken = brokenImages && brokenImages.length > 0;

        return el( Panel, { name: 'dim-image-manager', title: '🖼 이미지 관리 (DIM)', icon: 'format-image', className: 'dim-mgr-panel' },
            el( 'div', { style: { paddingTop: '4px' } },

                /* 버튼 행 */
                el( 'div', { style: { display: 'flex', gap: '6px', marginBottom: '8px' } },
                    el( Button, {
                        variant:  'secondary',
                        size:     'small',
                        onClick:  scanPost,
                        disabled: scanning || removing,
                        style:    { flex: 1, justifyContent: 'center' }
                    }, scanning
                        ? el( 'span', null, el( Spinner, { style: { marginRight: '4px' } } ), '스캔 중…' )
                        : '🔍 오류 이미지 스캔'
                    ),
                    hasBroken && el( Button, {
                        variant:       'primary',
                        isDestructive: true,
                        size:          'small',
                        onClick:       removeBroken,
                        disabled:      removing,
                        style:         { flex: 1, justifyContent: 'center' }
                    }, '🗑 오류 블록 제거' )
                ),

                /* 오류 이미지 목록 */
                hasBroken && el( 'ul', {
                    style: { margin: '0 0 8px', padding: '0 0 0 16px', fontSize: '12px', color: '#d63638' }
                },
                    brokenImages.map( function ( img ) {
                        return el( 'li', { key: img.id },
                            'ID ' + img.id + ( img.name ? ' — ' + img.name : '' )
                        );
                    } )
                ),

                /* 결과 메시지 */
                message && el( 'p', {
                    style: {
                        margin:     '4px 0 0',
                        fontSize:   '12px',
                        lineHeight: '1.5',
                        color:      msgOk ? '#2271b1' : '#d63638'
                    }
                }, message )
            )
        );
    }

    register( 'dim-image-manager', { render: DimImagePanel, icon: 'format-image' } );

    // ── DIM 패널을 plugin 패널 그룹 최상단으로 이동 ─────────────────
    (function () {
        function moveDimPanelToTop() {
            var inner = document.querySelector( '.dim-mgr-panel' );
            if ( ! inner ) return;
            var outer = inner.closest( '.plugin-document-setting-panel' );
            if ( ! outer || ! outer.parentNode ) return;
            // 같은 부모 안의 첫 번째 .plugin-document-setting-panel 앞에 삽입
            // (firstElementChild 사용 시 core 패널 앞으로 가버리는 문제 방지)
            var firstPlugin = outer.parentNode.querySelector( '.plugin-document-setting-panel' );
            if ( ! firstPlugin || firstPlugin === outer ) return;
            outer.parentNode.insertBefore( outer, firstPlugin );
        }

        var mo = new MutationObserver( moveDimPanelToTop );
        mo.observe( document.body, { childList: true, subtree: true } );
        setTimeout( moveDimPanelToTop, 800 );
        setTimeout( moveDimPanelToTop, 2500 );
    }() );

    // ── H2 소제목 카운트를 "글 블록 ×" 탭바 옆에 주입 ──────────────
    (function () {
        var badge = null;

        function injectBadge() {
            // 탭바 컨테이너 후보 (WP 버전별 클래스명 다름)
            var header = document.querySelector( '.edit-post-sidebar__panel-tabs' )
                      || document.querySelector( '.interface-complementary-area-header__small' )
                      || document.querySelector( '.interface-complementary-area-header' );
            if ( ! header ) return null;

            var b = document.createElement( 'span' );
            b.id = 'dim-h2-badge';
            b.style.cssText = [
                'display:inline-block',
                'font-size:11px',
                'font-weight:600',
                'color:#2271b1',
                'background:#e8f3fb',
                'border:1px solid #c5d9ea',
                'border-radius:3px',
                'padding:1px 6px',
                'margin-left:8px',
                'vertical-align:middle',
                'white-space:nowrap',
                'line-height:1.6',
            ].join( ';' );
            header.appendChild( b );
            return b;
        }

        function updateH2Badge() {
            var count = countH2( wp.data.select( 'core/block-editor' ).getBlocks() );

            if ( ! badge || ! badge.isConnected ) {
                badge = document.getElementById( 'dim-h2-badge' ) || injectBadge();
            }
            if ( badge ) badge.textContent = '소제목 ' + count + '개';
        }

        wp.data.subscribe( function () {
            requestAnimationFrame( updateH2Badge );
        } );
        setTimeout( updateH2Badge, 1000 );
        setTimeout( updateH2Badge, 2500 );
    }() );

    // 편집기 로드 후 "글" 탭(Document panel)을 기본으로 표시
    (function () {
        var done = false;
        var unsub = wp.data.subscribe( function () {
            if ( done ) return;
            var postId = wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId();
            if ( ! postId ) return;
            done = true;
            unsub();
            try {
                var ep = wp.data.dispatch( 'core/edit-post' ) || wp.data.dispatch( 'core/editor' );
                if ( ep && ep.openGeneralSidebar ) {
                    ep.openGeneralSidebar( 'edit-post/document' );
                }
            } catch ( e ) {}
        } );
    }() );

}( jQuery ) );
