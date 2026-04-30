-- Adds demo recent encounter events for patients and active users that do
-- not already have recent encounter activity.

START TRANSACTION;

SET @demo_facility_id = COALESCE((
    SELECT facility.id
    FROM facility
    ORDER BY facility.billing_location DESC, facility.id ASC
    LIMIT 1
), 0);

SET @demo_facility = COALESCE((
    SELECT facility.name
    FROM facility
    WHERE facility.id = @demo_facility_id
    LIMIT 1
), 'Demo Facility');

SET @demo_provider_id = COALESCE((
    SELECT users.id
    FROM users
    WHERE users.active = 1
      AND users.authorized = 1
    ORDER BY users.calendar DESC, users.id ASC
    LIMIT 1
), 1);

SET @first_patient_id = (
    SELECT patient_data.pid
    FROM patient_data
    ORDER BY patient_data.pid ASC
    LIMIT 1
);

INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    billing_facility,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code
)
SELECT
    UNHEX(REPLACE(UUID(), '-', '')),
    DATE_ADD(CURDATE() - INTERVAL 1 DAY, INTERVAL 9 HOUR),
    'Recent demo follow-up visit',
    @demo_facility,
    @demo_facility_id,
    @demo_facility_id,
    patients_missing_recent_events.pid,
    900000000000 + patients_missing_recent_events.pid,
    9,
    @demo_provider_id,
    'AMB'
FROM (
    SELECT patient_data.pid
    FROM patient_data
    WHERE NOT EXISTS (
        SELECT 1
        FROM form_encounter
        WHERE form_encounter.pid = patient_data.pid
          AND form_encounter.date >= CURDATE() - INTERVAL 30 DAY
    )
) AS patients_missing_recent_events;

INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    billing_facility,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code
)
SELECT
    UNHEX(REPLACE(UUID(), '-', '')),
    DATE_ADD(CURDATE() - INTERVAL 2 DAY, INTERVAL 10 HOUR),
    'Recent demo provider check-in',
    @demo_facility,
    @demo_facility_id,
    @demo_facility_id,
    @first_patient_id,
    900100000000 + users_missing_recent_events.id,
    9,
    users_missing_recent_events.id,
    'AMB'
FROM (
    SELECT users.id
    FROM users
    WHERE users.active = 1
      AND users.authorized = 1
      AND NOT EXISTS (
          SELECT 1
          FROM form_encounter
          WHERE form_encounter.provider_id = users.id
            AND form_encounter.date >= CURDATE() - INTERVAL 30 DAY
      )
) AS users_missing_recent_events
WHERE @first_patient_id IS NOT NULL;

COMMIT;
