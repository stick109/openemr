-- Schema for the Upload Intake Form encounter form.
--
-- This table is a standard OpenEMR form table (date/pid/encounter/user/
-- groupname/authorized/activity) plus the intake-specific columns
-- (form_type, document_id, inserted_row_id, diff_preview). Keeping the
-- canonical columns lets formFetch / FormService::addForm and the encounter
-- timeline operate on it without a special case.
--
-- The Doctrine migration for this form (see plan §3.8) is responsible for:
--   1. Running the CREATE TABLE statement below.
--   2. Inserting the registry row that lights up the menu entry under the
--      Administrative dropdown (see plan §3.2). The exact INSERT to use:
--
--      INSERT INTO `registry`
--        (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,
--         `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,
--         `aco_spec`, `form_foreign_id`)
--      VALUES
--        ('Upload Document (Co-Pilot)', 1, 'upload_intake_form', 1, 1, NOW(), 0,
--         'Administrative', '', 1, 0, 'admin|super', NULL);
--
--   3. The accompanying upgrade SQL file should mirror both statements per
--      project convention.

CREATE TABLE IF NOT EXISTS `form_upload_intake_form` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `date`            DATETIME     DEFAULT NULL,
    `pid`             BIGINT       NOT NULL,
    `encounter`       BIGINT       NOT NULL,
    `user`            VARCHAR(255) DEFAULT NULL,
    `groupname`       VARCHAR(255) DEFAULT NULL,
    `authorized`      TINYINT(4)   DEFAULT 0,
    `activity`        TINYINT(4)   DEFAULT 1,
    `form_type`       ENUM('Demographics', 'MedicalHistory', 'Consent', 'lab_pdf') NOT NULL,
    `document_id`     INT          DEFAULT NULL,
    `inserted_row_id` INT          DEFAULT NULL,
    `diff_preview`    LONGTEXT     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_encounter` (`encounter`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB;

-- Citation rows associated with a form_upload_intake_form record (S17).
-- One row per Citation object returned from the agent-service sidecar
-- (see agent-service/CONTRACT.md and src/Services/Agent/Sidecar/AgentRunResult.php).
-- The `source_type` ENUM matches the discriminator on the API-level
-- Citation union; `pdf_bbox` rows populate the page/bbox columns and may
-- carry a `field_name` (clinical SourceCitation contract), while
-- `guideline` rows populate chunk_id/source_url/snippet/section.
CREATE TABLE IF NOT EXISTS `form_upload_intake_form_citation` (
    `id`         BIGINT       NOT NULL AUTO_INCREMENT,
    `form_id`    BIGINT       NOT NULL,
    `source_type` ENUM('pdf_bbox', 'guideline') NOT NULL,
    `field_name` VARCHAR(255) DEFAULT NULL,
    `page`       INT          DEFAULT NULL,
    `bbox_x0`    DECIMAL(10, 4) DEFAULT NULL,
    `bbox_y0`    DECIMAL(10, 4) DEFAULT NULL,
    `bbox_x1`    DECIMAL(10, 4) DEFAULT NULL,
    `bbox_y1`    DECIMAL(10, 4) DEFAULT NULL,
    `chunk_id`   VARCHAR(255) DEFAULT NULL,
    `source_url` TEXT         DEFAULT NULL,
    `snippet`    TEXT         DEFAULT NULL,
    `section`    VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_form_id` (`form_id`)
) ENGINE=InnoDB;

-- Manual verification (run from the project root):
--   docker compose --project-name openemr exec -T mysql \
--     mariadb -uroot -proot openemr \
--     -e "DESCRIBE form_upload_intake_form_citation;"
