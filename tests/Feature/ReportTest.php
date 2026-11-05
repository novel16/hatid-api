<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Batch;
use App\Models\Parcel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class ReportTest extends AuthTestCase
{
    /** Keep current-period assertions independent of the execution host's calendar. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
    }

    /** CSV content negotiation must not bypass rider auth or turn missing Bearer into a redirect. */
    public function test_reports_export_and_dashboard_require_rider_bearer_scope(): void
    {
        $admin = $this->token($this->account(UserRole::Admin));
        $wrong = $this->account()->createToken('Wrong ability', ['admin:access'])->plainTextToken;
        foreach (['/reports/earnings', '/reports/earnings/export', '/dashboard'] as $path) {
            $this->api('GET', '/api/v1'.$path, headers: ['Accept' => 'text/csv'])->assertUnauthorized();
            $this->api('GET', '/api/v1'.$path, token: $admin)->assertForbidden();
            $this->api('GET', '/api/v1'.$path, token: $wrong)->assertForbidden();
        }
    }

    /** Local midnight uses half-open UTC bounds; delivery earnings do not follow intake dates. */
    public function test_report_uses_manila_confirmation_date_and_preserves_intake_batch(): void
    {
        $rider = $this->account();
        $batch = Batch::factory()->for($rider)->create(['start_date' => '2026-11-02', 'end_date' => '2026-11-08']);
        $this->receipt($rider, ['received_at' => '2026-11-09 15:59:59']);
        $first = $this->receipt($rider, ['batch_id' => $batch->id, 'received_at' => '2026-11-09 16:00:00', 'expected_method' => 'gcash']);
        $this->receipt($rider, ['received_at' => '2026-11-10 15:59:59', 'receipt_method' => 'gcash', 'customer_total' => 23000, 'received_amount' => 23000, 'fee' => 2000, 'seller_share' => 21000]);
        $this->receipt($rider, ['received_at' => '2026-11-10 16:00:00']);
        $result = $this->api('GET', $this->report(['period' => 'day', 'date' => '2026-11-10']), token: $this->token($rider))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('report.period.start', '2026-11-10')
            ->assertJsonPath('report.period.end', '2026-11-10')->assertJsonPath('report.period.timezone', 'Asia/Manila')
            ->assertJsonPath('report.summary.receipts', 2)->assertJsonPath('report.summary.cash', 11500)
            ->assertJsonPath('report.summary.gcash', 23000)->assertJsonPath('report.summary.fees', 3500)
            ->assertJsonPath('report.summary.seller_share', 31000)->assertJsonPath('report.summary.total', 34500)
            ->assertJsonPath('report.trend.0.receipts', 2)->assertJsonPath('report.trend.0.fees', 3500)
            ->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.operation_id');
        $saved = collect($result->json('data'))->firstWhere('id', $first->id);
        $this->assertSame('2026-11-02', $saved['batch']['start']);
        $this->assertSame('2026-11-08', $saved['batch']['end']);
    }

    /** Calendar defaults cover Monday/Sunday, leap months and year boundaries without sample dates. */
    #[DataProvider('periods')]
    public function test_calendar_periods(string $period, string $date, string $start, string $end): void
    {
        $response = $this->api('GET', $this->report(['period' => $period, 'date' => $date]), token: $this->token($this->account()))
            ->assertOk()->assertJsonPath('report.period.start', $start)->assertJsonPath('report.period.end', $end)
            ->assertJsonPath('report.summary.receipts', 0)->assertJsonPath('report.summary.fees', 0);
        $this->assertLessThanOrEqual(60, count($response->json('report.trend')));
    }

    /** Literal calendar dates make timezone and leap-year mistakes observable. */
    public static function periods(): array
    {
        return [
            ['day', '2026-11-10', '2026-11-10', '2026-11-10'],
            ['week', '2026-11-10', '2026-11-09', '2026-11-15'],
            ['week', '2027-01-01', '2026-12-28', '2027-01-03'],
            ['month', '2028-02-29', '2028-02-01', '2028-02-29'],
        ];
    }

    /** Expected methods, foreign text matches and incomplete/non-success receipts cannot inflate reports. */
    public function test_report_search_actual_method_and_ownership_share_the_collection_rules(): void
    {
        $rider = $this->account();
        $own = $this->receipt($rider, ['expected_method' => 'gcash']);
        $own->customer->update(['name' => 'Matching Customer']);
        $foreign = $this->receipt($this->account());
        $foreign->customer->update(['name' => 'Matching Customer']);
        foreach ([['status' => 'failed'], ['status' => 'returned'], ['received_at' => null], ['received_amount' => 100]] as $changes) {
            $this->receipt($rider, $changes)->customer->update(['name' => 'Matching Customer']);
        }
        $token = $this->token($rider);
        $this->api('GET', $this->report(['q' => 'Matching Customer', 'method' => 'cash']), token: $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('report.summary.fees', 1500);
        $this->api('GET', $this->report(['q' => 'Matching Customer', 'method' => 'gcash']), token: $token)
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('report.summary.total', 0);
    }

    /** Pagination changes visible rows only; the complete filtered chart/totals stay unchanged. */
    public function test_page_sizes_do_not_redefine_report_totals_or_trends(): void
    {
        $rider = $this->account();
        for ($index = 0; $index < 12; $index++) {
            $this->receipt($rider);
        }
        $token = $this->token($rider);
        foreach ([10, 50, 100] as $size) {
            $this->api('GET', $this->report(['per_page' => $size]), token: $token)->assertOk()
                ->assertJsonPath('meta.per_page', $size)->assertJsonPath('meta.total', 12)
                ->assertJsonPath('report.summary.receipts', 12)->assertJsonPath('report.summary.fees', 18000);
        }
        $this->api('GET', $this->report(['page' => 2]), token: $token)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('report.summary.cash', 138000)->assertJsonPath('report.trend.9.receipts', 12);
        $long = $this->api('GET', $this->report(['start' => '2020-01-01', 'end' => '2026-12-31']), token: $token)->assertOk();
        $this->assertLessThanOrEqual(60, count($long->json('report.trend')));
        $this->assertSame(18000, array_sum(array_column($long->json('report.trend'), 'fees')));
    }

    /** Export ignores paging and preserves exact cents while making spreadsheet formulas literal text. */
    public function test_csv_exports_all_filtered_rows_with_safe_text_and_exact_cents(): void
    {
        $rider = $this->account();
        for ($index = 0; $index < 201; $index++) {
            $parcel = $this->receipt($rider, ['customer_total' => 11501, 'received_amount' => 11501, 'fee' => 1501, 'seller_share' => 10000, 'receipt_method' => 'gcash']);
            $parcel->customer->update(['name' => '=SUM(A1:A2)']);
            $parcel->seller->update(['name' => " \t@External"]);
        }
        $this->receipt($rider, ['receipt_method' => 'cash']);
        $this->receipt($this->account(), ['receipt_method' => 'gcash']);
        $uri = str_replace('/earnings?', '/earnings/export?', $this->report(['method' => 'gcash', 'page' => 2, 'per_page' => 1]));
        $response = $this->api('GET', $uri, token: $this->token($rider), headers: ['Accept' => 'text/csv'])->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        $this->assertCount(202, $rows);
        $this->assertSame("'=SUM(A1:A2)", $rows[1][1]);
        $this->assertSame("' \t@External", $rows[1][2]);
        $this->assertSame('115.01', $rows[1][6]);
        $this->assertSame('15.01', $rows[1][7]);
        $this->assertSame('100.00', $rows[1][8]);
        $this->assertStringNotContainsString('submission_hash', $content);
    }

    /** Today's flows and lifetime seller debt remain different scopes, with ready carryover work visible. */
    public function test_dashboard_reuses_today_reports_and_lifetime_seller_balance(): void
    {
        $rider = $this->account();
        $today = $this->receipt($rider);
        $this->receipt($rider, ['seller_id' => $today->seller_id, 'received_at' => '2026-11-01 01:00:00', 'customer_total' => 23000, 'received_amount' => 23000, 'fee' => 2000, 'seller_share' => 21000]);
        Parcel::factory()->count(2)->for($rider)->create();
        Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()]);
        Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()->addDay()]);
        Parcel::factory()->for($rider)->create(['status' => 'failed']);
        Parcel::factory()->for($this->account())->create();
        $token = $this->token($rider);
        $this->api('POST', '/api/v1/remittances', ['operation_id' => (string) Str::uuid(), 'seller_id' => $today->seller_id, 'amount' => 6000, 'method' => 'cash', 'handover_confirmed' => true], $token)->assertCreated();
        $this->api('GET', '/api/v1/dashboard', token: $token)->assertOk()->assertJsonPath('data.period.start', '2026-11-10')
            ->assertJsonPath('data.summary.receipts', 1)->assertJsonPath('data.summary.fees', 1500)
            ->assertJsonPath('data.summary.cash', 11500)->assertJsonPath('data.work.ready', 3)
            ->assertJsonPath('data.work.open', 5)->assertJsonPath('data.work.held', 2)->assertJsonPath('data.work.failed', 1)
            ->assertJsonPath('data.seller_outstanding', 25000)->assertJsonMissingPath('data.user_id');
        $this->api('GET', $this->report(['period' => 'day', 'date' => '2026-11-10']), token: $token)->assertOk()
            ->assertJsonPath('report.summary.fees', 1500)->assertJsonPath('report.summary.total', 11500);
    }

    /** Invalid ranges and forged ownership fail before any report query is allowed. */
    #[DataProvider('invalidFilters')]
    public function test_report_validation(array $changes, string $field): void
    {
        $this->api('GET', $this->report($changes), token: $this->token($this->account()))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    /** Validate real dates and calendar ranges instead of accepting guessed or reversed values. */
    public static function invalidFilters(): array
    {
        return [
            [['period' => 'year'], 'period'], [['period' => 'day', 'date' => '2026-02-29'], 'date'],
            [['period' => 'custom', 'start' => '2026-11-20', 'end' => '2026-11-10'], 'end'],
            [['start' => '11/01/2026'], 'start'], [['start' => null], 'start'],
            [['per_page' => 101], 'per_page'], [['page' => 0], 'page'], [['method' => 'prepaid'], 'method'],
            [['user_id' => 123], 'user_id'], [['timezone' => 'UTC'], 'timezone'],
        ];
    }

    /** Builds a deterministic private confirmed receipt only in the isolated test database. */
    private function receipt(User $rider, array $changes = []): Parcel
    {
        return Parcel::factory()->for($rider)->create(array_replace([
            'status' => 'delivered', 'received_at' => now(), 'received_amount' => 11500, 'receipt_method' => 'cash',
        ], $changes));
    }

    /** The URL helper uses actual query parameters so export/pagination share the same filter contract. */
    private function report(array $values = []): string
    {
        $defaults = ['period' => 'custom', 'start' => '2026-11-01', 'end' => '2026-11-30'];
        if (isset($values['period']) && $values['period'] !== 'custom') {
            $defaults = [];
        }

        return '/api/v1/reports/earnings?'.http_build_query(array_replace($defaults, $values));
    }
}
