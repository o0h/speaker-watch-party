<?php

declare(strict_types=1);

require_once __DIR__ . '/middleware/Auth.php';
require_once __DIR__ . '/handlers/SlideHandler.php';
require_once __DIR__ . '/handlers/FetchPdfHandler.php';
require_once __DIR__ . '/handlers/UploadPdfHandler.php';

// Worker変数を参照渡しで受け取るクロージャを返す
return function () use (&$currentSlide, &$presenting, $stateFile): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    header('Content-Type: application/json');

    // POST /api/slide/:n
    if ($method === 'POST' && preg_match('#^/api/slide/(\d+)$#', $path, $m)) {
        if (!Auth::verify()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $presenting = true;
        file_put_contents($stateFile, json_encode(['presenting' => true, 'slide' => (int) $m[1]]));
        SlideHandler::handle((int) $m[1], $currentSlide);
        return;
    }

    // POST /api/fetch-pdf
    if ($method === 'POST' && $path === '/api/fetch-pdf') {
        if (!Auth::verify()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        FetchPdfHandler::handle();
        return;
    }

    // POST /api/upload-pdf
    if ($method === 'POST' && $path === '/api/upload-pdf') {
        if (!Auth::verify()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        UploadPdfHandler::handle();
        return;
    }

    // POST /api/stop
    if ($method === 'POST' && $path === '/api/stop') {
        if (!Auth::verify()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $presenting = false;
        file_put_contents($stateFile, json_encode(['presenting' => false, 'slide' => $currentSlide]));
        mercure_publish(['slide'], json_encode(['presenting' => false]));
        http_response_code(200);
        echo json_encode(['ok' => true]);
        return;
    }

    // GET /api/state
    if ($method === 'GET' && $path === '/api/state') {
        $url  = getenv('SPEAKERDECK_URL') ?: '';
        $slug = basename(rtrim(parse_url($url, PHP_URL_PATH), '/'));
        $user = explode('/', trim(parse_url($url, PHP_URL_PATH), '/'))[0] ?? '';

        http_response_code(200);
        echo json_encode([
            'presenting'      => $presenting,
            'slide'           => $currentSlide,
            'effective'       => $currentSlide + (int) (getenv('SLIDE_OFFSET') ?: 0),
            'speakerdeck_url' => $url,
            'slug'            => $slug,
            'user'            => $user,
            'tweet_hashtags'  => getenv('TWEET_HASHTAGS') ?: '',
        ]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
};
