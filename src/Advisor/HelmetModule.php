<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Advisor;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use DateTimeImmutable;
use JsonException;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\Module;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Module\ToolDefinition;
use Waynestate\Helmet\Headers\HeaderReasons;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Policy\JsonFilePolicySource;
use Waynestate\Helmet\Policy\PolicyReadException;
use Waynestate\Helmet\Policy\VersionStatus;
use Waynestate\Helmet\Policy\VersionWarning;

/**
 * HELMET's ADVISOR module: two read-only tools that answer from HELMET's own
 * code, in-process, about an app inside the project root.
 *
 *  - helmet_headers: the security headers and CSP HELMET sends, each with its
 *    reason, plus how the app is wired to HELMET.
 *  - helmet_php_support: the support status of the app's PHP floor, from the
 *    CREED policy ADVISOR bundles, read with HELMET's own policy code.
 *
 * No subprocess, no network, no model call, nothing written, and no .env or
 * secret read: each tool takes one argument (the app folder) and reports what
 * it ran and where every answer came from. HELMET reports; PROCTOR decides.
 * A helmet is for wearing, not for judging: that is the proctor's job.
 *
 * Composer\Semver comes with waynestate/advisor (it requires composer/semver),
 * so this class may use it: the module only ever loads inside ADVISOR.
 */
final class HelmetModule implements Module
{
    public const TOOL = 'helmet';

    /** The line every answer ends with. */
    public const VERDICT = 'HELMET reports; PROCTOR decides.';

    /**
     * Shown in place of the per-request nonce, which only exists inside a
     * request. Base64 letters only: the CSP builder strips anything else.
     */
    public const NONCE_PLACEHOLDER = 'PerRequestNonce';

    private const DOCS = 'https://helmet.apps.wayne.edu/docs/';

    /** The front controllers checked for a helmet_boot() call, in this order. */
    private const ENTRY_POINTS = ['public/index.php', 'index.php', 'web/index.php', 'htdocs/index.php'];

    /** The largest file the wiring check reads; a front controller is far smaller. */
    private const MAX_READ = 262144;

    /**
     * The settings that change the headers, by Laravel env var and by
     * helmet_boot() key (null when the raw PHP path has no such key).
     *
     * @var list<array{env: string, boot: string|null, changes: string}>
     */
    private const SETTINGS = [
        ['env' => 'HELMET_CSP', 'boot' => 'csp', 'changes' => 'Content-Security-Policy, merged over the default per directive (null removes one).'],
        ['env' => 'HELMET_CSP_REPORT_URI', 'boot' => 'csp_report_uri', 'changes' => 'Adds report-to and report-uri to the CSP and sends Reporting-Endpoints.'],
        ['env' => 'HELMET_CSP_REPORT_ONLY', 'boot' => 'csp_report_only', 'changes' => 'Sends the policy as Content-Security-Policy-Report-Only.'],
        ['env' => 'HELMET_CSP_TRUSTED_TYPES_ENABLED', 'boot' => 'csp_trusted_types.enabled', 'changes' => 'Adds the Trusted Types directives to the report-only header.'],
        ['env' => 'HELMET_COOP', 'boot' => 'coop', 'changes' => 'Cross-Origin-Opener-Policy (empty sends none).'],
        ['env' => 'HELMET_CORP', 'boot' => 'corp', 'changes' => 'Cross-Origin-Resource-Policy (off by default).'],
        ['env' => 'HELMET_COEP', 'boot' => 'coep', 'changes' => 'Cross-Origin-Embedder-Policy (off by default).'],
        ['env' => 'HELMET_PERMISSIONS_POLICY', 'boot' => 'permissions_policy', 'changes' => 'Permissions-Policy feature map ({} sends none).'],
        ['env' => 'HELMET_HSTS_MAX_AGE', 'boot' => 'hsts_max_age', 'changes' => 'Strict-Transport-Security max-age.'],
        ['env' => 'HELMET_HSTS_INCLUDE_SUBDOMAINS', 'boot' => 'hsts_include_subdomains', 'changes' => 'Strict-Transport-Security includeSubDomains.'],
        ['env' => 'HELMET_HSTS_PRELOAD', 'boot' => 'hsts_preload', 'changes' => 'Strict-Transport-Security preload (hard to undo).'],
        ['env' => 'HELMET_NO_STORE_ENABLED', 'boot' => 'no_store.enabled', 'changes' => 'Cache-Control: no-store on every response or on no_store.paths.'],
        ['env' => 'HELMET_SCOPE', 'boot' => null, 'changes' => 'Laravel only: "web" limits the headers to the web middleware group.'],
        ['env' => 'HELMET_PROXIES', 'boot' => 'trusted_proxies', 'changes' => 'Which proxies may say the request was HTTPS, and so whether HSTS is sent.'],
        ['env' => 'HELMET_PRESET', 'boot' => 'preset', 'changes' => 'Fills the trusted proxies from a platform preset when HELMET_PROXIES is unset.'],
    ];

    /** Off by default; listed so an agent knows they exist. */
    private const OPTIONAL = ['Cross-Origin-Resource-Policy', 'Reporting-Endpoints', 'Cache-Control', 'Content-Security-Policy-Report-Only'];

    public function __construct(private readonly ModuleContext $context) {}

    public function id(): string
    {
        return 'helmet';
    }

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return [
            self::headersTool($this->context),
            self::phpSupportTool($this->context),
        ];
    }

    /** @return list<\Waynestate\Advisor\Module\ResourceDefinition> */
    public function resources(): array
    {
        return [];
    }

    /** @return list<\Waynestate\Advisor\Module\PromptDefinition> */
    public function prompts(): array
    {
        return [];
    }

    private static function headersTool(ModuleContext $context): ToolDefinition
    {
        return new ToolDefinition(
            name: 'helmet_headers',
            title: 'Explain the security headers HELMET sends',
            description: 'Builds the security headers and Content-Security-Policy HELMET sends with its defaults, using HELMET\'s own SecurityHeaders code in-process, and returns each header and each CSP directive with the reason it is there. It also reports how the app folder is wired to HELMET (the installed version, a published config/helmet.php and whether it was edited, a helmet_boot() call in the front controller) and which settings change the answer. No subprocess, no network, nothing written, and it never reads .env or the environment. The path must be inside the project. HELMET reports; PROCTOR decides.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'App folder, inside the project root. Default: the root itself.'],
                ],
                'additionalProperties' => false,
            ],
            handler: static function (array $args) use ($context): array {
                $app = self::appFolder($context, $args);
                $version = self::helmetVersion();
                $isHttps = true;
                $headers = SecurityHeaders::build(
                    SecurityHeaders::defaultCspPolicy(),
                    self::NONCE_PLACEHOLDER,
                    $isHttps,
                );

                $sent = [];
                foreach ($headers as $name => $value) {
                    $sent[] = [
                        'name' => $name,
                        'value' => $value,
                        'when' => $name === 'Strict-Transport-Security' ? 'HTTPS responses only' : 'every response',
                        'reason' => HeaderReasons::header($name),
                    ];
                }

                $optional = [];
                foreach (self::OPTIONAL as $name) {
                    $optional[] = ['name' => $name, 'reason' => HeaderReasons::header($name)];
                }

                return [
                    'tool' => self::TOOL,
                    'ran' => sprintf(
                        'Built HELMET %s\'s default headers in-process with SecurityHeaders::build() and read the wiring files in %s (no subprocess, no network, nothing written, no .env read).',
                        $version,
                        $context->guard->relative($app),
                    ),
                    'helmet_version' => $version,
                    'app' => $context->guard->relative($app),
                    'wiring' => self::wiring($context, $app),
                    'headers' => $sent,
                    'csp' => [
                        'header' => 'Content-Security-Policy',
                        'nonce' => sprintf('Shown as %s: HELMET makes a fresh one for every request and binds it to script-src, style-src and style-src-elem. Print it on your own <script> and <style> tags.', self::NONCE_PLACEHOLDER),
                        'directives' => self::directives($headers['Content-Security-Policy'] ?? ''),
                    ],
                    'removed' => [
                        ['name' => 'X-Powered-By', 'by' => 'helmet_boot()', 'reason' => HeaderReasons::header('X-Powered-By')],
                    ],
                    'optional' => $optional,
                    'settings' => self::SETTINGS,
                    'source' => [
                        'code' => 'waynestate/helmet ' . $version . ': src/Headers/SecurityHeaders.php (defaultCspPolicy, defaultPermissionsPolicy, build) and src/Headers/HeaderReasons.php',
                        'docs' => self::DOCS . 'guides/headers.html',
                        'csp_guide' => self::DOCS . 'guides/csp.html',
                    ],
                    'notice' => 'Reference data from HELMET ' . $version . '. These are the defaults; the settings listed can change them, and this tool reads none of their values. ' . self::VERDICT,
                ];
            },
            group: 'helmet',
            readOnly: true,
        );
    }

    private static function phpSupportTool(ModuleContext $context): ToolDefinition
    {
        return new ToolDefinition(
            name: 'helmet_php_support',
            title: 'Check the support status of an app\'s PHP floor',
            description: 'Reads the PHP constraint (require.php) and any platform pin (config.platform.php) from the app\'s composer.json, takes the lowest PHP version the constraint allows (the floor), and looks up its support status (active, security-only or end of life) and its dates in the CREED policy ADVISOR bundles, using HELMET\'s own policy reader and the same warning text the environment ribbon shows. It also lists every PHP version in the policy the constraint allows. No subprocess, no network, nothing written. The path must be inside the project. HELMET reports; PROCTOR decides.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'App folder holding composer.json, inside the project root. Default: the root itself.'],
                ],
                'additionalProperties' => false,
            ],
            handler: static function (array $args) use ($context): array {
                $app = self::appFolder($context, $args);
                $composer = self::readComposerJson($app);
                if ($composer === null) {
                    throw new ToolInputError('That folder has no readable composer.json. Give the app folder that holds it.');
                }

                $policyFile = $context->content->policyFile();
                try {
                    $policy = new JsonFilePolicySource($policyFile);
                } catch (PolicyReadException $e) {
                    throw new ToolInputError('ADVISOR\'s bundled CREED policy could not be read: ' . $e->getMessage());
                }
                $today = $context->clock->today();
                $phpVersions = $policy->components()['php'] ?? [];
                uksort($phpVersions, static fn(string $a, string $b): int => version_compare($a, $b));

                $require = $composer['require'] ?? null;
                $constraint = is_array($require) && is_string($require['php'] ?? null) ? trim($require['php']) : null;
                $config = $composer['config'] ?? null;
                $platformValue = is_array($config) && is_array($config['platform'] ?? null) ? ($config['platform']['php'] ?? null) : null;
                $platform = is_string($platformValue) ? trim($platformValue) : null;

                $floor = null;
                $constraintProblem = null;
                $allowed = [];
                if ($constraint !== null && $constraint !== '') {
                    try {
                        $parsed = (new VersionParser())->parseConstraints($constraint);
                        $lower = $parsed->getLowerBound();
                        if (!$lower->isZero()) {
                            $floor = self::minor($lower->getVersion());
                        }
                        foreach (array_keys($phpVersions) as $minor) {
                            if (Semver::satisfies($minor . '.0', $constraint) || Semver::satisfies($minor . '.99', $constraint)) {
                                $allowed[] = $minor;
                            }
                        }
                    } catch (\UnexpectedValueException $e) {
                        $constraintProblem = 'Composer could not parse the constraint: ' . $e->getMessage();
                    }
                }

                $status = new VersionStatus($today, $policy->components());
                $warning = new VersionWarning($policy, $today);
                $describe = static fn(string $minor): array => self::describe($minor, $status, $warning, $phpVersions, $today);

                $pinned = $platform !== null && $platform !== '' ? self::minor($platform) : null;

                return [
                    'tool' => self::TOOL,
                    'ran' => sprintf(
                        'Read composer.json in %s and looked the PHP floor up in ADVISOR\'s bundled CREED policy with HELMET\'s JsonFilePolicySource and VersionStatus, for %s (no subprocess, no network, nothing written).',
                        $context->guard->relative($app),
                        $today->format('Y-m-d'),
                    ),
                    'helmet_version' => self::helmetVersion(),
                    'app' => $context->guard->relative($app),
                    'date' => $today->format('Y-m-d'),
                    'constraint' => $constraint,
                    'constraint_problem' => $constraintProblem,
                    'floor' => $floor === null ? null : $describe($floor),
                    'platform_pin' => $pinned === null ? null : ['declared' => $platform] + $describe($pinned),
                    'allowed' => array_map($describe, $allowed),
                    'summary' => self::summary($constraint, $constraintProblem, $floor, $floor === null ? null : $describe($floor)),
                    'source' => [
                        'policy' => $context->content->source('policy.json'),
                        'policy_refreshed_on' => self::refreshedOn($policyFile),
                        'bundle_generated_on' => $context->content->generatedOn(),
                        'read_with' => 'waynestate/helmet: src/Policy/JsonFilePolicySource.php, VersionStatus.php and VersionWarning.php',
                        'app_file' => ($context->guard->relative($app) === '.' ? '' : $context->guard->relative($app) . '/') . 'composer.json',
                    ],
                    'notice' => 'Reference data from HELMET ' . self::helmetVersion() . ' and the CREED policy ADVISOR bundles. ' . self::VERDICT . ' PROCTOR checks the same policy in CI.',
                ];
            },
            group: 'helmet',
            readOnly: true,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function appFolder(ModuleContext $context, array $args): string
    {
        $app = $context->guard->resolve((string) ($args['path'] ?? '.'));
        if (!is_dir($app)) {
            throw new ToolInputError('The path must be a directory that holds the app.');
        }

        return $app;
    }

    /**
     * Split a compiled CSP header value back into its directives, so the
     * answer shows exactly what goes on the wire (nonce and strict-dynamic
     * included), each with its reason.
     *
     * @return list<array{directive: string, sources: list<string>, reason: string|null}>
     */
    private static function directives(string $csp): array
    {
        $out = [];
        foreach (explode(';', $csp) as $part) {
            $tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false || $tokens === []) {
                continue;
            }
            $directive = strtolower(array_shift($tokens));
            $out[] = [
                'directive' => $directive,
                'sources' => $tokens,
                'reason' => HeaderReasons::directive($directive),
            ];
        }

        // The builder compiles in its own order; list them in the order
        // defaultCspPolicy() declares them (the order the docs and the source
        // explain them in), with anything else after, in wire order.
        $order = array_flip(array_keys(SecurityHeaders::defaultCspPolicy()));
        $wire = array_flip(array_column($out, 'directive'));
        usort($out, static fn(array $a, array $b): int => [$order[$a['directive']] ?? PHP_INT_MAX, $wire[$a['directive']]]
            <=> [$order[$b['directive']] ?? PHP_INT_MAX, $wire[$b['directive']]]);

        return $out;
    }

    /**
     * How the app is wired to HELMET, from three files it already has. Each
     * finding names the file it came from.
     *
     * @return array{installed: string|null, laravel: bool, published_config: string, boot_calls: list<string>, notes: list<string>}
     */
    private static function wiring(ModuleContext $context, string $app): array
    {
        $notes = [];

        $installed = null;
        $laravel = false;
        $lock = self::readJson($app . '/composer.lock');
        if ($lock !== null) {
            foreach (['packages', 'packages-dev'] as $group) {
                foreach (is_array($lock[$group] ?? null) ? $lock[$group] : [] as $package) {
                    if (!is_array($package)) {
                        continue;
                    }
                    if (($package['name'] ?? null) === 'waynestate/helmet' && is_string($package['version'] ?? null)) {
                        $installed = $package['version'];
                    }
                    if (($package['name'] ?? null) === 'laravel/framework') {
                        $laravel = true;
                    }
                }
            }
            $notes[] = $installed === null
                ? 'composer.lock does not list waynestate/helmet: the headers listed are what HELMET would send once installed.'
                : 'composer.lock pins waynestate/helmet ' . $installed . '.';
        } else {
            $notes[] = 'No composer.lock in the app folder, so the installed HELMET version is unknown.';
        }

        $published = 'none';
        $appConfig = $app . '/config/helmet.php';
        if (is_file($appConfig)) {
            $shipped = dirname(__DIR__, 2) . '/config/helmet.php';
            $same = is_file($shipped) && hash_file('sha256', $appConfig) === hash_file('sha256', $shipped);
            $published = $same ? 'matches the shipped default' : 'edited';
            $notes[] = $same
                ? 'config/helmet.php is published and unchanged, so only the HELMET_* settings listed under settings can change the headers.'
                : 'config/helmet.php differs from the copy this HELMET ships (edited, or published from another version): read its "csp" and header keys, they override the defaults listed.';
        } elseif ($laravel) {
            $notes[] = 'Laravel app with no published config/helmet.php: the package config applies, driven by the HELMET_* settings listed under settings.';
        }

        $bootCalls = [];
        foreach (self::ENTRY_POINTS as $entry) {
            $file = $app . '/' . $entry;
            if (!is_file($file) || filesize($file) > self::MAX_READ) {
                continue;
            }
            $code = file_get_contents($file);
            if (is_string($code) && str_contains($code, 'helmet_boot(')) {
                $bootCalls[] = $entry;
            }
        }
        foreach ($bootCalls as $entry) {
            $notes[] = $entry . ' calls helmet_boot(): the array it passes overrides the defaults listed (the keys are under settings).';
        }

        return [
            'installed' => $installed,
            'laravel' => $laravel,
            'published_config' => $published,
            'boot_calls' => $bootCalls,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, array{active_until: string, security_until: string}> $phpVersions
     *
     * @return array{version: string, status: string, active_until: string|null, security_until: string|null, days_of_security_left: int|null, warning: string|null}
     */
    private static function describe(string $minor, VersionStatus $status, VersionWarning $warning, array $phpVersions, DateTimeImmutable $today): array
    {
        $dates = $phpVersions[$minor] ?? null;
        $securityUntil = $status->securityUntil('php', $minor);

        return [
            'version' => $minor,
            'status' => $status->status('php', $minor),
            // A version CREED lists without active_until is read as security-only for its whole life.
            'active_until' => $dates === null || $dates['active_until'] === '1970-01-01' ? null : $dates['active_until'],
            'security_until' => $dates['security_until'] ?? null,
            'days_of_security_left' => $securityUntil === null ? null : (int) $today->diff($securityUntil)->format('%r%a'),
            'warning' => $warning->warning('php', $minor),
        ];
    }

    /**
     * One sentence an agent can repeat to a person.
     *
     * @param array{version: string, status: string, active_until: string|null, security_until: string|null, days_of_security_left: int|null, warning: string|null}|null $floor
     */
    private static function summary(?string $constraint, ?string $problem, ?string $floorVersion, ?array $floor): string
    {
        if ($constraint === null || $constraint === '') {
            return 'composer.json declares no PHP constraint (require.php), so there is no floor to check. Declare one, for example "php": "^8.3".';
        }
        if ($problem !== null) {
            return $problem;
        }
        if ($floorVersion === null || $floor === null) {
            return sprintf('The constraint %s has no lower bound, so it allows every PHP version. Give it a floor, for example "^8.3".', $constraint);
        }

        return match ($floor['status']) {
            VersionStatus::ACTIVE => sprintf('The floor, PHP %s (from %s), is in active support until %s and gets security fixes until %s.', $floorVersion, $constraint, $floor['active_until'] ?? 'unknown', $floor['security_until'] ?? 'unknown'),
            VersionStatus::SECURITY => sprintf('The floor, PHP %s (from %s), is security-only: fixes stop on %s (%d days). Plan the move to a version in active support.', $floorVersion, $constraint, $floor['security_until'] ?? 'unknown', $floor['days_of_security_left'] ?? 0),
            VersionStatus::EOL => sprintf('The floor, PHP %s (from %s), is end of life: security fixes stopped on %s. Raise the floor.', $floorVersion, $constraint, $floor['security_until'] ?? 'unknown'),
            default => sprintf('The floor, PHP %s (from %s), is not in the CREED policy, so its support status is unknown.', $floorVersion, $constraint),
        };
    }

    /** "8.3.0.0", "8.3.0" or "8.3" to "8.3". */
    private static function minor(string $version): string
    {
        $parts = explode('.', ltrim($version, 'vV'));

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /** @return array<string, mixed>|null */
    private static function readComposerJson(string $app): ?array
    {
        return self::readJson($app . '/composer.json');
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file) || filesize($file) > 8 * self::MAX_READ) {
            return null;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    private static function refreshedOn(string $policyFile): ?string
    {
        $data = self::readJson($policyFile);
        $date = $data['refreshed_on'] ?? null;

        return is_string($date) ? $date : null;
    }

    /** The installed HELMET version as tagged, or "unknown". */
    private static function helmetVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('waynestate/helmet') ?? 'unknown';
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }
}
