#!/usr/bin/env python3
"""
WordPress 중복 이미지 Merge 스크립트

동작 순서:
  1. WordPress 미디어 라이브러리 전체 조회
  2. 이미지 파일을 실제로 다운로드해 MD5 해시로 중복 감지
  3. 중복 그룹마다 가장 오래된 첨부(attachment)를 master로 확정
  4. master URL·ID를 참조하도록 포스트 본문 / featured_media 전부 업데이트
  5. 중복 첨부(duplicate)를 WordPress에서 삭제

사용법:
  # 실제 변경 없이 미리 보기
  python wp_merge_duplicate_images.py --dry-run

  # 실제 실행
  python wp_merge_duplicate_images.py

환경변수 (.env 또는 직접 설정):
  WP_URL            https://your-site.com
  WP_USERNAME       wp-username
  WP_APP_PASSWORD   xxxx xxxx xxxx xxxx
"""

import os
import hashlib
import base64
import re
import sys
import argparse
from collections import defaultdict

import requests
from dotenv import load_dotenv

load_dotenv()

WP_URL = os.getenv("WP_URL", "").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME", "")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")


def _auth_headers(extra=None):
    token = base64.b64encode(f"{WP_USERNAME}:{WP_APP_PASSWORD}".encode()).decode()
    h = {"Authorization": f"Basic {token}"}
    if extra:
        h.update(extra)
    return h


# ──────────────────────────────────────────────
# 1. 미디어 전체 조회
# ──────────────────────────────────────────────

def fetch_all_media():
    """WordPress 미디어 라이브러리 전체를 페이지 단위로 조회."""
    items = []
    page = 1
    while True:
        resp = requests.get(
            f"{WP_URL}/wp-json/wp/v2/media",
            headers=_auth_headers(),
            params={"per_page": 100, "page": page, "media_type": "image"},
            timeout=30,
        )
        if resp.status_code == 400:
            break
        resp.raise_for_status()
        batch = resp.json()
        if not batch:
            break
        items.extend(batch)
        total_pages = int(resp.headers.get("X-WP-TotalPages", 1))
        if page >= total_pages:
            break
        page += 1
    return items


# ──────────────────────────────────────────────
# 2. 이미지 해시 계산
# ──────────────────────────────────────────────

def image_md5(url: str) -> str | None:
    """URL에서 이미지를 다운로드해 MD5 반환. 실패 시 None."""
    try:
        r = requests.get(url, timeout=20, headers={"User-Agent": "Mozilla/5.0"})
        if r.status_code == 200:
            return hashlib.md5(r.content).hexdigest()
    except Exception as e:
        print(f"   ⚠️ 다운로드 실패 {url}: {e}")
    return None


def group_by_hash(media_items: list) -> dict[str, list[dict]]:
    """해시 → [media_item, ...] 딕셔너리 반환. 중복(2개 이상)만 포함."""
    hash_map: dict[str, list[dict]] = defaultdict(list)
    total = len(media_items)
    for idx, item in enumerate(media_items, 1):
        url = item.get("source_url", "")
        if not url:
            continue
        print(f"   [{idx}/{total}] 해시 계산: {url.split('/')[-1]}", end="\r")
        h = image_md5(url)
        if h:
            hash_map[h].append(item)
    print()
    return {h: items for h, items in hash_map.items() if len(items) >= 2}


# ──────────────────────────────────────────────
# 3. 포스트 참조 교체
# ──────────────────────────────────────────────

def fetch_all_posts():
    """공개/비공개/임시글 전체 조회."""
    items = []
    for status in ("publish", "draft", "private", "future", "pending"):
        page = 1
        while True:
            resp = requests.get(
                f"{WP_URL}/wp-json/wp/v2/posts",
                headers=_auth_headers(),
                params={"per_page": 100, "page": page, "status": status},
                timeout=30,
            )
            if resp.status_code in (400, 401, 403):
                break
            if resp.status_code != 200:
                break
            batch = resp.json()
            if not batch:
                break
            items.extend(batch)
            total_pages = int(resp.headers.get("X-WP-TotalPages", 1))
            if page >= total_pages:
                break
            page += 1
    return items


def _replace_url_in_content(content: str, old_url: str, new_url: str) -> str:
    """본문 HTML에서 이미지 URL 교체 (srcset 포함)."""
    # src, srcset 등 다양한 위치에 있는 URL 교체
    return content.replace(old_url, new_url)


def update_post(post_id: int, payload: dict, dry_run: bool):
    if dry_run:
        print(f"      [DRY-RUN] 포스트 {post_id} 업데이트 생략: {list(payload.keys())}")
        return True
    resp = requests.post(
        f"{WP_URL}/wp-json/wp/v2/posts/{post_id}",
        headers=_auth_headers({"Content-Type": "application/json"}),
        json=payload,
        timeout=30,
    )
    if resp.status_code in (200, 201):
        return True
    print(f"      ❌ 포스트 {post_id} 업데이트 실패 HTTP {resp.status_code}: {resp.text[:200]}")
    return False


def fix_posts_for_group(
    master: dict,
    duplicates: list[dict],
    all_posts: list[dict],
    dry_run: bool,
):
    """포스트들에서 duplicate URL/ID를 master로 교체."""
    master_id = master["id"]
    master_url = master["source_url"]
    dup_ids = {d["id"] for d in duplicates}
    dup_urls = {d["source_url"] for d in duplicates}

    changed = 0
    for post in all_posts:
        payload = {}
        raw_content = post.get("content", {}).get("raw") or post.get("content", {}).get("rendered", "")

        # 본문 URL 교체
        new_content = raw_content
        for old_url in dup_urls:
            new_content = _replace_url_in_content(new_content, old_url, master_url)

        if new_content != raw_content:
            payload["content"] = new_content

        # featured_media 교체
        if post.get("featured_media") in dup_ids:
            payload["featured_media"] = master_id

        if payload:
            print(f"      → 포스트 #{post['id']} 「{post.get('title', {}).get('rendered', '')[:40]}」 수정")
            update_post(post["id"], payload, dry_run)
            changed += 1

    return changed


# ──────────────────────────────────────────────
# 4. 중복 첨부 삭제
# ──────────────────────────────────────────────

def delete_attachment(attachment_id: int, dry_run: bool):
    if dry_run:
        print(f"      [DRY-RUN] 첨부 {attachment_id} 삭제 생략")
        return
    resp = requests.delete(
        f"{WP_URL}/wp-json/wp/v2/media/{attachment_id}",
        headers=_auth_headers(),
        params={"force": True},
        timeout=30,
    )
    if resp.status_code in (200, 201):
        print(f"      🗑️ 첨부 {attachment_id} 삭제 완료")
    else:
        print(f"      ❌ 첨부 {attachment_id} 삭제 실패 HTTP {resp.status_code}")


# ──────────────────────────────────────────────
# 메인
# ──────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="WordPress 중복 이미지 Merge")
    parser.add_argument("--dry-run", action="store_true", help="실제 변경 없이 미리 보기")
    parser.add_argument("--skip-delete", action="store_true", help="중복 첨부 삭제 건너뜀")
    args = parser.parse_args()

    if not all([WP_URL, WP_USERNAME, WP_APP_PASSWORD]):
        print("❌ WP_URL / WP_USERNAME / WP_APP_PASSWORD 환경변수를 설정하세요.")
        sys.exit(1)

    mode = "[DRY-RUN]" if args.dry_run else "[실행]"
    print(f"\n{'='*60}")
    print(f"  WordPress 중복 이미지 Merge {mode}")
    print(f"  사이트: {WP_URL}")
    print(f"{'='*60}\n")

    # 1. 미디어 조회
    print("📂 미디어 라이브러리 조회 중...")
    media_items = fetch_all_media()
    print(f"   총 {len(media_items)}개 이미지 조회 완료\n")

    # 2. 해시로 중복 감지
    print("🔍 중복 이미지 감지 중 (MD5 해시 비교)...")
    dup_groups = group_by_hash(media_items)
    if not dup_groups:
        print("✅ 중복 이미지 없음. 작업 완료.")
        return

    print(f"\n⚠️  중복 그룹 {len(dup_groups)}개 발견!\n")

    # 3. 포스트 전체 조회
    print("📝 포스트 전체 조회 중...")
    all_posts = fetch_all_posts()
    print(f"   총 {len(all_posts)}개 포스트 조회 완료\n")

    # 4. 그룹별 처리
    total_fixed_posts = 0
    total_deleted = 0

    for hash_val, items in dup_groups.items():
        # 가장 오래된(ID 작은) 것을 master로
        items_sorted = sorted(items, key=lambda x: x["id"])
        master = items_sorted[0]
        duplicates = items_sorted[1:]

        print(f"📎 해시: {hash_val[:12]}... | master ID={master['id']} | 중복 {len(duplicates)}개")
        print(f"   master: {master['source_url']}")
        for d in duplicates:
            print(f"   dup   : {d['source_url']}")

        # 포스트 참조 교체
        fixed = fix_posts_for_group(master, duplicates, all_posts, args.dry_run)
        total_fixed_posts += fixed
        if fixed == 0:
            print("      (참조 포스트 없음)")

        # 중복 첨부 삭제
        if not args.skip_delete:
            for dup in duplicates:
                delete_attachment(dup["id"], args.dry_run)
                total_deleted += 1
        print()

    print(f"\n{'='*60}")
    print(f"  완료! 수정된 포스트: {total_fixed_posts}개 | 삭제된 첨부: {total_deleted}개")
    if args.dry_run:
        print("  ※ --dry-run 모드였으므로 실제 변경 없음")
    print(f"{'='*60}\n")


if __name__ == "__main__":
    main()
