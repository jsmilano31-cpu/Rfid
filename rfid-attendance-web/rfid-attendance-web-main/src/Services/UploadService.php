<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class UploadService
{
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const TARGETS = [
        'profile' => ['directory' => 'profile-images', 'maximum' => 2_097_152],
        'event_banner' => ['directory' => 'event-banners', 'maximum' => 2_097_152],
    ];

    public static function storeImage(?array $file, string $target): ?string
    {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (!isset(self::TARGETS[$target])) {
            throw new DomainException('The upload destination is invalid.');
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new DomainException(self::uploadError((int) $file['error']));
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $targetConfig = self::TARGETS[$target];
        if ($size < 1 || $size > $targetConfig['maximum']) {
            $maximumMb = (int) ($targetConfig['maximum'] / 1_048_576);
            throw new DomainException("Select an image no larger than {$maximumMb} MB.");
        }
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new DomainException('The uploaded image could not be verified.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (!is_string($mimeType) || !isset(self::IMAGE_TYPES[$mimeType]) || !$imageInfo) {
            throw new DomainException('Upload a valid JPG, PNG, or WebP image.');
        }
        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1 || $width > 10_000 || $height > 10_000 || ($width * $height) > 40_000_000) {
            throw new DomainException('The image dimensions are too large.');
        }

        $directory = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $targetConfig['directory'];
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new DomainException('The image storage directory is unavailable.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . self::IMAGE_TYPES[$mimeType];
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new DomainException('The image could not be saved.');
        }

        return '/uploads/' . $targetConfig['directory'] . '/' . $filename;
    }

    public static function storeMultipleImages(?array $files, string $target): array
    {
        if (!$files || empty($files['name'])) {
            return [];
        }
        $saved = [];
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

        foreach ($names as $idx => $name) {
            $err = (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $singleFile = [
                'name' => $name,
                'tmp_name' => $tmpNames[$idx] ?? '',
                'error' => $err,
                'size' => (int) ($sizes[$idx] ?? 0),
            ];
            $path = self::storeImage($singleFile, $target);
            if ($path !== null) {
                $saved[] = $path;
            }
        }
        return $saved;
    }

    public static function removeManaged(?string $path): void
    {
        $path = trim((string) $path);
        if (!preg_match('#^/uploads/(profile-images|event-banners)/[a-f0-9]{40}\.(?:jpg|png|webp)$#', $path)) {
            return;
        }
        $absolutePath = PUBLIC_PATH . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected image is too large.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Try again.',
            default => 'The image could not be uploaded.',
        };
    }
}
