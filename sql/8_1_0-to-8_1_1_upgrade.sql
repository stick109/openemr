--
--  Comment Meta Language Constructs:
--
--  #IfNotTable
--    argument: table_name
--    behavior: if the table_name does not exist,  the block will be executed

--  #IfTable
--    argument: table_name
--    behavior: if the table_name does exist, the block will be executed

--  #IfColumn
--    arguments: table_name colname
--    behavior:  if the table and column exist,  the block will be executed

--  #IfMissingColumn
--    arguments: table_name colname
--    behavior:  if the table exists but the column does not,  the block will be executed

--  #IfNotColumnType
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a column colname with a data type equal to value, then the block will be executed

--  #IfNotColumnTypeDefault
--    arguments: table_name colname value value2
--    behavior:  If the table table_name does not have a column colname with a data type equal to value and a default equal to value2, then the block will be executed

--  #IfNotRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a row where colname = value, the block will be executed.

--  #IfNotRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2, the block will be executed.

--  #IfNotRow3D
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.

--  #IfNotRow4D
--    arguments: table_name colname value colname2 value2 colname3 value3 colname4 value4
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3 AND colname4 = value4, the block will be executed.

--  #IfNotRow2Dx2
--    desc:      This is a very specialized function to allow adding items to the list_options table to avoid both redundant option_id and title in each element.
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  The block will be executed if both statements below are true:
--               1) The table table_name does not have a row where colname = value AND colname2 = value2.
--               2) The table table_name does not have a row where colname = value AND colname3 = value3.

--  #IfRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does have a row where colname = value, the block will be executed.

--  #IfRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does have a row where colname = value AND colname2 = value2, the block will be executed.

--  #IfRow3D
--        arguments: table_name colname value colname2 value2 colname3 value3
--        behavior:  If the table table_name does have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.

--  #IfRowIsNull
--    arguments: table_name colname
--    behavior:  If the table table_name does have a row where colname is null, the block will be executed.

--  #IfIndex
--    desc:      This function is most often used for dropping of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the table and index exist the relevant statements are executed, otherwise not.

--  #IfNotIndex
--    desc:      This function will allow adding of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the index does not exist, it will be created

--  #EndIf
--    all blocks are terminated with a #EndIf statement.

--  #IfNotListReaction
--    Custom function for creating Reaction List

--  #IfNotListOccupation
--    Custom function for creating Occupation List

--  #IfTextNullFixNeeded
--    desc: convert all text fields without default null to have default null.
--    arguments: none

--  #IfTableEngine
--    desc:      Execute SQL if the table has been created with given engine specified.
--    arguments: table_name engine
--    behavior:  Use when engine conversion requires more than one ALTER TABLE

--  #IfInnoDBMigrationNeeded
--    desc: find all MyISAM tables and convert them to InnoDB.
--    arguments: none
--    behavior: can take a long time.

--  #IfDocumentNamingNeeded
--    desc: populate name field with document names.
--    arguments: none

--  #IfUpdateEditOptionsNeeded
--    desc: Change Layout edit options.
--    arguments: mode(add or remove) layout_form_id the_edit_option comma_separated_list_of_field_ids

--  #IfVitalsDatesNeeded
--    desc: Change date from zeroes to date of vitals form creation.
--    arguments: none

--  #IfMBOEncounterNeeded
--    desc: Add encounter to the form_misc_billing_options table
--    arguments: none

--
-- Widen questionnaire_response.encounter to BIGINT.
-- int(11) silently overflows for OpenEMR's modern bigint encounter ids
-- (e.g. 900100000006 saturates to 2147483647). Match forms.encounter and
-- form_encounter.encounter which already use bigint(20).
--

#IfNotColumnType questionnaire_response encounter bigint
ALTER TABLE `questionnaire_response` MODIFY `encounter` BIGINT DEFAULT NULL COMMENT 'May or may not be associated with an encounter';
#EndIf

--
-- Upload Intake Form encounter form (intake-forms-plan.md §3.2, §3.3, §3.8)
-- Provides a per-encounter PDF intake-form upload UI under the Administrative menu.
--

#IfNotTable form_upload_intake_form
CREATE TABLE `form_upload_intake_form` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `date` DATETIME DEFAULT NULL,
  `pid` BIGINT NOT NULL,
  `encounter` BIGINT NOT NULL,
  `user` VARCHAR(255) DEFAULT NULL,
  `groupname` VARCHAR(255) DEFAULT NULL,
  `authorized` TINYINT(4) DEFAULT 0,
  `activity` TINYINT(4) DEFAULT 1,
  `form_type` ENUM('Demographics','MedicalHistory','Consent') NOT NULL,
  `document_id` INT NULL,
  `inserted_row_id` INT NULL,
  `diff_preview` LONGTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_encounter` (`encounter`),
  KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfNotRow2D registry directory upload_intake_form name Upload Intake Form
INSERT INTO `registry`
  (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,
   `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,
   `aco_spec`, `form_foreign_id`)
VALUES
  ('Upload Intake Form', 1, 'upload_intake_form', 1, 1, NOW(), 0,
   'Administrative', '', 1, 0, 'admin|super', NULL);
#EndIf

