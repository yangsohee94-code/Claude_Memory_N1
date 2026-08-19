#!/usr/bin/env python3
import os
import re
import io
import json
import base64
import datetime
import requests
import anthropic
from pathlib import Path
from dotenv import load_dotenv

load_dotenv()

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")
WP_URL = os.getenv("WP_URL", "").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD")
CATEGORY = os.getenv("CATEGORY", "entertainment")
TOPIC_OVERRIDE = os.getenv("TOPIC", "")
GOOGLE_CREDENTIALS = os.getenv("GOOGLE_CREDENTIALS", "")
DRIVE_ROOT_FOLDER_ID = os.getenv("GOOGLE_DRIVE_ROOT_FOLDER_ID", "")


def get_slot():
    kst_hour = (datetime.datetime.utcnow().hour + 9) % 24
    slot_hours = [5, 9, 11, 14, 16, 18, 20]
    for i, h in enumerate(slot_hours):
        if kst_hour == h:
            return i
    return 0


def get_topic():
    if TOPIC_OVERRIDE:
        return TOPIC_OVERRIDE
    topic_file = Path(f"topics/{CATEGORY}.txt")
    if not topic_file.exists():
        return f"오늘의 {CATEGORY} 트렌드 주제"
    topics = [
        l.strip()
        for l in topic_file.read_text(encoding="utf-8").splitlines()
        if l.strip() and not l.startswith("#")
    ]
    if not topics:
        return f"오늘의 {CATEGORY} 트렌드 주제"
    slot = get_slot()
    day = datetime.date.today().toordinal()
    index = (day * 7 + slot) % len(topics)
    return topics[index]


def load_references():
    ref_dir = Path(f"references/{CATEGORY}")
    if not ref_dir.exists():
        return ""
    files = sorted([f for f in ref_dir.glob("*.txt") if f.stat().st_size > 0])[-2:]
    texts = []
    for f in files:
        content = f.read_text(encoding="utf-8").strip()
        for line in content.splitlines():
            line = line.strip()
            if line.startswith("http"):
                try:
                    resp = requests.get(line, timeout=10, headers={"User-Agent": "Mozilla/5.0"})
                    texts.append(f"[출처: {line}]\n{resp.text[:3000]}")
                except Exception:
                    texts.append(f"[URL 로드 실패: {line}]")
            elif line:
                texts.append(line)
    return "\n\n".join(texts)


def load_system_prompt():
    category_prompt = Path(f"prompts/{CATEGORY}.md")
    if category_prompt.exists():
        return category_prompt.read_text(encoding="utf-8")
    p = Path("system_prompt.md")
    return p.read_text(encoding="utf-8") if p.exists() else ""


def generate_content(topic, references=""):
    system_prompt = load_system_prompt()
    user_msg = topic
    if references:
        user_msg += f"\n\n[참고 자료]\n{references}"
    client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    response = client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=8096,
        system=system_prompt,
        messages=[{"role": "user", "content": user_msg}],
    )
    return response.content[0].text


def parse_output(text):
    title_match = re.search(r"^TITLE:\s*(.+)", text, re.MULTILINE)
    title = title_match.group(1).strip() if title_match else "제목 없음"
    content_match = re.search(r"---CONTENT---\n(.*?)\n---END---", text, re.DOTALL)
    if content_match:
        content = content_match.group(1).strip()
    else:
        code_match = re.findall(r"```(?:html|markdown)?\n(.*?)```", text, re.DOTALL)
        content = code_match[0].strip() if code_match else text
    return title, content


# ── Google Drive 연동 ─────────────────────────────────────────────────────────

def _get_drive_service():
    if not GOOGLE_CREDENTIALS or not DRIVE_ROOT_FOLDER_ID:
        return None
    try:
        from google.oauth2.service_account import Credentials
        from googleapiclient.discovery import build
        creds_data = json.loads(GOOGLE_CREDENTIALS)
        creds = Credentials.from_service_account_info(
            creds_data,
            scopes=["https://www.googleapis.com/auth/drive.readonly"],
        )
        return build("drive", "v3", credentials=creds)
    except Exception as e:
        print(f"   ⚠️ Drive 서비스 초기화 실패: {e}")
        return None


def _find_topic_folder(service, topic):
    """카테고리 폴더 → 주제 폴더 순으로 탐색. 완전 일치 우선, 없으면 포함 관계로 매칭."""
    # 1) 카테고리 폴더 찾기
    q = (f"'{DRIVE_ROOT_FOLDER_ID}' in parents "
         f"and name='{CATEGORY}' "
         f"and mimeType='application/vnd.google-apps.folder' "
         f"and trashed=false")
    res = service.files().list(q=q, fields="files(id,name)").execute()
    cat_folders = res.get("files", [])
    if not cat_folders:
        print(f"   📂 Drive 카테고리 폴더 없음: {CATEGORY}/")
        return None
    cat_id = cat_folders[0]["id"]

    # 2) 주제 폴더 목록 가져오기
    q2 = (f"'{cat_id}' in parents "
          f"and mimeType='application/vnd.google-apps.folder' "
          f"and trashed=false")
    res2 = service.files().list(q=q2, fields="files(id,name)").execute()
    folders = res2.get("files", [])

    # 완전 일치
    for f in folders:
        if f["name"] == topic:
            return f["id"]

    # 부분 일치 (폴더명이 주제에 포함되거나, 주제가 폴더명에 포함)
    topic_clean = topic.replace(" ", "")
    for f in folders:
        folder_clean = f["name"].replace(" ", "")
        if folder_clean in topic_clean or topic_clean in folder_clean:
            print(f"   📂 Drive 폴더 부분매칭: {f['name']}")
            return f["id"]

    print(f"   📂 Drive 주제 폴더 없음: {topic[:30]}")
    return None


def _download_images(service, folder_id):
    from googleapiclient.http import MediaIoBaseDownload
    q = (f"'{folder_id}' in parents "
         f"and mimeType contains 'image/' "
         f"and trashed=false")
    res = service.files().list(q=q, fields="files(id,name,mimeType)", orderBy="name").execute()
    files = res.get("files", [])

    images = []
    for file in files:
        try:
            request = service.files().get_media(fileId=file["id"])
            buf = io.BytesIO()
            dl = MediaIoBaseDownload(buf, request)
            done = False
            while not done:
                _, done = dl.next_chunk()
            buf.seek(0)
            images.append({"name": file["name"], "mime": file["mimeType"], "data": buf.read()})
        except Exception as e:
            print(f"   ⚠️ 이미지 다운로드 실패 {file['name']}: {e}")
    return images


def _upload_to_wp_media(img_data, filename, mime_type):
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {
        "Authorization": f"Basic {token}",
        "Content-Disposition": f'attachment; filename="{filename}"',
        "Content-Type": mime_type,
    }
    resp = requests.post(
        f"{WP_URL}/wp-json/wp/v2/media",
        headers=headers,
        data=img_data,
        timeout=60,
    )
    if resp.status_code in (200, 201):
        data = resp.json()
        return data.get("id"), data.get("source_url", "")
    print(f"   ⚠️ 미디어 업로드 실패 {filename}: HTTP {resp.status_code}")
    return None, None


def prepare_images(topic):
    """
    Google Drive에서 주제 폴더의 사진을 가져와 WordPress에 업로드.
    반환: (featured_media_id, extra_images_html)
      - featured_media_id : 대표 썸네일 ID (첫 번째 사진)
      - extra_images_html : 나머지 사진 <img> 태그 HTML
    """
    service = _get_drive_service()
    if not service:
        return None, ""

    folder_id = _find_topic_folder(service, topic)
    if not folder_id:
        return None, ""

    images = _download_images(service, folder_id)
    if not images:
        print("   📂 Drive 폴더에 사진 없음")
        return None, ""

    print(f"   📸 사진 {len(images)}장 Drive에서 다운로드")

    wp_ids, wp_urls = [], []
    for img in images:
        media_id, url = _upload_to_wp_media(img["data"], img["name"], img["mime"])
        if media_id:
            wp_ids.append(media_id)
            wp_urls.append(url)

    if not wp_ids:
        return None, ""

    featured_id = wp_ids[0]

    # 두 번째 사진부터 본문 삽입용 HTML
    extra_html = ""
    for url in wp_urls[1:]:
        extra_html += f'\n<figure class="wp-block-image size-large"><img src="{url}" alt=""/></figure>\n'

    print(f"   ✅ 사진 {len(wp_ids)}장 WordPress 업로드 완료")
    return featured_id, extra_html


# ── WordPress 발행 ─────────────────────────────────────────────────────────────

def publish_to_wordpress(title, content, featured_media_id=None):
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {"Authorization": f"Basic {token}", "Content-Type": "application/json"}
    cat_map = {
        "entertainment": int(os.getenv("WP_CAT_ENTERTAINMENT", "0")),
        "car": int(os.getenv("WP_CAT_CAR", "0")),
        "health": int(os.getenv("WP_CAT_HEALTH", "0")),
    }
    categories = [c for c in [cat_map.get(CATEGORY, 0)] if c > 0]
    payload = {"title": title, "content": content, "status": "publish"}
    if categories:
        payload["categories"] = categories
    if featured_media_id:
        payload["featured_media"] = featured_media_id
    resp = requests.post(f"{WP_URL}/wp-json/wp/v2/posts", headers=headers, json=payload, timeout=30)
    print(f"   HTTP {resp.status_code}")
    if resp.status_code in (200, 201):
        data = resp.json()
        if isinstance(data, dict) and "id" in data:
            return True, data["id"], data.get("link", "")
    print(f"   오류: {resp.text[:300]}")
    return False, None, None


def main():
    topic = get_topic()
    refs = load_references()
    print(f"📂 [{CATEGORY}] 슬롯 {get_slot()} | 주제: {topic[:50]}")
    print(f"📋 지침: prompts/{CATEGORY}.md" if Path(f"prompts/{CATEGORY}.md").exists() else "📋 지침: system_prompt.md")

    # Google Drive에서 사진 준비
    featured_id, extra_images_html = prepare_images(topic)

    print("📝 Claude 글 생성 중...")
    raw = generate_content(topic, refs)
    title, content = parse_output(raw)

    # 추가 사진이 있으면 본문 끝에 삽입
    if extra_images_html:
        content = content + "\n" + extra_images_html

    print(f"✅ 제목: {title}")
    print(f"   본문 {len(content)}자" + (f" + 썸네일 포함" if featured_id else ""))
    print("🚀 WordPress 발행 중...")
    ok, post_id, link = publish_to_wordpress(title, content, featured_media_id=featured_id)
    if ok:
        print(f"✅ 발행 완료! ID:{post_id} {link}")
    else:
        print("❌ 발행 실패")
        exit(1)


if __name__ == "__main__":
    main()
