#!/usr/bin/env python3
"""SNS 자동 발행 모듈 — Threads / Facebook / Pinterest / 네이버 블로그"""
import os, re, time, requests, anthropic
from dotenv import load_dotenv

load_dotenv()

ANTHROPIC_API_KEY   = os.getenv("ANTHROPIC_API_KEY")
THREADS_USER_ID     = os.getenv("THREADS_USER_ID", "")
THREADS_TOKEN       = os.getenv("THREADS_ACCESS_TOKEN", "")
FB_PAGE_ID          = os.getenv("FB_PAGE_ID", "")
FB_PAGE_TOKEN       = os.getenv("FB_PAGE_ACCESS_TOKEN", "")
PINTEREST_TOKEN     = os.getenv("PINTEREST_ACCESS_TOKEN", "")
PINTEREST_BOARD_ID  = os.getenv("PINTEREST_BOARD_ID", "")


# ── SNS용 텍스트 생성 ────────────────────────────────────────────────────────

def generate_sns_text(title: str, content: str, post_url: str) -> dict:
    """블로그 제목+본문으로 플랫폼별 SNS 텍스트 생성"""
    plain = re.sub(r'<[^>]+>', '', content)[:1500]
    client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    prompt = f"""블로그 글을 SNS에 맞게 재작성해줘.

제목: {title}
본문 요약: {plain}
링크: {post_url}

출력 형식 (이 형식 그대로, 다른 텍스트 없이):
THREADS: [150자 이내. 핵심 한 줄 + 링크. 해시태그 3개 이내.]
FACEBOOK: [200자 이내. 독자 관심 유도 + 링크. 해시태그 없음.]
PINTEREST: [핀 설명 100자 이내. 키워드 중심 설명문.]
PINTEREST_TITLE: [핀 제목 50자 이내.]"""

    resp = client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=512,
        messages=[{"role": "user", "content": prompt}],
    )
    raw = resp.content[0].text
    result = {}
    for key in ("THREADS", "FACEBOOK", "PINTEREST", "PINTEREST_TITLE"):
        m = re.search(rf'^{key}:\s*(.+?)(?=\n[A-Z_]+:|$)', raw, re.MULTILINE | re.DOTALL)
        result[key] = m.group(1).strip() if m else ""
    return result


# ── Threads ──────────────────────────────────────────────────────────────────

def post_threads(text: str, image_url: str = "") -> bool:
    if not THREADS_USER_ID or not THREADS_TOKEN:
        print("   ⏭️ Threads: 토큰 미설정, 건너뜀")
        return False
    base = f"https://graph.threads.net/v1.0/{THREADS_USER_ID}"
    # 1단계: 컨테이너 생성
    params = {"text": text, "access_token": THREADS_TOKEN}
    if image_url:
        params.update({"media_type": "IMAGE", "image_url": image_url})
    else:
        params["media_type"] = "TEXT"
    r = requests.post(f"{base}/threads", params=params, timeout=30)
    if r.status_code not in (200, 201):
        print(f"   ⚠️ Threads 컨테이너 생성 실패: {r.status_code} {r.text[:200]}")
        return False
    container_id = r.json().get("id")
    # 2단계: 발행 (최대 30초 대기)
    time.sleep(5)
    r2 = requests.post(f"{base}/threads_publish",
                       params={"creation_id": container_id, "access_token": THREADS_TOKEN},
                       timeout=30)
    if r2.status_code in (200, 201):
        print(f"   ✅ Threads 발행 완료")
        return True
    print(f"   ⚠️ Threads 발행 실패: {r2.status_code} {r2.text[:200]}")
    return False


# ── Facebook ─────────────────────────────────────────────────────────────────

def post_facebook(text: str, link: str = "", image_url: str = "") -> bool:
    if not FB_PAGE_ID or not FB_PAGE_TOKEN:
        print("   ⏭️ Facebook: 토큰 미설정, 건너뜀")
        return False
    url = f"https://graph.facebook.com/v19.0/{FB_PAGE_ID}/feed"
    payload = {"message": text, "access_token": FB_PAGE_TOKEN}
    if link:
        payload["link"] = link
    r = requests.post(url, data=payload, timeout=30)
    if r.status_code in (200, 201):
        print(f"   ✅ Facebook 발행 완료")
        return True
    print(f"   ⚠️ Facebook 발행 실패: {r.status_code} {r.text[:200]}")
    return False


# ── Pinterest ─────────────────────────────────────────────────────────────────

def post_pinterest(title: str, description: str, link: str, image_url: str = "") -> bool:
    if not PINTEREST_TOKEN or not PINTEREST_BOARD_ID:
        print("   ⏭️ Pinterest: 토큰 미설정, 건너뜀")
        return False
    headers = {
        "Authorization": f"Bearer {PINTEREST_TOKEN}",
        "Content-Type": "application/json",
    }
    payload = {
        "board_id": PINTEREST_BOARD_ID,
        "title": title,
        "description": description,
        "link": link,
    }
    if image_url:
        payload["media_source"] = {"source_type": "image_url", "url": image_url}
    r = requests.post("https://api.pinterest.com/v5/pins",
                      headers=headers, json=payload, timeout=30)
    if r.status_code in (200, 201):
        print(f"   ✅ Pinterest 발행 완료")
        return True
    print(f"   ⚠️ Pinterest 발행 실패: {r.status_code} {r.text[:200]}")
    return False


# ── 통합 발행 ────────────────────────────────────────────────────────────────

def publish_to_sns(title: str, content: str, post_url: str, featured_image_url: str = "", category: str = ""):
    """블로그 발행 후 SNS + 네이버 블로그 전체 발행. 토큰 없는 플랫폼은 자동 건너뜀."""
    print("📱 SNS 발행 중...")
    texts = generate_sns_text(title, content, post_url)
    post_threads(texts.get("THREADS", ""), image_url=featured_image_url)
    post_facebook(texts.get("FACEBOOK", ""), link=post_url, image_url=featured_image_url)
    post_pinterest(
        title=texts.get("PINTEREST_TITLE", title),
        description=texts.get("PINTEREST", ""),
        link=post_url,
        image_url=featured_image_url,
    )
    # 네이버 블로그 발행
    try:
        from naver_post import post_naver_blog
        post_naver_blog(title, content, category=category)
    except ImportError:
        print("   ⏭️ 네이버 블로그: naver_post 모듈 없음, 건너뜀")
    except Exception as e:
        print(f"   ⚠️ 네이버 블로그 발행 오류: {e}")
