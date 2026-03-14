# Speaker Watch Party

Keynoteのスライド進行をリアルタイムで参加者ブラウザに同期するツール。

```
Keynote → AppleScript → FrankenPHP → Mercure SSE → 参加者ブラウザ
```

## 前提条件

- Docker / Docker Compose
- macOS（AppleScript実行用）
- Keynote

---

## セットアップ

### 1. 環境変数の設定

```bash
cp .env.example .env
```

`.env` を編集します。

| 変数 | 説明 | 例 |
|------|------|----|
| `SPEAKERDECK_URL` | 発表スライドのSpeakerDeck URL | `https://speakerdeck.com/yourname/your-talk` |
| `API_TOKEN` | AppleScript・管理画面の認証トークン | `ランダムな文字列` |
| `MERCURE_JWT_SECRET` | Mercure Hubの署名シークレット | `ランダムな文字列` |
| `DOMAIN` | 公開ドメイン名（空欄でローカルHTTP） | `example.com` |
| `SLIDE_OFFSET` | スライド番号オフセット（後述） | `0` |
| `TWEET_HASHTAGS` | Xポストボタンのハッシュタグ（カンマ区切り・`#`不要） | `phperkaigi,a` |

### 2. サーバー起動

```bash
make up -d
```

### 3. PDFのアップロード

管理画面でSpeakerDeckからダウンロードしたPDFをアップロードします。

`http://localhost/admin.html`（本番は `https://example.com/admin.html`）

「API トークン」欄に `.env` の `API_TOKEN` を入力してアップロードしてください。

### 4. AppleScriptの設定

`keynote_sync.applescript` の `API_BASE` を環境に合わせて変更します。

```applescript
property API_BASE : "http://localhost"   -- 本番の場合は https://example.com
```

ローカルで使う場合はデフォルトのままで動作します。

---

## 使い方

### 起動順序

1. `make up -d`（サーバー起動）
2. `http://localhost/admin.html` でPDFをアップロード
3. 参加者URL（`http://localhost/`）をブラウザで開く
4. Keynoteでプレゼンファイルを開いてスライドショーを開始
5. AppleScriptを起動

```bash
osascript keynote_sync.applescript
```

AppleScriptはスライドショーの前後どちらのタイミングで起動しても動作します。

### 参加者の操作

| 操作 | 内容 |
|------|------|
| `● LIVE` バッジ（赤） | 発表者に同期中。クリックで同期OFF |
| `ライブに戻る (p.N) ▶` | 発表者の現在ページに戻る（同期再開） |
| `‹` / `›` ボタン | ページ移動 |
| `←` / `→` キー、`h` / `l` キー | ページ移動（PC） |
| 画面左半分クリック / 右スワイプ | 前のページ |
| 画面右半分クリック / 左スワイプ | 次のページ |
| `𝕏 ポスト` ボタン | 表示中のスライドへのリンク付きでXにポスト |

手動でページ移動すると同期が外れます。`ライブに戻る` ボタンで発表者の現在ページに戻れます。

### URL共有

スライドが切り替わるたびにURLのハッシュが更新されます（例: `http://localhost/#5`）。このURLを共有すると、開いた時点でそのページを表示します。プレゼン中に開いた場合は同期が外れた状態で開始します。

---

## 本番デプロイ

### Docker Compose を使う場合

`.env` の `DOMAIN` にドメイン名を設定してサーバーで起動します。

```
DOMAIN=example.com
```

CaddyがLet's Encryptで自動的にHTTPS証明書を取得・更新します。ポート80・443が外部に開放されていれば追加設定は不要です。

### docker run を使う場合

`.env` ファイルなしで環境変数を直接注入できます。

```bash
docker run -d \
  -e SERVER_NAME=example.com \
  -e SPEAKERDECK_URL=https://speakerdeck.com/yourname/your-talk \
  -e API_TOKEN=your-secret-token \
  -e MERCURE_JWT_SECRET=your-jwt-secret \
  -e SLIDE_OFFSET=0 \
  -e TWEET_HASHTAGS=phperkaigi,a \
  -p 80:80 -p 443:443 -p 443:443/udp \
  -v /path/to/data:/app/data \
  speaker-watch-party-app
```

---

## SLIDE_OFFSET について

Keynote手元ファイルとSpeakerDeckのページ番号がずれる場合に使います。

例：スライドタイトル前にアナウンス用スライドが3枚ある場合

```
SLIDE_OFFSET=-3
```

```
Keynote:      1, 2, 3（アナウンス）,  4（タイトル）,  5（本題1）...
参加者表示:   1, 1, 1,               1,              2...
```

SpeakerDeck側のページ番号と合わせたい場合は、ずれ枚数の負の値を設定します。

---

## Makeコマンド

```bash
make up      # 起動（フォアグラウンド）
make up -d   # 起動（バックグラウンド）
make down    # 停止
make build   # イメージ再ビルド
make logs    # ログを表示
make ps      # コンテナ状態確認
```

---

## ディレクトリ構成

```
speaker-watch-party/
├── .env.example                  # 環境変数テンプレート
├── Makefile
├── keynote_sync.applescript      # 発表者側スクリプト（Mac）
├── .runtime/
│   ├── Dockerfile
│   ├── Caddyfile
│   └── compose.yml
├── api/
│   ├── worker.php                # FrankenPHP Workerエントリポイント
│   ├── router.php
│   ├── handlers/
│   │   ├── SlideHandler.php
│   │   ├── UploadPdfHandler.php
│   │   └── FetchPdfHandler.php
│   └── middleware/
│       └── Auth.php
├── public/
│   ├── index.html                # 参加者向け画面
│   ├── admin.html                # 管理画面
│   └── assets/
│       └── viewer.js
└── data/                         # 永続化ボリューム（Dockerマウント）
    └── slide.pdf
```
