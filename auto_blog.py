#!/usr/bin/env python3
import os, re, io, json, base64, datetime, requests, anthropic
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
WP_CATEGORY_ID_OVERRIDE = os.getenv("WP_CATEGORY_ID", "")

SLOT_HOURS = [5, 9, 11, 14, 16, 18, 20]

def get_slot():
    kst_hour = (datetime.datetime.utcnow().hour + 9) % 24
    for i, h in enumerate(SLOT_HOURS):
        if kst_hour == h:
            return i
    return 0

def get_topic():
    if TOPIC_OVERRIDE:
        return TOPIC_OVERRIDE
    # references 폴더에 파일이 있으면 가장 오래된 파일명을 주제로 사용
    ref_dir = Path(f"references/{CATEGORY}")
    if ref_dir.exists():
        ref_files = sorted([f for f in ref_dir.glob("*.txt") if f.stat().st_size > 0])
        if ref_files:
            topic = ref_files[0].stem
            print(f"   📌 references 파일에서 주제 선택: {topic}")
            return topic
    topic_file = Path(f"topics/{CATEGORY}.txt")
    if not topic_file.exists():
        return f"오늘의 {CATEGORY} 트렌드 주제"
    topics = [l.strip() for l in topic_file.read_text(encoding="utf-8").splitlines()
              if l.strip() and not l.startswith("#")]
    if not topics:
        return f"오늘의 {CATEGORY} 트렌드 주제"
    slot = get_slot()
    day = datetime.date.today().toordinal()
    index = (day * 7 + slot) % len(topics)
    return topics[index]


def delete_used_topic(topic):
    """발행 완료 후 topics 파일과 references 파일에서 해당 주제 삭제"""
    # references 파일 삭제
    ref_file = Path(f"references/{CATEGORY}/{topic}.txt")
    if ref_file.exists():
        ref_file.unlink()
        print(f"   🗑️ references 삭제: {ref_file.name}")
    # topics 파일에서 해당 줄 제거
    topic_file = Path(f"topics/{CATEGORY}.txt")
    if topic_file.exists():
        lines = topic_file.read_text(encoding="utf-8").splitlines(keepends=True)
        new_lines = [l for l in lines if l.strip() != topic]
        if len(new_lines) < len(lines):
            topic_file.write_text("".join(new_lines), encoding="utf-8")
            print(f"   🗑️ topics/{CATEGORY}.txt에서 삭제: {topic}")

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
    base = Path("system_prompt.md")
    base_text = base.read_text(encoding="utf-8") if base.exists() else ""
    category_prompt = Path(f"prompts/{CATEGORY}.md")
    if category_prompt.exists():
        cat_text = category_prompt.read_text(encoding="utf-8")
        return base_text + "\n\n---\n\n## 카테고리 추가 규칙\n\n" + cat_text
    return base_text

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

def _get_folder_key():
    if TOPIC_OVERRIDE and re.match(r"^\d{8}_\d+$", TOPIC_OVERRIDE):
        return TOPIC_OVERRIDE
    kst_now = datetime.datetime.utcnow() + datetime.timedelta(hours=9)
    slot = get_slot() + 1  # 1~7
    return kst_now.strftime("%Y%m%d") + f"_{slot}"

def _find_topic_folder(service):
    folder_key = _get_folder_key()
    print(f"   📂 Drive 폴더 키: {CATEGORY}/{folder_key}/")
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
    q2 = (f"'{cat_id}' in parents "
          f"and name='{folder_key}' "
          f"and mimeType='application/vnd.google-apps.folder' "
          f"and trashed=false")
    res2 = service.files().list(q=q2, fields="files(id,name)").execute()
    folders = res2.get("files", [])
    if folders:
        return folders[0]["id"]
    print(f"   📂 Drive 폴더 없음 ({folder_key}) — 사진 없이 발행")
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

def prepare_images():
    service = _get_drive_service()
    if not service:
        return None, ""
    folder_id = _find_topic_folder(service)
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
    extra_urls = wp_urls[1:]
    print(f"   ✅ 사진 {len(wp_ids)}장 WordPress 업로드 완료")
    return featured_id, extra_urls

ENTERTAINMENT_DRAMA_KEYWORDS = [
    "드라마", "영화", "시즌", "넷플릭스", "디즈니", "웨이브", "티빙", "왓챠",
    "OTT", "방영", "방송", "개봉", "출연", "주연", "조연", "감독", "대본",
    "시청률", "결말", "스포", "리뷰", "줄거리", "OST", "촬영", "제작",
]
HEALTH_DIET_KEYWORDS = [
    "다이어트", "식단", "체중", "칼로리", "감량", "살", "지방", "탄수화물",
    "단백질", "저칼로리", "공복", "단식", "간헐적", "식이", "BMI", "체지방",
    "뱃살", "허벅지", "다이어트식", "건강식단", "끼니", "탄단지",
]

def _auto_classify(title, content):
    """제목+본문 키워드로 entertainment/health 서브카테고리 자동 분류."""
    text = (title + " " + content).lower()
    if CATEGORY == "entertainment":
        drama_score = sum(1 for kw in ENTERTAINMENT_DRAMA_KEYWORDS if kw in text)
        if drama_score >= 2:
            label = "영화&드라마"
            cat_id = int(os.getenv("WP_CAT_ENTERTAINMENT_DRAMA", "43"))
        else:
            label = "연예소식"
            cat_id = int(os.getenv("WP_CAT_ENTERTAINMENT_POP", "44"))
        print(f"   🏷️ 자동 분류 → {label} (드라마 키워드 {drama_score}개, ID:{cat_id})")
        return cat_id
    if CATEGORY == "health":
        diet_score = sum(1 for kw in HEALTH_DIET_KEYWORDS if kw in text)
        if diet_score >= 2:
            label = "다이어트&식단"
            cat_id = int(os.getenv("WP_CAT_HEALTH_DIET", "47"))
        else:
            label = "건강정보&영양"
            cat_id = int(os.getenv("WP_CAT_HEALTH_INFO", "48"))
        print(f"   🏷️ 자동 분류 → {label} (다이어트 키워드 {diet_score}개, ID:{cat_id})")
        return cat_id
    return int(os.getenv("WP_CAT_CAR", "29"))

def get_category_ids(title="", content=""):
    # 수동 오버라이드가 있으면 그것을 우선 사용
    if WP_CATEGORY_ID_OVERRIDE:
        cat_id = int(WP_CATEGORY_ID_OVERRIDE)
        print(f"   📌 카테고리 ID 수동 지정: {cat_id}")
        return [cat_id]
    # 콘텐츠 기반 자동 분류
    cat_id = _auto_classify(title, content)
    return [cat_id] if cat_id else []

def publish_to_wordpress(title, content, featured_media_id=None):
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {"Authorization": f"Basic {token}", "Content-Type": "application/json"}
    categories = get_category_ids(title, content)
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
    slot = get_slot()
    prompt_file = f"prompts/{CATEGORY}.md" if Path(f"prompts/{CATEGORY}.md").exists() else "system_prompt.md"
    print(f"📂 [{CATEGORY}] 슬롯 {slot} | 주제: {topic[:60]}")
    print(f"📋 지침: {prompt_file}")
    featured_id, extra_image_urls = prepare_images()
    print("📝 Claude 글 생성 중...")
    raw = generate_content(topic, refs)
    title, content = parse_output(raw)
    if extra_image_urls:
        parts = re.split(r'(?=<h2[\s>])', content, flags=re.IGNORECASE)
        img_idx = 0
        assembled = []
        for part in parts:
            assembled.append(part)
            if re.match(r'<h2[\s>]', part, re.IGNORECASE) and img_idx < len(extra_image_urls):
                url = extra_image_urls[img_idx]
                assembled.append(f'\n<figure class="wp-block-image size-large"><img src="{url}" alt=""/></figure>\n')
                img_idx += 1
        content = "".join(assembled)
    thumb_msg = f" + 썸네일 포함 (추가사진 {len(extra_image_urls)}장 H2 배치)" if featured_id else ""
    print(f"✅ 제목: {title}")
    print(f"   본문 {len(content)}자{thumb_msg}")
    print("🚀 WordPress 발행 중...")
    ok, post_id, link = publish_to_wordpress(title, content, featured_media_id=featured_id)
    if ok:
        print(f"✅ 발행 완료! ID:{post_id} {link}")
        if not TOPIC_OVERRIDE:
            delete_used_topic(topic)
    else:
        print("❌ 발행 실패")
        exit(1)

if __name__ == "__main__":
    main()
