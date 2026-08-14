<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — supporting logbook documents                           Feature 2
 * -----------------------------------------------------------------------------
 *  The photograph or PDF of the paper logbook page.
 *
 *  A sibling of Uploader rather than an extension of it, because the two have
 *  opposite requirements and merging them would weaken both:
 *
 *    Uploader              public destination photos. Re-encodes through GD,
 *                          which strips anything smuggled in the metadata, and
 *                          stores under uploads/ to be served directly.
 *
 *    DocumentUploader      private evidence. Must accept PDF, which GD cannot
 *                          decode, and must NOT be publicly served at all.
 *
 *  WHY NOT uploads/. That directory is world-readable; its only protection is
 *  a filename nobody can guess. For a destination photo that is fine — it is
 *  meant to be seen. A logbook page carries names, home addresses and mobile
 *  numbers of private individuals, and "the URL is long" is obscurity, not
 *  access control. These files go under storage/, which is already
 *  "Require all denied", and reach a browser only through document.php after
 *  it has checked who is asking.
 *
 *  WHAT IS CHECKED
 *
 *    1. The upload genuinely arrived by HTTP POST (is_uploaded_file), not a
 *       server path the request nominated.
 *    2. The type is read from the file's own bytes with finfo. The extension
 *       and the browser-supplied MIME type are both attacker input.
 *    3. Images are decoded and re-encoded through GD, so a JPEG carrying PHP
 *       in a comment block does not survive.
 *    4. PDFs cannot be re-encoded, so they are checked for the %PDF- header
 *       and scanned for the constructs that make a PDF active — /JavaScript,
 *       /OpenAction, /Launch, /EmbeddedFile. A PDF that wants to run something
 *       when opened is not a photograph of a logbook.
 *    5. The stored name is 32 random hex characters plus an extension this
 *       class chose. Nothing from the original filename is used to build a
 *       path — that is how a "logbook.pdf" called ../../index.php gets in.
 * =============================================================================
 */
final class DocumentUploader
{
    /** A phone photograph of a page is ~2-4 MB; a multi-page PDF scan more. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    /** Widest edge kept for a photographed page: legible, not wasteful. */
    private const MAX_EDGE = 2400;

    /**
     * Constructs that make a PDF do something when it is opened. A supporting
     * document is a picture of a page; none of these belong in one.
     */
    private const PDF_ACTIVE = [
        '/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/EmbeddedFile', '/RichMedia',
    ];

    /** @var array<int, string> */
    private array $errors = [];

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors !== [] ? reset($this->errors) : null;
    }

    /**
     * Validates and stores one document.
     *
     * @param  array<string, mixed> $file One entry from $_FILES
     * @return array{stored_name:string, original_name:string, mime_type:string, byte_size:int}|null
     */
    public function store(array $file, string $kind = 'logbooks'): ?array
    {
        $this->errors = [];

        $mime = $this->validate($file);

        if ($mime === null) {
            return null;
        }

        $extension = self::ALLOWED[$mime];
        $stored    = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = self::directory($kind);

        if ($directory === null) {
            $this->errors[] = 'The document folder could not be created.';
            return null;
        }

        $absolute = $directory . DIRECTORY_SEPARATOR . $stored;

        $written = $mime === 'application/pdf'
            ? move_uploaded_file((string) $file['tmp_name'], $absolute)
            : $this->rewriteImage((string) $file['tmp_name'], $mime, $absolute);

        if (!$written) {
            $this->errors[] = 'The document could not be saved. Check folder permissions.';
            return null;
        }

        @chmod($absolute, 0644);

        return [
            'stored_name'   => $stored,
            /* Kept for display only, so the manager recognises which file this
               was. Never used to build a path. */
            'original_name' => mb_substr(self::cleanName((string) ($file['name'] ?? 'document')), 0, 200),
            'mime_type'     => $mime,
            'byte_size'     => (int) filesize($absolute),
        ];
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $file @return string|null the detected MIME */
    private function validate(array $file): ?string
    {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($code !== UPLOAD_ERR_OK) {
            $this->errors[] = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE    => 'That file is larger than the server allows.',
                UPLOAD_ERR_PARTIAL                           => 'The upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE                           => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload.',
                default                                      => 'The upload failed.',
            };

            return null;
        }

        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $this->errors[] = 'Invalid upload.';
            return null;
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            $this->errors[] = 'Documents must be 8 MB or smaller. A photo taken on a phone is usually well under this.';
            return null;
        }

        $mime = self::detectMime((string) $file['tmp_name']);

        if ($mime === null || !isset(self::ALLOWED[$mime])) {
            $this->errors[] = 'Only JPG, PNG and PDF files are accepted.';
            return null;
        }

        if ($mime === 'application/pdf') {
            return $this->validatePdf((string) $file['tmp_name']) ? $mime : null;
        }

        /* getimagesize returns false for a non-image even when the first bytes
           were crafted to look like one. */
        if (@getimagesize((string) $file['tmp_name']) === false) {
            $this->errors[] = 'That file is not a valid image.';
            return null;
        }

        return $mime;
    }

    private function validatePdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->errors[] = 'That file could not be read.';
            return false;
        }

        $head = (string) fread($handle, 1024);

        if (!str_starts_with($head, '%PDF-')) {
            fclose($handle);
            $this->errors[] = 'That file is not a valid PDF.';
            return false;
        }

        /* Read the whole file in chunks looking for active content, with an
           overlap so a marker split across a chunk boundary is still seen. */
        rewind($handle);
        $carry = '';

        while (!feof($handle)) {
            $chunk = $carry . (string) fread($handle, 262144);

            foreach (self::PDF_ACTIVE as $marker) {
                if (stripos($chunk, $marker) !== false) {
                    fclose($handle);
                    $this->errors[] = 'That PDF contains embedded scripts or actions, so it was not accepted. '
                        . 'Please upload a plain scan or photograph of the logbook page.';

                    return false;
                }
            }

            $carry = substr($chunk, -32);
        }

        fclose($handle);

        return true;
    }

    /**
     * Decodes and re-encodes an image.
     *
     * The round trip is the point: anything hidden in a comment segment or an
     * EXIF block does not survive being rebuilt from pixels.
     */
    private function rewriteImage(string $source, string $mime, string $absolute): bool
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png'  => @imagecreatefrompng($source),
            default      => false,
        };

        if ($image === false) {
            $this->errors[] = 'That file could not be read as an image.';
            return false;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $edge   = max($width, $height);

        if ($edge > self::MAX_EDGE) {
            $scale     = self::MAX_EDGE / $edge;
            $newWidth  = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            imagedestroy($image);
            $image = $resized;
        }

        $ok = $mime === 'image/jpeg'
            ? imagejpeg($image, $absolute, 88)
            : imagepng($image, $absolute, 6);

        imagedestroy($image);

        return $ok;
    }

    private static function detectMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    /** Display-only tidy-up. Never produces a path. */
    private static function cleanName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w \-.()]+/u', '', $name) ?? '';

        return trim($name) !== '' ? trim($name) : 'document';
    }

    /**
     * Where a kind of document lives, under storage/ and its deny-all rule.
     *
     * An allowlist, not a free path. The subfolder is always chosen by calling
     * code and never taken from a request, but writing the allowlist down is
     * what keeps that true after the third caller is added by someone who did
     * not read this comment.
     */
    private const FOLDERS = [
        'logbooks'    => 'logbooks',      // Feature 2 — photographed logbook pages
        'inspections' => 'inspections',   // Compliance evidence photos
    ];

    public static function directory(string $kind = 'logbooks'): ?string
    {
        if (!isset(self::FOLDERS[$kind])) {
            return null;
        }

        $directory = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . self::FOLDERS[$kind];

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        return is_writable($directory) ? $directory : null;
    }

    /**
     * Absolute path of a stored document.
     *
     * The name is re-validated against the shape this class generates rather
     * than trusted from the database, so a row tampered with directly still
     * cannot point the reader at a file elsewhere on disk.
     */
    public static function pathFor(string $storedName, string $kind = 'logbooks'): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $storedName) !== 1) {
            return null;
        }

        $directory = self::directory($kind);

        if ($directory === null) {
            return null;
        }

        $absolute = $directory . DIRECTORY_SEPARATOR . $storedName;

        return is_file($absolute) ? $absolute : null;
    }

    public static function delete(?string $storedName, string $kind = 'logbooks'): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        $absolute = self::pathFor($storedName, $kind);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }
}
