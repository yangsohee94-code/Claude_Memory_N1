#!/usr/bin/env python3
"""네이버 블로그 자동 발행 모듈 — Playwright 기반 (봇 감지 최소화)"""
import os, re, json, time, random, html as html_lib
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
import anthropic
from dotenv import load_dotenv

load_dotenv()

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")

# 카테고리별 네이버 계정 매핑
# 형식: NAVER_ACCOUNT_entertainment=아이디|비밀번호|블로그카테고리명
#       NAVER_ACCOUNT_health=아이디|비밀번호|블로그카테고리명
#       NAVER_ACCOUNT_car=아이디|비밀번호|블로그카테고리명
# 쿠키: NAVER_COOKIES_entertainment / NAVER_COOKIES_health / NAVER_COOKIES_car

def _get_account_for_category(wp_category: str) -> dict | None:
    """워드프레스 카테고리에 해당하는 네이버 계정 반환"""
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


# ── 네이버용 콘텐츠 변환 지침 ─────────────────────────────────────────────

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


# 재창작 각도 — 같은 소재를 매번 다른 시각으로 써서 유사 콘텐츠 감지 방지
_ANGLES = [
    "독자가 '나에게 해당되는가'에 집중. 조건·자격·상황별 분기를 중심으로 재창작.",
    "독자가 가장 궁금해할 '실제 비용·수치'를 중심으로 재창작. 구체적 금액과 조건 부각.",
    "'지금 해야 하는 이유'와 타이밍을 중심으로 재창작. 기한·변화·현재 시점 강조.",
    "'대부분 모르는 단점·함정'을 중심으로 재창작. 솔직한 시각으로 균형 있게.",
    "'경쟁 대상과 비교하면 어떤가'를 중심으로 재창작. 차이점과 선택 기준 부각.",
]


def convert_wp_to_naver(title: str, wp_html: str, category: str = "") -> dict:
    """워드프레스 HTML 본문 → 네이버 블로그 최적화 텍스트 변환"""
    plain = re.sub(r'<[^>]+>', ' ', wp_html)
    plain = html_lib.unescape(plain)
    plain = re.sub(r'[ \t]+', ' ', plain)
    plain = re.sub(r'\n{3,}', '\n\n', plain).strip()

    # 발행 시각 기반으로 각도 선택 (같은 날 2번 발행해도 다른 각도)
    angle = _ANGLES[int(time.time() / 3600) % len(_ANGLES)]
    category_hint = f"\n카테고리: {category}" if category else ""
    user_msg = (
        f"원본 제목: {title}{category_hint}\n"
        f"재창작 각도: {angle}\n\n"
        f"원본 본문:\n{plain[:3500]}"
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
    """랜덤 대기 — 기계적 고정 딜레이 방지"""
    time.sleep(random.uniform(min_s, max_s))


def _human_move_and_click(page, selector: str):
    """마우스를 자연스럽게 이동 후 클릭"""
    el = page.wait_for_selector(selector, timeout=8000)
    if not el:
        return
    box = el.bounding_box()
    if not box:
        el.click()
        return
    # 요소 중심에서 약간 벗어난 랜덤 위치 클릭 (사람은 정확히 중앙을 누르지 않음)
    x = box["x"] + box["width"] * random.uniform(0.3, 0.7)
    y = box["y"] + box["height"] * random.uniform(0.3, 0.7)
    # 현재 위치에서 목표 위치까지 자연스럽게 이동
    page.mouse.move(x + random.randint(-50, 50), y + random.randint(-30, 30))
    _pause(0.2, 0.5)
    page.mouse.move(x, y)
    _pause(0.1, 0.3)
    page.mouse.click(x, y)


def _human_type(page, text: str):
    """사람처럼 불규칙한 속도로 타이핑"""
    for ch in text:
        page.keyboard.type(ch)
        # 글자마다 다른 딜레이 (80~200ms), 가끔 긴 정지 (문장 끝에서 생각하듯)
        if ch in '.!?。\n':
            time.sleep(random.uniform(0.3, 0.8))
        elif ch == ' ':
            time.sleep(random.uniform(0.05, 0.15))
        else:
            time.sleep(random.uniform(0.08, 0.20))


def _scroll_naturally(page):
    """페이지를 자연스럽게 스크롤 (읽는 척)"""
    for _ in range(random.randint(2, 4)):
        page.mouse.wheel(0, random.randint(200, 500))
        _pause(0.4, 1.0)
    # 다시 위로
    page.keyboard.press("Control+Home")
    _pause(0.5, 1.2)


# ── webdriver 감지 차단 스크립트 ──────────────────────────────────────────────

_STEALTH_JS = """
() => {
    // navigator.webdriver 숨기기
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });

    // Chrome 자동화 플래그 제거
    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Array;
    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Promise;
    delete window.cdc_adoQpoasnfa76pfcZLmcfl_Symbol;

    // plugins 가짜 주입 (빈 plugins = headless 감지)
    Object.defineProperty(navigator, 'plugins', {
        get: () => [1, 2, 3, 4, 5],
    });

    // languages 설정
    Object.defineProperty(navigator, 'languages', {
        get: () => ['ko-KR', 'ko', 'en-US', 'en'],
    });

    // permissions query 오버라이드
    const originalQuery = window.navigator.permissions.query;
    window.navigator.permissions.query = (parameters) =>
        parameters.name === 'notifications'
            ? Promise.resolve({ state: Notification.permission })
            : originalQuery(parameters);
}
"""


# ── 로그인 ────────────────────────────────────────────────────────────────────

def _load_cookies(context, cookies_json: str) -> bool:
    """저장된 쿠키로 세션 복원"""
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
    """네이버 홈에서 로그인 상태 확인"""
    try:
        page.goto("https://www.naver.com", timeout=20000)
        page.wait_for_load_state("domcontentloaded", timeout=10000)
        _pause(1.0, 2.0)
        # 로그인된 경우 닉네임 영역이 보임
        logged = page.query_selector(".gnb_my_namebox, .MyView-module__gnb_my_name___nFkGX")
        return logged is not None
    except Exception:
        return False


# ── Smart Editor 3 조작 ──────────────────────────────────────────────────────

def _type_in_editor(page, content: str):
    """Smart Editor 3.0 본문에 텍스트 입력 — 사람처럼"""
    # 본문 영역(제목 제외) contenteditable 요소 찾아 클릭
    js_focus = """() => {
        const editables = Array.from(document.querySelectorAll('[contenteditable="true"]'));
        const body = editables.find(el => {
            const rect = el.getBoundingClientRect();
            return rect.height > 80 && rect.top > 150;
        }) || editables[1];
        if (!body) return false;
        body.click();
        body.focus();
        return true;
    }"""
    ok = page.evaluate(js_focus)
    _pause(0.5, 1.0)

    if ok:
        # 기존 내용 전체 선택 후 삭제
        page.keyboard.press("Control+a")
        _pause(0.2, 0.4)
        page.keyboard.press("Delete")
        _pause(0.3, 0.6)
        # 단락별로 나눠서 입력 (한 번에 붙여넣으면 기계처럼 보임)
        paragraphs = content.split('\n')
        for i, para in enumerate(paragraphs):
            if para.strip():
                _human_type(page, para)
            page.keyboard.press("Enter")
            # 단락 사이 잠깐 멈춤 (생각하는 듯)
            if i % 3 == 2:
                _pause(0.8, 1.8)
            else:
                _pause(0.2, 0.5)
    else:
        # fallback: 에디터 중앙 클릭 후 타이핑
        page.mouse.click(640, 450)
        _pause(0.5, 1.0)
        page.keyboard.press("Control+a")
        page.keyboard.press("Delete")
        _human_type(page, content)

    print(f"   ✅ 본문 입력 완료 ({len(content)}자)")


def _input_tags(page, tags: list):
    """태그 입력"""
    if not tags:
        return
    tag_sels = [
        ".se-tag-input",
        "input[placeholder*='태그']",
        "#tag_input",
        ".tag_area input",
    ]
    for sel in tag_sels:
        try:
            inp = page.wait_for_selector(sel, timeout=4000)
            if inp:
                for tag in tags[:7]:
                    _human_move_and_click(page, sel)
                    _pause(0.3, 0.6)
                    _human_type(page, tag.strip())
                    _pause(0.2, 0.4)
                    page.keyboard.press("Enter")
                    _pause(0.4, 0.8)
                print(f"   ✅ 태그 {len(tags[:7])}개 입력")
                return
        except PWTimeout:
            continue


def _select_category(page, category_name: str):
    """카테고리 선택"""
    if not category_name:
        return
    cat_sels = [".category_select", "#category", "select[name*='category']"]
    for sel in cat_sels:
        try:
            el = page.wait_for_selector(sel, timeout=3000)
            if el:
                _pause(0.3, 0.7)
                page.select_option(sel, label=category_name)
                print(f"   ✅ 카테고리: {category_name}")
                return
        except (PWTimeout, Exception):
            continue


def _click_publish(page) -> bool:
    """발행 버튼 클릭"""
    publish_sels = [
        "button:has-text('발행')",
        ".btn_publish",
        "[data-action='publish']",
        ".publish_btn",
        "button.button__publish",
    ]
    for sel in publish_sels:
        try:
            btn = page.wait_for_selector(sel, timeout=6000)
            if btn and btn.is_visible():
                _pause(1.0, 2.0)  # 발행 전 잠깐 멈춤 (사람은 확인함)
                _human_move_and_click(page, sel)
                _pause(2.0, 4.0)
                # 확인 팝업 처리
                try:
                    confirm = page.wait_for_selector(
                        "button:has-text('확인'), button:has-text('발행하기')",
                        timeout=4000,
                    )
                    if confirm:
                        _pause(0.5, 1.0)
                        confirm.click()
                        _pause(2.0, 3.0)
                except PWTimeout:
                    pass
                return True
        except (PWTimeout, Exception):
            continue
    return False


# ── 단일 계정 발행 ────────────────────────────────────────────────────────────

def _post_one_account(account: dict, naver_title: str, naver_content: str, naver_tags: list) -> bool:
    """Playwright로 네이버 계정 1개에 발행"""
    naver_id   = account["id"]
    cookies    = account.get("cookies", "")
    blog_cat   = account.get("blog_category", "") or account.get("category", "")

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-blink-features=AutomationControlled",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--window-size=1280,900",
            ],
        )
        context = browser.new_context(
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/125.0.0.0 Safari/537.36"
            ),
            viewport={"width": 1280, "height": 900},
            locale="ko-KR",
            timezone_id="Asia/Seoul",
            extra_http_headers={
                "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
            },
        )

        # webdriver 감지 차단 — 모든 페이지에 적용
        context.add_init_script(_STEALTH_JS)
        page = context.new_page()

        try:
            # 1. 쿠키 로드 → 로그인 확인
            if not _load_cookies(context, cookies):
                print(f"   ❌ [{naver_id}] 쿠키 없음 — NAVER_COOKIES_{{category}} 설정 필요")
                return False

            if not _is_logged_in(page):
                print(f"   ❌ [{naver_id}] 쿠키 만료 — 쿠키를 새로 추출해주세요")
                return False

            print(f"   ✅ [{naver_id}] 로그인 확인")

            # 2. 블로그 에디터 열기
            write_url = f"https://blog.naver.com/{naver_id}/write"
            page.goto(write_url, timeout=30000)
            page.wait_for_load_state("networkidle", timeout=25000)
            _pause(3.0, 5.0)  # 에디터 로드 후 사람처럼 잠깐 훑어봄
            _scroll_naturally(page)

            # 3. 제목 입력
            title_sels = [".se-title-input", "#post-title", "input[placeholder*='제목']"]
            title_ok = False
            for sel in title_sels:
                try:
                    page.wait_for_selector(sel, timeout=6000)
                    _human_move_and_click(page, sel)
                    _pause(0.4, 0.8)
                    _human_type(page, naver_title)
                    title_ok = True
                    print(f"   ✅ 제목 입력 완료")
                    break
                except PWTimeout:
                    continue

            if not title_ok:
                print(f"   ⚠️ [{naver_id}] 제목 입력 영역 못 찾음")
                return False

            _pause(1.0, 2.0)

            # 4. 본문 입력
            _type_in_editor(page, naver_content)
            _pause(1.5, 3.0)

            # 5. 카테고리 선택
            _select_category(page, blog_cat)
            _pause(0.5, 1.0)

            # 6. 태그 입력
            _input_tags(page, naver_tags)
            _pause(1.0, 2.0)

            # 7. 발행
            if _click_publish(page):
                print(f"   ✅ [{naver_id}] 발행 완료: {naver_title}")
                return True
            else:
                print(f"   ⚠️ [{naver_id}] 발행 버튼 못 찾음")
                return False

        except Exception as e:
            print(f"   ❌ [{naver_id}] 오류: {e}")
            return False
        finally:
            _pause(1.0, 2.0)
            browser.close()


# ── 메인 발행 함수 (카테고리 → 해당 계정 1개 발행) ─────────────────────────

def post_naver_blog(title: str, wp_content: str, category: str = "") -> bool:
    """워드프레스 카테고리에 맞는 네이버 블로그 계정에만 발행"""
    if not category:
        print("   ⏭️ 네이버 블로그: 카테고리 없음, 건너뜀")
        return False

    account = _get_account_for_category(category)
    if not account or not account["id"]:
        print(f"   ⏭️ 네이버 블로그: [{category}] 매핑 계정 없음, 건너뜀")
        print(f"      → NAVER_ACCOUNT_{category}=아이디|비밀번호|블로그카테고리 설정 필요")
        return False

    print(f"   🔄 네이버 변환 중... (카테고리: {category} → 계정: {account['id']})")
    naver = convert_wp_to_naver(title, wp_content, category)
    naver_title   = naver["title"]
    naver_content = naver["content"]
    naver_tags    = naver.get("tags", [])
    print(f"   📝 네이버 제목: {naver_title}")

    if not naver_content:
        print("   ❌ 네이버 콘텐츠 변환 실패")
        return False

    return _post_one_account(account, naver_title, naver_content, naver_tags)
