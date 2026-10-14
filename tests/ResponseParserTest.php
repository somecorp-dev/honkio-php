<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Exception\ApiException;
use Honkio\Exception\AuthenticationException;
use Honkio\Exception\BadRequestException;
use Honkio\Exception\ConflictException;
use Honkio\Exception\InternalServerException;
use Honkio\Exception\NotFoundException;
use Honkio\Exception\PaymentRequiredException;
use Honkio\Exception\PermissionDeniedException;
use Honkio\Exception\RateLimitException;
use Honkio\Exception\UnavailableForLegalReasonsException;
use Honkio\Exception\UnprocessableEntityException;
use Honkio\Http\ResponseParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    public function test204EmptyIsNull(): void
    {
        $this->assertNull(ResponseParser::parse(204, ''));
    }

    public function testDecodesJsonAsAssociativeArrays(): void
    {
        $this->assertSame(['id' => 'x', 'n' => [1, 2]], ResponseParser::parse(200, '{"id":"x","n":[1,2]}'));
    }

    public function testNonJson2xxIsAnApplicationError(): void
    {
        try {
            ResponseParser::parse(200, 'not json');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('application_error', $e->errorCode);
            $this->assertSame(200, $e->statusCode);
        }
    }

    public function testEnvelopeIsMapped(): void
    {
        try {
            ResponseParser::parse(422, '{"code":"NO_CONSENT","message":"No consent on file.","messageEn":"x","messageFr":"y","statusCode":422,"details":{"to":"+1"}}');
            $this->fail('expected an exception');
        } catch (UnprocessableEntityException $e) {
            $this->assertSame('NO_CONSENT', $e->errorCode);
            $this->assertSame('No consent on file.', $e->getMessage());
            $this->assertSame(422, $e->statusCode);
            $this->assertSame(422, $e->getCode());
            $this->assertSame(['to' => '+1'], $e->details);
        }
    }

    public function testFieldsBesideTheEnvelopeBecomeDetailsWhenThereIsACode(): void
    {
        try {
            ResponseParser::parse(400, '{"code":"VERIFICATION_INVALID_CODE","message":"m","messageEn":"m","messageFr":"m","statusCode":400,"attempts_remaining":2}');
            $this->fail('expected an exception');
        } catch (BadRequestException $e) {
            $this->assertSame(['attempts_remaining' => 2], $e->details);
        }
    }

    public function testExplicitDetailsWinOverExtraFields(): void
    {
        try {
            ResponseParser::parse(400, '{"code":"X","message":"m","details":{"a":1},"extra":2}');
            $this->fail('expected an exception');
        } catch (BadRequestException $e) {
            $this->assertSame(['a' => 1], $e->details);
        }
    }

    public function testNoCodeMeansNoDetails(): void
    {
        try {
            ResponseParser::parse(502, '{"error":"bad gateway"}');
            $this->fail('expected an exception');
        } catch (InternalServerException $e) {
            $this->assertSame('application_error', $e->errorCode);
            $this->assertSame('HTTP 502', $e->getMessage());
            $this->assertNull($e->details);
        }
    }

    public function testNonJsonErrorBodyBecomesTheMessage(): void
    {
        try {
            ResponseParser::parse(503, '<html>down</html>');
            $this->fail('expected an exception');
        } catch (InternalServerException $e) {
            $this->assertSame('application_error', $e->errorCode);
            $this->assertSame('<html>down</html>', $e->getMessage());
        }
    }

    public function testEmptyErrorBodyFallsBackToTheStatus(): void
    {
        try {
            ResponseParser::parse(500, '');
            $this->fail('expected an exception');
        } catch (InternalServerException $e) {
            $this->assertSame('HTTP 500', $e->getMessage());
        }
    }

    public function testAnErrorArrayBodyIsNotAnEnvelope(): void
    {
        try {
            ResponseParser::parse(400, '[1,2]');
            $this->fail('expected an exception');
        } catch (BadRequestException $e) {
            $this->assertSame('application_error', $e->errorCode);
            $this->assertSame('[1,2]', $e->getMessage());
        }
    }

    /** @return array<string, array{int, class-string<ApiException>}> */
    public static function statuses(): array
    {
        return [
            '400' => [400, BadRequestException::class],
            '401' => [401, AuthenticationException::class],
            '402' => [402, PaymentRequiredException::class],
            '403' => [403, PermissionDeniedException::class],
            '404' => [404, NotFoundException::class],
            '409' => [409, ConflictException::class],
            '422' => [422, UnprocessableEntityException::class],
            '429' => [429, RateLimitException::class],
            '451' => [451, UnavailableForLegalReasonsException::class],
            '500' => [500, InternalServerException::class],
            '599' => [599, InternalServerException::class],
            '418' => [418, ApiException::class],
        ];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('statuses')]
    public function testStatusMapsToASubclass(int $status, string $class): void
    {
        try {
            ResponseParser::parse($status, '{"code":"C","message":"m"}');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame($class, $e::class);
        }
    }
}
