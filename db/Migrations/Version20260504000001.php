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
        $table = new Table('form_upload_intake_form');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => false,
        ]);
        $table->addColumn('pid', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('encounter', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('form_type', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'columnDefinition' => "ENUM('Demographics','MedicalHistory','Consent') NOT NULL",
        ]);
        $table->addColumn('document_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'default' => 'CURRENT_TIMESTAMP',
        ]);
        $this->addPrimaryKey($table, 'id');
        $table->addIndex(['encounter'], 'idx_encounter');
        $table->addIndex(['pid'], 'idx_pid');

        $this->createTable($table);

        // Register the new encounter form in the Administrative category.
        // Verbatim INSERT from intake-forms-plan.md §3.2.
        $this->addSql(
            "INSERT INTO `registry`"
            . " (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,"
            . " `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,"
            . " `aco_spec`, `form_foreign_id`)"
            . " VALUES"
            . " ('Upload Intake Form', 1, 'upload_intake_form', 1, 1, NOW(), 0,"
            . " 'Administrative', '', 1, 0, 'admin|super', NULL)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `registry` WHERE `directory` = 'upload_intake_form'");
        $this->addSql('DROP TABLE form_upload_intake_form');
    }
}
