<?php

/**
 * AgentCurrentPatientResolver
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use OpenEMR\Common\Http\HttpRestRequest;
use Throwable;

final class AgentCurrentPatientResolver
{
    private const CLIENT_PATIENT_CONTEXT_FIELDS = [
        'active_pid',
        'patient',
        'patient_context',
        'patient_id',
        'patient_uuid',
        'pid',
        'puuid',
        'selected_patient',
        'selected_pid',
    ];

    /**
     * @param array<mixed> $payload
     */
    public function resolve(HttpRestRequest $request, array $payload): AgentPatientResolution
    {
        $tamperedFields = $this->findClientPatientContextFields($request, $payload);
        if ($tamperedFields !== []) {
            return AgentPatientResolution::denied(
                'patient_context_tampered',
                'Patient context must come from the authenticated server session.'
            );
        }

        if (!$request->hasSession()) {
            return AgentPatientResolution::denied(
                'missing_session',
                'Agent access requires an authenticated server session.'
            );
        }

        try {
            $sessionPid = $request->getSession()->get('pid');
        } catch (Throwable) {
            return AgentPatientResolution::denied(
                'missing_session',
                'Agent access requires an authenticated server session.'
            );
        }

        if (is_array($sessionPid) || is_object($sessionPid)) {
            return AgentPatientResolution::denied(
                'ambiguous_patient',
                'Current patient context is ambiguous.'
            );
        }

        $pid = $this->normalizePid($sessionPid);
        if ($pid === null) {
            return AgentPatientResolution::denied(
                'missing_patient',
                'Agent access requires exactly one current patient.'
            );
        }

        return AgentPatientResolution::allowed(new AgentPatientContext($pid));
    }

    /**
     * @param array<mixed> $payload
     * @return list<string>
     */
    private function findClientPatientContextFields(HttpRestRequest $request, array $payload): array
    {
        $found = [];
        $queryParams = $request->query->all();

        foreach (self::CLIENT_PATIENT_CONTEXT_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $found[] = 'payload.' . $field;
            }

            if (array_key_exists($field, $queryParams)) {
                $found[] = 'query.' . $field;
            }
        }

        return $found;
    }

    private function normalizePid(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/[,|]/', $trimmed) === 1 || !ctype_digit($trimmed)) {
            return null;
        }

        $pid = (int) $trimmed;
        return $pid > 0 ? $pid : null;
    }
}
