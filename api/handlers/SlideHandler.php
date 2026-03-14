<?php

declare(strict_types=1);

class SlideHandler
{
    public static function handle(int $slide, int &$currentSlide): void
    {
        $offset    = (int) (getenv('SLIDE_OFFSET') ?: 0);
        $effective = $slide + $offset;

        // Worker メモリ上の状態を更新
        $currentSlide = $slide;

        // Mercure Hub へ配信
        mercure_publish(
            ['slide'],
            json_encode(['presenting' => true, 'slide' => $slide, 'effective' => $effective]),
        );

        http_response_code(200);
        echo json_encode(['slide' => $slide, 'effective' => $effective]);
    }
}
