<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Endpoints;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

/** $honkio->authApps and its nested resources: paths, methods and bodies. */
final class AuthAppsTest extends TestCase
{
    /** @return list<Response> */
    private function ok(int $count = 1): array
    {
        return array_fill(0, $count, MockHttp::json(200, ['id' => 'x']));
    }

    private function line(MockHttp $mock, int $i = 0): string
    {
        $r = $mock->request($i);
        $query = $r->getUri()->getQuery();

        return $r->getMethod() . ' ' . $r->getUri()->getPath() . ($query !== '' ? '?' . $query : '');
    }

    public function testAppRoutes(): void
    {
        [$honkio, $mock] = MockHttp::honkio([...$this->ok(4), new Response(204), ...$this->ok(6)]);
        $honkio->authApps->create(['hostname' => 'login.example.ca', 'name' => 'Acme', 'redirect_uris' => ['https://app.example.ca/cb']]);
        $honkio->authApps->list(['limit' => 10, 'cursor' => 'c1']);
        $honkio->authApps->get('app_1');
        $honkio->authApps->update('app_1', ['name' => 'Acme 2', 'passkeys_enabled' => false]);
        $honkio->authApps->remove('app_1');
        $honkio->authApps->rotateSecret('app_1');
        $honkio->authApps->verifyDomain('app_1');
        $honkio->authApps->signIns('app_1', ['user_id' => 'usr_1']);
        $honkio->authApps->signInPolicy('app_1');
        $honkio->authApps->updateSignInPolicy('app_1', ['sign_up_mode' => 'invite_only']);
        $honkio->authApps->update('app_1', ['send_from_email_domain_id' => null]);

        $this->assertSame([
            'POST /v1/auth-apps',
            'GET /v1/auth-apps?limit=10&cursor=c1',
            'GET /v1/auth-apps/app_1',
            'PATCH /v1/auth-apps/app_1',
            'DELETE /v1/auth-apps/app_1',
            'POST /v1/auth-apps/app_1/rotate-secret',
            'POST /v1/auth-apps/app_1/domain/verify',
            'GET /v1/auth-apps/app_1/sign-ins?user_id=usr_1',
            'GET /v1/auth-apps/app_1/sign-in-policy',
            'PATCH /v1/auth-apps/app_1/sign-in-policy',
            'PATCH /v1/auth-apps/app_1',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 10)));
        $this->assertSame(['hostname' => 'login.example.ca', 'name' => 'Acme', 'redirect_uris' => ['https://app.example.ca/cb']], $mock->jsonBody(0));
        $this->assertSame('{"send_from_email_domain_id":null}', (string) $mock->request(10)->getBody());
    }

    public function testLogoUploadSendsTheImageAsAMultipartLogoPart(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok(2));
        $png = "\x89PNG\r\n\x1a\nrest";
        $honkio->authApps->uploadLogo('app_1', $png, 'image/png', 'acme.png');
        $honkio->authApps->removeLogo('app_1');

        $r = $mock->request(0);
        $this->assertSame('PUT /v1/auth-apps/app_1/logo', $this->line($mock, 0));
        $this->assertMatchesRegularExpression('#^multipart/form-data; boundary=#', $r->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer k', $r->getHeaderLine('Authorization'));
        $body = (string) $r->getBody();
        $this->assertStringContainsString('Content-Disposition: form-data; name="logo"; filename="acme.png"', $body);
        // The logo part's headers (in any order; Guzzle also adds Content-Length)
        // carry the image type, and the part's contents are the image bytes.
        $this->assertSame(1, preg_match('#\r\n((?:[^\r\n]+\r\n)+)\r\n' . preg_quote($png, '#') . '\r\n--#', $body, $m));
        $this->assertContains('Content-Type: image/png', explode("\r\n", rtrim($m[1], "\r\n")));
        $this->assertSame('DELETE /v1/auth-apps/app_1/logo', $this->line($mock, 1));
    }

    public function testLogoUploadRefusesAnEmptyBodyOrABadType(): void
    {
        [$honkio, $mock] = MockHttp::honkio([]);
        try {
            $honkio->authApps->uploadLogo('app_1', '', 'image/png');
            $this->fail('an empty body was sent');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        try {
            $honkio->authApps->uploadLogo('app_1', 'x', "image/png\r\nX-Evil: 1");
        } finally {
            $this->assertSame(0, $mock->count());
        }
    }

    public function testSecurityRoutes(): void
    {
        [$honkio, $mock] = MockHttp::honkio([...$this->ok(5), new Response(204)]);
        $honkio->authApps->security->get('a');
        $honkio->authApps->security->update('a', ['attack_mode' => 'on', 'sms_daily_limit_cents' => 1000]);
        $honkio->authApps->security->summary('a', '7d');
        $honkio->authApps->security->blocklist('a');
        $honkio->authApps->security->addToBlocklist('a', ['kind' => 'COUNTRY', 'value' => 'XX']);
        $honkio->authApps->security->removeFromBlocklist('a', 'blk_1');

        $this->assertSame([
            'GET /v1/auth-apps/a/security',
            'PATCH /v1/auth-apps/a/security',
            'GET /v1/auth-apps/a/security/summary?window=7d',
            'GET /v1/auth-apps/a/security/blocklist',
            'POST /v1/auth-apps/a/security/blocklist',
            'DELETE /v1/auth-apps/a/security/blocklist/blk_1',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 5)));
        $this->assertSame(['attack_mode' => 'on', 'sms_daily_limit_cents' => 1000], $mock->jsonBody(1));
    }

    public function testPlanRoutes(): void
    {
        [$honkio, $mock] = MockHttp::honkio([...$this->ok(2), new Response(204), ...$this->ok(3)]);
        $honkio->authApps->plan->get('a');
        $honkio->authApps->plan->change('a', 'pro');
        $honkio->authApps->plan->cancelScheduledChange('a');
        $honkio->authApps->plan->costs('a', '2026-10');
        $honkio->authApps->plan->costs('a');
        $honkio->authApps->plan->usage('a');

        $this->assertSame([
            'GET /v1/auth-apps/a/plan',
            'PUT /v1/auth-apps/a/plan',
            'DELETE /v1/auth-apps/a/plan/scheduled',
            'GET /v1/auth-apps/a/costs?month=2026-10',
            'GET /v1/auth-apps/a/costs',
            'GET /v1/auth-apps/a/usage',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 5)));
        $this->assertSame(['plan' => 'pro'], $mock->jsonBody(1));
    }

    public function testOrganizationAndInvitationRoutes(): void
    {
        $o = $this->ok(1);
        $d = [new Response(204)];
        [$honkio, $mock] = MockHttp::honkio([
            ...$o, ...$o, ...$o, ...$o, ...$d, ...$o, ...$o, ...$o, ...$o, ...$d, ...$o, ...$o, ...$o, ...$o, ...$d, ...$o, ...$o, ...$o, ...$o,
        ]);
        $orgs = $honkio->authApps->organizations;
        $orgs->list('a');
        $orgs->create('a', ['name' => 'Team']);
        $orgs->get('a', 'org_1');
        $orgs->update('a', 'org_1', ['two_step_required' => true]);
        $orgs->remove('a', 'org_1');
        $orgs->memberships('a', 'org_1');
        $orgs->addMembership('a', 'org_1', ['user_id' => 'usr_1', 'role' => 'member']);
        $orgs->membership('a', 'org_1', 'usr_1');
        $orgs->updateMembership('a', 'org_1', 'usr_1', ['role' => 'admin']);
        $orgs->removeMembership('a', 'org_1', 'usr_1');
        $orgs->roles('a', 'org_1');
        $orgs->putRole('a', 'org_1', 'billing admins', ['two_step_required' => false]);
        $orgs->createRole('a', 'org_1', ['name' => 'billing']);
        $orgs->updateRole('a', 'org_1', 'billing', ['two_step_required' => true]);
        $orgs->removeRole('a', 'org_1', 'billing');
        $honkio->authApps->invitations->list('a');
        $honkio->authApps->invitations->create('a', ['email' => 'ada@example.ca', 'organization_id' => 'org_1']);
        $honkio->authApps->invitations->get('a', 'inv_1');
        $honkio->authApps->invitations->revoke('a', 'inv_1');

        $this->assertSame([
            'GET /v1/auth-apps/a/organizations',
            'POST /v1/auth-apps/a/organizations',
            'GET /v1/auth-apps/a/organizations/org_1',
            'PATCH /v1/auth-apps/a/organizations/org_1',
            'DELETE /v1/auth-apps/a/organizations/org_1',
            'GET /v1/auth-apps/a/organizations/org_1/memberships',
            'POST /v1/auth-apps/a/organizations/org_1/memberships',
            'GET /v1/auth-apps/a/organizations/org_1/memberships/usr_1',
            'PATCH /v1/auth-apps/a/organizations/org_1/memberships/usr_1',
            'DELETE /v1/auth-apps/a/organizations/org_1/memberships/usr_1',
            'GET /v1/auth-apps/a/organizations/org_1/roles',
            'PUT /v1/auth-apps/a/organizations/org_1/roles/billing%20admins',
            'PUT /v1/auth-apps/a/organizations/org_1/roles/billing',
            'PUT /v1/auth-apps/a/organizations/org_1/roles/billing',
            'DELETE /v1/auth-apps/a/organizations/org_1/roles/billing',
            'GET /v1/auth-apps/a/invitations',
            'POST /v1/auth-apps/a/invitations',
            'GET /v1/auth-apps/a/invitations/inv_1',
            'DELETE /v1/auth-apps/a/invitations/inv_1',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 18)));
        $this->assertSame(['two_step_required' => false], $mock->jsonBody(11));
        $this->assertSame('{}', (string) $mock->request(12)->getBody());
    }

    public function testUserSessionAndExportRoutes(): void
    {
        $o = $this->ok(1);
        $d = [new Response(204)];
        [$honkio, $mock] = MockHttp::honkio([
            ...$o, ...$o, ...$o, ...$o, ...$o, ...$o, ...$d, MockHttp::json(200, ['deleted' => 3, 'more' => false]), ...$o, ...$o, ...$d,
            ...$o, ...$o, new Response(200, ['Content-Type' => 'application/json'], '{"users":[]}'),
        ]);
        $users = $honkio->authApps->users;
        $users->list('a', ['limit' => 50]);
        $users->get('a', 'usr_1');
        $users->update('a', 'usr_1', ['name' => 'Ada', 'metadata' => []]);
        $users->block('a', 'usr_1', true, 'spam');
        $users->block('a', 'usr_1', false);
        $users->unblock('a', 'usr_1');
        $users->remove('a', 'usr_1');
        $this->assertSame(['deleted' => 3, 'more' => false], $users->removeLikelyBots('a', null, 'su_bd'));
        $users->sessions('a', 'usr_1');
        $users->signIns('a', 'usr_1');
        $users->revokeSession('a', 'ses_1');
        $honkio->authApps->exports->create('a', 'su_1');
        $honkio->authApps->exports->get('a', 'exp_1');
        $this->assertSame('{"users":[]}', $honkio->authApps->exports->download('tok_1'));

        $this->assertSame([
            'GET /v1/auth-apps/a/users?limit=50',
            'GET /v1/auth-apps/a/users/usr_1',
            'PATCH /v1/auth-apps/a/users/usr_1',
            'POST /v1/auth-apps/a/users/usr_1/block',
            'POST /v1/auth-apps/a/users/usr_1/unblock',
            'POST /v1/auth-apps/a/users/usr_1/unblock',
            'DELETE /v1/auth-apps/a/users/usr_1',
            'POST /v1/auth-apps/a/users/bulk-delete',
            'GET /v1/auth-apps/a/users/usr_1/sessions',
            'GET /v1/auth-apps/a/users/usr_1/sign-ins',
            'DELETE /v1/auth-apps/a/sessions/ses_1',
            'POST /v1/auth-apps/a/exports',
            'GET /v1/auth-apps/a/exports/exp_1',
            'GET /v1/auth-exports/tok_1',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 13)));
        // metadata is the caller's own map: empty is {}.
        $this->assertSame('{"name":"Ada","metadata":{}}', (string) $mock->request(2)->getBody());
        $this->assertSame(['reason' => 'spam'], $mock->jsonBody(3));
        $this->assertSame(['likely_bot' => true], $mock->jsonBody(7));
        $this->assertSame('su_bd', $mock->request(7)->getHeaderLine('X-Step-Up-Token'));
        $this->assertSame('{}', (string) $mock->request(11)->getBody());
        $this->assertSame('su_1', $mock->request(11)->getHeaderLine('X-Step-Up-Token'));
        $this->assertSame('Bearer k', $mock->request(11)->getHeaderLine('Authorization'));
    }

    public function testUserPasskeyRoutes(): void
    {
        $passkeys = ['data' => [['id' => 'pk_1', 'name' => 'iPhone', 'usable' => true]]];
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, $passkeys), new Response(204)]);
        $users = $honkio->authApps->users;

        $this->assertSame($passkeys, $users->passkeys('a', 'usr_1'));
        $this->assertNull($users->removePasskey('a', 'usr_1', 'pk/1'));

        $this->assertSame([
            'GET /v1/auth-apps/a/users/usr_1/passkeys',
            'DELETE /v1/auth-apps/a/users/usr_1/passkeys/pk%2F1',
        ], array_map(fn (int $i): string => $this->line($mock, $i), range(0, 1)));
        $this->assertSame('Bearer k', $mock->request(1)->getHeaderLine('Authorization'));
    }

    public function testErrorsAreTheUsualExceptions(): void
    {
        [$honkio] = MockHttp::honkio([MockHttp::json(404, ['code' => 'NOT_FOUND', 'message' => 'Not found', 'statusCode' => 404])]);
        $this->expectException(NotFoundException::class);
        $honkio->authApps->get('app_missing');
    }

    public function testADotIdentifierIsRefused(): void
    {
        [$honkio, $mock] = MockHttp::honkio([]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $honkio->authApps->organizations->get('a', '..');
        } finally {
            $this->assertSame(0, $mock->count());
        }
    }

    public function testEveryTableRowIsExercisedHere(): void
    {
        // A route added to NOT_LAUNCHED without a test above shows up here.
        $source = (string) file_get_contents(__FILE__);
        foreach (Endpoints::NOT_LAUNCHED as [$resource, $method]) {
            $this->assertMatchesRegularExpression('/->' . preg_quote($method, '/') . '\(/', $source, "$resource.$method is not called in this test");
        }
    }
}
