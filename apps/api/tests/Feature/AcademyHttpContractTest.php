<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academy\AcademyError;
use App\Http\Controllers\Api\V1\Academy\CatalogQueryController;
use App\Http\Requests\Academy\EnrollmentStoreRequest;
use App\Http\Resources\Academy\AcademyActionResource;
use App\Http\Resources\Academy\AcademyReadResource;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class AcademyHttpContractTest extends TestCase
{
    public function test_academy_routes_require_the_existing_api_session(): void
    {
        $this->getJson('/api/v1/academy/classes/01K00000000000000000000000/enrollments')
            ->assertStatus(401)
            ->assertExactJson(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']]);
    }

    /** @dataProvider concealedReasons */
    public function test_sensitive_domain_reasons_have_an_indistinguishable_external_response(string $reason): void
    {
        Route::middleware('api')->get('/api/v1/academy/__probe/' . strtolower($reason), static function () use ($reason): void {
            throw new AcademyError($reason, ['internal_id' => 999]);
        });

        $response = $this->getJson('/api/v1/academy/__probe/' . strtolower($reason));
        $response->assertStatus(404)->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']]);
        self::assertSame('application/json', $response->headers->get('content-type'));
        self::assertStringNotContainsString('999', $response->getContent());
    }

    public function concealedReasons(): array
    {
        return [['TARGET_NOT_FOUND'], ['NOT_AUTHORIZED'], ['OUT_OF_SCOPE'], ['CLASS_ASSIGNMENT_REQUIRED']];
    }

    public function test_unknown_mass_assignment_fields_are_rejected(): void
    {
        Route::middleware('api')->post('/api/v1/academy/__validation', static fn (EnrollmentStoreRequest $request) => response()->json($request->validated()));
        $user = new User(['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'x']);

        $this->actingAs($user)->postJson('/api/v1/academy/__validation', [
            'person' => '01K00000000000000000000000',
            'status' => 'FORGED',
            'created_by' => 999,
        ])->assertStatus(422)
          ->assertJsonPath('error.code', 'VALIDATION_ERROR')
          ->assertJsonStructure(['error' => ['details' => ['fields' => ['status', 'created_by']]]]);
    }

    public function test_action_resource_does_not_expose_internal_or_actor_identifiers(): void
    {
        $resource = new AcademyActionResource([
            'enrollment_id' => 9,
            'public_id' => '01K00000000000000000000000',
            'person_id' => 10,
            'recorded_by' => 11,
            'outcome' => 'CREATED',
        ]);
        $payload = $resource->toArray(request());
        self::assertSame(['public_id' => '01K00000000000000000000000', 'outcome' => 'CREATED'], $payload);
    }

    public function test_pagination_and_sort_inputs_are_bounded_and_allowlisted(): void
    {
        Route::middleware('api')->get('/api/v1/academy/__list-validation', static fn (\App\Http\Requests\Academy\AcademyListRequest $request) => response()->json($request->validated()));
        $user = new User(['name' => 'Synthetic', 'email' => 'list@example.test', 'password' => 'x']);

        foreach ([['per_page' => 100000], ['per_page' => -1], ['page' => 0], ['sort' => 'id desc'], ['search' => '*']] as $query) {
            $this->actingAs($user)->getJson('/api/v1/academy/__list-validation?' . http_build_query($query))
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->actingAs($user)->getJson('/api/v1/academy/__list-validation?page=2&per_page=100&status=SAFE')
            ->assertOk()->assertExactJson(['page' => '2', 'per_page' => '100', 'status' => 'SAFE']);
    }

    public function test_a31_read_query_is_bounded_and_allowlisted(): void
    {
        Route::middleware('api')->get('/api/v1/academy/__read-validation', static fn (\App\Http\Requests\Academy\AcademyQueryRequest $request) => response()->json($request->validated()));
        $user = new User(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => 'x']);
        foreach ([['per_page'=>101], ['search'=>'ab'], ['sort'=>'id desc'], ['direction'=>'sideways'], ['unknown'=>'x']] as $query) {
            $this->actingAs($user)->getJson('/api/v1/academy/__read-validation?' . http_build_query($query))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->actingAs($user)->getJson('/api/v1/academy/__read-validation?page=2&per_page=100&search=academy&sort=name&direction=desc')
            ->assertOk()->assertJsonPath('sort', 'name')->assertJsonPath('direction', 'desc');
    }

    public function test_read_projection_minimizes_person_minor_and_document_data(): void
    {
        $payload = (new AcademyReadResource([
            'public_id'=>'01K00000000000000000000000','display_name'=>'Aluno','enrollment_status'=>'FACTUAL',
            'person_id'=>91,'birth_date'=>'2015-01-01','phone'=>'secret','guardian'=>'secret','file_id'=>92,'token_hash'=>'secret','answers_metadata'=>['secret'=>true],
        ]))->toArray(request());
        self::assertSame(['public_id'=>'01K00000000000000000000000','display_name'=>'Aluno','enrollment_status'=>'FACTUAL'], $payload);
    }

    public function test_read_query_allowlists_are_endpoint_specific(): void
    {
        $request = \App\Http\Requests\Academy\AcademyQueryRequest::create('/api/v1/academy/courses', 'GET', [
            'program' => '01K00000000000000000000000',
            'sort' => 'version',
        ]);
        $route = new \Illuminate\Routing\Route('GET', 'api/v1/academy/courses', [CatalogQueryController::class, 'courses']);
        $request->setRouteResolver(static fn () => $route);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('program', $validator->errors()->toArray());
        self::assertArrayHasKey('sort', $validator->errors()->toArray());
    }

    public function test_manifest_and_router_expose_all_a31_read_contracts(): void
    {
        $manifest = json_decode(file_get_contents(base_path('../../docs/api/wave5_academy_http_contracts.json')), true, 512, JSON_THROW_ON_ERROR);
        $reads = array_values(array_filter($manifest['endpoints'], static fn (array $e): bool => $e['method'] === 'GET' && str_contains($e['service_operation'], 'QueryService::')));
        self::assertCount(28, $reads);
        foreach ($reads as $endpoint) {
            self::assertTrue(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/v1/academy/' . $endpoint['uri'] && in_array('GET', $route->methods(), true)), $endpoint['uri']);
        }
    }

    public function test_every_a31_read_endpoint_executes_the_authentication_boundary(): void
    {
        $manifest = json_decode(file_get_contents(base_path('../../docs/api/wave5_academy_http_contracts.json')), true, 512, JSON_THROW_ON_ERROR);
        $reads = array_values(array_filter($manifest['endpoints'], static fn (array $e): bool => $e['method'] === 'GET' && str_contains($e['service_operation'], 'QueryService::')));
        $numeric = ['academicUnit', 'curriculum', 'courseVersion', 'classSession', 'attempt'];

        foreach ($reads as $endpoint) {
            $uri = preg_replace_callback('/\{([^}]+)\}/', static fn (array $m): string => in_array($m[1], $numeric, true) ? '1' : '01K00000000000000000000000', $endpoint['uri']);
            $query = str_contains($uri, 'people:search') ? '?search=abc' : '';
            $this->getJson('/api/v1/academy/' . $uri . $query)
                ->assertStatus(401)
                ->assertExactJson(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']]);
        }
    }
}
