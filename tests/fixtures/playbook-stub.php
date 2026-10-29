<?php

declare(strict_types=1);

namespace Waynestate\Playbook {
    // A test double for the private waynestate/playbook package. It is
    // loaded only by the tests that need it (require_once at the top of the
    // test file), never shipped, and guarded so the suite still passes if
    // the real package happens to be installed alongside.
    if (!class_exists(Playbook::class)) {
        final class Playbook
        {
            /** @var array<string, string> */
            public static array $values = [];

            public static function get(string $key): ?string
            {
                $value = self::$values[$key] ?? null;

                return is_string($value) && $value !== '' ? $value : null;
            }

            public static function set(string $key, string $value): void
            {
                self::$values[$key] = $value;
            }

            public static function reset(): void
            {
                self::$values = [];
            }
        }
    }
}
