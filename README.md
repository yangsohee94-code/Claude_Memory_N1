# Claude_Memory_N1

수익형 블로그 자동화 + WordPress 플러그인 모음

## 구조

```
.
├── auto_blog.py              # 메인 자동화: Claude 글 생성 → WP 발행 → SNS 발행
├── sns_post.py               # SNS 발행 모듈 (Threads / Facebook / Pinterest)
├── wp_merge_duplicate_images.py  # WordPress 중복 이미지 정리 유틸리티
├── requirements.txt
│
├── prompts/                  # 카테고리별 프롬프트 (글 작성 지침)
├── topics/                   # 카테고리별 주제 목록
├── references/               # 카테고리별 참고 자료
│
├── duplicate-image-merger/   # WordPress 플러그인: 중복 이미지 통합
├── quick-image-insert/       # WordPress 플러그인: 빠른 이미지 삽입
├── sns-share-scheduler/      # WordPress 플러그인: SNS 예약 발행
│
└── index.html                # NotebookLM 노트북 목록 뷰어 (구글 드라이브 API 사용)
```

## 블로그 자동화 사용법

### 환경변수 설정 (.env)

```env
ANTHROPIC_API_KEY=...
WP_URL=https://your-site.com
WP_USERNAME=...
WP_APP_PASSWORD=...
CATEGORY=entertainment   # car / entertainment / health / sports / 재테크 / 등

# 카테고리 ID (WP)
WP_CAT_ENTERTAINMENT_POP=44
WP_CAT_ENTERTAINMENT_DRAMA=43
WP_CAT_HEALTH_DIET=47
WP_CAT_HEALTH_INFO=48
WP_CAT_CAR=29

# Google Drive (사진 첨부, 선택)
GOOGLE_CREDENTIALS=...
GOOGLE_DRIVE_ROOT_FOLDER_ID=...

# SNS (선택)
THREADS_USER_ID=...
THREADS_ACCESS_TOKEN=...
FB_PAGE_ID=...
FB_PAGE_ACCESS_TOKEN=...
PINTEREST_ACCESS_TOKEN=...
PINTEREST_BOARD_ID=...
```

### 실행

```bash
pip install -r requirements.txt
python auto_blog.py
```

### 중복 이미지 정리

```bash
# 미리보기
python wp_merge_duplicate_images.py --dry-run

# 실제 실행
python wp_merge_duplicate_images.py
```
