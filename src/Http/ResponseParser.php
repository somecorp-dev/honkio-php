<?php

declare(strict_types=1);

namespace Honkio\Http;

use Honkio\Exception\ApiException;

final class ResponseParser
{
    /** The error envelope every API error carries; anything else beside it is route-specific data. */
    private const ENVELOPE = ['code', 'message', 'messageEn', 'messageFr', 'statusCode', 'details'];

    /**
     * Decodes a JSON response, or throws the matching ApiException. An empty
     * 2xx body (204) is null.
     *
     * @throws ApiException
     */
    public static function parse(int $status, string $text): mixed
    {
        $body = null;
        $isJson = $text === '';
        if ($text !== '') {
            try {
                $body = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                $isJson = true;
            } catch (\JsonException) {
                $isJson = false;
            }
        }

        if ($status >= 200 && $status < 300) {
            if (!$isJson) {
                throw ApiException::create('application_error', 'The API answered with a body that is not JSON.', $status);
            }

            return $body;
        }

        // `{}` decodes to [] in PHP; it is still a JSON object.
        if (!$isJson || !is_array($body) || ($body !== [] && array_is_list($body))) {
            throw ApiException::create('application_error', self::excerpt($text) ?: "HTTP $status", $status);
        }

        $code = $body['code'] ?? null;
        $message = $body['message'] ?? null;
        $details = null;
        if (array_key_exists('details', $body)) {
            $details = $body['details'];
        } elseif (is_string($code)) {
            // A real API error (it has a code) may carry data beside the envelope:
            // attempts_remaining on VERIFICATION_INVALID_CODE; status, http_status and
            // error_reason on WEBHOOK_REPLAY_FAILED. A JSON body with no code
            // (a proxy's, say) gets no details.
            $extra = array_diff_key($body, array_flip(self::ENVELOPE));
            if ($extra !== []) {
                $details = $extra;
            }
        }

        throw ApiException::create(
            is_string($code) ? $code : 'application_error',
            is_string($message) ? $message : "HTTP $status",
            $status,
            $details,
        );
    }

    private static function excerpt(string $text): string
    {
        return preg_match('/^.{0,200}/su', $text, $m) === 1 ? $m[0] : substr($text, 0, 200);
    }
}
