<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Http\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    public function testEncodesEachSegment(): void
    {
        $this->assertSame(
            '/v1/compliance/consents/%2B14165550100',
            Path::fill('/v1/compliance/consents/{phone}', ['phone' => '+14165550100']),
        );
        $this->assertSame(
            '/v1/compliance/consents/email/a%40b.ca',
            Path::fill('/v1/compliance/consents/email/{address}', ['address' => 'a@b.ca']),
        );
        $this->assertSame('/v1/emails/a%2Fb', Path::fill('/v1/emails/{id}', ['id' => 'a/b']));
    }

    public function testFillsSeveralPlaceholders(): void
    {
        $this->assertSame(
            '/v1/emails/received/r1/attachments/a%20b',
            Path::fill('/v1/emails/received/{id}/attachments/{attachmentId}', ['id' => 'r1', 'attachmentId' => 'a b']),
        );
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return ['empty' => [''], 'dot' => ['.'], 'dotdot' => ['..']];
    }

    #[DataProvider('refused')]
    public function testRefusesEmptyAndDotSegments(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Path::fill('/v1/email-domains/{id}', ['id' => $value]);
    }

    public function testRefusesAMissingParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Path::fill('/v1/email-domains/{id}', []);
    }
}
