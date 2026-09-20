<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academy\AcademyError;
use App\Http\Requests\Academy\EnrollmentStoreRequest;
use App\Http\Resources\Academy\AcademyActionResource;
use App\Models\User;
use Illuminate\Support\Facades\Route;
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
}
