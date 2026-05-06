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
