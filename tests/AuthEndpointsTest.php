<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Endpoints;
use PHPUnit\Framework\TestCase;

/**
 * Honkio Auth's routes (Endpoints::AUTH) against the API's route source
 * (api/src/routes/authApps/*.ts, and the export download route). Its
 * management routes are in the public OpenAPI document and in Endpoints::ALL,
 * which SpecContractTest checks against it; the export download is a
 * single-use link that stays out of the document, in Endpoints::UNCHECKED.
 */
final class AuthEndpointsTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../web/generated/openapi.json';
    private const ROUTES_DIR = __DIR__ . '/../../../api/src/routes/authApps';
    private const API_SRC = __DIR__ . '/../../../api/src';

    private function requireMonorepo(): void
    {
        if (!Monorepo::present()) {
            $this->markTestSkipped(Monorepo::skipReason());
        }
    }

    /** `/v1/auth-apps/{id}/organizations/{orgId}` and `/:id/organizations/:orgId` both become `/:/organizations/:`. */
    private static function shape(string $path): string
    {
        return (string) preg_replace(['/\{\w+\}/', '/:\w+/'], ':', $path);
    }

    /** @return array<string, true> "METHOD /shape" for every route registered in api/src/routes/authApps */
    private function apiRoutes(): array
    {
        $this->assertDirectoryExists(self::ROUTES_DIR, 'api/src/routes/authApps is missing; was it moved?');
        $routes = [];
        foreach (glob(self::ROUTES_DIR . '/*.ts') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/\\.(get|post|put|patch|delete)\\s*(?:<[^>]*(?:<[^>]*>[^>]*)*>)?\\s*\\(\\s*'([^']*)'/", $source, $m, PREG_SET_ORDER);
            foreach ($m as [, $method, $path]) {
                $full = '/v1/auth-apps' . ($path === '/' ? '' : $path);
                $routes[strtoupper($method) . ' ' . self::shape($full)] = true;
            }
        }

        return $routes;
    }

    public function testTheTableCoversEveryContractRoute(): void
    {
        $this->assertGreaterThanOrEqual(49, count(Endpoints::AUTH));
        foreach (Endpoints::AUTH as [$resource, , , $path]) {
            $this->assertMatchesRegularExpression('#^authApps(\.\w+)?$#', $resource);
            $this->assertMatchesRegularExpression('#^/v1/auth-(apps|exports)(/|$)#', $path);
        }
    }

    public function testEveryAuthAppsRouteExistsInTheApi(): void
    {
        $this->requireMonorepo();
        $routes = $this->apiRoutes();
        $this->assertArrayHasKey('POST /v1/auth-apps', $routes, 'the route scan found nothing; did the route syntax change?');
        foreach (Endpoints::AUTH as [$resource, $method, $http, $path]) {
            if (!str_starts_with($path, '/v1/auth-apps')) {
                continue;
            }
            $this->assertArrayHasKey(
                "$http " . self::shape($path),
                $routes,
                "$resource.$method: $http $path is not registered in api/src/routes/authApps",
            );
        }
    }

    public function testTheExportDownloadRouteExistsInTheApi(): void
    {
        $this->requireMonorepo();
        $found = false;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::API_SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'ts' && str_contains((string) file_get_contents($file->getPathname()), '/v1/auth-exports')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'GET /v1/auth-exports/{token} is not registered anywhere in api/src');
    }

    public function testTheManagementRoutesAreInThePublicSpecAndTheDownloadIsNot(): void
    {
        $this->requireMonorepo();
        $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($spec);
        $this->assertIsArray($spec['paths']);
        foreach (Endpoints::AUTH as [$resource, $method, $http, $path]) {
            $published = isset($spec['paths'][$path][strtolower($http)]);
            if (str_starts_with($path, '/v1/auth-exports')) {
                $this->assertFalse($published, "$resource.$method: $http $path is a single-use link and should stay out of the public spec");
            } else {
                $this->assertTrue($published, "$resource.$method: $http $path is not in the public spec");
            }
        }
    }

    public function testTheManagementRoutesAreInAllAndOnlyTheDownloadIsUnchecked(): void
    {
        $authInAll = array_values(array_filter(
            Endpoints::ALL,
            static fn (array $e): bool => str_starts_with($e[0], 'authApps'),
        ));
        $this->assertSame([...$authInAll, ...Endpoints::UNCHECKED_AUTH], Endpoints::AUTH);
        $this->assertSame([['authApps.exports', 'download', 'GET', '/v1/auth-exports/{token}']], Endpoints::UNCHECKED_AUTH);
    }

    public function testTheOldNameStillWorks(): void
    {
        $this->assertSame(Endpoints::AUTH, Endpoints::NOT_LAUNCHED);
    }

    public function testNoRouteIsListedTwice(): void
    {
        $seen = [];
        foreach ([...Endpoints::ALL, ...Endpoints::UNCHECKED] as [$resource, $method, $http, $path]) {
            $key = "$resource.$method $http $path";
            $this->assertArrayNotHasKey($key, $seen);
            $seen[$key] = true;
        }
    }
}
