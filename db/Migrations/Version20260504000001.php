<?php

/**
 * Add the Upload Intake Form encounter form.
 *
 * Creates the form_upload_intake_form table that logs each intake-form
 * upload tied to an encounter, and registers the form in the encounter's
 * Administrative dropdown via the registry table.
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
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504000001 extends AbstractMigration
{
    use CreateTableTrait;

    public function getDescription(): string
    {
        return 'Add Upload Intake Form encounter form (form_upload_intake_form table + registry row).';
    }

    public function up(Schema $schema): void
    {
        // Canonical OpenEMR form-table layout (date / pid / encounter / user /
        // groupname / authorized / activity) plus the intake-specific columns
        // (form_type / document_id / inserted_row_id / diff_preview). Keeping
        // the canonical columns lets formFetch and FormService::addForm work
        // out of the box, and lets the encounter timeline render the row
        // without a special case.
        $table = new Table('form_upload_intake_form');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => false,
        ]);
        $table->addColumn('date', Types::DATETIME_MUTABLE, [
            'notnull' => false,
        ]);
        $table->addColumn('pid', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('encounter', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('user', Types::STRING, [
            'length' => 255,
            'notnull' => false,
        ]);
        $table->addColumn('groupname', Types::STRING, [
            'length' => 255,
            'notnull' => false,
        ]);
        $table->addColumn('authorized', Types::SMALLINT, [
            'notnull' => false,
            'default' => 0,
        ]);
        $table->addColumn('activity', Types::SMALLINT, [
            'notnull' => false,
            'default' => 1,
        ]);
        $table->addColumn('form_type', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'columnDefinition' => "ENUM('Demographics','MedicalHistory','Consent') NOT NULL",
        ]);
        $table->addColumn('document_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('inserted_row_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('diff_preview', Types::TEXT, [
            'notnull' => false,
            'columnDefinition' => 'LONGTEXT DEFAULT NULL',
        ]);
        $this->addPrimaryKey($table, 'id');
        $table->addIndex(['encounter'], 'idx_encounter');
        $table->addIndex(['pid'], 'idx_pid');

        $this->createTable($table);

        // Register the new encounter form in the Administrative category.
        // Idempotent INSERT keyed by directory: the registry table has no
        // UNIQUE on directory, so a plain INSERT VALUES ... would let two
        // upload_intake_form rows accumulate if this migration ever runs in
        // parallel with sql/8_1_0-to-8_1_1_upgrade.sql (see the May 2026
        // duplicate-row incident).  The matching SQL guard lives in that
        // upgrade file; this stays consistent so re-running either pipeline
        // is a no-op when the row already exists.  The display name matches
        // the renamed "Upload Document (Co-Pilot)" entry from
        // sql/8_1_0-to-8_1_1_upgrade.sql so the two code paths converge.
        $this->addSql(
            "INSERT INTO `registry`"
            . " (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,"
            . "  `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,"
            . "  `aco_spec`, `form_foreign_id`)"
            . " SELECT"
            . " 'Upload Document (Co-Pilot)', 1, 'upload_intake_form', 1, 1, NOW(), 0,"
            . " 'Administrative', '', 1, 0, 'admin|super', NULL"
            . " FROM DUAL"
            . " WHERE NOT EXISTS ("
            . "   SELECT 1 FROM `registry` WHERE `directory` = 'upload_intake_form'"
            . " )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `registry` WHERE `directory` = 'upload_intake_form'");
        $this->addSql('DROP TABLE form_upload_intake_form');
    }
}
