<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Endpoints;
use Honkio\Honkio;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's route table must exist in the API's committed OpenAPI snapshot
 * (web/generated/openapi.json, which the API's own tests keep current), and
 * every route must have a method on its resource class.
 */
final class SpecContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../web/generated/openapi.json';

    /** @return array<string, array<string, mixed>> */
    private function paths(): array
    {
        if (!Monorepo::present()) {
            $this->markTestSkipped(Monorepo::skipReason());
        }
        $this->assertFileExists(self::SPEC, 'web/generated/openapi.json is missing; was it moved?');
        $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($spec);
        $this->assertIsArray($spec['paths']);

        return $spec['paths'];
    }

    public function testEveryEndpointIsInTheSpec(): void
    {
        $paths = $this->paths();
        foreach (Endpoints::ALL as [$resource, $method, $http, $path]) {
            $this->assertArrayHasKey($path, $paths, "$resource.$method: $path is not in the OpenAPI spec");
            $this->assertArrayHasKey(strtolower($http), $paths[$path], "$resource.$method: $http $path is not in the OpenAPI spec");
        }
    }

    public function testEveryEndpointHasAResourceMethod(): void
    {
        $this->assertGreaterThan(70, count(Endpoints::ALL));
        $client = new Honkio('k');
        foreach (Endpoints::ALL as [$resource, $method]) {
            $target = $client;
            foreach (explode('.', $resource) as $property) {
                $this->assertTrue(property_exists($target, $property), "No resource property $resource");
                $target = $target->{$property};
            }
            $this->assertTrue(
                method_exists($target, $method) && (new \ReflectionMethod($target, $method))->isPublic(),
                "$resource has no public method $method()",
            );
        }
    }

    public function testEveryPublicResourceMethodIsInTheTable(): void
    {
        $listed = [];
        foreach (Endpoints::ALL as [$resource, $method]) {
            $listed["$resource.$method"] = true;
        }
        // Not routes: verify() is a local helper around Webhook::verify().
        $local = ['webhooks.verify' => true];

        $client = new Honkio('k');
        $resources = [];
        foreach ((new \ReflectionObject($client))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $resources[$property->getName()] = $property->getValue($client);
        }
        $resources['emails.received'] = $client->emails->received;
        foreach ($resources as $name => $object) {
            foreach ((new \ReflectionObject($object))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isConstructor()) {
                    continue;
                }
                $key = "$name.{$m->getName()}";
                $this->assertTrue(isset($listed[$key]) || isset($local[$key]), "$key is not in Endpoints::ALL");
            }
        }
    }
}
