<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Batch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class BatchTest extends AuthTestCase
{
    /** A rider can save a batch and read it after its original week has ended. */
    public function test_batch_creation_preserves_original_dates_and_controlled_fields(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15));
        $token = $this->token($this->account());
        $response = $this->api('POST', '/api/v1/batches', [
            'name' => '  November 2–8  ', 'start' => '2026-11-02', 'end' => '2026-11-08',
        ], $token)->assertCreated()->assertJsonPath('data.name', 'November 2–8')
            ->assertJsonPath('data.start', '2026-11-02')->assertJsonPath('data.end', '2026-11-08')
            ->assertJsonPath('data.period', 'previous')->assertJsonMissingPath('data.user_id');
        $this->assertSame(['id', 'name', 'start', 'end', 'period', 'created_at'], array_keys($response->json('data')));
        $this->api('GET', '/api/v1/batches/'.$response->json('data.id'), token: $token)
            ->assertOk()->assertJsonPath('data.start', '2026-11-02')->assertJsonPath('data.period', 'previous');
    }

    /** Search and pagination remain inside the owner scope and preserve query links. */
    #[DataProvider('entrySizes')]
    public function test_owned_search_and_pagination(int $size): void
    {
        $rider = $this->account();
        $ids = [];
        for ($index = 0; $index < 12; $index++) {
            $start = CarbonImmutable::parse('2026-01-05')->addWeeks($index);
            $ids[] = Batch::factory()->for($rider)->create([
                'name' => 'Route '.$index, 'start_date' => $start->toDateString(), 'end_date' => $start->addDays(6)->toDateString(),
            ])->id;
        }
        Batch::factory()->for($rider)->create(['name' => 'Different batch']);
        $foreign = Batch::factory()->create(['name' => 'Route private']);
        $token = $this->token($rider);
        $response = $this->api('GET', '/api/v1/batches?q=Route&per_page='.$size, token: $token)
            ->assertOk()->assertJsonPath('meta.total', 12)->assertJsonPath('meta.per_page', $size)
            ->assertJsonCount(min(12, $size), 'data')->assertJsonPath('data.0.id', $ids[11])
            ->assertJsonMissingPath('data.0.user_id')->assertJsonStructure(['data', 'links', 'meta', 'current_week']);
        $this->assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
        $this->assertStringContainsString('q=Route', $response->json('links.first'));
        $this->assertStringContainsString('per_page='.$size, $response->json('links.first'));
        $this->api('GET', '/api/v1/batches?q=Route&page=2&per_page=10', token: $token)
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data');
        $this->api('GET', '/api/v1/batches?q=NoMatch', token: $token)
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    /** The shared chooser uses these entry sizes without changing server totals. */
    public static function entrySizes(): array
    {
        return [[10], [50], [100]];
    }

    /** A rider cannot read another rider's historical batch. */
    public function test_foreign_and_missing_batches_are_hidden(): void
    {
        $foreign = Batch::factory()->create();
        $token = $this->token($this->account());
        $this->api('GET', '/api/v1/batches/'.$foreign->id, token: $token)->assertNotFound();
        $this->api('GET', '/api/v1/batches/999999', token: $token)->assertNotFound();
        $this->api('GET', '/api/v1/batches/not-a-number', token: $token)->assertNotFound();
    }

    /** Exact date ranges are unique per rider while another rider may use the same week. */
    public function test_duplicate_ranges_return_controlled_validation_without_cross_rider_collisions(): void
    {
        $rider = $this->account();
        Batch::factory()->for($rider)->create();
        $values = ['name' => 'Another name', 'start' => '2026-11-02', 'end' => '2026-11-08'];
        $this->api('POST', '/api/v1/batches', $values, $this->token($rider))
            ->assertUnprocessable()->assertJsonValidationErrors('start');
        $this->api('POST', '/api/v1/batches', $values, $this->token($this->account()))->assertCreated();
        $this->assertDatabaseCount('batches', 2);
    }

    /** The database also rejects duplicates when application validation is bypassed. */
    public function test_database_enforces_the_owned_date_range(): void
    {
        $rider = $this->account();
        Batch::factory()->for($rider)->create();
        $this->expectException(UniqueConstraintViolationException::class);
        Batch::factory()->for($rider)->create();
    }

    /** Dates must be real calendar inputs; ownership and fabricated progress are not client inputs. */
    #[DataProvider('invalidBatches')]
    public function test_batch_validation(array $changes, string $field): void
    {
        $this->api('POST', '/api/v1/batches', array_replace([
            'name' => 'New batch', 'start' => '2026-11-02', 'end' => '2026-11-08',
        ], $changes), $this->token($this->account()))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('batches', 0);
    }

    /** Covers malformed dates, impossible calendars and attempted identity/progress overrides. */
    public static function invalidBatches(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name'],
            'long name' => [['name' => str_repeat('a', 61)], 'name'],
            'name type' => [['name' => []], 'name'],
            'date type' => [['start' => []], 'start'],
            'short date' => [['start' => '2026-11-2'], 'start'],
            'impossible date' => [['start' => '2026-02-30'], 'start'],
            'timestamp' => [['end' => '2026-11-08T00:00:00Z'], 'end'],
            'reversed range' => [['end' => '2026-11-01'], 'end'],
            'foreign owner' => [['user_id' => 999], 'user_id'],
            'record identity' => [['id' => 999], 'id'],
            'fake completion' => [['status' => 'completed'], 'status'],
        ];
    }

    /** Query validation and a ten-entry default match the existing shared list contract. */
    public function test_query_validation_and_default_size(): void
    {
        $token = $this->token($this->account());
        $this->api('GET', '/api/v1/batches', token: $token)->assertOk()->assertJsonPath('meta.per_page', 10);
        foreach (['per_page=0' => 'per_page', 'per_page=101' => 'per_page', 'page=0' => 'page', 'q[]=invalid' => 'q'] as $query => $field) {
            $this->api('GET', '/api/v1/batches?'.$query, token: $token)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    /** The local calendar determines the week even when UTC is still Sunday or the previous year. */
    #[DataProvider('calendarBoundaries')]
    public function test_manila_week_defaults(string $utc, string $start, string $end): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->api('GET', '/api/v1/batches', token: $this->token($this->account()))->assertOk()
            ->assertJsonPath('current_week.start', $start)->assertJsonPath('current_week.end', $end);
    }

    /** Sunday/Monday and year transitions cannot move an intake period to the wrong week. */
    public static function calendarBoundaries(): array
    {
        return [
            ['2026-11-08 15:30:00', '2026-11-02', '2026-11-08'],
            ['2026-11-08 16:30:00', '2026-11-09', '2026-11-15'],
            ['2026-12-31 16:30:00', '2026-12-28', '2027-01-03'],
            ['2027-01-03 16:30:00', '2027-01-04', '2027-01-10'],
        ];
    }

    /** The calendar badge is independent of delivery completion and does not hide older batches. */
    public function test_period_badges_cover_previous_current_and_upcoming(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08 16:30:00', 'UTC'));
        $rider = $this->account();
        foreach (['previous' => '2026-11-02', 'current' => '2026-11-09', 'upcoming' => '2026-11-16'] as $name => $date) {
            Batch::factory()->for($rider)->create([
                'name' => $name, 'start_date' => $date, 'end_date' => CarbonImmutable::parse($date)->addDays(6)->toDateString(),
            ]);
        }
        $response = $this->api('GET', '/api/v1/batches', token: $this->token($rider))->assertOk()->assertJsonCount(3, 'data');
        foreach ($response->json('data') as $batch) {
            $this->assertSame($batch['name'], $batch['period']);
        }
    }

    /** Authentication, database role and token ability protect all batch endpoints. */
    public function test_batches_require_rider_access(): void
    {
        $this->api('GET', '/api/v1/batches')->assertUnauthorized();
        $this->api('POST', '/api/v1/batches')->assertUnauthorized();
        $this->api('GET', '/api/v1/batches', token: $this->token($this->account(UserRole::Admin)))->assertForbidden();
        $rider = $this->account();
        $restricted = $rider->createToken('Restricted', ['other'], now()->addHour());
        $this->api('GET', '/api/v1/batches', token: $restricted->plainTextToken)->assertForbidden();
        $expired = $rider->createToken('Expired', ['rider:access'], now()->subMinute());
        $this->api('GET', '/api/v1/batches', token: $expired->plainTextToken)->assertUnauthorized();
    }

    /** This stage exposes intake creation/read only, preserving historical dates. */
    public function test_update_and_delete_are_not_exposed(): void
    {
        $rider = $this->account();
        $batch = Batch::factory()->for($rider)->create();
        $token = $this->token($rider);
        foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
            $this->api($method, '/api/v1/batches/'.$batch->id, ['start' => '2026-12-01'], $token)->assertStatus(405);
        }
        $this->assertSame('2026-11-02', $batch->fresh()->start_date->toDateString());
    }
}
