#!/usr/bin/env python3
"""네이버 블로그 자동 발행 모듈 — Playwright 기반"""
import os, re, json, time, html as html_lib
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
import anthropic
from dotenv import load_dotenv

load_dotenv()

NAVER_ID       = os.getenv("NAVER_ID", "")
NAVER_PW       = os.getenv("NAVER_PW", "")
NAVER_COOKIES  = os.getenv("NAVER_COOKIES", "")   # JSON array, 쿠키 내보내기 값
NAVER_CATEGORY = os.getenv("NAVER_BLOG_CATEGORY", "")  # 네이버 블로그 카테고리 이름
ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")

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


def convert_wp_to_naver(title: str, wp_html: str, category: str = "") -> dict:
    """워드프레스 HTML 본문 → 네이버 블로그 최적화 텍스트 변환"""
    # HTML 태그 제거 후 평문 추출
    plain = re.sub(r'<[^>]+>', ' ', wp_html)
    plain = html_lib.unescape(plain)
    plain = re.sub(r'[ \t]+', ' ', plain)
    plain = re.sub(r'\n{3,}', '\n\n', plain).strip()

    category_hint = f"\n카테고리: {category}" if category else ""
    user_msg = f"원본 제목: {title}{category_hint}\n\n원본 본문:\n{plain[:3500]}"

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


# ── 로그인 ────────────────────────────────────────────────────────────────────

def _load_cookies(context) -> bool:
    """저장된 쿠키로 세션 복원"""
    if not NAVER_COOKIES:
        return False
    try:
        cookies = json.loads(NAVER_COOKIES)
        context.add_cookies(cookies)
        print("   🍪 쿠키로 세션 복원")
        return True
    except Exception as e:
        print(f"   ⚠️ 쿠키 로드 실패: {e}")
        return False


def _login_pw(page) -> bool:
    """ID/PW 로그인 (쿠키 없을 때 fallback)"""
    if not NAVER_ID or not NAVER_PW:
        return False
    try:
        page.goto("https://nid.naver.com/nidlogin.login", timeout=30000)
        page.wait_for_selector("#id", timeout=10000)
        # 봇 감지 우회: 천천히 입력
        page.focus("#id")
        time.sleep(0.5)
        for ch in NAVER_ID:
            page.keyboard.type(ch, delay=60)
        time.sleep(0.4)
        page.focus("#pw")
        for ch in NAVER_PW:
            page.keyboard.type(ch, delay=60)
        time.sleep(0.3)
        page.click(".btn_login")
        page.wait_for_timeout(4000)

        if "nid.naver.com" not in page.url:
            print("   ✅ 네이버 ID/PW 로그인 성공")
            return True
        print("   ⚠️ 로그인 실패 — CAPTCHA 또는 2단계 인증 필요. NAVER_COOKIES 설정 권장.")
        return False
    except Exception as e:
        print(f"   ⚠️ 로그인 오류: {e}")
        return False


def _is_logged_in(page) -> bool:
    """로그인 상태 확인"""
    try:
        page.goto("https://www.naver.com", timeout=15000)
        page.wait_for_load_state("domcontentloaded", timeout=10000)
        return page.query_selector(".MyView-module__gnb_my_namebox___ErbHh, .link_login") is None \
               or page.query_selector(".gnb_my_namebox") is not None
    except Exception:
        return False


# ── Smart Editor 3 조작 ──────────────────────────────────────────────────────

def _type_in_editor(page, content: str):
    """Smart Editor 3.0 본문 영역에 텍스트 입력"""
    # contenteditable 요소 중 제목 제외한 본문 영역 찾기
    js_set = """(text) => {
        const editables = Array.from(document.querySelectorAll('[contenteditable="true"]'));
        // 제목 입력창 제외 (보통 첫 번째)
        const body = editables.find(el => {
            const rect = el.getBoundingClientRect();
            return rect.height > 100 && rect.top > 150;
        }) || editables[1];
        if (!body) return false;
        body.focus();
        // 기존 내용 지우기
        document.execCommand('selectAll', false, null);
        document.execCommand('delete', false, null);
        // 텍스트 삽입
        document.execCommand('insertText', false, text);
        return true;
    }"""
    ok = page.evaluate(js_set, content)
    if not ok:
        # fallback: 클릭 후 직접 타이핑
        page.mouse.click(640, 450)
        time.sleep(0.5)
        page.keyboard.press("Control+a")
        page.keyboard.press("Delete")
        page.keyboard.type(content, delay=2)


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
            inp = page.wait_for_selector(sel, timeout=3000)
            if inp:
                for tag in tags[:7]:
                    inp.click()
                    inp.type(tag.strip(), delay=30)
                    page.keyboard.press("Enter")
                    time.sleep(0.3)
                print(f"   🏷️ 태그 {len(tags[:7])}개 입력")
                return
        except PWTimeout:
            continue


def _select_category(page, category_name: str):
    """카테고리 선택"""
    if not category_name:
        return
    cat_sels = [
        ".category_select",
        "#category",
        "select[name*='category']",
    ]
    for sel in cat_sels:
        try:
            el = page.wait_for_selector(sel, timeout=3000)
            if el:
                page.select_option(sel, label=category_name)
                print(f"   📂 카테고리: {category_name}")
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
            btn = page.wait_for_selector(sel, timeout=5000)
            if btn and btn.is_visible():
                btn.click()
                page.wait_for_timeout(3000)
                # 발행 확인 팝업 처리
                try:
                    confirm = page.wait_for_selector(
                        "button:has-text('확인'), button:has-text('발행하기')",
                        timeout=3000
                    )
                    if confirm:
                        confirm.click()
                        page.wait_for_timeout(2000)
                except PWTimeout:
                    pass
                return True
        except (PWTimeout, Exception):
            continue
    return False


# ── 메인 발행 함수 ────────────────────────────────────────────────────────────

def post_naver_blog(title: str, wp_content: str, category: str = "") -> bool:
    """워드프레스 글을 네이버 블로그에 재창작 발행"""
    if not NAVER_ID:
        print("   ⏭️ 네이버 블로그: NAVER_ID 미설정, 건너뜀")
        return False

    print("   🔄 네이버 블로그 변환 중...")
    naver = convert_wp_to_naver(title, wp_content, category)
    naver_title   = naver["title"]
    naver_content = naver["content"]
    naver_tags    = naver.get("tags", [])
    print(f"   📝 네이버 제목: {naver_title}")

    if not naver_content:
        print("   ❌ 네이버 콘텐츠 변환 실패")
        return False

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-blink-features=AutomationControlled",
                "--disable-dev-shm-usage",
                "--disable-gpu",
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
        )
        page = context.new_page()

        try:
            # 1. 로그인
            cookie_loaded = _load_cookies(context)

            if cookie_loaded:
                # 쿠키 유효성 확인
                if not _is_logged_in(page):
                    print("   ⚠️ 쿠키 만료. ID/PW 로그인 시도...")
                    if not _login_pw(page):
                        print("   ❌ 로그인 실패")
                        return False
            else:
                if not _login_pw(page):
                    print("   ❌ 로그인 실패 — NAVER_ID/PW 또는 NAVER_COOKIES 설정 필요")
                    return False

            # 2. 블로그 에디터 열기
            write_url = f"https://blog.naver.com/{NAVER_ID}/write"
            print(f"   📂 에디터 열기: {write_url}")
            page.goto(write_url, timeout=30000)
            page.wait_for_load_state("networkidle", timeout=20000)
            time.sleep(3)

            # 3. 제목 입력
            title_sels = [
                ".se-title-input",
                "#post-title",
                "input[placeholder*='제목']",
                ".se-ff-nanumgothic input",
            ]
            title_ok = False
            for sel in title_sels:
                try:
                    el = page.wait_for_selector(sel, timeout=5000)
                    if el:
                        el.click()
                        el.fill(naver_title)
                        title_ok = True
                        print(f"   ✅ 제목 입력 완료")
                        break
                except PWTimeout:
                    continue

            if not title_ok:
                print("   ⚠️ 제목 입력 영역을 찾지 못했습니다")
                return False

            time.sleep(1)

            # 4. 본문 입력
            _type_in_editor(page, naver_content)
            time.sleep(1)
            print(f"   ✅ 본문 입력 완료 ({len(naver_content)}자)")

            # 5. 카테고리 선택
            _select_category(page, NAVER_CATEGORY)

            # 6. 태그 입력
            _input_tags(page, naver_tags)

            # 7. 발행
            if _click_publish(page):
                print(f"   ✅ 네이버 블로그 발행 완료: {naver_title}")
                return True
            else:
                print("   ⚠️ 발행 버튼을 찾지 못했습니다")
                return False

        except Exception as e:
            print(f"   ❌ 네이버 블로그 발행 오류: {e}")
            return False
        finally:
            browser.close()
