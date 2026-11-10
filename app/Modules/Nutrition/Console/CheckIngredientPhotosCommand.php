<?php

namespace App\Modules\Nutrition\Console;

use App\Modules\Nutrition\Enums\IngredientPhotoSource;
use App\Modules\Nutrition\Models\IngredientPhoto;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Checks that every Commons ingredient photo still resolves (SPEC 17). Licenses aren't re-checked.
 */
class CheckIngredientPhotosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingredients:check-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a HEAD request to every distinct ingredient photo URL and list the ones that fail';

    /**
     * Requests sent at the same time, to stay within Wikimedia's rate limits.
     */
    private const CONCURRENCY = 2;

    /**
     * Pause between one batch of requests and the next.
     */
    private const DELAY_MS = 250;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Photos of soft-deleted ingredients are hidden from the API, so they aren't checked.
        $photos = IngredientPhoto::with('ingredient:id,key')
            ->whereHas('ingredient')
            ->where('source', IngredientPhotoSource::Commons)
            ->orderBy('path')
            ->get();

        // A file shared by several ingredients is requested once: `url` only depends on the path.
        /** @var Collection<string, Collection<int, IngredientPhoto>> $byUrl */
        $byUrl = $photos->groupBy(fn (IngredientPhoto $photo) => $photo->url);

        $results = [];
        foreach ($byUrl->keys()->chunk(self::CONCURRENCY) as $i => $urls) {
            if ($i > 0) {
                Sleep::for(self::DELAY_MS)->milliseconds();
            }

            $results += Http::pool(fn (Pool $pool) => $urls->map(fn (string $url) => $pool->as($url)
                ->withUserAgent((string) config('services.ingredient_photos.user_agent'))
                ->withOptions(['allow_redirects' => true])
                ->timeout(15)
                ->head($url))->all(), self::CONCURRENCY);
        }

        $failed = 0;
        foreach ($byUrl as $url => $group) {
            $result = $results[$url] ?? null;
            if ($result instanceof Response && $result->successful()) {
                continue;
            }

            $failed++;
            $status = match (true) {
                $result instanceof Response => (string) $result->status(),
                $result instanceof Throwable => $result->getMessage(),
                default => 'no response',
            };
            $keys = $group->map(fn (IngredientPhoto $photo) => $photo->ingredient->key)->unique()->sort()->implode(', ');

            $this->error("{$group->first()->path} ({$status}): {$keys}");
        }

        if ($failed > 0) {
            $this->error("{$failed} of {$byUrl->count()} photo files failed.");

            return self::FAILURE;
        }

        $this->info("All {$byUrl->count()} photo files resolve.");

        return self::SUCCESS;
    }
}
