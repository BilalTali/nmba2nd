<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImageOptimizationService
{
    protected ImageManager $manager;

    /**
     * Maximum dimension (px) for any single axis — prevents oversized canvas storage.
     * Reduced to 800px to dramatically save disk space and inodes on Hostinger.
     */
    protected const MAX_WIDTH = 800;
    protected const MAX_HEIGHT = 800;

    /**
     * Portal hard size ceiling per file: 5 MB.
     */
    protected const PORTAL_SIZE_LIMIT_BYTES = 5 * 1024 * 1024;

    /**
     * Compression quality starting baseline (%).
     * Reduced to 75% for massive storage space savings without noticeable visual loss.
     */
    protected const QUALITY_START = 75;

    /**
     * Hard quality floor before aborting compression (%).
     * If size still exceeds limit below this threshold, throw an exception.
     */
    protected const QUALITY_FLOOR = 30;

    /**
     * Step size per compression iteration (%).
     */
    protected const QUALITY_STEP = 5;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Normalize, orient, downscale, and progressively compress up to 3 uploaded images.
     * Processing occurs entirely OUTSIDE database transactions to keep DB locks brief.
     *
     * @param array<UploadedFile> $files Array of 1–3 valid UploadedFile instances.
     * @return array<string> Array of stored relative paths on the public disk.
     *
     * @throws InvalidArgumentException If an uploaded file is invalid or corrupt.
     * @throws RuntimeException         If a file cannot be compressed below the 5 MB limit.
     */
    public function optimizeBatch(array $files): array
    {
        $storedPaths = [];

        foreach ($files as $index => $file) {
            if (!($file instanceof UploadedFile) || !$file->isValid()) {
                throw new InvalidArgumentException(
                    "File at index {$index} is corrupt, incomplete, or not a valid upload token."
                );
            }

            // Guard against malformed binary contents, partial transfers, or corrupt EXIF structures.
            try {
                $image = $this->manager->read($file->getRealPath());
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Failed to decode image stream at index {$index}. File contains a corrupt binary structure or un-parsable EXIF matrix: " . $e->getMessage(),
                    0,
                    $e
                );
            }

            // Structural integrity check — zero dimensions indicate a corrupt or empty image binary.
            if (!$image->width() || !$image->height()) {
                throw new RuntimeException(
                    "Invalid image geometry at index {$index}. File stream yields a zero width or height dimension matrix."
                );
            }

            // Downscale to maximum allowed canvas dimensions while preserving aspect ratio.
            if ($image->width() > self::MAX_WIDTH || $image->height() > self::MAX_HEIGHT) {
                $image->scaleDown(self::MAX_WIDTH, self::MAX_HEIGHT);
            }

            // Detect MIME type for format-aware compression routing.
            $mediaType  = $image->origin()->mediaType();

            // Build a unique temp filename; extension is always .tmp since we write raw bytes.
            $tempName   = uniqid('nmba_', true) . '.tmp';
            $tempPath   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName;

            $quality        = self::QUALITY_START;
            $compressedSize = 0;

            // Format-aware progressive compression loop.
            // GIFs skip lossy compression entirely to preserve animation frame sequences.
            do {
                $encodedImage = match ($mediaType) {
                    'image/png' => $image->encodeByMediaType('image/png', $quality),
                    'image/gif' => $image->encodeByMediaType('image/gif'),   // Animation-safe: no quality param
                    default     => $image->encodeByMediaType('image/jpeg', $quality),
                };

                file_put_contents($tempPath, (string) $encodedImage);
                $compressedSize = filesize($tempPath);

                // GIFs: break immediately — lossy stepping would destroy animation frames.
                if ($mediaType === 'image/gif') {
                    break;
                }

                $quality -= self::QUALITY_STEP;

                // Hard floor guard: if quality drops below floor and file is still too large,
                // abort immediately to prevent infinite compression loops in daemon threads.
                if ($quality < self::QUALITY_FLOOR && $compressedSize > self::PORTAL_SIZE_LIMIT_BYTES) {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    throw new RuntimeException(
                        "File at index {$index} could not be compressed below the portal 5 MB threshold " .
                        "even at quality floor " . self::QUALITY_FLOOR . "%. " .
                        "Current size: " . round($compressedSize / 1024 / 1024, 2) . " MB."
                    );
                }

            } while ($compressedSize > self::PORTAL_SIZE_LIMIT_BYTES);

            // Final size verification after loop exit (safety net for edge cases).
            if ($compressedSize > self::PORTAL_SIZE_LIMIT_BYTES) {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                throw new RuntimeException(
                    "File at index {$index} final optimization cycle failed to secure sub-5 MB boundaries."
                );
            }

            // Determine a safe permanent filename with the correct extension.
            $extension = match ($mediaType) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                default     => 'jpg',
            };
            $permanentFilename = uniqid('nmba_', true) . '.' . $extension;
            $permanentPath     = 'events/' . $permanentFilename;

            // Persist compressed binary to the public storage disk.
            Storage::disk('public')->put($permanentPath, file_get_contents($tempPath));

            // Remove the temp file immediately after successful storage write.
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            $storedPaths[] = $permanentPath;

            // Explicit memory footprint containment: release image objects and run GC
            // to prevent slow memory leaks in long-running CLI background daemon processes.
            unset($image, $encodedImage);
            gc_collect_cycles();
        }

        return $storedPaths;
    }
}
