#!/usr/bin/env python3
"""
자동 블로그 발행 스크립트
카테고리별 주제 선택 -> Claude 글 생성 -> WordPress 발행
"""

import os
import re
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
    files = sorted(
        [f for f in ref_dir.glob("*.txt") if f.stat().st_size > 0]
    )[-2:]
    texts = []
    for f in files:
        content = f.read_text(encoding="utf-8").strip()
        for line in content.splitlines():
            line = line.strip()
            if line.startswith("http"):
                try:
                    resp = requests.get(
                        line, timeout=10,
                        headers={"User-Agent": "Mozilla/5.0"}
                    )
                    texts.append(f"[출처: {line}]\n{resp.text[:3000]}")
                except Exception:
                    texts.append(f"[URL 로드 실패: {line}]")
            elif line:
                texts.append(line)
    return "\n\n".join(texts)


def load_system_prompt():
    # 카테고리별 전용 지침 우선 로드
    category_prompt = Path(f"prompts/{CATEGORY}.md")
    if category_prompt.exists():
        return category_prompt.read_text(encoding="utf-8")
    # 없으면 기본 system_prompt.md 사용
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
    content_match = re.search(
        r"---CONTENT---\n(.*?)\n---END---", text, re.DOTALL
    )
    if content_match:
        content = content_match.group(1).strip()
    else:
        code_match = re.findall(
            r"```(?:html|markdown)?\n(.*?)```", text, re.DOTALL
        )
        content = code_match[0].strip() if code_match else text
    return title, content


def publish_to_wordpress(title, content):
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {
        "Authorization": f"Basic {token}",
        "Content-Type": "application/json",
    }
    cat_map = {
        "entertainment": int(os.getenv("WP_CAT_ENTERTAINMENT", "0")),
        "car": int(os.getenv("WP_CAT_CAR", "0")),
        "health": int(os.getenv("WP_CAT_HEALTH", "0")),
    }
    categories = [c for c in [cat_map.get(CATEGORY, 0)] if c > 0]
    payload = {"title": title, "content": content, "status": "publish"}
    if categories:
        payload["categories"] = categories
    resp = requests.post(
        f"{WP_URL}/wp-json/wp/v2/posts",
        headers=headers,
        json=payload,
        timeout=30,
    )
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
    print("📝 Claude 글 생성 중...")
    raw = generate_content(topic, refs)
    title, content = parse_output(raw)
    print(f"✅ 제목: {title}")
    print(f"   본문 {len(content)}자")
    print("🚀 WordPress 발행 중...")
    ok, post_id, link = publish_to_wordpress(title, content)
    if ok:
        print(f"✅ 발행 완료! ID:{post_id} {link}")
    else:
        print("❌ 발행 실패")
        exit(1)


if __name__ == "__main__":
    main()
