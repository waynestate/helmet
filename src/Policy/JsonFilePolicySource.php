<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Policy;

use JsonException;

/**
 * A PolicySource that reads a CREED-format policy file.
 *
 * The file is a JSON object with a "schema_version" and a "components" object.
 * This source throws a PolicyReadException on any problem it finds: a missing
 * or unreadable file, invalid JSON, a schema_version it does not know, or a
 * missing "components" object. Callers that must not throw (such as the
 * version warning at request time) catch PolicyReadException.
 */
final class JsonFilePolicySource implements PolicySource
{
    /**
     * The schema versions this source understands.
     *
     * @var list<int>
     */
    private const SUPPORTED_SCHEMA_VERSIONS = [1];

    /**
     * @var array<string, array<string, array{active_until: string, security_until: string}>>
     */
    private array $components;

    public function __construct(private readonly string $path)
    {
        $this->components = $this->load();
    }

    public function components(): array
    {
        return $this->components;
    }

    /**
     * @return array<string, array<string, array{active_until: string, security_until: string}>>
     *
     * @throws PolicyReadException
     */
    private function load(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new PolicyReadException("The policy file could not be read: {$this->path}");
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new PolicyReadException("The policy file could not be read: {$this->path}");
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PolicyReadException(
                "The policy file is not valid JSON: {$this->path}",
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new PolicyReadException("The policy file must contain a JSON object: {$this->path}");
        }

        $schemaVersion = $data['schema_version'] ?? null;
        if (!in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new PolicyReadException(
                'The policy file has an unknown schema_version: ' . var_export($schemaVersion, true),
            );
        }

        $components = $data['components'] ?? null;
        if (!is_array($components)) {
            throw new PolicyReadException("The policy file is missing a 'components' object: {$this->path}");
        }

        $normalized = [];
        foreach ($components as $component => $entry) {
            if (is_string($component) && is_array($entry)) {
                $normalized[$component] = self::versions($entry);
            }
        }

        return $normalized;
    }

    /**
     * The support dates of one component, keyed by version.
     *
     * CREED's policy (policy.schema.json) keeps them under "versions" next
     * to room for more keys; an older, flat file put the versions straight
     * on the component. Both are read. Every date in CREED's schema is
     * optional, so a version without a security_until date is skipped (there
     * is no end to measure), and one without active_until is treated as
     * security-only for its whole life.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, array{active_until: string, security_until: string}>
     */
    private static function versions(array $entry): array
    {
        $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : $entry;

        $dates = [];
        foreach ($versions as $version => $support) {
            if (!is_array($support) || !is_string($support['security_until'] ?? null)) {
                continue;
            }
            $activeUntil = $support['active_until'] ?? null;
            $dates[(string) $version] = [
                'active_until' => is_string($activeUntil) ? $activeUntil : '1970-01-01',
                'security_until' => $support['security_until'],
            ];
        }

        return $dates;
    }
}
