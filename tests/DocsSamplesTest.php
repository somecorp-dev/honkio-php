<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Endpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP and Laravel samples on honkio.ca/docs lint (php -l) and call methods
 * that exist. web/lib/docs-samples/sdk-php.ts holds each sample as a template
 * string; this reads them with a regular expression, so the samples avoid
 * backticks and placeholders.
 */
final class DocsSamplesTest extends TestCase
{
    private const SAMPLES_FILE = __DIR__ . '/../../../web/lib/docs-samples/sdk-php.ts';
    /** Helpers a resource exposes beside the routes. */
    private const HELPERS = ['webhooks->verify'];

    /** @return array<string, string> */
    private static function samples(): array
    {
        if (!Monorepo::present()) {
            self::markTestSkipped(Monorepo::skipReason());
        }
        $source = (string) file_get_contents(self::SAMPLES_FILE);
        self::assertGreaterThan(
            0,
            preg_match_all('/^ {2}(\w+): `((?:[^`\\\\]|\\\\.)*)`/m', $source, $found, PREG_SET_ORDER),
            'no samples found in sdk-php.ts',
        );
        $samples = [];
        foreach ($found as $match) {
            // A template string reads \\ as a single backslash.
            $samples[$match[1]] = str_replace('\\\\', '\\', $match[2]);
        }

        return $samples;
    }

    /** @return array<string, array{string}> */
    public static function sampleNames(): array
    {
        if (!Monorepo::present()) {
            return ['skipped without the monorepo' => ['']];
        }
        $names = [];
        foreach (array_keys(self::samples()) as $name) {
            $names[$name] = [$name];
        }

        return $names;
    }

    /** @return list<string> every "resource->method" in Endpoints::ALL, with "emails->received" for the nested resource */
    private static function known(): array
    {
        $known = [];
        foreach (Endpoints::ALL as [$resource, $method]) {
            $known[] = str_replace('.', '->', $resource) . '->' . $method;
        }

        return array_values(array_unique($known));
    }

    /** @return list<string> the top-level resource properties, from Endpoints::ALL */
    private static function resources(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => explode('.', $row[0])[0],
            Endpoints::ALL,
        )));
    }

    /** @return list<string> */
    private static function calls(string $code): array
    {
        preg_match_all('/\$\w+((?:->\w+)+)\(/', $code, $matches);
        $calls = [];
        foreach ($matches[1] as $chain) {
            $parts = explode('->', ltrim($chain, '-> '));
            if (count($parts) < 2) {
                continue; // $e->getMessage(), $this->subject(): no resource
            }
            $calls[] = implode('->', $parts);
        }

        return $calls;
    }

    public function testTheSampleFileWasRead(): void
    {
        $names = array_keys(self::samples());
        foreach (['smsQuickstartSend', 'emailQuickstart', 'sdkQuickstart', 'sdkWebhookVerify'] as $expected) {
            self::assertContains($expected, $names);
        }
    }

    #[DataProvider('sampleNames')]
    public function testSampleLints(string $name): void
    {
        $code = self::samples()[$name];
        self::assertStringStartsWith('<?php', $code, "$name must start with the PHP open tag");
        $file = tempnam(sys_get_temp_dir(), 'honkio-sample-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $code . "\n");
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
            self::assertSame(0, $status, "$name does not parse:\n" . implode("\n", $output));
        } finally {
            @unlink($file);
        }
    }

    #[DataProvider('sampleNames')]
    public function testSampleCallsExist(string $name): void
    {
        $known = self::known();
        foreach (self::calls(self::samples()[$name]) as $call) {
            // Only chains that start at a resource property count: $honkio->messages->send(.
            if (! preg_match('/^(\w+)->(\w+)(->\w+)?$/', $call)) {
                continue;
            }
            if (! in_array(explode('->', $call)[0], self::resources(), true)) {
                continue;
            }
            self::assertTrue(
                in_array($call, $known, true) || in_array($call, self::HELPERS, true),
                "$name: ->$call( is not in Endpoints::ALL",
            );
        }
    }

    public function testTheQuickstartsUseTheClientsCalls(): void
    {
        self::assertContains('messages->send', self::calls(self::samples()['smsQuickstartSend']));
        self::assertContains('emails->send', self::calls(self::samples()['emailQuickstart']));
        self::assertContains('emails->received->list', self::known());
    }

    public function testAMisspelledCallIsCaught(): void
    {
        self::assertNotContains('messages->sendd', self::known());
        self::assertSame(['messages->sendd'], self::calls('$honkio->messages->sendd([]);'));
    }
}
