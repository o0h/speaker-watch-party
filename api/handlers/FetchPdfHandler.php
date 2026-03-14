<?php

declare(strict_types=1);

class FetchPdfHandler
{
    private const PDF_PATH = '/app/data/slide.pdf';

    public static function handle(): void
    {
        $url = getenv('SPEAKERDECK_URL');
        if (!$url) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'SPEAKERDECK_URL is not set']);
            return;
        }

        // slugはURLの末尾から取得
        $slug = basename(rtrim(parse_url($url, PHP_URL_PATH), '/'));

        // SpeakerDeck の HTML を取得して data-id を抽出
        $dataId = self::fetchDataId($url);
        if ($dataId === null) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to fetch SpeakerDeck page']);
            return;
        }

        // PDF をダウンロード
        $pdfUrl = "https://files.speakerdeck.com/presentations/{$dataId}/{$slug}.pdf";
        $ok = self::downloadFile($pdfUrl, self::PDF_PATH);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to download PDF']);
            return;
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'url' => $url]);
    }

    private static function fetchDataId(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'     => 'GET',
                'user_agent' => 'Mozilla/5.0 (compatible; speaker-watch-party/1.0)',
                'timeout'    => 15,
            ],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            return null;
        }

        // <div ... data-id="abc123" ...> を探す
        if (!preg_match('/data-id=["\']([a-f0-9-]+)["\']/', $html, $m)) {
            return null;
        }

        return $m[1];
    }

    private static function downloadFile(string $url, string $dest): bool
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 30,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return false;
        }

        return file_put_contents($dest, $data) !== false;
    }
}
