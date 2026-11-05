<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class GeoapifyService
{
    private const LOCK_BUCKET = '1970-01-01 00:00:00';

    /** Expose app-accounted shared usage; this is not a live provider wallet/billing balance. */
    public function usage(): array
    {
        return ['used' => (int) DB::table('map_usage')->where('bucket', '>=', $this->cutoff())->sum('units') / 4,
            'limit' => min(2500, max(0, (int) config('maps.credit_limit'))), 'window' => 'rolling_24h'];
    }

    /** Keep browser callers from receiving or choosing the provider credential. */
    public function configured(): bool
    {
        return is_string(config('maps.api_key')) && trim(config('maps.api_key')) !== '';
    }

    /** Cache only normalized geometry; every caller rebuilds its own current owned stops. */
    public function matrix(array $payload): array
    {
        abort_unless($this->configured(), 503, 'Maps are not configured. Manual delivery remains available.');
        $key = 'matrix:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $cache = Cache::store('maps');
        if (($cached = $cache->get($key)) !== null) {
            return $cached;
        }
        $count = count($payload['targets']);
        // ponytail: reserve 500 extra credits for long PH road detours; release only a known successful remainder.
        $reserved = ($count + 500) * 4;
        $bucket = $this->reserve($reserved);
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withQueryParameters(['apiKey' => config('maps.api_key')])
                ->post('https://api.geoapify.com/v1/routematrix', $payload);
        } catch (ConnectionException) {
            abort(503, 'Road estimates are temporarily unavailable. Choose a stop manually.');
        }
        abort_unless($response->successful(), 503, 'Road estimates are temporarily unavailable. Choose a stop manually.');
        $rows = $response->json('sources_to_targets');
        abort_unless(is_array($rows) && count($rows) === 1 && is_array($rows[0]) && count($rows[0]) === $count, 503, 'Road estimates could not be read. Choose a stop manually.');
        $cells = [];
        $cost = $count;
        foreach ($rows[0] as $index => $cell) {
            abort_unless(is_array($cell) && ($cell['source_index'] ?? null) === 0 && ($cell['target_index'] ?? null) === $index
                && array_key_exists('distance', $cell) && array_key_exists('time', $cell), 503, 'Road estimates could not be read. Choose a stop manually.');
            $distance = $cell['distance'];
            $seconds = $cell['time'];
            if ($distance === null || $seconds === null) {
                $cells[] = ['distance' => null, 'time' => null];

                continue;
            }
            abort_unless(is_numeric($distance) && is_numeric($seconds) && is_finite((float) $distance) && is_finite((float) $seconds)
                && $distance >= 0 && $seconds >= 0 && $distance <= 50_000_000, 503, 'Road estimates could not be read. Choose a stop manually.');
            $cost += (int) floor($distance / 500000);
            $cells[] = ['distance' => (float) $distance, 'time' => (float) $seconds];
        }
        $actual = $cost * 4;
        // Out-of-contract provider results stay charged conservatively and are never cached as recommendations.
        abort_if($actual > $reserved, 503, 'Road estimates exceeded the safe allowance. Choose a stop manually.');
        DB::table('map_usage')->where('bucket', $bucket)->decrement('units', $reserved - $actual);
        $cache->put($key, $cells, 300);

        return $cells;
    }

    /** Proxy fixed raster tiles through the same quota gate, with a separate bounded file cache. */
    public function tile(int $z, int $x, int $y): string
    {
        abort_unless($this->configured(), 503, 'Maps are not configured. Manual delivery remains available.');
        $key = "tile:$z:$x:$y";
        $cache = Cache::store('maps');
        if (is_string($cached = $cache->get($key))) {
            return $cached;
        }
        $this->reserve(1);
        try {
            $response = Http::connectTimeout(3)->timeout(8)->get("https://maps.geoapify.com/v1/tile/osm-carto/$z/$x/$y.png", ['apiKey' => config('maps.api_key')]);
        } catch (ConnectionException) {
            abort(503, 'The map is temporarily unavailable. Manual delivery remains available.');
        }
        $bytes = $response->body();
        abort_unless($response->successful() && str_starts_with(strtolower($response->header('Content-Type')), 'image/png')
            && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && strlen($bytes) <= 1_000_000, 503, 'The map is temporarily unavailable. Manual delivery remains available.');
        $cache->put($key, $bytes, 86400);

        return $bytes;
    }

    /** Keep the current and previous 24 UTC hour buckets: conservative across midnight/provider resets. */
    private function cutoff(): string
    {
        return now()->utc()->subHours(24)->startOfHour()->format('Y-m-d H:i:s');
    }

    /** Reserve before any network call; one persisted row lock serializes every rider and worker. */
    private function reserve(int $units): string
    {
        abort_unless(RateLimiter::attempt('hatid-geoapify-provider', 5, /** Provider requests share one short burst limit. */ static fn (): bool => true, 1),
            429, 'Maps are busy. Wait a moment and try again.');
        DB::table('map_usage')->insertOrIgnore(['bucket' => self::LOCK_BUCKET, 'units' => 0]);

        /** The lock and counter changes commit before network I/O; failed requests keep their reservation. */
        return DB::transaction(function () use ($units): string {
            DB::table('map_usage')->where('bucket', self::LOCK_BUCKET)->lockForUpdate()->first();
            DB::table('map_usage')->where('bucket', '!=', self::LOCK_BUCKET)->where('bucket', '<', $this->cutoff())->delete();
            $used = (int) DB::table('map_usage')->where('bucket', '>=', $this->cutoff())->sum('units');
            $limit = min(2500, max(0, (int) config('maps.credit_limit'))) * 4;
            abort_if($used + $units > $limit, 429, 'The shared free map allowance is temporarily unavailable. Continue manually or open Google Maps.');
            $bucket = now()->utc()->startOfHour()->format('Y-m-d H:i:s');
            DB::table('map_usage')->insertOrIgnore(['bucket' => $bucket, 'units' => 0]);
            DB::table('map_usage')->where('bucket', $bucket)->increment('units', $units);

            return $bucket;
        }, 3);
    }
}
