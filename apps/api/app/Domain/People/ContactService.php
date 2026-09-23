<?php

declare(strict_types=1);

namespace App\Domain\People;

// Person contacts (Class B, ADR-0017 D03). Values are encrypted at application level (AES-256-GCM,
// AAD bound to the Person) with the key_version recorded per row; the blind index (distinct HMAC key)
// exists only because exact equality is needed (duplicate refusal, exact contact search). A phone
// number stays a string. No hard delete: ending a contact sets it INACTIVE.
// Reads decrypt, so every read of values is a sensitive read and is audited (count only).
final class ContactService
{
    private PersonRecords $people;

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public static function normalizeForIndex(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return 'email:' . mb_strtolower($value);
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return 'phone:' . (str_starts_with($value, '+') ? '+' : '') . $digits;
    }

    public function list(int $user, int $session, string $publicId): array
    {
        $id = $this->people->id($publicId);
        return $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id): array {
            $decision = $this->readAuthority($guard, $id);
            $person = $this->people->row($id);
            if ($person->status_code === PeopleCatalog::PERSON_DECEASED) {
                return ['items' => [], 'hidden' => 'DECEASED'];
            }
            $rows = $this->rt->db->table('person_contacts as pc')->join('contact_types as ct', 'ct.id', '=', 'pc.contact_type_id')
                ->where('pc.person_id', $id)->orderByRaw("pc.status = 'ACTIVE' DESC")->orderByDesc('pc.is_primary')->orderBy('pc.id')
                ->get(['pc.*', 'ct.code as type_code', 'ct.name as type_name'])->all();
            $items = array_map(fn (object $row): array => $this->project($row, $person), $rows);
            $this->rt->audit->record($actor, $decision, 'PEOPLE_SENSITIVE_READ', 'people', $id, null, ['area' => 'contacts', 'count' => count($items)]);
            return ['items' => $items, 'hidden' => null];
        });
    }

    public function create(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->people->id($publicId);
        $contactId = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $in): int {
            $decision = $guard->require(PeopleCatalog::PEOPLE_CONTACT_MANAGE, $id);
            $person = $this->writablePerson($id);
            $type = $this->rt->db->table('contact_types')->where('code', (string) ($in['type'] ?? ''))->where('is_active', 1)->first();
            if (!$type) {
                throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'type']);
            }
            $value = $this->validValue((string) $type->code, (string) ($in['value'] ?? ''));
            $crypto = $this->rt->crypto();
            $this->assertNotDuplicate($id, $crypto->blindIndexes(self::normalizeForIndex($value)), null);
            [$ciphertext, $version] = $crypto->encrypt($value, $this->aad($person));
            $primary = (bool) ($in['is_primary'] ?? false);
            $now = $this->rt->ts();
            if ($primary) {
                $this->clearPrimary($id, (int) $type->id);
            }
            $contactId = (int) $this->rt->db->table('person_contacts')->insertGetId([
                'person_id' => $id,
                'contact_type_id' => (int) $type->id,
                'value_ciphertext' => $ciphertext,
                'value_blind_index' => $crypto->blindIndex(self::normalizeForIndex($value)),
                'key_version' => $version,
                'is_primary' => $primary ? 1 : 0,
                'verified_at' => null,
                'status' => PeopleCatalog::LINK_ACTIVE,
                'created_at' => $now,
                'lock_version' => 0,
            ]);
            $this->rt->audit->record($actor, $decision, 'CONTACT_CREATED', 'person_contacts', $contactId, null, ['type' => (string) $type->code, 'is_primary' => $primary, 'key_version' => $version, 'person_entity_id' => $id]);
            return $contactId;
        });
        return $this->one($user, $session, $publicId, $contactId);
    }

    public function update(int $user, int $session, string $publicId, string $ref, array $in): array
    {
        $id = $this->people->id($publicId);
        $contactId = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $in): int {
            $decision = $guard->require(PeopleCatalog::PEOPLE_CONTACT_MANAGE, $id);
            $person = $this->writablePerson($id);
            $row = $this->locate($id, $ref, true);
            if (!is_numeric($in['lock_version'] ?? null) || (int) $in['lock_version'] !== (int) $row->lock_version) {
                throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'person_contacts']);
            }
            if ($row->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'person_contacts']);
            }
            $changes = [];
            $changed = [];
            $version = (int) $row->key_version;
            if (array_key_exists('value', $in)) {
                $type = (string) $this->rt->db->table('contact_types')->where('id', $row->contact_type_id)->value('code');
                $value = $this->validValue($type, (string) $in['value']);
                $crypto = $this->rt->crypto();
                $this->assertNotDuplicate($id, $crypto->blindIndexes(self::normalizeForIndex($value)), (int) $row->id);
                [$ciphertext, $version] = $crypto->encrypt($value, $this->aad($person));
                $changes += ['value_ciphertext' => $ciphertext, 'value_blind_index' => $crypto->blindIndex(self::normalizeForIndex($value)), 'key_version' => $version, 'verified_at' => null];
                $changed[] = 'value';
            }
            if (array_key_exists('is_primary', $in) && (bool) $in['is_primary'] !== (bool) $row->is_primary) {
                if ((bool) $in['is_primary']) {
                    $this->clearPrimary($id, (int) $row->contact_type_id);
                }
                $changes['is_primary'] = (bool) $in['is_primary'] ? 1 : 0;
                $changed[] = 'is_primary';
            }
            if ($changes !== []) {
                $this->versioned((int) $row->id, (int) $row->lock_version, $changes);
                $this->rt->audit->record($actor, $decision, 'CONTACT_UPDATED', 'person_contacts', (int) $row->id, ['lock_version' => (int) $row->lock_version, 'key_version' => (int) $row->key_version], ['changed' => $changed, 'lock_version' => (int) $row->lock_version + 1, 'key_version' => $version, 'person_entity_id' => $id]);
            }
            return (int) $row->id;
        });
        return $this->one($user, $session, $publicId, $contactId);
    }

    public function end(int $user, int $session, string $publicId, string $ref, ?string $reason): void
    {
        $id = $this->people->id($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $reason): void {
            $decision = $guard->require(PeopleCatalog::PEOPLE_CONTACT_MANAGE, $id);
            $this->protectedCheck($this->people->row($id), $id);
            $row = $this->locate($id, $ref, true);
            if ($row->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'person_contacts']);
            }
            $this->versioned((int) $row->id, (int) $row->lock_version, ['status' => PeopleCatalog::LINK_INACTIVE, 'is_primary' => 0]);
            $this->rt->audit->record($actor, $decision, 'CONTACT_ENDED', 'person_contacts', (int) $row->id, ['status' => (string) $row->status], ['status' => PeopleCatalog::LINK_INACTIVE, 'person_entity_id' => $id], $reason === null ? null : trim($reason));
        });
    }

    private function one(int $user, int $session, string $publicId, int $contactId): array
    {
        foreach ($this->list($user, $session, $publicId)['items'] as $item) {
            if ($item['ref'] === $this->rt->refs->for('person_contacts', $contactId)) {
                return $item;
            }
        }
        throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_contacts']);
    }

    /** Reading values needs PEOPLE_SENSITIVE_VIEW or PEOPLE_CONTACT_MANAGE in a GENERAL context; never for protected minors. */
    private function readAuthority(PeopleGuard $guard, int $id): PeopleDecision
    {
        $guard->require(PeopleCatalog::PEOPLE_VIEW, $id, null, [PeopleAuthority::GENERAL, PeopleAuthority::ACADEMY, PeopleAuthority::CHILDREN]);
        $this->protectedCheck($this->people->row($id), $id);
        foreach ([PeopleCatalog::PEOPLE_SENSITIVE_VIEW, PeopleCatalog::PEOPLE_CONTACT_MANAGE] as $permission) {
            if ($guard->holds($permission, $id)) {
                return $guard->require($permission, $id);
            }
        }
        throw new PeopleError(PeopleReason::SENSITIVE_RESTRICTED, ['area' => 'contacts']);
    }

    private function protectedCheck(object $person, int $id): void
    {
        if ($this->people->isProtected($person, isset($this->people->childProfiles([$id])[$id]))) {
            throw new PeopleError(PeopleReason::MINOR_PROTECTED, ['area' => 'contacts']);
        }
    }

    private function writablePerson(int $id): object
    {
        $person = $this->people->row($id, true);
        $this->protectedCheck($person, $id);
        if ($person->status_code === PeopleCatalog::PERSON_DECEASED) {
            throw new PeopleError(PeopleReason::PERSON_DECEASED);
        }
        return $person;
    }

    private function project(object $row, object $person): array
    {
        return [
            'ref' => $this->rt->refs->for('person_contacts', (int) $row->id),
            'type' => (string) $row->type_code,
            'type_name' => (string) $row->type_name,
            'value' => $this->rt->crypto()->decrypt((string) $row->value_ciphertext, (int) $row->key_version, $this->aad($person)),
            'is_primary' => (bool) $row->is_primary,
            'verified_at' => $row->verified_at === null ? null : (string) $row->verified_at,
            'status' => (string) $row->status,
            'created_at' => (string) $row->created_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function locate(int $personId, string $ref, bool $lock): object
    {
        $ids = $this->rt->db->table('person_contacts')->where('person_id', $personId)->pluck('id')->all();
        $contactId = $this->rt->refs->resolve('person_contacts', $ref, $ids);
        if ($contactId === null) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_contacts']);
        }
        $query = $this->rt->db->table('person_contacts')->where('id', $contactId)->where('person_id', $personId);
        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_contacts']);
    }

    private function validValue(string $type, string $value): string
    {
        $value = trim($value);
        $ok = match ($type) {
            'PHONE' => preg_match('/^\+?[0-9][0-9 ()\-]{5,24}$/D', $value) === 1 && strlen(preg_replace('/\D+/', '', $value) ?? '') >= 6,
            'EMAIL' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 191,
            default => mb_strlen($value) >= 3 && mb_strlen($value) <= 191,
        };
        if (!$ok) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'value']);
        }
        return $value;
    }

    private function assertNotDuplicate(int $personId, array $indexes, ?int $except): void
    {
        $query = $this->rt->db->table('person_contacts')->where('person_id', $personId)->where('status', PeopleCatalog::LINK_ACTIVE)->whereIn('value_blind_index', $indexes);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw new PeopleError(PeopleReason::CONTACT_EXISTS);
        }
    }

    private function clearPrimary(int $personId, int $typeId): void
    {
        $this->rt->db->table('person_contacts')->where('person_id', $personId)->where('contact_type_id', $typeId)->where('is_primary', 1)
            ->update(['is_primary' => 0, 'lock_version' => $this->rt->db->raw('lock_version + 1')]);
    }

    private function versioned(int $id, int $version, array $changes): void
    {
        if ($this->rt->db->table('person_contacts')->where('id', $id)->where('lock_version', $version)->update($changes + ['lock_version' => $version + 1]) !== 1) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'person_contacts']);
        }
    }

    private function aad(object $person): string
    {
        return 'mepa.people.contact.v1|' . $person->public_id;
    }
}
