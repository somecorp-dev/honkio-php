<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

/**
 * /v1/templates: every template with every part (email, SMS, push) in English,
 * French or both. The same templates {@see Templates} (/v1/email-templates)
 * shows when they have an email part; use this one for an SMS-only, push-only
 * or French template.
 *
 * @phpstan-type TemplateEmailPart array{subject: string, html?: string|null, text?: string|null}
 * @phpstan-type TemplateSmsPart array{text: string}
 * @phpstan-type TemplatePushPart array{title?: string|null, body: string}
 * @phpstan-type TemplatePartsInput array{email?: TemplateEmailPart|null, sms?: TemplateSmsPart|null, push?: TemplatePushPart|null}
 * @phpstan-type TemplateVariable array{key: string, fallback?: string}
 * @phpstan-type CreateTemplateParams array{
 *     name: string,
 *     parts: array{en?: TemplatePartsInput, fr?: TemplatePartsInput},
 *     alias?: string|null,
 *     default_language?: 'en'|'fr',
 *     from_address?: string|null,
 *     reply_to?: string|list<string>,
 *     variables?: list<TemplateVariable>,
 * }
 * @phpstan-type UpdateTemplateParams array{
 *     name?: string,
 *     parts?: array{en?: TemplatePartsInput|null, fr?: TemplatePartsInput|null},
 *     alias?: string|null,
 *     default_language?: 'en'|'fr',
 *     from_address?: string|null,
 *     reply_to?: string|list<string>,
 *     variables?: list<TemplateVariable>,
 * }
 */
final class ChannelTemplates
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create a template draft with a part for each channel, in English, French or both:
     * `['name' => ..., 'parts' => ['en' => ['sms' => ['text' => ...]], 'fr' => [...]]]`.
     * The default language (`default_language`, `en` unless set) needs at least one part;
     * the other language can only have parts the default language has. Live sends use the
     * template once it is published; a send with a test key renders the draft.
     *
     * @param CreateTemplateParams $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/templates', [], [], $params, null, false, ['parts']);
    }

    /**
     * One template with its parts, by id or alias.
     *
     * @return array<mixed>
     */
    public function get(string $idOrAlias): array
    {
        return $this->http->callArray('GET', '/v1/templates/{idOrAlias}', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * A page of templates, newest first, with which parts each language has:
     * `['limit' => 50, 'cursor' => ...]`.
     *
     * @param array{limit?: int, cursor?: string|null} $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/templates', [], $query);
    }

    /**
     * Edit the draft. Each part named in `parts` replaces that part, null removes it, and parts
     * left out are kept; a language set to null loses all its parts. Live sends keep the
     * published version until you publish again. On a published template, changing `alias`
     * needs a live key (`LIVE_KEY_REQUIRED`).
     *
     * @param UpdateTemplateParams $params
     * @return array<mixed>
     */
    public function update(string $idOrAlias, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/templates/{idOrAlias}', ['idOrAlias' => $idOrAlias], [], $params, null, false, ['parts']);
    }

    /**
     * Publish the draft, every part in every language, as a new version. Live keys only.
     *
     * @return array<mixed>
     */
    public function publish(string $idOrAlias): array
    {
        return $this->http->callArray('POST', '/v1/templates/{idOrAlias}/publish', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * Publish an earlier version again as a new version: `['version' => 2]`. Live keys only.
     *
     * @param array{version: int} $params
     * @return array<mixed>
     */
    public function rollback(string $idOrAlias, array $params): array
    {
        return $this->http->callArray('POST', '/v1/templates/{idOrAlias}/rollback', ['idOrAlias' => $idOrAlias], [], $params);
    }

    /**
     * A template's published versions, newest first.
     *
     * @return array<mixed>
     */
    public function versions(string $idOrAlias): array
    {
        return $this->http->callArray('GET', '/v1/templates/{idOrAlias}/versions', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * Delete a template and its versions. Live keys only. Returns null.
     */
    public function remove(string $idOrAlias): null
    {
        return $this->http->callNull('DELETE', '/v1/templates/{idOrAlias}', ['idOrAlias' => $idOrAlias]);
    }
}
