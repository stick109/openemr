<?php

/**
 * Anonymizer
 *
 * Server-side PHI redactor used immediately before durable logging. The LLM
 * provider operates under a signed BAA, so model input is sent without
 * placeholder substitution; only payloads bound for `api_log` or other
 * durable sinks pass through this component.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use Psr\Log\LoggerInterface;
use Throwable;

final class Anonymizer
{
    private const DEFAULT_TOKEN_LIFETIME_SECONDS = 900;

    /**
     * Fields whose values are operational metadata, not model-visible direct identifiers.
     *
     * @var array<string, bool>
     */
    private const SAFE_FIELDS = [
        'button_label' => true,
        'caps' => true,
        'certainty' => true,
        'checked_evidence' => true,
        'citation_ids' => true,
        'data_class' => true,
        'date' => true,
        'error_class' => true,
        'fields_used' => true,
        'followup_intents' => true,
        'heading' => true,
        'intent_id' => true,
        'latency_ms' => true,
        'lookback_days' => true,
        'max_documents' => true,
        'max_records' => true,
        'record_id' => true,
        'record_uuid' => true,
        'reliability' => true,
        'request_id' => true,
        'response_generation' => true,
        'source_count' => true,
        'source_id' => true,
        'source_type' => true,
        'status' => true,
        'table' => true,
        'tool' => true,
        'tool_runs' => true,
    ];

    /**
     * @var array<string, array{
     *     token_issued_at: int,
     *     expires_at: int,
     *     map: array<string, array<string, string>>,
     *     counters: array<string, int>
     * }>
     */
    private array $scopes = [];

    /**
     * @var callable(string, string, string, int, string, ?int): void
     */
    private $auditLogger;

    public function __construct(
        private readonly int $tokenLifetimeSeconds = self::DEFAULT_TOKEN_LIFETIME_SECONDS,
        private readonly LoggerInterface $logger = new SystemLogger(),
        ?callable $auditLogger = null
    ) {
        $this->auditLogger = $auditLogger ?? static function (
            string $event,
            string $user,
            string $groupname,
            int $success,
            string $comments,
            ?int $patientId
        ): void {
            EventAuditLogger::getInstance()->newEvent($event, $user, $groupname, $success, $comments, $patientId);
        };
    }

    /**
     * @param array<string, mixed> $packet
     * @return array<string, mixed>
     */
    public function anonymizeEvidencePacket(AgentAccessToken $accessToken, array $packet): array
    {
        try {
            $anonymized = $this->anonymizeValue($accessToken, $packet, '');
            $result = is_array($anonymized) ? $anonymized : [];
            $this->logger->debug('agent.anonymizer.completed', [
                'mode' => 'evidence_packet',
                'placeholder_count' => $this->placeholderCount($accessToken),
            ]);
            return $result;
        } catch (Throwable $exception) {
            $this->reportFailure($accessToken, 'evidence_packet', $exception);
            throw $exception;
        }
    }

    public function anonymizePayload(AgentAccessToken $accessToken, mixed $payload): mixed
    {
        try {
            $result = $this->anonymizeValue($accessToken, $payload, '');
            $this->logger->debug('agent.anonymizer.completed', [
                'mode' => 'payload',
                'placeholder_count' => $this->placeholderCount($accessToken),
            ]);
            return $result;
        } catch (Throwable $exception) {
            $this->reportFailure($accessToken, 'payload', $exception);
            throw $exception;
        }
    }

    public function placeholderCount(AgentAccessToken $accessToken): int
    {
        $tokenId = $accessToken->getTokenId();
        if (!isset($this->scopes[$tokenId])) {
            return 0;
        }

        $count = 0;
        foreach ($this->scopes[$tokenId]['map'] as $categoryMap) {
            $count += count($categoryMap);
        }

        return $count;
    }

    public function purgeExpired(?int $now = null): void
    {
        $now ??= time();
        foreach ($this->scopes as $tokenId => $scope) {
            if ($scope['expires_at'] <= $now) {
                unset($this->scopes[$tokenId]);
            }
        }
    }

    private function anonymizeValue(AgentAccessToken $accessToken, mixed $value, string $fieldName): mixed
    {
        $normalizedField = $this->normalizeFieldName($fieldName);
        $directCategory = $this->directCategoryForField($normalizedField);

        if (is_array($value)) {
            $anonymized = [];
            foreach ($value as $key => $item) {
                $childFieldName = is_string($key) ? $key : $fieldName;
                $anonymized[$key] = $this->anonymizeValue($accessToken, $item, $childFieldName);
            }

            return $anonymized;
        }

        if (is_scalar($value)) {
            $stringValue = trim((string) $value);
            if ($directCategory !== null && $stringValue !== '') {
                return $this->placeholderFor($accessToken, $directCategory, $stringValue);
            }

            if (is_string($value) && !isset(self::SAFE_FIELDS[$normalizedField])) {
                return $this->anonymizeText($accessToken, $value);
            }
        }

        return $value;
    }

    private function anonymizeText(AgentAccessToken $accessToken, string $text): string
    {
        $text = preg_replace_callback(
            '/\b((?:SSN|Social Security(?: number)?)\s*[:#]?\s*)(\d{3}[- ]?\d{2}[- ]?\d{4}|\d{9})\b/i',
            fn (array $matches): string => $matches[1] . $this->placeholderFor($accessToken, 'ssn', $matches[2]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            fn (array $matches): string => $this->placeholderFor($accessToken, 'email', $matches[0]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b\d{3}-\d{2}-\d{4}\b/',
            fn (array $matches): string => $this->placeholderFor($accessToken, 'ssn', $matches[0]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<![A-Za-z0-9])(?:\+?1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}(?![A-Za-z0-9])/',
            fn (array $matches): string => $this->placeholderFor($accessToken, 'phone', $matches[0]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b\d{1,6}\s+(?:[A-Za-z0-9.\'#-]+\s+){0,6}(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Court|Ct|Circle|Cir|Way|Parkway|Pkwy|Terrace|Ter|Place|Pl|Square|Sq|Trail|Trl)\.?(?:\s+(?:Apt|Apartment|Suite|Ste|Unit|#)\s*[A-Za-z0-9-]+)?\b/i',
            fn (array $matches): string => $this->placeholderFor($accessToken, 'address', $matches[0]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b((?:insurance|member|policy|subscriber)\s*(?:id|number|no\.?|#)\s*[:#]?\s*)([A-Z0-9][A-Z0-9._-]{4,})\b/i',
            fn (array $matches): string => $matches[1] . $this->placeholderFor($accessToken, 'insurance_id', $matches[2]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b((?:MRN|medical record(?: number)?|patient id|public id|pubpid|chart(?: number)?|account(?: number)?)\s*[:#]?\s*)([A-Z0-9][A-Z0-9._-]{3,})\b/i',
            fn (array $matches): string => $matches[1] . $this->placeholderFor($accessToken, 'identifier', $matches[2]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b((?:patient|pt|name)\s*[:#]?\s*)([A-Z][a-z]+(?:[-\'][A-Z][a-z]+)?(?:\s+[A-Z][a-z]+(?:[-\'][A-Z][a-z]+)?){1,3})\b/i',
            fn (array $matches): string => $matches[1] . $this->placeholderFor($accessToken, 'patient_name', $matches[2]),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b([A-Z][a-z]+(?:[-\'][A-Z][a-z]+)?(?:\s+[A-Z][a-z]+(?:[-\'][A-Z][a-z]+)?){1,3})(\s+(?:called|reports|reported|presented|arrived|visited|lives|was|is)\b)/',
            fn (array $matches): string => $this->placeholderFor($accessToken, 'patient_name', $matches[1]) . $matches[2],
            $text
        ) ?? $text;

        return $text;
    }

    private function directCategoryForField(string $fieldName): ?string
    {
        if ($fieldName === '') {
            return null;
        }

        if (isset(self::SAFE_FIELDS[$fieldName])) {
            return null;
        }

        if (in_array($fieldName, ['ssn', 'social_security_number'], true)) {
            return 'ssn';
        }

        if (str_contains($fieldName, 'email')) {
            return 'email';
        }

        if (str_contains($fieldName, 'phone') || str_contains($fieldName, 'fax')) {
            return 'phone';
        }

        if (
            str_contains($fieldName, 'address')
            || str_contains($fieldName, 'street')
            || in_array($fieldName, ['line1', 'line2', 'addr1', 'addr2'], true)
        ) {
            return 'address';
        }

        if (
            str_contains($fieldName, 'insurance_id')
            || str_contains($fieldName, 'member_id')
            || str_contains($fieldName, 'policy_number')
            || str_contains($fieldName, 'subscriber_id')
        ) {
            return 'insurance_id';
        }

        if (
            in_array($fieldName, ['patient_name', 'full_name', 'first_name', 'last_name', 'fname', 'lname', 'mname'], true)
            || str_contains($fieldName, 'subscriber_name')
            || str_contains($fieldName, 'guardian_name')
        ) {
            return 'patient_name';
        }

        if (
            in_array($fieldName, ['mrn', 'pubpid', 'patient_uuid', 'patient_identifier'], true)
            || str_contains($fieldName, 'medical_record_number')
            || str_contains($fieldName, 'chart_number')
            || str_contains($fieldName, 'account_number')
        ) {
            return 'identifier';
        }

        return null;
    }

    private function placeholderFor(AgentAccessToken $accessToken, string $category, string $rawValue): string
    {
        $normalizedValue = $this->normalizeRawValue($rawValue);
        if ($normalizedValue === '') {
            return '';
        }

        $scope =& $this->scopeFor($accessToken);
        if (isset($scope['map'][$category][$normalizedValue])) {
            return $scope['map'][$category][$normalizedValue];
        }

        $scope['counters'][$category] = ($scope['counters'][$category] ?? 0) + 1;
        $placeholder = $this->newPlaceholder($category, $scope['counters'][$category]);
        $scope['map'][$category][$normalizedValue] = $placeholder;

        return $placeholder;
    }

    private function newPlaceholder(string $category, int $counter): string
    {
        return match ($category) {
            'patient_name' => $counter === 1 ? '[PATIENT_NAME]' : '[PATIENT_NAME_' . $counter . ']',
            'ssn' => $counter === 1 ? '[PATIENT_SSN]' : '[PATIENT_SSN_' . $counter . ']',
            'address' => '[PATIENT_ADDRESS_' . $counter . ']',
            'phone' => '[PATIENT_PHONE_' . $counter . ']',
            'email' => '[PATIENT_EMAIL_' . $counter . ']',
            'insurance_id' => '[INSURANCE_ID_' . $counter . ']',
            default => '[REDACTED_IDENTIFIER_' . $counter . ']',
        };
    }

    /**
     * @return array{
     *     token_issued_at: int,
     *     expires_at: int,
     *     map: array<string, array<string, string>>,
     *     counters: array<string, int>
     * }
     */
    private function &scopeFor(AgentAccessToken $accessToken): array
    {
        $tokenId = $accessToken->getTokenId();
        $tokenIssuedAt = $accessToken->getIssuedAt();
        if (
            !isset($this->scopes[$tokenId])
            || $this->scopes[$tokenId]['token_issued_at'] !== $tokenIssuedAt
        ) {
            $this->scopes[$tokenId] = [
                'token_issued_at' => $tokenIssuedAt,
                'expires_at' => time() + max(1, $this->tokenLifetimeSeconds),
                'map' => [],
                'counters' => [],
            ];
        }

        return $this->scopes[$tokenId];
    }

    private function normalizeFieldName(string $fieldName): string
    {
        $normalized = strtolower(trim($fieldName));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return $normalized === null ? '' : trim($normalized, '_');
    }

    private function normalizeRawValue(string $rawValue): string
    {
        $normalized = strtolower(trim($rawValue));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return $normalized === null ? '' : $normalized;
    }

    private function reportFailure(AgentAccessToken $accessToken, string $mode, Throwable $exception): void
    {
        $this->logger->error('agent.anonymizer.failure', [
            'mode' => $mode,
            'error_class' => $exception::class,
            'exception' => $exception,
        ]);

        try {
            ($this->auditLogger)(
                'agent-anonymizer-failure',
                'agent',
                'agent',
                0,
                sprintf('agent_anonymizer mode=%s error=%s', $mode, $exception::class),
                $accessToken->getPatientContext()->getPid()
            );
        } catch (\RuntimeException $auditException) {
            // Audit-side runtime failures (e.g. DB writes) must not mask the
            // original exception. \Error and \ErrorException are intentionally
            // left to propagate per project policy — they signal real bugs.
            $this->logger->error('agent.anonymizer.audit_failure', [
                'mode' => $mode,
                'error_class' => $auditException::class,
            ]);
        }
    }
}
