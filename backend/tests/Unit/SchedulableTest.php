<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SectionType;
use App\Models\HomepageSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduling window shared by banners, sections, and CMS pages.
 *
 * The two behaviours worth pinning down are the ones a naive implementation
 * gets wrong: a null end must mean "open-ended" rather than "expired", and the
 * window must be applied in SQL so counts and existence checks agree with what
 * is actually visible.
 */
final class SchedulableTest extends TestCase
{
    use RefreshDatabase;

    private function section(?Carbon $startsAt, ?Carbon $endsAt): HomepageSection
    {
        return HomepageSection::factory()
            ->ofType(SectionType::CustomContent)
            ->create(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    #[Test]
    public function a_row_with_no_bounds_is_always_within_its_window(): void
    {
        $this->assertTrue($this->section(null, null)->isWithinWindow());
    }

    #[Test]
    public function a_row_with_no_end_date_is_open_ended_rather_than_expired(): void
    {
        // The obvious `where('ends_at', '>', now())` silently hides every row
        // that was never given an end date — which is most of them.
        $section = $this->section(Carbon::now()->subDay(), null);

        $this->assertTrue($section->isWithinWindow());
        $this->assertSame('live', $section->windowState());

        $this->assertTrue(
            HomepageSection::query()->withinWindow()->whereKey($section->getKey())->exists(),
        );
    }

    #[Test]
    public function a_row_whose_start_has_not_arrived_is_scheduled(): void
    {
        $section = $this->section(Carbon::now()->addDay(), null);

        $this->assertFalse($section->isWithinWindow());
        $this->assertTrue($section->isScheduled());
        $this->assertSame('scheduled', $section->windowState());

        $this->assertFalse(
            HomepageSection::query()->withinWindow()->whereKey($section->getKey())->exists(),
        );
    }

    #[Test]
    public function a_row_whose_end_has_passed_is_expired(): void
    {
        $section = $this->section(Carbon::now()->subWeek(), Carbon::now()->subDay());

        $this->assertFalse($section->isWithinWindow());
        $this->assertTrue($section->isExpired());
        $this->assertSame('expired', $section->windowState());
    }

    #[Test]
    public function the_window_is_evaluated_in_sql_not_in_php(): void
    {
        $this->section(null, null);
        $this->section(Carbon::now()->addDay(), null);
        $this->section(Carbon::now()->subWeek(), Carbon::now()->subDay());

        // Filtering after loading would make this count report three, and every
        // paginated listing would lie about its totals in the same way.
        $this->assertSame(1, HomepageSection::query()->withinWindow()->count());
        $this->assertSame(1, HomepageSection::query()->scheduled()->count());
        $this->assertSame(1, HomepageSection::query()->expired()->count());
    }

    #[Test]
    public function the_boundary_is_inclusive_at_the_start_and_exclusive_at_the_end(): void
    {
        $now = Carbon::create(2026, 6, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        // Starting exactly now is live; ending exactly now is over. Without
        // this asymmetry a section would be visible for one extra tick past its
        // stated end, or invisible for one tick after its stated start.
        $this->assertTrue($this->section($now, null)->isWithinWindow());
        $this->assertFalse($this->section(null, $now)->isWithinWindow());

        Carbon::setTestNow();
    }

    #[Test]
    public function it_reports_the_next_moment_visibility_changes(): void
    {
        $endsAt = Carbon::now()->addHours(2);

        // Used to cap a cache TTL: caching a flash sale for ten minutes when it
        // ends in two would leave it advertised after it closed.
        $this->assertTrue(
            $endsAt->equalTo($this->section(Carbon::now()->subDay(), $endsAt)->nextTransitionAt()),
        );

        $this->assertNull($this->section(null, null)->nextTransitionAt());
    }
}
