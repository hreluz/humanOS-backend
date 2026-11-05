<?php

namespace Tests\Feature\Nutrition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class CheckIngredientPhotosCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsIngredientFixtures;

    private const BASE = 'https://commons.wikimedia.org/wiki/Special:FilePath/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_it_succeeds_when_every_photo_resolves(): void
    {
        $this->seedPhotos();
        Http::fake(['*' => Http::response(status: 200)]);

        $this->artisan('ingredients:check-photos')
            ->expectsOutput('All 3 photo files resolve.')
            ->assertExitCode(0);

        Http::assertSentCount(3);
    }

    public function test_a_404_and_a_connection_error_fail_and_list_both_files_with_their_ingredients(): void
    {
        $this->seedPhotos();
        Http::fake([
            self::BASE.'Egg_whole.jpg?width=960' => Http::response(status: 404),
            self::BASE.'Milk_glass.jpg?width=960' => Http::failedConnection('Could not resolve host'),
            '*' => Http::response(status: 200),
        ]);

        $this->artisan('ingredients:check-photos')
            ->expectsOutput('Egg_whole.jpg (404): egg_whole_raw')
            ->expectsOutputToContain('Milk_glass.jpg (Could not resolve host')
            ->expectsOutput('2 of 3 photo files failed.')
            ->assertExitCode(1);
    }

    public function test_a_shared_file_is_requested_once_and_lists_every_ingredient_using_it(): void
    {
        $this->seedPhotos();
        Http::fake([
            self::BASE.'Egg_cracked.jpg?width=960' => Http::response(status: 410),
            '*' => Http::response(status: 200),
        ]);

        $this->artisan('ingredients:check-photos')
            ->expectsOutput('Egg_cracked.jpg (410): egg_whole_raw, egg_yolk_raw')
            ->assertExitCode(1);

        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'Egg_cracked.jpg')));
    }

    public function test_it_only_requests_the_detail_url_with_head_and_the_user_agent(): void
    {
        config(['services.ingredient_photos.user_agent' => 'humanOS-test/1.0 (test@example.test)']);
        $this->seedPhotos();
        Http::fake(['*' => Http::response(status: 200)]);

        $this->artisan('ingredients:check-photos')->assertExitCode(0);

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);
        foreach ($recorded as [$request]) {
            $this->assertSame('HEAD', $request->method());
            $this->assertStringEndsWith('?width=960', $request->url());
            $this->assertSame(['humanOS-test/1.0 (test@example.test)'], $request->header('User-Agent'));
        }
    }

    public function test_it_waits_between_batches_of_two_requests(): void
    {
        $this->seedPhotos();
        Http::fake(['*' => Http::response(status: 200)]);

        $this->artisan('ingredients:check-photos')->assertExitCode(0);

        // Three files: one batch of two, a pause, then one more.
        Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
    }

    public function test_no_photos_at_all_succeeds_and_sends_nothing(): void
    {
        $this->seedIngredients([$this->egg()]);
        Http::fake();

        $this->artisan('ingredients:check-photos')->assertExitCode(0);

        Http::assertNothingSent();
        Sleep::assertNeverSlept();
    }

    /**
     * Three distinct files across three ingredients; `Egg_cracked.jpg` is shared by two of them.
     */
    private function seedPhotos(): void
    {
        $this->seedIngredients([
            $this->egg(['photos' => [$this->photo('Egg_whole.jpg'), $this->photo('Egg_cracked.jpg')]]),
            $this->ingredient('egg_yolk_raw', 'Egg, yolk, raw', ['photos' => [$this->photo('Egg cracked.jpg')]]),
            $this->ingredient('milk_whole', 'Milk, whole', ['photos' => [$this->photo('Milk_glass.jpg')]]),
        ]);
    }
}
