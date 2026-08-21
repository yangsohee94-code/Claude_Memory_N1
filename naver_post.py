#!/usr/bin/env python3
"""네이버 블로그 자동 발행 모듈 — Playwright 기반 (봇 감지 최소화)"""
import os, re, json, time, random, html as html_lib, datetime
from pathlib import Path
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
import anthropic
from dotenv import load_dotenv

load_dotenv()

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")

# ── User-Agent 풀 (실행마다 랜덤 선택) ──────────────────────────────────────
_USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36",
]
_VIEWPORTS = [
    {"width": 1280, "height": 900},
    {"width": 1366, "height": 768},
    {"width": 1440, "height": 900},
    {"width": 1920, "height": 1080},
]

# ── webdriver 감지 차단 스크립트 ──────────────────────────────────────────────
_STEALTH_JS = """
() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });

    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Array;
    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Promise;
    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Symbol;

    // window.chrome 주입 (headless Chromium에는 없어서 즉각 감지됨)
    if (!window.chrome) {
        window.chrome = {
            app: { isInstalled: false, InstallState: {DISABLED:'disabled',INSTALLED:'installed',NOT_INSTALLED:'not_installed'}, RunningState: {CANNOT_RUN:'cannot_run',READY_TO_RUN:'ready_to_run',RUNNING:'running'} },
            runtime: { OnInstalledReason: {}, PlatformArch: {}, PlatformOs: {}, RequestUpdateCheckStatus: {} },
            loadTimes: function() { return {}; },
            csi: function() { return {}; },
        };
    }

    // navigator.plugins — 진짜 Chrome처럼 객체 배열로
    const pluginData = [
        { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
        { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '' },
        { name: 'Native Client', filename: 'internal-nacl-plugin', description: '' },
    ];
    Object.defineProperty(navigator, 'plugins', {
        get: () => {
            const arr = pluginData.map(p => {
                const obj = Object.create(null);
                obj.name = p.name; obj.filename = p.filename; obj.description = p.description; obj.length = 0;
                return obj;
            });
            arr.item = (i) => arr[i];
            arr.namedItem = (name) => arr.find(p => p.name === name) || null;
            arr.refresh = () => {};
            return arr;
        }
    });

    Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 4 });
    Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
    Object.defineProperty(navigator, 'languages', { get: () => ['ko-KR', 'ko', 'en-US', 'en'] });

    const originalQuery = window.navigator.permissions.query;
    window.navigator.permissions.query = (parameters) =>
        parameters.name === 'notifications'
            ? Promise.resolve({ state: Notification.permission })
            : originalQuery(parameters);
}
"""


# ── 카테고리별 네이버 계정 매핑 ──────────────────────────────────────────────
# 형식: NAVER_ACCOUNT_entertainment=아이디|비밀번호|블로그카테고리명
#       NAVER_COOKIES_entertainment=JSON 배열

def _get_account_for_category(wp_category: str) -> dict | None:
    key = wp_category.lower().strip()
    raw = os.getenv(f"NAVER_ACCOUNT_{key}", "")
    if not raw:
        return None
    parts = raw.split("|")
    return {
        "id":            parts[0].strip() if len(parts) > 0 else "",
        "pw":            parts[1].strip() if len(parts) > 1 else "",
        "blog_category": parts[2].strip() if len(parts) > 2 else "",
        "cookies":       os.getenv(f"NAVER_COOKIES_{key}", ""),
    }


# ── 네이버용 콘텐츠 변환 ─────────────────────────────────────────────────────

_NAVER_SYSTEM = """
너는 워드프레스 블로그 글을 네이버 블로그 홈판 노출에 최적화된 글로 재창작하는 에이전트다.
단순 복붙 금지. 플랫폼 알고리즘과 독자 소비 방식에 맞춰 재창작한다.

[필수 준수 규칙]
1. 문체: 평서체(~이다/~했다/~된다) 유지. 존댓말 어미(~합니다/~됩니다/~입니다/~세요/~드립니다) 완전 금지.
2. 타깃 독자: 40~50대. 유행어·신조어·줄임말 금지. 어려운 용어는 한 줄 설명 병기.
3. 이모지·이모티콘 일절 금지.
4. 인사 문구 금지 ("안녕하세요" 류).
5. HTML 태그 없는 순수 텍스트로 작성.
6. 소제목은 ▶ 기호로 시작 (예: ▶ 소제목 내용).
7. 표 내용은 텍스트로 풀어서 서술.
8. 글자 수: 공백 제외 1,200~1,500자.
9. 문단은 3~4문장 이내로 짧게.
10. 단점·제약 1개 이상 반드시 포함 (장점만 나열 금지).
11. 광고 클릭 유도 문구 절대 금지.

[홈판 노출 최적화]
- 도입 3문장 안에 핵심 키워드 + 검색 의도 답변 포함.
- 소제목 1개 이상은 실제 검색어 표현 그대로 사용.
- 마무리 3종 세트 (순서 고정):
  ① 다음 편 주제 예고 1문장 (구체적 주제 명시)
  ② 이웃추가 유도 1문장 (평서체)
  ③ 독자 상황·선택을 묻는 열린 댓글 질문 1문장

[출력 형식 — 이 형식 그대로, 다른 텍스트 없이]
NAVER_TITLE: [제목 25~35자, 홈판 노출 최적화]
---NAVER_CONTENT---
[본문 전체 — 순수 텍스트]
---NAVER_END---
NAVER_TAGS: [태그1,태그2,태그3,태그4,태그5,태그6,태그7]
"""

_ANGLES = [
    "독자가 '나에게 해당되는가'에 집중. 조건·자격·상황별 분기를 중심으로 재창작.",
    "독자가 가장 궁금해할 '실제 비용·수치'를 중심으로 재창작. 구체적 금액과 조건 부각.",
    "'지금 해야 하는 이유'와 타이밍을 중심으로 재창작. 기한·변화·현재 시점 강조.",
    "'대부분 모르는 단점·함정'을 중심으로 재창작. 솔직한 시각으로 균형 있게.",
    "'경쟁 대상과 비교하면 어떤가'를 중심으로 재창작. 차이점과 선택 기준 부각.",
]

def convert_wp_to_naver(title: str, wp_html: str, category: str = "", post_seq: int = 0) -> dict:
    plain = re.sub(r'<[^>]+>', ' ', wp_html)
    plain = html_lib.unescape(plain)
    plain = re.sub(r'[ \t]+', ' ', plain)
    plain = re.sub(r'\n{3,}', '\n\n', plain).strip()

    day_num  = datetime.date.today().toordinal()
    cat_hash = sum(ord(c) for c in category)
    angle    = _ANGLES[(day_num + cat_hash + post_seq) % len(_ANGLES)]

    category_hint = f"\n카테고리: {category}" if category else ""
    user_msg = (
        f"원본 제목: {title}{category_hint}\n"
        f"재창작 각도: {angle}\n\n"
        f"원본 본문:\n{plain[:5000]}"
    )

    client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    resp = client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=2048,
        system=_NAVER_SYSTEM,
        messages=[{"role": "user", "content": user_msg}],
    )
    raw = resp.content[0].text

    result = {"title": title, "content": "", "tags": []}
    m = re.search(r'NAVER_TITLE:\s*(.+?)(?=\n)', raw)
    if m:
        result["title"] = m.group(1).strip()
    m = re.search(r'---NAVER_CONTENT---\n(.*?)---NAVER_END---', raw, re.DOTALL)
    if m:
        result["content"] = m.group(1).strip()
    m = re.search(r'NAVER_TAGS:\s*(.+?)(?=\n|$)', raw)
    if m:
        result["tags"] = [t.strip().lstrip('#') for t in m.group(1).split(',') if t.strip()]

    return result


# ── 사람처럼 보이는 유틸 ──────────────────────────────────────────────────────

def _pause(min_s: float = 0.8, max_s: float = 2.0):
    time.sleep(random.uniform(min_s, max_s))


# 중요: Playwright에서 Frame 객체에는 .keyboard 속성이 없다.
# .keyboard 는 Page 전용이며, 포커스된 요소가 iframe 안에 있어도 page.keyboard 로 입력하면 된다.
# 따라서 _human_type 은 항상 page 를 받는다.
def _human_type(page, text: str):
    """불규칙한 속도로 타이핑 (항상 page.keyboard 사용)"""
    for ch in text:
        page.keyboard.type(ch)
        if ch in '.!?。\n':
            time.sleep(random.uniform(0.3, 0.8))
        elif ch == ' ':
            time.sleep(random.uniform(0.05, 0.15))
        else:
            time.sleep(random.uniform(0.08, 0.20))


def _scroll_naturally(page):
    for _ in range(random.randint(2, 4)):
        page.mouse.wheel(0, random.randint(200, 500))
        _pause(0.4, 1.0)
    page.keyboard.press("Control+Home")
    _pause(0.5, 1.2)


# ── 쿠키 / 로그인 ────────────────────────────────────────────────────────────

def _load_cookies(context, cookies_json: str) -> bool:
    if not cookies_json:
        return False
    try:
        cookies = json.loads(cookies_json)
        context.add_cookies(cookies)
        print("   🍪 쿠키로 세션 복원")
        return True
    except Exception as e:
        print(f"   ⚠️ 쿠키 로드 실패: {e}")
        return False


def _is_logged_in(page) -> bool:
    try:
        page.goto("https://www.naver.com", timeout=20000)
        page.wait_for_load_state("domcontentloaded", timeout=10000)
        _pause(1.5, 2.5)
        # 닉네임 박스가 보이면 로그인 상태
        nickname = page.query_selector(".gnb_my_namebox, .MyView-module__gnb_my_name___nFkGX")
        if nickname and nickname.is_visible():
            return True
        # 로그인 버튼이 보이면 로그아웃 상태
        login_btn = page.query_selector("#gnb-login-block")
        if login_btn and login_btn.is_visible():
            return False
        return False
    except Exception:
        return False


# ── Smart Editor 3.0 프레임 탐색 ─────────────────────────────────────────────

def _get_editor_frame(page):
    """Smart Editor 3.0이 로드된 Frame 반환.
    Naver 블로그 에디터는 <iframe id="mainFrame" name="mainFrame"> 안에 로드됨.
    page.frame(name=...) 은 name 속성으로 찾으므로 name="mainFrame" 필요."""
    # 1차: mainFrame iframe 대기
    try:
        page.wait_for_selector("iframe#mainFrame", timeout=15000)
        frame = page.frame(name="mainFrame")
        if frame:
            frame.wait_for_load_state("domcontentloaded", timeout=20000)
            _pause(2.0, 3.0)
            return frame
    except Exception:
        pass

    # 2차: URL 패턴으로 에디터 프레임 찾기
    for frame in page.frames:
        if frame == page.main_frame:
            continue
        try:
            if "blog.naver.com" in frame.url or "se.naver.com" in frame.url:
                el = frame.query_selector('[contenteditable="true"]')
                if el:
                    print("   ℹ️ URL 패턴으로 에디터 프레임 발견")
                    return frame
        except Exception:
            continue

    # 3차: contenteditable 보유 프레임 탐색
    for frame in page.frames:
        if frame == page.main_frame:
            continue
        try:
            el = frame.query_selector('[contenteditable="true"]')
            if el:
                print("   ℹ️ contenteditable 기반으로 에디터 프레임 발견")
                return frame
        except Exception:
            continue

    print("   ⚠️ 에디터 프레임 못 찾음 — 메인 페이지로 폴백")
    return page


# ── Smart Editor 3.0 조작 (keyboard 는 항상 page 사용) ───────────────────────

def _dismiss_drafts_popup(page, frame):
    """임시저장 글 있음 팝업 처리 (있으면 '새 글 쓰기' 클릭)"""
    try:
        for target in [frame, page]:
            btn = target.query_selector("button:has-text('새 글 쓰기'), button:has-text('새로 쓰기')")
            if btn and btn.is_visible():
                btn.click()
                _pause(1.0, 2.0)
                print("   ℹ️ 임시저장 팝업 닫음")
                return
    except Exception:
        pass


def _input_title(frame, page, title: str) -> bool:
    """제목 입력 — 요소 클릭(frame)으로 포커스 후 page.keyboard로 입력"""
    title_sels = [
        ".se-title-input [contenteditable]",
        ".se-title-text [contenteditable]",
        "[placeholder='제목']",
        ".se-title-input",
        "#post-title",
        "input[placeholder*='제목']",
    ]
    for sel in title_sels:
        try:
            el = frame.wait_for_selector(sel, timeout=5000)
            if el and el.is_visible():
                el.click()
                _pause(0.4, 0.8)
                page.keyboard.press("Control+a")
                _pause(0.2, 0.3)
                page.keyboard.press("Delete")
                _human_type(page, title)
                print(f"   ✅ 제목 입력 완료")
                return True
        except PWTimeout:
            continue
    return False


def _type_in_editor(frame, page, content: str):
    """Smart Editor 3.0 본문 입력 — 포커스(frame), 타이핑(page.keyboard)"""
    js_focus = """() => {
        const editables = Array.from(document.querySelectorAll('[contenteditable="true"]'));
        const body = editables.find(el => {
            const rect = el.getBoundingClientRect();
            return rect.height > 80 && rect.top > 100;
        }) || editables[editables.length - 1];
        if (!body) return false;
        body.click();
        body.focus();
        return true;
    }"""
    ok = frame.evaluate(js_focus)
    _pause(0.5, 1.0)

    if ok:
        page.keyboard.press("Control+a")
        _pause(0.2, 0.4)
        page.keyboard.press("Delete")
        _pause(0.3, 0.6)
        paragraphs = content.split('\n')
        for i, para in enumerate(paragraphs):
            if para.strip():
                _human_type(page, para)
            page.keyboard.press("Enter")
            if i % 3 == 2:
                _pause(0.8, 1.8)
            else:
                _pause(0.2, 0.5)
    else:
        # fallback: Tab 키로 본문 영역 포커스
        page.keyboard.press("Tab")
        _pause(0.5, 1.0)
        page.keyboard.press("Control+a")
        page.keyboard.press("Delete")
        _human_type(page, content)

    print(f"   ✅ 본문 입력 완료 ({len(content)}자)")


def _input_tags(frame, page, tags: list):
    """태그 입력 — 클릭(frame 요소), 타이핑(page.keyboard)"""
    if not tags:
        return
    tag_sels = [
        ".se-tag-input input",
        "input[placeholder*='태그']",
        "#tag_input",
        ".tag_area input",
        ".se-tag-area input",
    ]
    for sel in tag_sels:
        try:
            inp = frame.wait_for_selector(sel, timeout=4000)
            if inp:
                for tag in tags[:7]:
                    inp.click()
                    _pause(0.3, 0.6)
                    _human_type(page, tag.strip())
                    _pause(0.2, 0.4)
                    page.keyboard.press("Enter")
                    _pause(0.4, 0.8)
                print(f"   ✅ 태그 {len(tags[:7])}개 입력")
                return
        except PWTimeout:
            continue


def _select_category(frame, page, category_name: str):
    """카테고리 선택"""
    if not category_name:
        return
    cat_sels = [
        ".se-category-select",
        ".category_select",
        "#category",
        "select[name*='category']",
    ]
    for sel in cat_sels:
        try:
            el = frame.wait_for_selector(sel, timeout=3000)
            if el:
                _pause(0.3, 0.7)
                try:
                    frame.select_option(sel, label=category_name)
                except Exception:
                    el.click()
                    _pause(0.5, 1.0)
                    option = frame.query_selector(
                        f"li:has-text('{category_name}'), option:has-text('{category_name}')"
                    )
                    if option:
                        option.click()
                print(f"   ✅ 카테고리: {category_name}")
                return
        except (PWTimeout, Exception):
            continue


def _click_publish(page, frame) -> bool:
    """발행 버튼 클릭 — 타임아웃을 짧게 유지해 총 대기시간 최소화"""
    publish_sels = [
        "button:has-text('발행')",
        ".btn_publish",
        "[data-action='publish']",
        ".publish_btn",
        "button.button__publish",
        "button:has-text('등록')",
    ]
    for target in [frame, page]:
        for sel in publish_sels:
            try:
                btn = target.wait_for_selector(sel, timeout=2000)  # 짧은 timeout
                if btn and btn.is_visible():
                    _pause(1.0, 2.5)
                    btn.click()
                    _pause(2.0, 4.0)
                    # 확인 팝업 처리
                    for confirm_sel in [
                        "button:has-text('확인')",
                        "button:has-text('발행하기')",
                        "button:has-text('등록하기')",
                    ]:
                        try:
                            confirm = target.wait_for_selector(confirm_sel, timeout=3000)
                            if confirm and confirm.is_visible():
                                _pause(0.5, 1.0)
                                confirm.click()
                                _pause(2.0, 3.0)
                                break
                        except PWTimeout:
                            continue
                    return True
            except (PWTimeout, Exception):
                continue
    return False


def _save_screenshot(page, naver_id: str, step: str):
    try:
        screenshots_dir = Path("/tmp/naver_screenshots")
        screenshots_dir.mkdir(exist_ok=True)
        ts = int(time.time())
        path = screenshots_dir / f"{naver_id}_{step}_{ts}.png"
        page.screenshot(path=str(path))
        print(f"   📸 스크린샷 저장: {path}")
    except Exception as e:
        print(f"   ⚠️ 스크린샷 저장 실패: {e}")


# ── 단일 계정 발행 ────────────────────────────────────────────────────────────

def _post_one_account(account: dict, naver_title: str, naver_content: str, naver_tags: list) -> bool:
    naver_id = account["id"]
    cookies  = account.get("cookies", "")
    blog_cat = account.get("blog_category", "")

    ua       = random.choice(_USER_AGENTS)
    viewport = random.choice(_VIEWPORTS)

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-blink-features=AutomationControlled",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--no-first-run",
                "--disable-default-apps",
                "--disable-infobars",
                f"--window-size={viewport['width']},{viewport['height']}",
            ],
        )
        context = browser.new_context(
            user_agent=ua,
            viewport=viewport,
            locale="ko-KR",
            timezone_id="Asia/Seoul",
            extra_http_headers={
                "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            },
        )
        context.add_init_script(_STEALTH_JS)
        page = context.new_page()

        try:
            # 1. 쿠키 로드 → 로그인 확인
            if not _load_cookies(context, cookies):
                print(f"   ❌ [{naver_id}] 쿠키 없음 — NAVER_COOKIES_{{category}} 시크릿 설정 필요")
                return False

            if not _is_logged_in(page):
                print(f"   ❌ [{naver_id}] 쿠키 만료 또는 로그인 실패")
                _save_screenshot(page, naver_id, "login_failed")
                # RuntimeError 를 던지면 sns_post.py 에서 re-raise 되어
                # GitHub Actions 실패 → 실패 이메일 자동 발송
                raise RuntimeError(
                    f"[NAVER 쿠키 만료] 계정: {naver_id} — "
                    f"get_naver_cookies.py 재실행 후 GitHub Secrets 업데이트 필요"
                )

            print(f"   ✅ [{naver_id}] 로그인 확인 (UA: Chrome/{ua.split('Chrome/')[1].split(' ')[0]})")
            _pause(1.0, 2.0)

            # 2. 글쓰기 페이지 이동
            page.goto("https://blog.naver.com/PostWriteForm.naver", timeout=30000)
            page.wait_for_load_state("domcontentloaded", timeout=25000)
            _pause(3.0, 5.0)
            _scroll_naturally(page)

            # 3. 에디터 프레임 탐색 (Smart Editor 3.0은 iframe 안에 로드됨)
            frame = _get_editor_frame(page)
            _pause(1.0, 2.0)

            # 4. 임시저장 팝업 처리 (있는 경우)
            _dismiss_drafts_popup(page, frame)

            # 5. 제목 입력
            # frame: 요소 포커스 | page: 키보드 입력 — Frame에는 .keyboard 없음
            if not _input_title(frame, page, naver_title):
                print(f"   ⚠️ [{naver_id}] 제목 입력 영역 못 찾음")
                _save_screenshot(page, naver_id, "title_not_found")
                return False

            _pause(1.0, 2.0)

            # 6. 본문 입력
            _type_in_editor(frame, page, naver_content)
            _pause(1.5, 3.0)

            # 7. 카테고리 선택
            _select_category(frame, page, blog_cat)
            _pause(0.5, 1.0)

            # 8. 태그 입력
            _input_tags(frame, page, naver_tags)
            _pause(1.0, 2.0)

            # 9. 발행
            if _click_publish(page, frame):
                print(f"   ✅ [{naver_id}] 발행 완료: {naver_title}")
                return True
            else:
                print(f"   ⚠️ [{naver_id}] 발행 버튼 못 찾음")
                _save_screenshot(page, naver_id, "publish_btn_not_found")
                return False

        except RuntimeError:
            raise  # 쿠키 만료 등 치명적 오류는 상위로 전파
        except Exception as e:
            print(f"   ❌ [{naver_id}] 오류: {e}")
            try:
                _save_screenshot(page, naver_id, "error")
            except Exception:
                pass
            return False
        finally:
            _pause(1.0, 2.0)
            browser.close()


# ── 메인 발행 함수 ────────────────────────────────────────────────────────────

def post_naver_blog(title: str, wp_content: str, category: str = "", post_seq: int = 0) -> bool:
    if not category:
        print("   ⏭️ 네이버 블로그: 카테고리 없음, 건너뜀")
        return False

    account = _get_account_for_category(category)
    if not account or not account["id"]:
        print(f"   ⏭️ 네이버 블로그: [{category}] 매핑 계정 없음, 건너뜀")
        print(f"      → NAVER_ACCOUNT_{category}=아이디|비밀번호|블로그카테고리 설정 필요")
        return False

    print(f"   🔄 네이버 변환 중... (카테고리: {category} → 계정: {account['id']})")
    naver = convert_wp_to_naver(title, wp_content, category, post_seq=post_seq)
    naver_title   = naver["title"]
    naver_content = naver["content"]
    naver_tags    = naver.get("tags", [])
    print(f"   📝 네이버 제목: {naver_title}")

    if not naver_content:
        print("   ❌ 네이버 콘텐츠 변환 실패")
        return False

    return _post_one_account(account, naver_title, naver_content, naver_tags)
