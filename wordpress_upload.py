#!/usr/bin/env python3
"""
Claude에서 생성한 글을 WordPress 임시글(draft)로 업로드하는 스크립트
"""

import requests
import json
import base64
import sys
from datetime import datetime


# ===== 설정 (본인 정보로 수정하세요) =====
WP_URL = "https://your-wordpress-site.com"  # WordPress 사이트 URL
WP_USERNAME = "your_username"               # WordPress 사용자명
WP_APP_PASSWORD = "xxxx xxxx xxxx xxxx"    # Application Password (공백 포함 그대로)
# ==========================================


def create_draft(title: str, content: str, tags: list = None, categories: list = None) -> dict:
    """WordPress에 임시글(draft)로 업로드"""

    # Basic Auth 헤더 생성
    credentials = f"{WP_USERNAME}:{WP_APP_PASSWORD}"
    token = base64.b64encode(credentials.encode()).decode("utf-8")
    headers = {
        "Authorization": f"Basic {token}",
        "Content-Type": "application/json",
    }

    payload = {
        "title": title,
        "content": content,
        "status": "draft",  # 임시글
    }
    if tags:
        payload["tags"] = tags
    if categories:
        payload["categories"] = categories

    response = requests.post(
        f"{WP_URL}/wp-json/wp/v2/posts",
        headers=headers,
        json=payload,
    )

    if response.status_code in (200, 201):
        data = response.json()
        return {
            "success": True,
            "id": data["id"],
            "link": data["link"],
            "edit_link": f"{WP_URL}/wp-admin/post.php?post={data['id']}&action=edit",
        }
    else:
        return {
            "success": False,
            "status_code": response.status_code,
            "error": response.text,
        }


def main():
    # --- 여기에 Claude가 생성한 글 내용을 넣으세요 ---
    title = "Claude가 작성한 글 제목"
    content = """
<p>여기에 Claude가 생성한 본문 내용을 넣으세요.</p>
<p>HTML 태그를 사용할 수 있습니다.</p>
<h2>소제목</h2>
<p>본문 내용...</p>
"""
    # ------------------------------------------------

    print(f"[{datetime.now().strftime('%H:%M:%S')}] WordPress에 임시글 업로드 중...")
    result = create_draft(title, content)

    if result["success"]:
        print(f"✅ 업로드 성공!")
        print(f"   글 ID   : {result['id']}")
        print(f"   미리보기 : {result['link']}")
        print(f"   편집하기 : {result['edit_link']}")
    else:
        print(f"❌ 업로드 실패 (HTTP {result['status_code']})")
        print(f"   오류: {result['error']}")
        sys.exit(1)


if __name__ == "__main__":
    main()
