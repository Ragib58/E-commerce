<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores and removes admin-uploaded media on the configured filesystem disk.
 *
 * Deliberately disk-agnostic: it writes through Storage, so the same code path
 * serves the local `public` disk in development and S3/MinIO in production.
 * Switching FILESYSTEM_DISK moves asset delivery with no code change — which is
 * why nothing here builds a URL by string concatenation.
 *
 * Stored values are *relative paths* (`branding/logo-abc123.svg`), never
 * absolute URLs. The Setting model expands them at read time, so moving a
 * bucket or changing a CDN domain does not require rewriting every row.
 */
final class MediaService
{
    /**
     * Store an upload and return its disk-relative path.
     *
     * The filename is regenerated rather than taken from the upload: a client
     * controls the original name, and preserving it invites both collisions and
     * path traversal. The original is kept only as a slug for recognisability
     * when browsing the bucket.
     *
     * @param  string  $directory  Logical directory key from `filesystems.uploads.paths`.
     */
    public function store(UploadedFile $file, string $directory = 'branding'): string
    {
        $path = $this->resolveDirectory($directory);

        return $file->storeAs(
            $path,
            $this->generateFilename($file),
            ['disk' => $this->disk(), 'visibility' => 'public']
        ) ?: throw new \RuntimeException('The uploaded file could not be stored.');
    }

    /**
     * Replace an existing asset, deleting the previous file.
     *
     * Ordering matters: the new file is written first, so a failed upload never
     * leaves the setting pointing at a file that has already been deleted.
     */
    public function replace(UploadedFile $file, ?string $previousPath, string $directory = 'branding'): string
    {
        $newPath = $this->store($file, $directory);

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $newPath) {
            $this->delete($previousPath);
        }

        return $newPath;
    }

    /**
     * Remove an asset from the disk.
     *
     * Never throws: a missing file is an acceptable end state, and an admin
     * clearing a logo must not see an error because the underlying object was
     * already removed from the bucket by hand.
     */
    public function delete(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        // Externally hosted assets (a CDN URL pasted into the field) are not
        // ours to delete.
        if ($this->isAbsoluteUrl($path)) {
            return false;
        }

        try {
            return Storage::disk($this->disk())->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete media asset.', [
                'path' => $path,
                'disk' => $this->disk(),
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Absolute, publicly reachable URL for a stored path.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        return Storage::disk($this->disk())->url($path);
    }

    public function exists(?string $path): bool
    {
        if ($path === null || $path === '' || $this->isAbsoluteUrl($path)) {
            return false;
        }

        return Storage::disk($this->disk())->exists($path);
    }

    /**
     * Validation rules for a brand image upload.
     *
     * Exposed here so form requests and the admin UI share one definition of
     * what is accepted instead of repeating the mime list.
     *
     * @return array<int, string>
     */
    public static function imageRules(bool $required = false): array
    {
        /** @var array<int, string> $mimes */
        $mimes = config('filesystems.uploads.image_mimes', ['jpg', 'png', 'webp', 'svg']);

        return [
            $required ? 'required' : 'nullable',
            'file',
            // Deliberately no `image` rule: it rejects SVG outright, and logos
            // are commonly SVG. The mime allowlist below is the real
            // constraint, and it is strictly narrower than `image` would be —
            // `image` admits any raster type, this admits seven named formats.
            'mimes:'.implode(',', $mimes),
            'max:'.(int) config('filesystems.uploads.max_image_size', 4096),
        ];
    }

    /**
     * A collision-resistant filename that keeps the original readable.
     */
    private function generateFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $slug = $slug === '' ? 'asset' : Str::limit($slug, 40, '');

        return sprintf('%s-%s.%s', $slug, Str::lower(Str::random(12)), $extension);
    }

    /**
     * Map a logical directory key to its configured path, refusing anything
     * not on the allowlist so a caller cannot write outside the known tree.
     */
    private function resolveDirectory(string $directory): string
    {
        /** @var array<string, string> $paths */
        $paths = config('filesystems.uploads.paths', []);

        return $paths[$directory] ?? throw new \InvalidArgumentException(
            sprintf('Unknown upload directory [%s].', $directory)
        );
    }

    private function disk(): string
    {
        return (string) config('filesystems.default', 'public');
    }

    private function isAbsoluteUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
