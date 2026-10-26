<?php

namespace Tests\Feature\Training;

use App\Modules\Training\Models\ExercisePhoto;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckExercisePhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class, ExerciseSeeder::class]);
        Http::preventStrayRequests();
    }

    public function test_it_succeeds_when_every_photo_resolves(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        $this->artisan('exercises:check-photos')
            ->expectsOutput('All '.ExercisePhoto::count().' photos resolve.')
            ->assertExitCode(0);

        Http::assertSentCount(ExercisePhoto::count());
    }

    public function test_it_fails_and_lists_a_broken_photo(): void
    {
        $broken = ExercisePhoto::where('path', 'Pushups/0.jpg')->firstOrFail()->url;

        Http::fake([
            $broken => Http::response(status: 404),
            '*' => Http::response(status: 200),
        ]);

        $this->artisan('exercises:check-photos')
            ->expectsOutput("push_up: {$broken} (404)")
            ->expectsOutput('1 of '.ExercisePhoto::count().' photos failed.')
            ->assertExitCode(1);
    }
}
