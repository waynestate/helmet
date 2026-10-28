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

        return $components;
    }
}
