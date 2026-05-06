<?php

/**
 * MedicalHistoryDispatcher
 *
 * Persists a Medical History intake form into the FHIR-shaped
 * `questionnaire_response` table and the encounter-form
 * `form_questionnaire_assessments` table.
 *
 * The dispatcher is intentionally minimal: it builds a small FHIR
 * `QuestionnaireResponse` JSON envelope describing the answers and stores
 * it. It does *not* exercise the full
 * {@see \OpenEMR\Services\QuestionnaireResponseService} pipeline, which is
 * tightly coupled to LHC-Forms-driven UI flows.
 *
 * The encounter-timeline `forms` row is registered exclusively by
 * `interface/forms/upload_intake_form/save.php` via
 * {@see \OpenEMR\Services\FormService::addForm()} after this dispatcher
 * returns — registering it here too would produce a duplicate timeline row
 * with stale metadata (wrong `form_id`, numeric `user`).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Dispatcher;

use JsonException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use OpenEMR\Services\Intake\Fhir\QuestionnaireResponseBuilder;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class MedicalHistoryDispatcher
{
    private const FORM_NAME = 'Medical History (Intake Upload)';
    private const QUESTIONNAIRE_NAME = QuestionnaireResponseBuilder::QUESTIONNAIRE_NAME;

    private readonly QuestionnaireResponseBuilder $responseBuilder;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        ?QuestionnaireResponseBuilder $responseBuilder = null,
    ) {
        $this->responseBuilder = $responseBuilder ?? new QuestionnaireResponseBuilder();
    }

    /**
     * @param array<array-key, mixed> $extracted
     */
    public function dispatch(int $patientId, int $encounterId, int $authUserId, array $extracted): DispatchOutcome
    {
        if ($encounterId <= 0) {
            throw new IngestionFailedException('Medical history requires a current encounter.');
        }

        $now = $this->clock->now();
        $responseId = $this->newResponseId();

        $questionnaireJson = $this->buildQuestionnaireDefinition();
        $responseJson = $this->responseBuilder->build(
            patientId: $patientId,
            payload: $extracted,
            authoredAtIso: $now->format(DATE_ATOM),
            encounterId: $encounterId,
            responseId: $responseId,
        );

        try {
            $questionnaireSerialised = json_encode($questionnaireJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $responseSerialised = json_encode($responseJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new IngestionFailedException(
                'Failed to serialise medical-history questionnaire JSON.',
                $exception
            );
        }

        $questionnaireResponseId = $this->insertQuestionnaireResponse(
            responseId: $responseId,
            patientId: $patientId,
            encounterId: $encounterId,
            authUserId: $authUserId,
            questionnaireJson: $questionnaireSerialised,
            responseJson: $responseSerialised,
            now: $now->format('Y-m-d H:i:s'),
        );

        $assessmentId = $this->insertAssessment(
            patientId: $patientId,
            authUserId: $authUserId,
            responseId: $responseId,
            questionnaireJson: $questionnaireSerialised,
            responseJson: $responseSerialised,
            now: $now->format('Y-m-d H:i:s'),
        );

        $diff = $this->buildDiff($extracted);

        $this->logger->info('Medical history ingested', [
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'response_id' => $responseId,
            'questionnaire_response_id' => $questionnaireResponseId,
            'assessment_id' => $assessmentId,
        ]);

        return new DispatchOutcome(
            insertedRowId: $questionnaireResponseId,
            diffPreview: $diff,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuestionnaireDefinition(): array
    {
        return [
            'resourceType' => 'Questionnaire',
            'id' => self::QUESTIONNAIRE_NAME,
            'status' => 'active',
            'name' => self::QUESTIONNAIRE_NAME,
            'title' => 'Intake Medical History',
            'item' => [
                ['linkId' => 'conditions', 'text' => 'Current medical conditions', 'type' => 'string', 'repeats' => true],
                ['linkId' => 'surgeries', 'text' => 'Past surgeries', 'type' => 'string', 'repeats' => true],
                ['linkId' => 'medications', 'text' => 'Current medications', 'type' => 'string', 'repeats' => true],
                ['linkId' => 'allergies', 'text' => 'Allergies', 'type' => 'string', 'repeats' => true],
                ['linkId' => 'familyHistory', 'text' => 'Family medical history', 'type' => 'string', 'repeats' => true],
                ['linkId' => 'social.smoking', 'text' => 'Smoking status', 'type' => 'string'],
                ['linkId' => 'social.alcohol', 'text' => 'Alcohol use', 'type' => 'string'],
                ['linkId' => 'social.drugs', 'text' => 'Recreational drug use', 'type' => 'string'],
            ],
        ];
    }

    private function insertQuestionnaireResponse(
        string $responseId,
        int $patientId,
        int $encounterId,
        int $authUserId,
        string $questionnaireJson,
        string $responseJson,
        string $now,
    ): int {
        $sql = 'INSERT INTO `questionnaire_response`
            (`response_id`, `questionnaire_id`, `questionnaire_name`, `audit_user_id`,
             `creator_user_id`, `create_time`, `last_updated`, `version`, `status`,
             `questionnaire`, `questionnaire_response`, `patient_id`, `encounter`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)';
        $bindings = [
            $responseId,
            self::QUESTIONNAIRE_NAME,
            self::QUESTIONNAIRE_NAME,
            $authUserId,
            $authUserId,
            $now,
            $now,
            'completed',
            $questionnaireJson,
            $responseJson,
            $patientId,
            $encounterId,
        ];

        try {
            return QueryUtils::sqlInsert($sql, $bindings);
        } catch (Throwable $exception) {
            throw new IngestionFailedException(
                'Failed to persist questionnaire response.',
                $exception
            );
        }
    }

    private function insertAssessment(
        int $patientId,
        int $authUserId,
        string $responseId,
        string $questionnaireJson,
        string $responseJson,
        string $now,
    ): int {
        $sql = 'INSERT INTO `form_questionnaire_assessments`
            (`date`, `response_id`, `pid`, `user`, `groupname`, `authorized`, `activity`,
             `form_name`, `questionnaire_id`, `questionnaire`, `questionnaire_response`)
            VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?)';
        $bindings = [
            $now,
            $responseId,
            $patientId,
            (string) $authUserId,
            'Default',
            self::FORM_NAME,
            self::QUESTIONNAIRE_NAME,
            $questionnaireJson,
            $responseJson,
        ];

        try {
            return QueryUtils::sqlInsert($sql, $bindings);
        } catch (Throwable $exception) {
            throw new IngestionFailedException(
                'Failed to persist medical-history assessment row.',
                $exception
            );
        }
    }

    /**
     * @param array<array-key, mixed> $extracted
     * @return list<DiffEntry>
     */
    private function buildDiff(array $extracted): array
    {
        $diff = [];
        foreach (['conditions', 'surgeries', 'medications', 'allergies', 'familyHistory'] as $key) {
            $values = $this->stringList($extracted, $key);
            $diff[] = new DiffEntry(
                field: $key,
                oldValue: null,
                newValue: implode('; ', $values),
                applied: $values !== [],
            );
        }
        foreach (['smoking', 'alcohol', 'drugs'] as $socialKey) {
            $value = $this->scalarString($extracted, 'social.' . $socialKey);
            $diff[] = new DiffEntry(
                field: 'social.' . $socialKey,
                oldValue: null,
                newValue: $value,
                applied: $value !== null,
            );
        }

        return $diff;
    }

    /**
     * @param array<array-key, mixed> $extracted
     * @return list<string>
     */
    private function stringList(array $extracted, string $key): array
    {
        $value = $extracted[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $extracted
     */
    private function scalarString(array $extracted, string $dottedPath): ?string
    {
        $cursor = $extracted;
        foreach (explode('.', $dottedPath) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        if (!is_string($cursor)) {
            return null;
        }
        $trimmed = trim($cursor);

        return $trimmed === '' ? null : $trimmed;
    }

    private function newResponseId(): string
    {
        $bytes = random_bytes(16);
        // RFC 4122 v4: clear top nibble of byte 6 and OR 0x40
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
