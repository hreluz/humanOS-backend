<?php

namespace App\Modules\Training\Console;

use App\Modules\Training\Models\ExercisePhoto;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CheckExercisePhotosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exercises:check-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a HEAD request to every exercise photo URL and list the ones that fail';

    /**
     * Requests sent at the same time.
     */
    private const CONCURRENCY = 10;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $photos = ExercisePhoto::with('exercise:id,key')->orderBy('exercise_id')->orderBy('position')->get();

        $responses = Http::pool(fn (Pool $pool) => $photos->map(
            fn (ExercisePhoto $photo) => $pool->as((string) $photo->id)->timeout(15)->head($photo->url),
        )->all(), self::CONCURRENCY);

        // A request that never got a response (DNS, timeout) comes back as an exception instead of a Response.
        $failed = $photos->reject(function (ExercisePhoto $photo) use ($responses) {
            $response = $responses[(string) $photo->id] ?? null;

            return $response instanceof Response && $response->successful();
        });

        foreach ($failed as $photo) {
            $response = $responses[(string) $photo->id] ?? null;
            $status = $response instanceof Response ? $response->status() : 'no response';
            $this->error("{$photo->exercise->key}: {$photo->url} ({$status})");
        }

        if ($failed->isNotEmpty()) {
            $this->error("{$failed->count()} of {$photos->count()} photos failed.");

            return self::FAILURE;
        }

        $this->info("All {$photos->count()} photos resolve.");

        return self::SUCCESS;
    }
}
