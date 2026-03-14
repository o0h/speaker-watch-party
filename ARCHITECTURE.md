# Speaker Watch Party — 技術設計ドキュメント

v0.2 — 2026-03-14

---

## 1. 技術スタック

| 役割 | 採用技術 | 理由 |
|---|---|---|
| Webサーバー / PHP実行環境 | **FrankenPHP** (dunglas/frankenphp) | Caddy組み込み・HTTPS自動・Mercure内蔵 |
| リアルタイム配信 | **Mercure**（FrankenPHP組み込み） | SSEベース・PHPから `mercure_publish()` 1行で配信 |
| バックエンド言語 | **PHP 8.3** | Worker modeで状態をメモリ保持 |
| 状態保持 | **PHPメモリ内変数**（Worker mode） | スライド番号のみ保持。DB不要 |
| フロントエンド | **Vanilla JS**（バンドルなし） | 参加者UIはシンプルに。依存ゼロ |
| PDF表示 | **PDF.js**（CDN） | cdnjs から配信。参加者はVPSにアクセスできている＝インターネット接続あり |
| コンテナ | **Docker / Docker Compose** | VPS上で `docker compose up` するだけで動く |
| HTTPS | **Caddy自動証明書**（Let's Encrypt） | FrankenPHP/Caddyが自動取得・更新 |

DB（SQLite含む）は不要。デッキは `.env` で1つ固定。

---

## 2. システム構成図

```
[Keynote on Mac]
    │  AppleScript（0.3秒ポーリング）
    │  起動時: POST /api/fetch-pdf  ← 最新PDFを取得してから開始
    │  変化時: POST /api/slide/{n}  ← スライド番号を通知
    │  Authorization: Bearer {API_TOKEN}
    ▼
[Docker: FrankenPHP]
    ├─ Caddy（HTTPS終端・静的ファイル配信）
    ├─ Mercure Hub（組み込み・SSEブロードキャスト）
    ├─ PHP Worker（スライド番号をメモリ保持・API処理）
    └─ /app/data/slide.pdf（ダウンロードしたPDF）
    ▼
[参加者のブラウザ]
    ├─ PDF.jsでスライド表示（ちらつきなし）
    ├─ EventSource で Mercure に接続
    └─ スライド番号受信 → 即座にページ切り替え
```

---

## 3. ディレクトリ構成

```
speaker-watch-party/
├── Makefile                   # build / up などの操作エントリポイント
├── .env.example
├── .runtime/                  # Docker関連ファイル
│   ├── compose.yml
│   ├── Dockerfile
│   └── Caddyfile
├── public/                    # Caddyのドキュメントルート（静的ファイル）
│   ├── index.html             # 参加者向けUI
│   └── assets/
│       └── viewer.js          # PDF.js連携・Mercureクライアント（PDF.jsはCDN）
├── api/                       # PHPアプリケーション
│   ├── worker.php             # FrankenPHP Worker entrypoint
│   ├── router.php             # ルーティング
│   ├── handlers/
│   │   ├── SlideHandler.php   # POST /api/slide/:n
│   │   └── FetchPdfHandler.php # POST /api/fetch-pdf
│   └── middleware/
│       └── Auth.php           # Bearer token検証
└── data/                      # コンテナ内永続化ボリューム
    └── slide.pdf              # ダウンロードしたPDF（fetch-pdf で上書き）
```

---

## 4. 設定（.env）

管理画面・DBは持たず、すべての設定を環境変数で固定する。

```
# 対象のSpeakerDeck URL（デッキは1つ固定）
SPEAKERDECK_URL=https://speakerdeck.com/o0h/my-talk

# Bearer tokenによるAPI認証
API_TOKEN=your-secret-token-here

# Mercure Hub 署名シークレット
MERCURE_JWT_SECRET=change-this-secret

# Caddyが使うドメイン名（HTTPS証明書の取得に使用）
DOMAIN=example.com

# スライド番号オフセット（Keynoteのビルドステップがある場合に調整）
SLIDE_OFFSET=0
```

---

## 5. APIエンドポイント設計

### 5.1 エンドポイント一覧

| エンドポイント | メソッド | 認証 | 説明 |
|---|---|---|---|
| `/` | GET | なし | 参加者向けUI |
| `/api/slide/:n` | POST | Bearer token | スライド番号更新 → Mercureへ配信 |
| `/api/fetch-pdf` | POST | Bearer token | SpeakerDeckから最新PDFを取得・保存 |
| `/api/state` | GET | なし | 現在のスライド番号取得（初期表示用） |
| `/slide.pdf` | GET | なし | PDFファイル配信（Caddyが直接配信） |
| `/.well-known/mercure` | GET | なし（anonymous） | Mercure Hub（FrankenPHP内蔵） |

### 5.2 POST /api/slide/:n

```
Request:
  Authorization: Bearer {API_TOKEN}

Response 200:
  { "slide": 3, "effective": 3 }

副作用:
  Worker変数 $currentSlide を更新
  mercure_publish("slide", json_encode(["slide" => 3, "effective" => 3]))
```

### 5.3 POST /api/fetch-pdf

```
Request:
  Authorization: Bearer {API_TOKEN}

処理フロー:
  1. 環境変数 SPEAKERDECK_URL を読む
  2. SpeakerDeck HTMLをfetchしてdata-idを抽出
  3. slugはURLの末尾から取得
  4. https://files.speakerdeck.com/presentations/{data-id}/{slug}.pdf をDL
  5. /app/data/slide.pdf として保存（上書き）

Response 200:
  { "ok": true, "url": "https://speakerdeck.com/o0h/my-talk" }

Response 500:
  { "ok": false, "error": "Failed to fetch PDF" }
```

### 5.4 GET /api/state

参加者がページを開いた時点での初期状態取得に使う。

```
Response 200:
  {
    "slide": 3,
    "effective": 3,
    "speakerdeck_url": "https://speakerdeck.com/o0h/my-talk",
    "slug": "my-talk",
    "user": "o0h"
  }
```

---

## 6. Mercureトピック設計

### トピック名

```
slide
```

### Publishペイロード

```json
{ "slide": 3, "effective": 3 }
```

- `slide`: Keynoteから受け取った生のスライド番号
- `effective`: `slide + SLIDE_OFFSET`（PDF.jsに渡す実際のページ番号）

オフセット計算はサーバー側で完結。クライアントは `effective` をそのまま使う。

### クライアント側購読（参加者UI）

```javascript
// 初期状態をAPIから取得
const state = await fetch("/api/state").then(r => r.json());
pdfViewer.currentPageNumber = state.effective;

// 以降はMercureでリアルタイム更新
const es = new EventSource("/.well-known/mercure?topic=slide");
es.onmessage = (e) => {
  const { effective } = JSON.parse(e.data);
  pdfViewer.currentPageNumber = effective;
};
```

---

## 7. FrankenPHP Worker構成

スライド番号はWorkerプロセスのメモリ上で保持する。DBは不要。

```php
// api/worker.php
$currentSlide = 1;  // Worker起動時の初期値

$handler = function () use (&$currentSlide): void {
    require __DIR__ . '/router.php';
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
```

Worker変数を複数のリクエストで共有するため、`use (&$currentSlide)` で参照渡しにする。

---

## 8. Caddyfile構成

```caddyfile
{
    frankenphp {
        worker /app/api/worker.php
    }
    order php_server before file_server
}

{$DOMAIN} {
    root * /app/public

    mercure {
        publisher_jwt {env.MERCURE_JWT_SECRET}
        subscriber_jwt {env.MERCURE_JWT_SECRET}
        anonymous
    }

    # PHPはAPIルートのみ処理
    handle /api/* {
        php_server
    }

    # PDFはCaddyが直接配信
    handle /slide.pdf {
        root * /app/data
        file_server
    }

    # HTML/JS/pdfjs
    handle {
        file_server
    }
}
```

---

## 9. Docker構成

Docker関連ファイルはすべて `.runtime/` に配置する。
`docker compose` は `-f .runtime/compose.yml` で指定するか、Makefileを通じて操作する。

### .runtime/compose.yml 概要

```yaml
services:
  app:
    build:
      context: ..          # PJルートをビルドコンテキストにする
      dockerfile: .runtime/Dockerfile
    ports:
      - "443:443"
      - "80:80"
    env_file: ../.env
    environment:
      SERVER_NAME: ${DOMAIN}
    volumes:
      - ../data:/app/data  # PDFを永続化
```

### .runtime/Dockerfile 概要

```dockerfile
FROM dunglas/frankenphp:latest-php8.3

COPY . /app
WORKDIR /app
```

---

## 9.5 Makefile

PJルートに配置。`make` コマンドで操作する。

```makefile
COMPOSE = docker compose -f .runtime/compose.yml

.PHONY: build up down logs ps

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps
```

- `make build` — イメージをビルド
- `make up` — フォアグラウンドで起動（Ctrl+C で停止）
- `make down` — コンテナ停止・削除
- `make logs` — ログをフォロー
- `make ps` — コンテナ状態確認

---

## 10. AppleScript（発表者側）

```applescript
set apiToken to "your-secret-token-here"
set serverUrl to "https://example.com"

-- プレゼン開始時に最新PDFを取得
-- curl はレスポンスが返るまでブロックするので、完了してからループへ進む
do shell script "curl -s -X POST " & serverUrl & "/api/fetch-pdf -H 'Authorization: Bearer " & apiToken & "'"

set prevSlide to 0
repeat
  tell application "Keynote"
    set cur to slide number of current slide of front document
  end tell
  if cur ≠ prevSlide then
    do shell script "curl -s -X POST " & serverUrl & "/api/slide/" & cur & " -H 'Authorization: Bearer " & apiToken & "'"
    set prevSlide to cur
  end if
  delay 0.3
end repeat
```

---

## 11. セキュリティ設計

| 対象 | 保護方法 |
|---|---|
| `POST /api/slide/:n` | Bearer token（環境変数 `API_TOKEN`） |
| `POST /api/fetch-pdf` | 同上 |
| Mercure publish | PHP内部からのみ（外部から叩けない） |
| Mercure subscribe | anonymous（参加者はURLを知っていれば閲覧可） |
| 参加者UI | 認証なし（URLを知っていれば閲覧可） |

---

## 12. スライド番号オフセット

`.env` の `SLIDE_OFFSET` で設定する。変更時はコンテナ再起動が必要。

```
effective = slide（Keynoteから受信） + SLIDE_OFFSET
```

例：Keynoteのアニメーションビルドが2ステップある場合 → `SLIDE_OFFSET=-2`

---

## 13. 参加者UIのSpeakerDeckパーマリンク

スライド番号受信時に以下のURLを生成して画面下部に表示する。

```
https://speakerdeck.com/{user}/{slug}?slide={effective}
```

`user` と `slug` は `GET /api/state` から取得する。

---

## 14. 実装ステップ（推奨順序）

| # | タスク |
|---|---|
| 1 | Dockerfile / Caddyfile / docker-compose.yml の骨格作成 |
| 2 | PHP Worker + ルーター + `POST /api/slide/:n` |
| 3 | Mercure配信の動作確認 |
| 4 | `POST /api/fetch-pdf`（SpeakerDeck fetch → PDF DL） |
| 5 | `GET /api/state` |
| 6 | 参加者UI（PDF.js + EventSource + パーマリンク表示） |
| 7 | AppleScriptの最終調整・動作確認 |

---

## 15. 未解決事項・スコープ外

| 項目 | 対応 |
|---|---|
| VPSのドメイン・DNS設定 | 運用時に設定（Caddyが自動でHTTPS取得） |
| SpeakerDeckのURLパターン変更 | 今回スコープ外。変更時は手動対応 |
| 会場Wi-Fiレイテンシ検証 | リハーサルで確認 |
