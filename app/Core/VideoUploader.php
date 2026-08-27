<?php
declare(strict_types=1);

namespace App\Core;

/**
 * TourSync — accepting a promotional video.
 *
 * WHY THIS IS NOT Uploader
 *
 * Uploader's safety model is re-encoding: it decodes the image through GD and
 * writes a fresh file, so whatever was hidden in the original does not survive
 * the round trip. There is no equivalent for video without ffmpeg, which is not
 * installed and cannot be assumed on shared cPanel hosting.
 *
 * So the model here is different and the difference is stated rather than
 * glossed over:
 *
 *   1. The extension is chosen by US from the detected MIME type, never taken
 *      from the name the browser sent. "clip.mp4.php" cannot become a .php.
 *   2. The filename is random. A predictable name is a name an attacker can
 *      reference before the office has reviewed the file.
 *   3. The file lands in uploads/, which carries an .htaccess that turns the
 *      PHP engine off and refuses to serve executable extensions at all. That
 *      is the actual guarantee — the directory cannot run code even if every
 *      check above were bypassed.
 *   4. Only three container types are accepted, all of which browsers play
 *      natively. Anything else is refused rather than stored and hoped about.
 *
 * WHAT IS NOT CHECKED, honestly: the contents of the container. A malformed
 * MP4 that crashes a particular decoder would be stored. The mitigation is that
 * it is served as a static file to a sandboxed <video> element, not parsed by
 * anything on the server.
 */
final class VideoUploader
{
    /** Detected MIME => the extension we give the file. */
    private const ALLOWED = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
    ];

    /**
     * Read from php.ini rather than written down, so the message and the
     * refusal cannot disagree with the server they are running on.
     *
     * Checked here as well as by PHP so the office gets a sentence they can act
     * on — PHP's own refusal arrives as an empty $_FILES entry with an error
     * code, which renders as "nothing happened".
     */
    private static function maxBytes(): int
    {
        return upload_limit_bytes();
    }

    /** @var array<int, string> */
    private array $errors = [];

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Stores one uploaded video.
     *
     * @param array<string, mixed> $file one entry from $_FILES
     * @return array{path: string, mime: string, size: int}|null null on refusal
     */
    public function store(array $file, string $subfolder = 'videos'): ?array
    {
        if (!$this->accepts($file)) {
            return null;
        }

        $mime      = (string) $this->detectMime((string) $file['tmp_name']);
        $extension = self::ALLOWED[$mime];

        $directory = dirname(__DIR__, 2) . '/uploads/' . $subfolder;

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->errors[] = 'The upload folder could not be created.';
            return null;
        }

        /* Random, not derived from the submitted name. */
        $name        = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $directory . '/' . $name;

        if (!@move_uploaded_file((string) $file['tmp_name'], $destination)) {
            $this->errors[] = 'The video could not be saved to disk.';
            return null;
        }

        @chmod($destination, 0644);

        return [
            'path' => 'uploads/' . $subfolder . '/' . $name,
            'mime' => $mime,
            'size' => (int) filesize($destination),
        ];
    }

    /** @param array<string, mixed> $file */
    private function accepts(array $file): bool
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($code !== UPLOAD_ERR_OK) {
            /* PHP's own refusals, translated. UPLOAD_ERR_INI_SIZE in particular
               arrives with an empty file and no explanation, which is how a
               person concludes the button is broken. */
            $this->errors[] = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'That file is larger than this server accepts ('
                    . upload_limit_mb() . ' MB). '
                    . 'Trim the clip, export it smaller, or paste a YouTube link instead.',
                UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE   => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR,
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the file. Contact your host.',
                default              => 'The file could not be uploaded.',
            };

            return false;
        }

        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $this->errors[] = 'That was not a valid upload.';
            return false;
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            $this->errors[] = 'That file is empty.';
            return false;
        }

        if ($size > self::maxBytes()) {
            $this->errors[] = 'That file is ' . round($size / 1048576) . ' MB. The limit is '
                . upload_limit_mb() . ' MB — '
                . 'paste a YouTube link instead for anything longer.';
            return false;
        }

        $mime = $this->detectMime((string) $file['tmp_name']);

        if ($mime === null || !isset(self::ALLOWED[$mime])) {
            /* The detected type is named so the office can tell a wrong file
               from an unsupported one — "you picked the .srt" reads very
               differently from "we do not take AVI". */
            $this->errors[] = 'That is not a video this system accepts'
                . ($mime !== null ? ' (it looks like ' . $mime . ')' : '')
                . '. Use MP4, WebM or MOV.';
            return false;
        }

        return true;
    }

    /** Read from the file's own bytes, never from the name or the browser's claim. */
    private function detectMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }
}
