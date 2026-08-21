#!/usr/bin/env python3
"""
네이버 쿠키 추출 도우미 — 로컬에서 1회 실행용
실행: python3 get_naver_cookies.py

1. 크롬 브라우저가 열립니다.
2. 네이버에 직접 로그인하세요.
3. 엔터를 누르면 쿠키가 JSON으로 출력됩니다.
4. 출력값을 GitHub Secrets → NAVER_COOKIES 에 붙여넣으세요.
"""
import json
from playwright.sync_api import sync_playwright


def main():
    print("=" * 60)
    print("네이버 쿠키 추출기")
    print("=" * 60)
    print("브라우저가 열리면 네이버에 로그인한 후 엔터를 누르세요.")
    print()

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False)
        context = browser.new_context()
        page = context.new_page()
        page.goto("https://nid.naver.com/nidlogin.login")

        input("✅ 로그인 완료 후 여기서 엔터를 누르세요...")

        cookies = context.cookies()
        # 네이버 도메인 쿠키만 필터링
        naver_cookies = [
            c for c in cookies
            if "naver.com" in c.get("domain", "")
        ]

        cookie_json = json.dumps(naver_cookies, ensure_ascii=False, indent=2)
        print("\n" + "=" * 60)
        print("아래 값을 GitHub Secrets → NAVER_COOKIES 에 붙여넣으세요:")
        print("=" * 60)
        print(cookie_json)
        print("=" * 60)

        # 파일로도 저장
        with open("naver_cookies.json", "w", encoding="utf-8") as f:
            f.write(cookie_json)
        print("\n✅ naver_cookies.json 파일로도 저장됨")
        print("⚠️  이 파일은 GitHub에 올리지 마세요! (.gitignore에 추가 권장)")

        browser.close()


if __name__ == "__main__":
    main()
