<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I block 2: contacts CRUD, application-level encryption, blind index, fail-closed, masking by permission. */
final class PeopleContactTest extends PeopleHttpCase
{
    private const MANAGER = ['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_CONTACT_MANAGE'];

    public function test_contact_is_encrypted_with_key_version_and_blind_index_and_round_trips(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit']);
        $created = $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '+244 923 456 789', 'is_primary' => true])->assertCreated();
        $item = $created->json('data');
        self::assertSame('+244 923 456 789', $item['value'], 'the phone number stays a string exactly as typed');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $item['ref']);
        $this->assertNoInternalFields($created->json());

        $row = DB::table('person_contacts')->where('person_id', $person['id'])->first();
        self::assertSame(1, (int) $row->key_version);
        self::assertSame(32, strlen($row->value_blind_index));
        self::assertStringNotContainsString('923', $row->value_ciphertext, 'no plaintext at rest');
        self::assertStringNotContainsString('456 789', bin2hex($row->value_ciphertext));
        self::assertNotSame(hash('sha256', 'phone:+244923456789', true), $row->value_blind_index, 'blind index is keyed, not a bare hash');

        $list = $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertOk()->json('data');
        self::assertSame('+244 923 456 789', $list[0]['value']);
        self::assertNotEmpty($this->audits('PEOPLE_SENSITIVE_READ', 'people', $person['id']), 'decrypting reads are audited');
        $audit = $this->audits('CONTACT_CREATED', 'person_contacts', (int) $row->id)[0];
        self::assertSame($staff['unit'], (int) $audit->unit_id);
        self::assertStringNotContainsString('923', (string) $audit->after_metadata);
    }

    public function test_update_end_duplicate_and_primary_rules(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit']);
        $base = 'people/' . $person['public_id'] . '/contacts';
        $email = $this->api($staff, 'POST', $base, ['type' => 'EMAIL', 'value' => 'Ana@Exemplo.ao', 'is_primary' => true])->assertCreated()->json('data');
        $this->api($staff, 'POST', $base, ['type' => 'EMAIL', 'value' => 'ana@exemplo.ao'])->assertStatus(409)->assertJsonPath('error.code', 'CONTACT_EXISTS');
        $second = $this->api($staff, 'POST', $base, ['type' => 'EMAIL', 'value' => 'outro@exemplo.ao', 'is_primary' => true])->assertCreated()->json('data');
        $list = $this->api($staff, 'GET', $base)->json('data');
        self::assertSame([false, true], [collect($list)->firstWhere('ref', $email['ref'])['is_primary'], collect($list)->firstWhere('ref', $second['ref'])['is_primary']]);

        $emailNow = collect($list)->firstWhere('ref', $email['ref']);
        $this->api($staff, 'PATCH', $base . '/' . $email['ref'], ['value' => 'nova@exemplo.ao', 'lock_version' => $emailNow['lock_version'] + 5])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $updated = $this->api($staff, 'PATCH', $base . '/' . $email['ref'], ['value' => 'nova@exemplo.ao', 'lock_version' => $emailNow['lock_version']])->assertOk()->json('data');
        self::assertSame('nova@exemplo.ao', $updated['value']);
        $this->api($staff, 'POST', $base . '/' . $email['ref'] . '/end', ['reason' => 'Endereço desactivado'])->assertNoContent();
        $ended = collect($this->api($staff, 'GET', $base)->json('data'))->firstWhere('ref', $email['ref']);
        self::assertSame('INACTIVE', $ended['status']);
        self::assertSame(2, DB::table('person_contacts')->where('person_id', $person['id'])->count(), 'ending never deletes');
        $this->api($staff, 'POST', $base, ['type' => 'PHONE', 'value' => 'abc'])->assertStatus(422);
        $this->api($staff, 'POST', $base, ['type' => 'EMERGENCY', 'value' => '923000000'])->assertStatus(422);
        self::assertCount(1, $this->audits('CONTACT_ENDED', 'person_contacts', (int) DB::table('person_contacts')->where('status', 'INACTIVE')->where('person_id', $person['id'])->value('id')));
    }

    public function test_contact_search_uses_blind_index_inside_sensitive_scope_only(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit'], ['full_name' => 'Pesquisa Por Contacto']);
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923 111 222'])->assertCreated();
        $found = $this->api($staff, 'GET', 'people?contact=' . urlencode('923-111-222'))->assertOk()->json('data');
        self::assertSame([$person['public_id']], array_column($found, 'public_id'));
        $this->api($staff, 'GET', 'people?contact=923999999')->assertOk()->assertJsonCount(0, 'data');
        $viewer = $this->staff(['PEOPLE_VIEW'], $staff['unit']);
        $this->api($viewer, 'GET', 'people?contact=923111222')->assertStatus(403)->assertJsonPath('error.code', 'SENSITIVE_DATA_RESTRICTED');
        $elsewhere = $this->staff(self::MANAGER);
        $this->api($elsewhere, 'GET', 'people?contact=923111222')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_contacts_hidden_without_sensitive_permission_and_for_minors_and_out_of_scope(): void
    {
        $unit = $this->unit();
        $person = $this->personAt($unit);
        $manager = $this->staff(self::MANAGER, $unit);
        $this->api($manager, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923555666'])->assertCreated();
        $viewer = $this->staff(['PEOPLE_VIEW'], $unit);
        $this->api($viewer, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertStatus(403)->assertJsonPath('error.code', 'SENSITIVE_DATA_RESTRICTED');
        $this->api($viewer, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923000111'])->assertStatus(403);

        $outsider = $this->staff(self::MANAGER);
        $this->api($outsider, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertStatus(404)->assertExactJson($this->notFoundBody());
        $ref = $this->api($manager, 'GET', 'people/' . $person['public_id'] . '/contacts')->json('data.0.ref');
        $this->api($outsider, 'PATCH', 'people/' . $person['public_id'] . '/contacts/' . $ref, ['value' => '923000999', 'lock_version' => 0])->assertStatus(404)->assertExactJson($this->notFoundBody());
        $other = $this->personAt($unit);
        $this->api($manager, 'PATCH', 'people/' . $other['public_id'] . '/contacts/' . $ref, ['value' => '923000999', 'lock_version' => 0])->assertStatus(404)->assertExactJson($this->notFoundBody());
        $this->api($manager, 'POST', 'people/' . $person['public_id'] . '/contacts/' . str_repeat('0', 32) . '/end')->assertStatus(404)->assertExactJson($this->notFoundBody());

        $minor = $this->personAt($unit, ['birth_precision' => 'EXACT', 'birth_date' => now('Africa/Luanda')->subYears(9)->format('Y-m-d')]);
        $this->api($manager, 'GET', 'people/' . $minor['public_id'] . '/contacts')->assertStatus(403)->assertJsonPath('error.code', 'MINOR_PROTECTED');
        $this->api($manager, 'POST', 'people/' . $minor['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923000222'])->assertStatus(403)->assertJsonPath('error.code', 'MINOR_PROTECTED');
    }

    public function test_deceased_person_contacts_are_not_shown_by_default_and_cannot_change(): void
    {
        $staff = $this->staff(self::MANAGER + [3 => 'PEOPLE_EDIT']);
        $person = $this->personAt($staff['unit']);
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923777888'])->assertCreated();
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/mark-deceased', ['lock_version' => 0, 'reason' => 'Óbito registado'])->assertOk();
        $list = $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertOk();
        self::assertSame([], $list->json('data'));
        self::assertSame('DECEASED', $list->json('meta.hidden'));
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923000333'])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        self::assertSame(1, DB::table('person_contacts')->where('person_id', $person['id'])->count(), 'history preserved');
    }

    public function test_encryption_fails_closed_without_plaintext_fallback_or_secret_in_logs(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit']);
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923123123'])->assertCreated();
        $logged = [];
        Log::listen(function ($event) use (&$logged): void { $logged[] = json_encode([$event->message, $event->context]); });

        $keyring = (string) config('people.keyring_path');
        foreach ([null, $keyring . '.missing', base_path('composer.json')] as $path) {
            config(['people.keyring_path' => $path]);
            $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/contacts', ['type' => 'PHONE', 'value' => '923654654'])->assertStatus(503)->assertJsonPath('error.code', 'PEOPLE_CRYPTO_UNAVAILABLE');
            $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertStatus(503);
        }
        // Same key for encryption and blind index is refused.
        $same = base64_encode(random_bytes(32));
        $reused = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-people-reused-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($reused, json_encode(['active_version' => 1, 'keys' => ['1' => ['encryption' => $same, 'blind_index' => $same]]]));
        config(['people.keyring_path' => $reused]);
        $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertStatus(503);
        // A different ring (unknown material for key_version 1) cannot decrypt: integrity failure, no fallback.
        $other = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-people-other-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($other, json_encode(['active_version' => 2, 'keys' => ['2' => ['encryption' => base64_encode(random_bytes(32)), 'blind_index' => base64_encode(random_bytes(32))]]]));
        config(['people.keyring_path' => $other]);
        $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertStatus(503);
        @unlink($reused);
        @unlink($other);

        self::assertSame(1, DB::table('person_contacts')->where('person_id', $person['id'])->count(), 'nothing written while crypto is unavailable');
        $ring = json_decode((string) file_get_contents($keyring), true);
        foreach ($logged as $line) {
            self::assertStringNotContainsString('923654654', $line);
            self::assertStringNotContainsString('923123123', $line);
            self::assertStringNotContainsString($ring['keys']['1']['encryption'], $line);
            self::assertStringNotContainsString($ring['keys']['1']['blind_index'], $line);
        }
        config(['people.keyring_path' => $keyring]);
        $this->api($staff, 'GET', 'people/' . $person['public_id'] . '/contacts')->assertOk()->assertJsonPath('data.0.value', '923123123');
    }
}
