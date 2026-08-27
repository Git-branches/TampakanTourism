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
    /**
     * What the office asked for. NOT necessarily what gets accepted — see
     * maxBytes() below, which is the number that actually applies.
     *
     * Raised from 5 MB because that rejected ordinary phone photographs. A
     * current handset produces 3-12 MB per shot and the office was being told
     * their own camera was wrong.
     */
    private const WANTED_BYTES = 50 * 1024 * 1024;

    /**
     * A ceiling on PIXELS, which is a different question from bytes and the one
     * that actually decides whether this survives.
     *
     * GD decodes to an uncompressed bitmap at roughly 4 bytes per pixel, and
     * downscale() holds a second image while it resizes. A 40 MB JPEG can carry
     * 150 megapixels — 600 MB for the source bitmap alone, against a 512 MB
     * memory_limit. That is a fatal error and a white screen, not a message
     * anybody can act on.
     *
     * So the dimensions are read from the header first, before GD is handed
     * anything. 60 megapixels clears every phone on the market — a 48 MP iPhone
     * frame is well inside it — and peaks around 250 MB, which the limit holds.
     *
     * This also closes a decompression bomb: a few KB of PNG can declare
     * 30000x30000, pass any byte check ever written, and take the process down.
     */
    private const MAX_PIXELS = 60_000_000;

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

        if (($file['size'] ?? 0) > self::maxBytes()) {
            $this->errors[] = 'Images must be ' . self::maxMegabytes() . ' MB or smaller.';
            return false;
        }

        $mime = $this->detectMime($file['tmp_name']);
        if ($mime === null || !isset(self::ALLOWED[$mime])) {
            $this->errors[] = 'Only JPG, PNG, and WebP images are accepted.';
            return false;
        }

        // getimagesize on a non-image returns false even when the first bytes
        // were crafted to look like one.
        $size = @getimagesize($file['tmp_name']);

        if ($size === false) {
            $this->errors[] = 'That file is not a valid image.';
            return false;
        }

        /* THE DIMENSION CHECK, and it happens HERE — after the header has been
           read and before GD is handed the file. getimagesize reads the header
           only; imagecreatefrom* allocates the whole bitmap. Getting these two
           in the wrong order is the difference between a message and a crash. */
        $pixels = (int) $size[0] * (int) $size[1];

        if ($pixels > self::MAX_PIXELS) {
            $this->errors[] = 'That image is ' . round($pixels / 1_000_000) . ' megapixels, which is too '
                . 'large to process. Please resize it, or use your phone\'s standard photo setting.';
            return false;
        }

        return true;
    }

    /**
     * The size an upload may actually be.
     *
     * The smaller of what the office wanted and what PHP will physically let
     * through. upload_max_filesize and post_max_size reject a file before a
     * single line of this application runs, so promising more than they allow
     * produces an upload that fails with no explanation anybody can act on —
     * and, when post_max_size is what was exceeded, an empty $_POST that fails
     * CSRF instead, which is a bewildering thing to debug.
     */
    public static function maxBytes(): int
    {
        return min(self::WANTED_BYTES, upload_limit_bytes());
    }

    /** The same figure as a whole number, for saying out loud in a form. */
    public static function maxMegabytes(): int
    {
        return (int) floor(self::maxBytes() / 1048576);
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
        //
        // 'guides' — tour guide profile photographs. Public on purpose: the
        // picture appears on the printed ID and on the verification page a
        // visitor opens by scanning it. The scanned CERTIFICATES are the
        // private half and go through DocumentUploader into storage/ instead.
        if (!in_array($subfolder, ['destinations', 'banners', 'qr', 'guides'], true)) {
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

    /**
     * Copies a stored file and returns the new relative path, or '' on failure.
     *
     * For duplicating a record that owns a picture. Copying the FILE rather than
     * letting two rows share one path is the point: shared paths mean deleting
     * either record deletes the other's image, which shows up weeks later as a
     * page with a hole in it and nothing in the log to explain it.
     *
     * Same containment rule as delete() — a stored value that does not begin
     * uploads/, or that tries to climb out with .., is refused rather than
     * followed. It also refuses to read anything that is not a real file, so a
     * path pointing at a directory or a dangling entry returns '' instead of
     * creating an empty one.
     */
    public static function copy(?string $relativePath): string
    {
        if ($relativePath === null || $relativePath === '') {
            return '';
        }

        if (!str_starts_with($relativePath, 'uploads/') || str_contains($relativePath, '..')) {
            return '';
        }

        $root     = dirname(APP_PATH) . DIRECTORY_SEPARATOR;
        $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_file($absolute)) {
            return '';
        }

        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? '.' . strtolower($extension) : '';

        /* The same random naming store() uses. A copy called "sunset (1).jpg"
           would be the only file in uploads/ whose name came from anywhere but
           bin2hex(random_bytes()). */
        $target = dirname($relativePath) . '/' . bin2hex(random_bytes(16)) . $extension;

        if (!@copy($absolute, $root . str_replace('/', DIRECTORY_SEPARATOR, $target))) {
            return '';
        }

        return $target;
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
