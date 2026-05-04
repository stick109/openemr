-- Schema for the Upload Intake Form encounter form.
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
--        ('Upload Intake Form', 1, 'upload_intake_form', 1, 1, NOW(), 0,
--         'Administrative', '', 1, 0, 'admin|super', NULL);
--
--   3. The accompanying upgrade SQL file should mirror both statements per
--      project convention.

CREATE TABLE IF NOT EXISTS `form_upload_intake_form` (
    `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
    `pid`         BIGINT(20)   DEFAULT NULL,
    `encounter`   BIGINT(20)   DEFAULT NULL,
    `type`        ENUM('Auto-detect', 'Demographics', 'Medical History', 'Consent') NOT NULL DEFAULT 'Auto-detect',
    `document_id` BIGINT(20)   DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
