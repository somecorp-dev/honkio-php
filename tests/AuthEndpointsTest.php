<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Endpoints;
use PHPUnit\Framework\TestCase;

/**
 * Honkio Auth's routes are left out of the public OpenAPI document until
 * launch, so Endpoints::NOT_LAUNCHED is checked against the API's route source
 * instead (api/src/routes/authApps/*.ts, and the export download route), and
 * a route that shows up in the public spec fails here so the launch moves it
 * into Endpoints::ALL.
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
        $this->assertGreaterThanOrEqual(49, count(Endpoints::NOT_LAUNCHED));
        foreach (Endpoints::NOT_LAUNCHED as [$resource, , , $path]) {
            $this->assertMatchesRegularExpression('#^authApps(\.\w+)?$#', $resource);
            $this->assertMatchesRegularExpression('#^/v1/auth-(apps|exports)(/|$)#', $path);
        }
    }

    public function testEveryAuthAppsRouteExistsInTheApi(): void
    {
        $this->requireMonorepo();
        $routes = $this->apiRoutes();
        $this->assertArrayHasKey('POST /v1/auth-apps', $routes, 'the route scan found nothing; did the route syntax change?');
        foreach (Endpoints::NOT_LAUNCHED as [$resource, $method, $http, $path]) {
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

    public function testNoneIsInThePublicSpecYet(): void
    {
        $this->requireMonorepo();
        $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($spec);
        $this->assertIsArray($spec['paths']);
        foreach (Endpoints::NOT_LAUNCHED as [$resource, $method, $http, $path]) {
            $this->assertFalse(
                isset($spec['paths'][$path][strtolower($http)]),
                "$resource.$method: $http $path is in the public spec now; move it from Endpoints::NOT_LAUNCHED to Endpoints::ALL",
            );
        }
    }

    public function testNoRouteIsListedTwice(): void
    {
        $seen = [];
        foreach ([...Endpoints::ALL, ...Endpoints::NOT_LAUNCHED] as [$resource, $method, $http, $path]) {
            $key = "$resource.$method $http $path";
            $this->assertArrayNotHasKey($key, $seen);
            $seen[$key] = true;
        }
    }
}
