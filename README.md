# NotebookLM 목록

Google Drive API를 통해 NotebookLM 노트북 목록을 조회하는 웹 앱입니다.

## 사용 방법

### 1. Google Cloud Console 설정

1. [Google Cloud Console](https://console.cloud.google.com)에서 새 프로젝트를 만들거나 기존 프로젝트를 선택합니다.
2. **API 및 서비스 > 라이브러리**에서 **Google Drive API**를 활성화합니다.
3. **API 및 서비스 > 사용자 인증 정보**에서 **OAuth 2.0 클라이언트 ID**를 생성합니다.
   - 애플리케이션 유형: **웹 애플리케이션**
   - 승인된 자바스크립트 원본: 앱을 서비스하는 URL (예: `http://localhost:8080`)
4. 생성된 클라이언트 ID를 복사합니다.

### 2. 앱 실행

```bash
# 로컬 서버로 실행 (예시)
python3 -m http.server 8080
```

브라우저에서 `http://localhost:8080`을 열고:
1. 클라이언트 ID를 입력 후 저장
2. **Google 로그인** 버튼 클릭
3. 권한 승인 후 NotebookLM 노트북 목록 확인

## 동작 원리

NotebookLM은 Google Drive에 파일을 저장할 때 전용 MIME 타입(`application/vnd.google-apps.drive-sdk.720312581700`)을 사용합니다.  
이 앱은 Drive API로 해당 MIME 타입의 파일만 필터링하여 목록으로 표시합니다.

## 기능

- Google OAuth 2.0 인증
- NotebookLM 노트북 목록 조회 및 표시
- 노트북 이름 검색 필터
- 클릭 시 NotebookLM에서 바로 열기
- 라이트/다크 모드 자동 지원
