( function () {
    var el                         = wp.element.createElement;
    var Fragment                   = wp.element.Fragment;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var BlockControls              = wp.blockEditor.BlockControls;
    var ToolbarButton              = wp.components.ToolbarButton;
    var createBlock                = wp.blocks.createBlock;

    // ── H2 블록 바로 아래에 이미지를 순서대로 배치하는 핵심 함수 ──
    function distributeImagesToH2( attachments ) {
        var allBlocks = wp.data.select( 'core/block-editor' ).getBlocks();
        var h2Blocks  = allBlocks.filter( function ( b ) {
            return b.name === 'core/heading' && b.attributes.level === 2;
        } );

        if ( h2Blocks.length === 0 ) {
            alert( 'H2 소제목이 없습니다. H2를 먼저 작성해 주세요.' );
            return;
        }

        var limit    = Math.min( attachments.length, h2Blocks.length );
        var toInsert = attachments.slice( 0, limit );
        var toDelete = attachments.slice( limit );

        toDelete.forEach( function ( d ) {
            wp.apiFetch( {
                path  : '/wp/v2/media/' + d.id + '?force=true',
                method: 'DELETE',
            } ).catch( function ( e ) {
                console.warn( '[QII] 삭제 실패 ID=' + d.id, e );
            } );
        } );

        if ( ! toInsert.length ) return;

        wp.data.dispatch( 'core/editor' ).editPost( { featured_media: toInsert[ 0 ].id } );

        for ( var i = toInsert.length - 1; i >= 0; i-- ) {
            var h2ClientId = h2Blocks[ i ].clientId;
            var h2Index    = wp.data.select( 'core/block-editor' ).getBlockIndex( h2ClientId );
            var imgBlock   = createBlock( 'core/image', {
                url    : toInsert[ i ].url,
                alt    : toInsert[ i ].alt || toInsert[ i ].title || '',
                caption: '',
                id     : toInsert[ i ].id,
            } );
            wp.data.dispatch( 'core/block-editor' ).insertBlocks( [ imgBlock ], h2Index + 1 );
        }
    }

    // ── 미디어 라이브러리 열기 ────────────────────────────────────
    function openMedia() {
        var frame = wp.media( {
            title   : '이미지 선택 — H2 소제목 순서대로 배치됩니다',
            button  : { text: '이미지 삽입' },
            multiple: 'add',
            library : { type: 'image' },
        } );
        frame.on( 'select', function () {
            var attachments = [];
            frame.state().get( 'selection' ).each( function ( a ) {
                attachments.push( a.toJSON() );
            } );
            distributeImagesToH2( attachments );
        } );
        frame.open();
    }

    // ── 브라우저 Canvas 자르기 모달 ──────────────────────────────
    function openCropModal( imgUrl, origId, clientId, origAlt ) {
        if ( ! imgUrl ) { alert( '이미지 URL이 없습니다.' ); return; }

        // ─ 오버레이 ─
        var overlay = document.createElement( 'div' );
        overlay.style.cssText = [
            'position:fixed;top:0;left:0;right:0;bottom:0',
            'background:rgba(0,0,0,0.88)',
            'z-index:999999',
            'display:flex;flex-direction:column;align-items:center;justify-content:center',
            'user-select:none',
        ].join( ';' );

        var title = document.createElement( 'div' );
        title.textContent = '드래그해서 자를 영역을 선택하세요';
        title.style.cssText = 'color:#fff;font-size:15px;margin-bottom:12px;';

        var canvas = document.createElement( 'canvas' );
        canvas.style.cssText = 'cursor:crosshair;display:block;touch-action:none;';
        var ctx = canvas.getContext( '2d' );

        var statusEl = document.createElement( 'div' );
        statusEl.style.cssText = 'color:#aaa;font-size:13px;margin-top:10px;min-height:20px;';

        var btnRow = document.createElement( 'div' );
        btnRow.style.cssText = 'display:flex;gap:12px;margin-top:14px;';

        var applyBtn  = makeBtn( '적용', '#007cba' );
        var cancelBtn = makeBtn( '취소', '#555' );

        btnRow.appendChild( applyBtn );
        btnRow.appendChild( cancelBtn );
        overlay.appendChild( title );
        overlay.appendChild( canvas );
        overlay.appendChild( statusEl );
        overlay.appendChild( btnRow );
        document.body.appendChild( overlay );

        function makeBtn( text, bg ) {
            var b = document.createElement( 'button' );
            b.textContent = text;
            b.style.cssText = 'padding:8px 24px;background:' + bg + ';color:#fff;border:none;border-radius:4px;font-size:14px;cursor:pointer;';
            return b;
        }

        // ─ 자르기 상태 ─
        var cropRect = null;   // {x,y,w,h} in canvas-display pixels
        var dragging = false;
        var startX   = 0, startY = 0;

        function normalizeRect( r ) {
            return {
                x: r.w < 0 ? r.x + r.w : r.x,
                y: r.h < 0 ? r.y + r.h : r.y,
                w: Math.abs( r.w ),
                h: Math.abs( r.h ),
            };
        }

        function draw() {
            ctx.clearRect( 0, 0, canvas.width, canvas.height );
            ctx.drawImage( img, 0, 0, canvas.width, canvas.height );

            if ( cropRect ) {
                var r = normalizeRect( cropRect );
                if ( r.w > 2 && r.h > 2 ) {
                    ctx.fillStyle = 'rgba(0,0,0,0.5)';
                    ctx.fillRect( 0, 0, canvas.width, r.y );
                    ctx.fillRect( 0, r.y, r.x, r.h );
                    ctx.fillRect( r.x + r.w, r.y, canvas.width - r.x - r.w, r.h );
                    ctx.fillRect( 0, r.y + r.h, canvas.width, canvas.height - r.y - r.h );

                    ctx.strokeStyle = '#fff';
                    ctx.lineWidth   = 2;
                    ctx.setLineDash( [ 6, 3 ] );
                    ctx.strokeRect( r.x, r.y, r.w, r.h );
                    ctx.setLineDash( [] );
                }
            }
        }

        // ─ 마우스 이벤트 ─
        function getPos( e ) {
            var rect = canvas.getBoundingClientRect();
            return {
                x: ( e.clientX || e.touches[ 0 ].clientX ) - rect.left,
                y: ( e.clientY || e.touches[ 0 ].clientY ) - rect.top,
            };
        }

        function onStart( e ) {
            e.preventDefault();
            var p = getPos( e );
            startX   = p.x; startY = p.y;
            cropRect = { x: startX, y: startY, w: 0, h: 0 };
            dragging = true;
        }
        function onMove( e ) {
            e.preventDefault();
            if ( ! dragging ) return;
            var p = getPos( e );
            cropRect.w = p.x - startX;
            cropRect.h = p.y - startY;
            draw();
        }
        function onEnd() { dragging = false; }

        canvas.addEventListener( 'mousedown',  onStart );
        canvas.addEventListener( 'mousemove',  onMove  );
        canvas.addEventListener( 'mouseup',    onEnd   );
        canvas.addEventListener( 'touchstart', onStart, { passive: false } );
        canvas.addEventListener( 'touchmove',  onMove,  { passive: false } );
        canvas.addEventListener( 'touchend',   onEnd   );

        // ─ 취소 ─
        cancelBtn.addEventListener( 'click', function () {
            document.body.removeChild( overlay );
        } );

        // ─ 적용 ─
        applyBtn.addEventListener( 'click', function () {
            if ( ! cropRect ) {
                alert( '자를 영역을 드래그해서 선택해 주세요.' );
                return;
            }
            var r = normalizeRect( cropRect );
            if ( r.w < 10 || r.h < 10 ) {
                alert( '자를 영역이 너무 작습니다.' );
                return;
            }

            // 실제 픽셀 좌표로 변환
            var scaleX = img.naturalWidth  / canvas.width;
            var scaleY = img.naturalHeight / canvas.height;

            var outCanvas    = document.createElement( 'canvas' );
            outCanvas.width  = Math.round( r.w * scaleX );
            outCanvas.height = Math.round( r.h * scaleY );
            var outCtx = outCanvas.getContext( '2d' );
            outCtx.drawImage(
                img,
                r.x * scaleX, r.y * scaleY, r.w * scaleX, r.h * scaleY,
                0, 0, outCanvas.width, outCanvas.height
            );

            applyBtn.textContent  = '업로드 중…';
            applyBtn.disabled     = true;
            cancelBtn.disabled    = true;
            statusEl.textContent  = '잘린 이미지를 업로드하는 중입니다…';

            outCanvas.toBlob( function ( blob ) {
                var origName  = imgUrl.split( '/' ).pop().split( '?' )[ 0 ];
                var base      = origName.replace( /\.[^.]+$/, '' );
                var newName   = base + '-cropped.jpg';

                wp.apiFetch( {
                    path   : '/wp/v2/media',
                    method : 'POST',
                    headers: {
                        'Content-Disposition': 'attachment; filename="' + newName + '"',
                        'Content-Type'       : 'image/jpeg',
                    },
                    body: blob,
                } )
                .then( function ( newMedia ) {
                    // 블록 속성 업데이트
                    wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, {
                        url: newMedia.source_url,
                        id : newMedia.id,
                        alt: origAlt || '',
                    } );

                    // 원본 미디어 삭제
                    if ( origId ) {
                        wp.apiFetch( {
                            path  : '/wp/v2/media/' + origId + '?force=true',
                            method: 'DELETE',
                        } ).catch( function ( e ) {
                            console.warn( '[QII] 원본 삭제 실패', e );
                        } );
                    }

                    document.body.removeChild( overlay );
                } )
                .catch( function ( err ) {
                    console.error( '[QII] 업로드 실패', err );
                    statusEl.textContent = '업로드 실패: ' + ( err.message || '알 수 없는 오류' );
                    applyBtn.textContent = '적용';
                    applyBtn.disabled    = false;
                    cancelBtn.disabled   = false;
                } );
            }, 'image/jpeg', 0.92 );
        } );

        // ─ 이미지 로드 ─
        var img         = new Image();
        img.crossOrigin = 'anonymous';

        img.onload = function () {
            var maxW  = window.innerWidth  * 0.85;
            var maxH  = window.innerHeight * 0.68;
            var scale = Math.min( maxW / img.naturalWidth, maxH / img.naturalHeight, 1 );
            canvas.width  = Math.round( img.naturalWidth  * scale );
            canvas.height = Math.round( img.naturalHeight * scale );
            draw();
        };

        img.onerror = function () {
            document.body.removeChild( overlay );
            alert( '이미지를 불러올 수 없습니다.' );
        };

        img.src = imgUrl;
    }

    // ── HOC: H2·H3 툴바에 이미지 삽입 버튼 + 이미지 블록에 자르기 버튼 ──
    var withImageButton = createHigherOrderComponent(
        function ( OriginalComponent ) {
            return function QiiWrapper( props ) {
                var isHeading = props.name === 'core/heading'
                    && props.attributes
                    && ( props.attributes.level === 2 || props.attributes.level === 3 );

                var isImage = props.name === 'core/image';

                return el(
                    Fragment, null,
                    el( OriginalComponent, props ),

                    // H2·H3: 이미지 삽입 버튼
                    props.isSelected && isHeading && el(
                        BlockControls, null,
                        el( ToolbarButton, {
                            icon      : 'format-image',
                            label     : '이미지 삽입 (H2 순서대로)',
                            showTooltip: true,
                            onClick   : openMedia,
                        } )
                    ),

                    // 이미지 블록: Canvas 자르기 버튼
                    props.isSelected && isImage && el(
                        BlockControls, null,
                        el( ToolbarButton, {
                            icon      : 'scissors',
                            label     : '자유 자르기',
                            showTooltip: true,
                            onClick   : ( function ( p ) {
                                return function () {
                                    openCropModal(
                                        p.attributes.url,
                                        p.attributes.id,
                                        p.clientId,
                                        p.attributes.alt
                                    );
                                };
                            } )( props ),
                        } )
                    )
                );
            };
        },
        'withImageButton'
    );

    wp.hooks.addFilter( 'editor.BlockEdit', 'quick-image-insert/button', withImageButton );

    // ── 불필요한 툴바 버튼 숨기기 (MutationObserver) ─────────────
    var HIDE_LABELS = [
        'AI 도우미', 'Rank Math AI', 'Content AI',
        '텍스트 정렬', '왼쪽 정렬', '가운데 정렬', '오른쪽 정렬',
        '이미지 링크',
        '자르기', '이미지 자르기',         // 네이티브 자르기 숨김 (커스텀 버튼으로 대체)
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
                        var vis = Array.from( g.querySelectorAll( 'button' ) ).filter(
                            function ( b ) { return getComputedStyle( b ).display !== 'none'; }
                        );
                        if ( ! vis.length ) g.style.cssText += ';display:none!important';
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
} )();
