<?php

/**
 * LabReportClassifiedException
 *
 * Thrown by {@see \OpenEMR\Services\Intake\IntakeFormIngestService::classify()}
 * when the auto-classifier identifies the uploaded PDF as a laboratory report
 * (`lab_pdf`). The legacy intake pipeline cannot dispatch lab reports — those
 * belong on the agent-service sidecar path so the LabPdfDispatcher writes them
 * to procedure_order / procedure_report / procedure_result instead of the
 * lists table. The form's save handler catches this exception and re-routes
 * the upload through the sidecar without prompting the user.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Exception;

final class LabReportClassifiedException extends IntakeFormException
{
}
