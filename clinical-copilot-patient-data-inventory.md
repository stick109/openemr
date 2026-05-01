# Clinical Copilot Patient Data Inventory

Yes. More precisely: Clinical Copilot is:

```text
button -> intent_id -> evidence tool -> approved data classes -> bounded SQL pull
```

The current buttons are defined in [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php), mapped to tools/data classes in [AgentEvidenceToolset.php](src\Services\Agent\Evidence\AgentEvidenceToolset.php), and backed by SQL in [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php). One nuance: "Recent events" already spans multiple tables, and "Changed since last visit" can include encounters/documents plus medication/allergy changes if those data classes are granted.

## Other Patient Data Examples

- Demographics/contact: `patient_data`, `addresses`, `phone_numbers`, `contact`, `employer_data`
- Problems/issues: `lists` with issue types like `medical_problem`, `health_concern`, `medical_device`, `surgery`, `dental`, plus `issue_encounter`
- Medications: `lists` type `medication`, `lists_medication`, `prescriptions`, `drug_sales`
- Allergies: `lists` type `allergy`
- Encounters/visits: `form_encounter`, `forms`, `external_encounters`
- Appointments/status: `openemr_postcalendar_events`, `patient_tracker`
- Vitals/measurements: `form_vitals`, `form_vitals_calculation`, `form_observation`
- Notes: `form_soap`, `form_dictation`, `form_clinical_notes`, `pnotes`, `transactions`
- ROS/history/SDOH: `form_ros`, `history_data`, `form_history_sdoh`
- Care planning: `form_care_plan`, `form_clinical_instructions`, `form_functional_cognitive_status`
- Immunizations: `immunizations`, `immunization_observation`
- Labs/procedures/orders: `procedure_order`, `procedure_order_code`, `procedure_report`, `procedure_result`, `procedure_specimen`, `procedure_answers`, `external_procedures`
- Documents/legal docs: `documents`, `onsite_documents`, `onsite_signatures`, `documents_legal_detail`
- Questionnaires/PROs: `questionnaire_response`, `form_questionnaire_assessments`, `pro_assessments`
- Care team/preferences: `care_teams`, `care_team_member`, `patient_care_experience_preferences`, `patient_treatment_intervention_preferences`
- Reminders/communication: `patient_reminders`, `dated_reminders`, `batchcom`, `medex_outgoing`, `medex_recalls`, `notification_log`
- Insurance/billing/claims/payments: `insurance_data`, `billing`, `claims`, `payments`, `payment_processing_audit`, `report_itemized`
- Amendments/audit/log context: `amendments`, `audit_master`, `log`, `extended_log`, `api_log`
- Group/therapy context: `therapy_groups_participants`, `therapy_groups_participant_attendance`

Future Copilot buttons could cleanly become things like "Active problems," "Recent vitals," "Latest labs," "Pending orders," "Immunization history," "Upcoming appointments," "Last SOAP note," "Care plan," "SDOH risks," "Care team," "Insurance summary," or "Patient portal activity." Each should get its own explicit intent, data class, ACL policy, row/document limits, and citation format.
