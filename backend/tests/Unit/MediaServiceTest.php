<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Storage behaviour for admin-uploaded brand assets.
 */
final class MediaServiceTest extends TestCase
{
    private MediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->media = new MediaService;
    }

    #[Test]
    public function it_stores_a_file_under_the_configured_directory(): void
    {
        $path = $this->media->store(UploadedFile::fake()->image('logo.png'));

        $this->assertStringStartsWith('branding/', $path);
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function it_regenerates_the_filename_rather_than_trusting_the_upload(): void
    {
        // The client controls the original name. Preserving it verbatim invites
        // collisions between two admins uploading "logo.png", and traversal
        // attempts through names like "../../config".
        $path = $this->media->store(UploadedFile::fake()->image('../../evil.png'));

        $this->assertStringStartsWith('branding/', $path);
        $this->assertStringNotContainsString('..', $path);
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function two_uploads_of_the_same_name_do_not_collide(): void
    {
        $first = $this->media->store(UploadedFile::fake()->image('logo.png'));
        $second = $this->media->store(UploadedFile::fake()->image('logo.png'));

        $this->assertNotSame($first, $second);

        Storage::disk('public')->assertExists($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function it_keeps_the_original_name_as_a_readable_slug(): void
    {
        $path = $this->media->store(UploadedFile::fake()->image('Company Logo.png'));

        // Recognisable when browsing the bucket, without being trusted.
        $this->assertStringContainsString('company-logo', $path);
    }

    #[Test]
    public function replacing_deletes_the_previous_file(): void
    {
        $first = $this->media->store(UploadedFile::fake()->image('first.png'));

        $second = $this->media->replace(UploadedFile::fake()->image('second.png'), $first);

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function replacing_with_no_previous_file_is_safe(): void
    {
        $path = $this->media->replace(UploadedFile::fake()->image('logo.png'), null);

        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function deleting_a_missing_file_does_not_throw(): void
    {
        // An admin clearing a logo must not see an error because the object was
        // already removed from the bucket by hand. Laravel's delete() is
        // idempotent and reports success for an absent file; what matters here
        // is that nothing is raised.
        $this->media->delete('branding/never-existed.png');

        // An empty path is not a delete request at all, so it short-circuits.
        $this->assertFalse($this->media->delete(null));
        $this->assertFalse($this->media->delete(''));
    }

    #[Test]
    public function it_refuses_to_delete_an_externally_hosted_asset(): void
    {
        // A CDN URL pasted into the field is not ours to delete.
        $this->assertFalse($this->media->delete('https://cdn.example.com/logo.png'));
    }

    #[Test]
    public function it_expands_a_stored_path_into_a_url(): void
    {
        $path = $this->media->store(UploadedFile::fake()->image('logo.png'));

        $url = (string) $this->media->url($path);

        // The exact prefix is the disk's business — `public` yields
        // APP_URL/storage/…, S3 yields the bucket or CDN host. What must hold
        // is that the caller gets a URL containing the stored path, never the
        // bare path itself.
        $this->assertNotSame($path, $url);
        $this->assertStringContainsString($path, $url);
        $this->assertStringContainsString('/storage/', $url);
    }

    #[Test]
    public function an_externally_hosted_url_passes_through_untouched(): void
    {
        // A CDN URL pasted into the field must not be re-prefixed with the
        // local disk's base path.
        $external = 'https://cdn.example.com/logo.png';

        $this->assertSame($external, $this->media->url($external));
        $this->assertNull($this->media->url(null));
        $this->assertNull($this->media->url(''));
    }

    #[Test]
    public function an_unknown_upload_directory_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Prevents a caller writing outside the known tree.
        $this->media->store(UploadedFile::fake()->image('logo.png'), 'etc-passwd');
    }

    #[Test]
    public function image_rules_reflect_the_configured_constraints(): void
    {
        $rules = MediaService::imageRules();

        $this->assertContains('nullable', $rules);
        $this->assertContains('file', $rules);
        $this->assertContains('required', MediaService::imageRules(required: true));

        // SVG must be admitted — logos are commonly SVG. Laravel's `image`
        // rule rejects it, so the mime allowlist is the constraint instead.
        $mimes = collect($rules)->first(fn (string $rule): bool => str_starts_with($rule, 'mimes:'));
        $this->assertStringContainsString('svg', (string) $mimes);
        $this->assertNotContains('image', $rules);
    }
}
