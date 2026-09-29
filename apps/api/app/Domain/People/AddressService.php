<?php

declare(strict_types=1);

namespace App\Domain\People;

// Person addresses (Class B, ADR-0017 D03). The detailed line is encrypted at application level with
// key_version per row (AAD bound to the Person); there is NO blind index for the address line in V1
// (no equality search). Province / municipality reference existing territorial_areas rows only. An
// address is never an institutional scope. Updates never overwrite history: the current link is ended
// ([starts_at, ends_at)) and a new address + link is written in the same transaction. No hard delete.
final class AddressService
{
    private PersonRecords $people;

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public function list(int $user, int $session, string $publicId, bool $history): array
    {
        $id = $this->people->id($publicId);
        return $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $history): array {
            $decision = $this->readAuthority($guard, $id);
            $person = $this->people->row($id);
            $query = $this->baseQuery()->where('pa.person_id', $id);
            if (!$history) {
                $query->where('pa.status', PeopleCatalog::LINK_ACTIVE);
            }
            $rows = $query->orderByRaw("pa.status = 'ACTIVE' DESC")->orderByDesc('pa.starts_at')->orderByDesc('pa.id')->get($this->columns())->all();
            $items = array_map(fn (object $row): array => $this->project($row, $person), $rows);
            $this->rt->audit->record($actor, $decision, 'PEOPLE_SENSITIVE_READ', 'people', $id, null, ['area' => 'addresses', 'count' => count($items), 'history' => $history]);
            return $items;
        });
    }

    public function create(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->people->id($publicId);
        $linkId = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $in): int {
            $decision = $guard->require(PeopleCatalog::PEOPLE_ADDRESS_MANAGE, $id);
            $person = $this->writablePerson($id);
            [$linkId, $addressId, $version] = $this->insert($person, $this->fields($in, null));
            $this->rt->audit->record($actor, $decision, 'ADDRESS_CREATED', 'person_addresses', $linkId, null, ['address_entity' => $addressId, 'key_version' => $version, 'person_entity' => $id]);
            return $linkId;
        });
        return $this->one($user, $session, $publicId, $linkId);
    }

    public function update(int $user, int $session, string $publicId, string $ref, array $in): array
    {
        $id = $this->people->id($publicId);
        $linkId = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $in): int {
            $decision = $guard->require(PeopleCatalog::PEOPLE_ADDRESS_MANAGE, $id);
            $person = $this->writablePerson($id);
            $link = $this->locate($id, $ref);
            if (!is_numeric($in['lock_version'] ?? null) || (int) $in['lock_version'] !== (int) $link->lock_version) {
                throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'person_addresses']);
            }
            if ($link->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'person_addresses']);
            }
            $current = $this->baseQuery()->where('pa.id', $link->id)->first($this->columns());
            $fields = $this->fields($in, $this->project($current, $person));
            $now = $this->rt->ts();
            $this->endLink($link, $now);
            [$newLink, $addressId, $version] = $this->insert($person, $fields, $now);
            $this->rt->audit->record($actor, $decision, 'ADDRESS_UPDATED', 'person_addresses', $newLink, ['previous_link' => (int) $link->id, 'lock_version' => (int) $link->lock_version], ['address_entity' => $addressId, 'key_version' => $version, 'person_entity' => $id, 'history_preserved' => true]);
            return $newLink;
        });
        return $this->one($user, $session, $publicId, $linkId);
    }

    public function end(int $user, int $session, string $publicId, string $ref, ?string $reason): void
    {
        $id = $this->people->id($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $reason): void {
            $decision = $guard->require(PeopleCatalog::PEOPLE_ADDRESS_MANAGE, $id);
            $this->protectedCheck($this->people->row($id, true), $id);
            $link = $this->locate($id, $ref);
            if ($link->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'person_addresses']);
            }
            $this->endLink($link, $this->rt->ts(), $reason);
            $this->rt->audit->record($actor, $decision, 'ADDRESS_ENDED', 'person_addresses', (int) $link->id, ['status' => PeopleCatalog::LINK_ACTIVE], ['status' => PeopleCatalog::LINK_INACTIVE, 'person_entity' => $id], $reason === null ? null : trim($reason));
        });
    }

    private function one(int $user, int $session, string $publicId, int $linkId): array
    {
        foreach ($this->list($user, $session, $publicId, false) as $item) {
            if ($item['ref'] === $this->rt->refs->for('person_addresses', $linkId)) {
                return $item;
            }
        }
        throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_addresses']);
    }

    /** @return array{0: int, 1: int, 2: int} [link id, address id, key version] */
    private function insert(object $person, array $fields, ?string $now = null): array
    {
        $now ??= $this->rt->ts();
        [$ciphertext, $version] = $this->rt->crypto()->encrypt($fields['line1'], $this->aad($person));
        $addressId = (int) $this->rt->db->table('addresses')->insertGetId([
            'country_code' => $fields['country_code'],
            'province_id' => $fields['province_id'],
            'municipality_id' => $fields['municipality_id'],
            'line1_ciphertext' => $ciphertext,
            'locality' => $fields['locality'],
            'key_version' => $version,
            'created_at' => $now,
            'lock_version' => 0,
        ]);
        $linkId = (int) $this->rt->db->table('person_addresses')->insertGetId([
            'person_id' => (int) $person->id,
            'address_id' => $addressId,
            'status' => PeopleCatalog::LINK_ACTIVE,
            'starts_at' => $now,
            'ends_at' => null,
            'reason' => null,
            'source_document_id' => null,
            'created_at' => $now,
            'lock_version' => 0,
        ]);
        return [$linkId, $addressId, $version];
    }

    private function endLink(object $link, string $now, ?string $reason = null): void
    {
        $updated = $this->rt->db->table('person_addresses')->where('id', $link->id)->where('lock_version', $link->lock_version)
            ->update(['status' => PeopleCatalog::LINK_INACTIVE, 'ends_at' => $now, 'reason' => $reason === null ? null : trim($reason), 'lock_version' => (int) $link->lock_version + 1]);
        if ($updated !== 1) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'person_addresses']);
        }
    }

    /** Validates request fields, falling back to the current projection for fields not sent (update). */
    private function fields(array $in, ?array $current): array
    {
        $country = strtoupper((string) ($in['country_code'] ?? ($current['country_code'] ?? 'AGO')));
        if (preg_match('/^[A-Z]{3}$/D', $country) !== 1) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'country_code']);
        }
        $line = trim((string) ($in['line1'] ?? ($current['line1'] ?? '')));
        if (mb_strlen($line) < 3 || mb_strlen($line) > 255) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'line1']);
        }
        $locality = array_key_exists('locality', $in) ? $in['locality'] : ($current['locality'] ?? null);
        $locality = $locality === null || trim((string) $locality) === '' ? null : mb_substr(trim((string) $locality), 0, 191);
        $provinceCode = array_key_exists('province', $in) ? $in['province'] : ($current['province']['code'] ?? null);
        $municipalityCode = array_key_exists('municipality', $in) ? $in['municipality'] : ($current['municipality']['code'] ?? null);
        $province = $this->area($provinceCode, 'province');
        $municipality = $this->area($municipalityCode, 'municipality');
        if ($province !== null && $municipality !== null && (int) $municipality->parent_id !== (int) $province->id) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'municipality']);
        }
        return ['country_code' => $country, 'line1' => $line, 'locality' => $locality, 'province_id' => $province?->id, 'municipality_id' => $municipality?->id];
    }

    private function area(mixed $code, string $field): ?object
    {
        if ($code === null || $code === '') {
            return null;
        }
        $area = is_string($code) ? $this->rt->db->table('territorial_areas')->where('code', $code)->first(['id', 'parent_id']) : null;
        if (!$area) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => $field]);
        }
        return $area;
    }

    private function readAuthority(PeopleGuard $guard, int $id): PeopleDecision
    {
        $guard->require(PeopleCatalog::PEOPLE_VIEW, $id, null, [PeopleAuthority::GENERAL, PeopleAuthority::ACADEMY, PeopleAuthority::CHILDREN]);
        $this->protectedCheck($this->people->row($id), $id);
        foreach ([PeopleCatalog::PEOPLE_SENSITIVE_VIEW, PeopleCatalog::PEOPLE_ADDRESS_MANAGE] as $permission) {
            if ($guard->holds($permission, $id)) {
                return $guard->require($permission, $id);
            }
        }
        throw new PeopleError(PeopleReason::SENSITIVE_RESTRICTED, ['area' => 'addresses']);
    }

    private function protectedCheck(object $person, int $id): void
    {
        if ($this->people->isProtected($person, isset($this->people->childProfiles([$id])[$id]))) {
            throw new PeopleError(PeopleReason::MINOR_PROTECTED, ['area' => 'addresses']);
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

    private function locate(int $personId, string $ref): object
    {
        $ids = $this->rt->db->table('person_addresses')->where('person_id', $personId)->pluck('id')->all();
        $linkId = $this->rt->refs->resolve('person_addresses', $ref, $ids);
        $link = $linkId === null ? null : $this->rt->db->table('person_addresses')->where('id', $linkId)->where('person_id', $personId)->lockForUpdate()->first();
        if (!$link) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_addresses']);
        }
        return $link;
    }

    private function baseQuery()
    {
        return $this->rt->db->table('person_addresses as pa')->join('addresses as a', 'a.id', '=', 'pa.address_id')
            ->leftJoin('territorial_areas as prov', 'prov.id', '=', 'a.province_id')
            ->leftJoin('territorial_areas as mun', 'mun.id', '=', 'a.municipality_id');
    }

    private function columns(): array
    {
        return ['pa.id as link', 'pa.status', 'pa.starts_at', 'pa.ends_at', 'pa.lock_version', 'a.country_code', 'a.line1_ciphertext', 'a.locality', 'a.key_version',
            'prov.code as province_code', 'prov.name as province_name', 'mun.code as municipality_code', 'mun.name as municipality_name'];
    }

    private function project(object $row, object $person): array
    {
        return [
            'ref' => $this->rt->refs->for('person_addresses', (int) $row->link),
            'country_code' => (string) $row->country_code,
            'province' => $row->province_code === null ? null : ['code' => (string) $row->province_code, 'name' => (string) $row->province_name],
            'municipality' => $row->municipality_code === null ? null : ['code' => (string) $row->municipality_code, 'name' => (string) $row->municipality_name],
            'line1' => $this->rt->crypto()->decrypt((string) $row->line1_ciphertext, (int) $row->key_version, $this->aad($person)),
            'locality' => $row->locality === null ? null : (string) $row->locality,
            'status' => (string) $row->status,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => $row->ends_at === null ? null : (string) $row->ends_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function aad(object $person): string
    {
        return 'mepa.people.address.line1.v1|' . $person->public_id;
    }
}
