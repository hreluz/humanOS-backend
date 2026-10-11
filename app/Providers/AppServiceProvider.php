<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\MissingValue;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));

                // Scramble turns server URLs into absolute ones with url(), which ties the
                // export to APP_URL. Keep the configured URLs relative so it stays deterministic.
                $openApi->servers = collect(config('scramble.servers'))
                    ->map(fn (string $url, string $description) => Server::make($url)->setDescription($description))
                    ->values()
                    ->all();

                // Fields added by the `confirmed` rule are cloned before @example tags are read,
                // so they mirror the examples of the field they confirm.
                foreach ($openApi->components->schemas as $schema) {
                    if (! $schema->type instanceof ObjectType) {
                        continue;
                    }

                    foreach ($schema->type->properties as $name => $property) {
                        $source = $schema->type->properties[Str::beforeLast($name, '_confirmation')] ?? null;

                        $hasExamples = collect($property->examples)->reject(fn ($example) => $example instanceof MissingValue)->isNotEmpty();

                        if (str_ends_with($name, '_confirmation') && $source && ! $hasExamples) {
                            $property->examples($source->examples);
                        }
                    }
                }
            });
    }
}
