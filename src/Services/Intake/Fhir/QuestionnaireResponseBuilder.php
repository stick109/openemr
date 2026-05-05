<?php

/**
 * QuestionnaireResponseBuilder
 *
 * Pure builder for the FHIR `QuestionnaireResponse` JSON envelope produced
 * from an extracted MedicalHistory payload. No DB writes, no I/O — the
 * builder takes a patient id plus the payload returned by OpenAI and
 * returns a FHIR-shaped associative array the caller can persist or wrap.
 *
 * The shape matches FHIR R4. Item link ids are the same dotted keys used by
 * the IntakeJsonSchemas medical-history schema, so the builder's output is
 * deterministically inspectable by tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Fhir;

final class QuestionnaireResponseBuilder
{
    public const QUESTIONNAIRE_NAME = 'IntakeMedicalHistory';

    private const LIST_KEYS = [
        'conditions',
        'surgeries',
        'medications',
        'allergies',
        'familyHistory',
    ];

    private const SOCIAL_KEYS = ['smoking', 'alcohol', 'drugs'];

    /**
     * Build a FHIR `QuestionnaireResponse` resource from a MedicalHistory
     * payload.
     *
     * The payload follows the {@see \OpenEMR\Services\Intake\Schema\IntakeJsonSchemas::medicalHistory()}
     * shape: top-level list keys (conditions, surgeries, ...) plus a `social`
     * object with smoking / alcohol / drugs string fields.
     *
     * @param array<array-key, mixed> $payload
     * @param ?string $authoredAtIso ISO-8601 timestamp; defaults to "now"
     *                               when null (left injectable for tests).
     * @param ?int $encounterId Optional encounter to reference; omitted from
     *                          the output when null.
     * @return array<string, mixed>
     */
    public function build(
        int $patientId,
        array $payload,
        ?string $authoredAtIso = null,
        ?int $encounterId = null,
        ?string $responseId = null,
    ): array {
        $items = [];

        foreach (self::LIST_KEYS as $key) {
            $values = $this->stringList($payload, $key);
            if ($values === []) {
                continue;
            }
            $items[] = [
                'linkId' => $key,
                'answer' => array_map(
                    static fn(string $value): array => ['valueString' => $value],
                    $values
                ),
            ];
        }

        $socialAnswers = [];
        foreach (self::SOCIAL_KEYS as $socialKey) {
            $value = $this->scalarString($payload, ['social', $socialKey]);
            if ($value === null) {
                continue;
            }
            $socialAnswers[] = [
                'linkId' => 'social.' . $socialKey,
                'text' => ucfirst($socialKey),
                'answer' => [['valueString' => $value]],
            ];
        }
        if ($socialAnswers !== []) {
            $items[] = [
                'linkId' => 'social',
                'text' => 'Social history',
                'item' => $socialAnswers,
            ];
        }

        $resource = [
            'resourceType' => 'QuestionnaireResponse',
            'status' => 'completed',
            'questionnaire' => 'Questionnaire/' . self::QUESTIONNAIRE_NAME,
            'subject' => ['reference' => 'Patient/' . $patientId],
            'authored' => $authoredAtIso ?? gmdate('c'),
            'item' => $items,
        ];

        if ($responseId !== null && $responseId !== '') {
            $resource['id'] = $responseId;
        }

        if ($encounterId !== null && $encounterId > 0) {
            $resource['encounter'] = ['reference' => 'Encounter/' . $encounterId];
        }

        return $resource;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<string>
     */
    private function stringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param non-empty-list<string> $path
     */
    private function scalarString(array $payload, array $path): ?string
    {
        $cursor = $payload;
        foreach ($path as $segment) {
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
}
