<?php

/**
 * @package   OpenEMR
 *
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core;

use OpenEMR\Core\Traits\SingletonTrait;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Typed access to environment variables. Extends Symfony ParameterBag.
 *
 * Merges values from $_SERVER, $_ENV, and getenv() (later sources win),
 * matching the priority used by Symfony DotEnv.
 *
 * Inherits typed getters from ParameterBag:
 *
 * @see ParameterBag::getString()   getString(string $key, string $default = ''): string
 * @see ParameterBag::getInt()      getInt(string $key, int $default = 0): int
 * @see ParameterBag::getBoolean()  getBoolean(string $key, bool $default = false): bool
 * @see ParameterBag::getAlpha()    getAlpha(string $key, string $default = ''): string
 * @see ParameterBag::getAlnum()    getAlnum(string $key, string $default = ''): string
 * @see ParameterBag::getDigits()   getDigits(string $key, string $default = ''): string
 * @see ParameterBag::getEnum()     getEnum(string $key, string $class, ?BackedEnum $default = null): ?BackedEnum
 */
class OEEnvBag extends ParameterBag
{
    use SingletonTrait;

    /**
     * UTF-8 BOM bytes (EF BB BF). Pasting an env var into Railway / a .env
     * editor from a UTF-8-with-BOM source, or staging a secret via a
     * PowerShell script that writes the BOM-emitting default UTF-8 encoder,
     * leaks the BOM into the value. Symfony's HttpClient validates
     * `auth_bearer` against a strict character set and rejects the BOM,
     * OAuth servers compare client_ids byte-for-byte, HMAC verifiers see a
     * 3-byte mismatch — and the user sees a generic error with no obvious
     * cause. Strip the BOM at the boundary so every downstream consumer is
     * covered by one defense, instead of repeating the trim per call site.
     */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        parent::__construct(self::stripUtf8Boms($parameters));
    }

    protected static function createInstance(): static
    {
        // `getenv()` with no arguments always returns an array<string, string>;
        // the string-returning overload requires a name argument.
        return new static(array_merge($_SERVER, $_ENV, getenv())); // @phpstan-ignore new.static
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function stripUtf8Boms(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_string($value) && str_starts_with($value, self::UTF8_BOM)) {
                $parameters[$key] = substr($value, strlen(self::UTF8_BOM));
            }
        }

        return $parameters;
    }
}
