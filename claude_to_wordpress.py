#!/usr/bin/env python3
"""
Claude API로 글 생성 → WordPress 임시글 자동 업로드
"""

import os
import base64
import requests
import anthropic
from dotenv import load_dotenv

load_dotenv()

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")
WP_URL = os.getenv("WP_URL", "").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD")

# GitHub Actions에서는 환경변수 TOPIC 사용, 없으면 아래 기본값 사용
TOPIC = os.getenv("TOPIC") or """
여기에 글 주제나 요청을 입력하세요.
예: 재택근무 생산성을 높이는 5가지 방법을 블로그 글로 작성해줘.
"""

SYSTEM_PROMPT = """당신은 전문 블로그 작가입니다.
요청받은 주제로 WordPress에 바로 올릴 수 있는 HTML 형식의 블로그 글을 작성하세요.

규칙:
- 제목은 첫 줄에 "TITLE: 제목 내용" 형식으로 작성
- 본문은 HTML 태그 사용 (<h2>, <h3>, <p>, <ul>, <li>, <strong> 등)
- <html>, <head>, <body> 태그는 사용하지 않음
- 독자가 읽기 편하고 유익한 내용으로 작성
- 한국어로 작성"""


def generate_content_with_claude(topic: str) -> tuple[str, str]:
    print("📝 Claude가 글을 생성하는 중...")
    client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    message = client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=4096,
        system=SYSTEM_PROMPT,
        messages=[{"role": "user", "content": topic}],
    )
    full_text = message.content[0].text
    lines = full_text.strip().split("\n")
    title = "제목 없음"
    body_lines = []
    for i, line in enumerate(lines):
        if line.startswith("TITLE:"):
            title = line.replace("TITLE:", "").strip()
            body_lines = lines[i + 1:]
            break
    else:
        body_lines = lines
    content = "\n".join(body_lines).strip()
    return title, content


def upload_to_wordpress(title: str, content: str) -> dict:
    print("🚀 WordPress에 업로드 중...")
    print(f"   URL: {WP_URL}")
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {
        "Authorization": f"Basic {token}",
        "Content-Type": "application/json",
    }
    payload = {
        "title": title,
        "content": content,
        "status": "draft",
    }
    response = requests.post(
        f"{WP_URL}/wp-json/wp/v2/posts",
        headers=headers,
        json=payload,
        timeout=30,
    )
    print(f"   HTTP 응답: {response.status_code}")
    print(f"   응답 내용: {response.text[:500]}")

    if response.status_code in (200, 201):
        data = response.json()
        if isinstance(data, dict) and "id" in data:
            return {
                "success": True,
                "id": data["id"],
                "edit_link": f"{WP_URL}/wp-admin/post.php?post={data['id']}&action=edit",
            }
        else:
            return {"success": False, "status_code": response.status_code, "error": str(data)}
    else:
        return {
            "success": False,
            "status_code": response.status_code,
            "error": response.text,
        }


def main():
    title, content = generate_content_with_claude(TOPIC)
    print(f"\n✅ 생성된 제목: {title}")
    print(f"   본문 길이: {len(content)}자\n")
    result = upload_to_wordpress(title, content)
    if result["success"]:
        print(f"✅ WordPress 임시글 업로드 완료!")
        print(f"   글 ID    : {result['id']}")
        print(f"   편집하기 : {result['edit_link']}")
    else:
        print(f"❌ 업로드 실패 (HTTP {result.get('status_code', '?')})")
        print(f"   오류: {result['error']}")
        raise SystemExit(1)


if __name__ == "__main__":
    main()
