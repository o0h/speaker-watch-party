<?php

declare(strict_types=1);

// Worker起動時の共有状態（リクエスト間で保持される）
$currentSlide  = 1;
$presenting    = false;
$stateFile     = '/app/data/.presenting';

if (file_exists($stateFile)) {
    $data          = json_decode(file_get_contents($stateFile), true) ?? [];
    $presenting    = (bool) ($data['presenting'] ?? false);
    $currentSlide  = (int)  ($data['slide']      ?? 1);
}

$handler = require __DIR__ . '/router.php';

// FrankenPHP Worker loop
while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
