<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Image upload handling for destination photos and announcement banners.
 *
 * The threat this class exists to stop: a file named holiday.jpg that is
 * actually PHP source. Three independent defences, because any one of them
 * can be worked around:
 *
 *   1. The type is read from the file's own bytes (finfo), never from the
 *      extension or the browser-supplied MIME type — both are attacker input.
 *   2. The image is decoded and re-encoded through GD. Anything that is not
 *      genuinely an image fails to decode, and any payload smuggled in the
 *      metadata of one that is does not survive the round trip.
 *   3. The stored filename is random, so an attacker cannot predict the URL
 *      of what they uploaded.
 *
 * uploads/.htaccess disables the PHP engine in the directory as a fourth
 * layer, for the case where all three of the above are somehow defeated.
 */
final class Uploader
{
    private const MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
    private const MAX_WIDTH = 1920;              // downscale anything larger

    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private array $errors = [];

    public function errors(): array { return $this->errors; }
    public function firstError(): ?string { return $this->errors ? reset($this->errors) : null; }

    /**
     * Validates, re-encodes, and stores one uploaded image.
     *
     * @param  array  $file      One entry from $_FILES
     * @param  string $subfolder 'destinations' | 'banners'
     * @return string|null       Web-relative path, or null on failure
     */
    public function store(array $file, string $subfolder = 'destinations'): ?string
    {
        $this->errors = [];

        if (!$this->validate($file)) {
            return null;
        }

        $mime = $this->detectMime($file['tmp_name']);
        $extension = self::ALLOWED[$mime];

        $image = $this->decode($file['tmp_name'], $mime);
        if ($image === null) {
            $this->errors[] = 'That file could not be read as an image.';
            return null;
        }

        $image = $this->downscale($image);

        $directory = $this->directory($subfolder);
        if ($directory === null) {
            return null;
        }

        // Random name: the uploader must not be able to predict the URL.
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $absolute = $directory . DIRECTORY_SEPARATOR . $filename;

        $written = $this->encode($image, $absolute, $extension);

        if (!$written) {
            $this->errors[] = 'The image could not be saved. Check folder permissions.';
            return null;
        }

        return 'uploads/' . $subfolder . '/' . $filename;
    }

    /**
     * Stores several files, skipping any that fail.
     *
     * store() clears the error list on each call, so failures are accumulated
     * here — otherwise uploading five files where the first three were
     * rejected would report only the last one's problem.
     */
    public function storeMany(array $files, string $subfolder = 'destinations'): array
    {
        $stored = [];
        $collected = [];
        $count = count($files['name'] ?? []);

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $path = $this->store([
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ], $subfolder);

            if ($path !== null) {
                $stored[] = $path;
            } else {
                foreach ($this->errors as $message) {
                    $collected[] = $files['name'][$i] . ': ' . $message;
                }
            }
        }

        $this->errors = $collected;
        return $stored;
    }

    // -------------------------------------------------------------------------

    private function validate(array $file): bool
    {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($code !== UPLOAD_ERR_OK) {
            $this->errors[] = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is larger than the server allows.',
                UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload.',
                default                                   => 'The upload failed.',
            };
            return false;
        }

        // Confirms the file genuinely arrived via HTTP upload rather than
        // being an arbitrary server path supplied by the request.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->errors[] = 'Invalid upload.';
            return false;
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            $this->errors[] = 'Images must be 5 MB or smaller.';
            return false;
        }

        $mime = $this->detectMime($file['tmp_name']);
        if ($mime === null || !isset(self::ALLOWED[$mime])) {
            $this->errors[] = 'Only JPG, PNG, and WebP images are accepted.';
            return false;
        }

        // getimagesize on a non-image returns false even when the first bytes
        // were crafted to look like one.
        if (@getimagesize($file['tmp_name']) === false) {
            $this->errors[] = 'That file is not a valid image.';
            return false;
        }

        return true;
    }

    private function detectMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);

        return $mime === false ? null : $mime;
    }

    private function decode(string $path, string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };

        return $image === false ? null : $image;
    }

    /** Keeps stored images to a sensible page weight. */
    private function downscale(\GdImage $image): \GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width <= self::MAX_WIDTH) {
            return $image;
        }

        $newWidth  = self::MAX_WIDTH;
        $newHeight = (int) round($height * (self::MAX_WIDTH / $width));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG and WebP.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    private function encode(\GdImage $image, string $absolute, string $extension): bool
    {
        return match ($extension) {
            'jpg'  => imagejpeg($image, $absolute, 85),
            'png'  => imagepng($image, $absolute, 6),
            'webp' => imagewebp($image, $absolute, 85),
            default => false,
        };
    }

    private function directory(string $subfolder): ?string
    {
        // Allowlist: the subfolder is chosen by the calling page, never by
        // the request, but an allowlist makes that guarantee explicit.
        if (!in_array($subfolder, ['destinations', 'banners', 'qr'], true)) {
            $this->errors[] = 'Invalid upload destination.';
            return null;
        }

        $directory = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subfolder;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->errors[] = 'The upload folder could not be created.';
            return null;
        }

        if (!is_writable($directory)) {
            $this->errors[] = 'The upload folder is not writable.';
            return null;
        }

        return $directory;
    }

    /** Removes a stored file. Silent when it is already gone. */
    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        // Never let a stored value walk out of the uploads directory.
        if (!str_starts_with($relativePath, 'uploads/') || str_contains($relativePath, '..')) {
            return;
        }

        $absolute = dirname(APP_PATH) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
