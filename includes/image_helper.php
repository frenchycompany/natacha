<?php
/**
 * Image helper functions for histoire chapter photos.
 */

define('HISTOIRE_UPLOAD_DIR', __DIR__ . '/../uploads/histoire/');
define('HISTOIRE_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB
define('HISTOIRE_MAX_WIDTH', 1200);
define('HISTOIRE_ALLOWED_MIMES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
]);

/**
 * Handle an uploaded chapter photo file.
 *
 * Validates the upload (image only, max 5MB, real MIME check via finfo),
 * resizes if wider than 1200px using GD, saves with a unique filename.
 *
 * @param array $file The $_FILES['photo'] entry
 * @return string|null The saved filename, or null on failure
 */
function handleChapterPhoto(array $file): ?string
{
    // Check for upload errors
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Check file size
    if ($file['size'] > HISTOIRE_MAX_FILE_SIZE) {
        return null;
    }

    // Verify real MIME type with finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!isset(HISTOIRE_ALLOWED_MIMES[$mime])) {
        return null;
    }

    $ext = HISTOIRE_ALLOWED_MIMES[$mime];

    // Load image with GD
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/gif'  => @imagecreatefromgif($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default      => false,
    };

    if (!$image) {
        return null;
    }

    // Resize if wider than max width
    $origWidth  = imagesx($image);
    $origHeight = imagesy($image);

    if ($origWidth > HISTOIRE_MAX_WIDTH) {
        $newWidth  = HISTOIRE_MAX_WIDTH;
        $newHeight = (int) round($origHeight * (HISTOIRE_MAX_WIDTH / $origWidth));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG and WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($image);
        $image = $resized;
    }

    // Generate unique filename
    $filename = uniqid('ch_', true) . '.' . $ext;
    $destPath = HISTOIRE_UPLOAD_DIR . $filename;

    // Ensure upload directory exists
    if (!is_dir(HISTOIRE_UPLOAD_DIR)) {
        mkdir(HISTOIRE_UPLOAD_DIR, 0755, true);
    }

    // Save image
    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $destPath, 85),
        'image/png'  => imagepng($image, $destPath, 8),
        'image/gif'  => imagegif($image, $destPath),
        'image/webp' => imagewebp($image, $destPath, 85),
        default      => false,
    };

    imagedestroy($image);

    if (!$saved) {
        return null;
    }

    return $filename;
}

/**
 * Delete a chapter photo from the uploads/histoire/ directory.
 *
 * @param string $filename The filename to delete
 */
function deleteChapterPhoto(string $filename): void
{
    if ($filename === '') {
        return;
    }

    // Prevent directory traversal
    $filename = basename($filename);
    $path = HISTOIRE_UPLOAD_DIR . $filename;

    if (is_file($path)) {
        unlink($path);
    }
}
