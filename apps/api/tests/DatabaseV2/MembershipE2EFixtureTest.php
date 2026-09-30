<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\MembershipHttpCase;

/**
 * Seeds the P0.9 browser fixture THROUGH the Membership API (every membership, number, period, transfer, legacy
 * identifier and milestone is a real, audited operation) and writes the transient manifest .tmp/p09-e2e-fixtures.json
 * (git-ignored; removed by the runner). One independent data set per Playwright viewport project, because the browser
 * journeys change state. Credentials are random per run.
 */
final class MembershipE2EFixtureTest extends MembershipHttpCase
{
    private const PROJECTS = ['desktop-1440x900', 'laptop-1366x768', 'tablet-768x1024', 'mobile-390x844'];

    public function test_seed_membership_browser_fixture_only(): void
    {
        $w = $this->world();
        $actor = $this->secretary($w['m']['id'], ['DOCUMENTS_VIEW']);
        $sets = [];
        foreach (self::PROJECTS as $index => $project) {
            $tag = strtoupper(substr($project, 0, 3)) . ($index + 1);
            $submit = $this->person($w['a1'], "Beatriz Nova {$tag}");
            $candidate = $this->submit($actor, $this->person($w['a1'], "Carlos Candidato {$tag}"), $w['a1'])->assertCreated()->json('data');
            $collective = [];
            foreach (['Daniela', 'Eduardo', 'Filipa'] as $name) {
                $c = $this->submit($actor, $this->person($w['a1'], "{$name} Colectiva {$tag}"), $w['a1'])->assertCreated()->json('data');
                $collective[] = $this->api($actor, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version']])->assertOk()->json('data.public_id');
            }
            $member = $this->approved($actor, $this->person($w['a1'], "Gabriel Membro {$tag}"), $w['a1']);
            $this->api($actor, 'POST', 'memberships/' . $member['public_id'] . '/legacy-identifiers', ['raw_number' => "ANT-{$tag}-0042"])->assertCreated();
            $this->api($actor, 'POST', 'memberships/' . $member['public_id'] . '/milestones', ['type' => 'CONVERSION', 'occurred_on' => '2012-04', 'date_precision' => 'MONTH'])->assertCreated();
            $this->api($actor, 'POST', 'memberships/' . $member['public_id'] . '/milestones', ['type' => 'BAPTISM', 'occurred_on' => '2013-08-18', 'date_precision' => 'EXACT'])->assertCreated();
            $moved = $this->approved($actor, $this->person($w['a1'], "Helena Transferida {$tag}"), $w['a1']);
            $t = $this->api($actor, 'POST', 'memberships/' . $moved['public_id'] . '/transfers', ['destination_public_id' => $w['a2']['public_id']])->assertCreated()->json('data.public_id');
            $this->api($actor, 'POST', 'memberships/transfers/' . $t . '/validate-origin')->assertOk();
            $this->api($actor, 'POST', 'memberships/transfers/' . $t . '/accept')->assertOk();
            $this->api($actor, 'POST', 'memberships/transfers/' . $t . '/complete')->assertOk();
            $pending = $this->approved($actor, $this->person($w['a1'], "Isabel Em Transferência {$tag}"), $w['a1']);
            $open = $this->api($actor, 'POST', 'memberships/' . $pending['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertCreated()->json('data.public_id');
            $this->api($actor, 'POST', 'memberships/transfers/' . $open . '/validate-origin')->assertOk();
            $this->api($actor, 'POST', 'memberships/transfers/' . $open . '/accept')->assertOk();
            $deceased = $this->approved($actor, $this->person($w['a1'], "Joaquim Falecido {$tag}"), $w['a1']);
            DB::table('people')->where('public_id', $deceased['person']['public_id'])->update(['status_id' => (int) DB::table('person_statuses')->where('code', 'DECEASED')->value('id')]);
            $sets[$project] = [
                'submit_person' => "Beatriz Nova {$tag}",
                'candidate' => ['public_id' => $candidate['public_id'], 'name' => "Carlos Candidato {$tag}"],
                'collective' => $collective,
                'collective_names' => ["Daniela Colectiva {$tag}", "Eduardo Colectiva {$tag}", "Filipa Colectiva {$tag}"],
                'member' => ['public_id' => $member['public_id'], 'name' => "Gabriel Membro {$tag}", 'number' => $member['member_number'], 'legacy' => "ANT-{$tag}-0042"],
                'moved' => ['public_id' => $moved['public_id'], 'name' => "Helena Transferida {$tag}", 'number' => $moved['member_number']],
                'pending' => ['public_id' => $pending['public_id'], 'name' => "Isabel Em Transferência {$tag}", 'number' => $pending['member_number'], 'transfer' => $open],
                'deceased' => ['public_id' => $deceased['public_id'], 'name' => "Joaquim Falecido {$tag}"],
            ];
        }
        $login = 'membership.e2e.' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        DB::table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $manifest = [
            'login' => $login, 'password' => $password, 'user_id' => $actor['user'],
            'a1' => ['public_id' => $w['a1']['public_id'], 'name' => 'Congregação A1'],
            'a2' => ['public_id' => $w['a2']['public_id'], 'name' => 'Congregação A2'],
            'b1' => ['public_id' => $w['b1']['public_id'], 'name' => 'Congregação B1'],
            'sets' => $sets,
        ];
        $dir = dirname(__DIR__, 4) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/p09-e2e-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->assertFileExists($dir . '/p09-e2e-fixtures.json');
        $this->assertGlobalInvariants();
    }

    private function approved(array $actor, array $person, array $congregation): array
    {
        $c = $this->submit($actor, $person, $congregation)->assertCreated()->json('data');
        $v = $this->api($actor, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version']])->assertOk()->json('data');
        return $this->api($actor, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version']])->assertOk()->json('data');
    }
}
