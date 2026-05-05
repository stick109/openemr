<?php

/**
 * Widen questionnaire_response.encounter from INT to BIGINT.
 *
 * OpenEMR uses bigint(20) for encounter ids throughout the schema
 * (forms.encounter, form_encounter.encounter, form_upload_intake_form.encounter).
 * The questionnaire_response.encounter column was left as int(11), causing
 * modern dev-stack encounter ids (e.g. 900100000006) to silently overflow and
 * saturate to 2147483647.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen questionnaire_response.encounter from INT(11) to BIGINT to match the canonical encounter id type used by forms and form_encounter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE `questionnaire_response` MODIFY `encounter` BIGINT DEFAULT NULL COMMENT \'May or may not be associated with an encounter\''
        );
    }

    public function down(Schema $schema): void
    {
        // Reverting to INT(11) will truncate any encounter ids > 2147483647.
        // This is a best-effort revert; data loss is expected for large ids.
        $this->addSql(
            'ALTER TABLE `questionnaire_response` MODIFY `encounter` INT(11) DEFAULT NULL COMMENT \'May or may not be associated with an encounter\''
        );
    }
}
