<?php

namespace Tests\Feature\Docs;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenApiDocumentTest extends TestCase
{
    private const EXPORT_PATH = 'docs/openapi/v1.json';

    /**
     * The documented operations and their exact response statuses (SPEC 02).
     */
    private const OPERATIONS = [
        'POST /v1/auth/register' => [201, 422, 429],
        'POST /v1/auth/login' => [200, 422, 429],
        'POST /v1/auth/logout' => [204, 401],
        'GET /v1/auth/me' => [200, 401],
        'GET /v1/auth/tokens' => [200, 401],
        'DELETE /v1/auth/tokens/{id}' => [204, 401, 404],
        'DELETE /v1/auth/tokens' => [204, 401],
        'GET /v1/auth/email/verify/{id}/{hash}' => [200, 403, 429],
        'POST /v1/auth/email/verification-notification' => [200, 202, 401, 429],
        'POST /v1/auth/forgot-password' => [200, 422, 429],
        'POST /v1/auth/reset-password' => [200, 422, 429],
    ];

    private const GUEST_OPERATIONS = [
        'POST /v1/auth/register',
        'POST /v1/auth/login',
        'GET /v1/auth/email/verify/{id}/{hash}',
        'POST /v1/auth/forgot-password',
        'POST /v1/auth/reset-password',
    ];

    public function test_committed_document_is_up_to_date(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

            $this->assertSame(
                file_get_contents(base_path(self::EXPORT_PATH)),
                file_get_contents($path),
                self::EXPORT_PATH.' is out of date. Run: php artisan scramble:export --path='.self::EXPORT_PATH,
            );
        } finally {
            unlink($path);
        }
    }

    public function test_document_contains_exactly_the_expected_operations(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::OPERATIONS), array_keys($this->operations()));
    }

    /**
     * @param  list<int>  $statuses
     */
    #[DataProvider('operationProvider')]
    public function test_operation_is_fully_documented(string $operation, array $statuses): void
    {
        $documented = $this->operations()[$operation] ?? null;

        $this->assertNotNull($documented, "{$operation} is not documented.");
        $this->assertSame(['Auth'], $documented['tags'] ?? null);
        $this->assertNotEmpty($documented['summary'] ?? null, "{$operation} has no summary.");

        $documentedStatuses = array_map('intval', array_keys($documented['responses']));
        sort($documentedStatuses);

        $this->assertSame($statuses, $documentedStatuses);
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function operationProvider(): array
    {
        return collect(self::OPERATIONS)
            ->mapWithKeys(fn (array $statuses, string $operation) => [$operation => [$operation, $statuses]])
            ->all();
    }

    public function test_bearer_security_is_applied_globally(): void
    {
        $document = $this->document();

        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], $document['components']['securitySchemes']['http']);
        $this->assertSame([['http' => []]], $document['security']);
    }

    public function test_only_guest_operations_opt_out_of_security(): void
    {
        foreach ($this->operations() as $operation => $documented) {
            if (in_array($operation, self::GUEST_OPERATIONS, true)) {
                $this->assertSame([], $documented['security'] ?? null, "{$operation} should be unauthenticated.");
            } else {
                $this->assertArrayNotHasKey('security', $documented, "{$operation} should inherit bearer security.");
            }
        }
    }

    /**
     * Every 2xx JSON body has a top-level `data` or `message` key (SPEC 04).
     */
    public function test_success_responses_use_the_envelope(): void
    {
        $document = json_decode(file_get_contents(base_path(self::EXPORT_PATH)), true);
        $offenders = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                foreach ($operation['responses'] as $status => $response) {
                    if (! str_starts_with((string) $status, '2')) {
                        continue;
                    }

                    $schema = $this->resolve($document, $response)['content']['application/json']['schema'] ?? null;

                    if ($schema === null) {
                        continue;
                    }

                    $properties = array_keys($this->resolve($document, $schema)['properties'] ?? []);

                    if (! in_array('data', $properties, true) && ! in_array('message', $properties, true)) {
                        $offenders[] = strtoupper($method)." {$path} {$status}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'These success responses have no top-level `data` or `message` key.');
    }

    public function test_docs_are_reachable_in_local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk();
    }

    public function test_docs_are_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
    }

    /**
     * Follow a local `$ref` (e.g. `#/components/schemas/UserResource`) until a concrete node.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $document, array $node): array
    {
        while (isset($node['$ref'])) {
            $node = data_get($document, str_replace('/', '.', substr($node['$ref'], 2)));
        }

        return $node;
    }

    /**
     * Operations keyed by "METHOD /path".
     *
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $operations = [];

        foreach ($this->document()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[strtoupper($method).' '.$path] = $operation;
            }
        }

        return $operations;
    }
}
