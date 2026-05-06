<?php

/**
 * CopilotRunContextVerificationException
 *
 * Raised by {@see CopilotRunContextVerifier::verify()} when a wire token
 * fails verification. Carries a typed reason so callers can map every
 * failure mode to a fail-closed response without inspecting message strings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Copilot;

final class CopilotRunContextVerificationException extends \RuntimeException
{
    public const REASON_MALFORMED = 'malformed';
    public const REASON_BAD_SIGNATURE = 'bad_signature';
    public const REASON_TAMPERED = 'tampered';
    public const REASON_UNKNOWN_KEY_VERSION = 'unknown_key_version';
    public const REASON_EXPIRED = 'expired';

    /**
     * @param self::REASON_* $reason
     */
    private function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function malformed(string $message, ?\Throwable $previous = null): self
    {
        return new self(self::REASON_MALFORMED, $message, $previous);
    }

    public static function tampered(string $message): self
    {
        return new self(self::REASON_TAMPERED, $message);
    }

    public static function expired(string $message): self
    {
        return new self(self::REASON_EXPIRED, $message);
    }

    public static function unknownKeyVersion(string $message): self
    {
        return new self(self::REASON_UNKNOWN_KEY_VERSION, $message);
    }
}
