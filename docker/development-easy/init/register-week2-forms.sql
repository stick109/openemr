-- Auto-registration of the Week 2 "Upload Document (Co-Pilot)" encounter form
-- for the docker/development-easy stack.
--
-- The OpenEMR core install does NOT register custom forms in interface/forms/*;
-- normally an admin must visit Admin -> Forms -> Forms Administration and
-- click "Register" for each form. For the Week 2 demo we want a fresh
-- `docker compose down -v && docker compose up` to land with this form already
-- registered, so the Co-Pilot upload entry shows up in the encounter
-- "Add Form" picker without any manual click.
--
-- This script is invoked by the dev-easy `forms-bootstrap` init service
-- (see docker-compose.yml) AFTER the openemr container reports healthy,
-- which means auto_configure.php has finished running the core install.
--
-- Idempotent: every statement uses CREATE TABLE IF NOT EXISTS / INSERT IGNORE
-- guards, so it is safe to re-run on every container start. The schema mirrors
-- interface/forms/upload_intake_form/table.sql and the registry insert mirrors
-- sql/8_1_0-to-8_1_1_upgrade.sql so future schema changes only need updating
-- in those two canonical places (this file is a thin replay of them).
--
-- THIS FILE IS DEV-EASY ONLY. Production OpenEMR installs continue to rely on
-- the upgrade SQL pipeline (sql/8_1_0-to-8_1_1_upgrade.sql) — this script is
-- mounted as a one-shot init service and never runs against production.

-- form_upload_intake_form: parent encounter form table (S14: lab_pdf enum).
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

-- form_upload_intake_form_citation: citation rows from the agent sidecar (S17).
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

-- Insert the registry row that lights up the form in the encounter
-- "Add Form" picker. Mirrors sql/8_1_0-to-8_1_1_upgrade.sql's INSERT.
--
-- The `registry` table only has a PRIMARY KEY on `id` (no UNIQUE on
-- `directory`), so plain INSERT IGNORE would not deduplicate by directory.
-- Guard with INSERT ... SELECT WHERE NOT EXISTS to keep the script
-- idempotent across re-runs.
INSERT INTO `registry`
    (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,
     `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,
     `aco_spec`, `form_foreign_id`)
SELECT
    'Upload Document (Co-Pilot)', 1, 'upload_intake_form', 1, 1, NOW(), 0,
    'Administrative', '', 1, 0, 'admin|super', NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `registry`
     WHERE `directory` = 'upload_intake_form'
       AND `name` = 'Upload Document (Co-Pilot)'
);

-- Failsafe: if a stale registry row from an older display name exists, fix it.
-- This matches the #IfRow2D guard in sql/8_1_0-to-8_1_1_upgrade.sql.
UPDATE `registry`
   SET `name` = 'Upload Document (Co-Pilot)'
 WHERE `directory` = 'upload_intake_form'
   AND `name` = 'Upload Intake Form';
