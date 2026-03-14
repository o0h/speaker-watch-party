<?php

declare(strict_types=1);

class UploadPdfHandler
{
    private const PDF_PATH = '/app/data/slide.pdf';

    public static function handle(): void
    {
        $file = $_FILES['pdf'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
            return;
        }

        if ($file['type'] !== 'application/pdf' && !str_ends_with($file['name'], '.pdf')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'File must be a PDF']);
            return;
        }

        if (!move_uploaded_file($file['tmp_name'], self::PDF_PATH)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
            return;
        }

        http_response_code(200);
        echo json_encode(['ok' => true]);
    }
}
