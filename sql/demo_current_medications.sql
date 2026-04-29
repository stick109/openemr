-- Adds demo current medications to any patient that does not already have
-- an active medication entry in the OpenEMR issue list.

START TRANSACTION;

INSERT INTO lists (
    date,
    type,
    title,
    begdate,
    enddate,
    activity,
    comments,
    pid,
    uuid
)
SELECT
    NOW(),
    'medication',
    demo_medications.title,
    CURDATE(),
    NULL,
    1,
    '',
    patients_missing_medications.pid,
    UNHEX(REPLACE(UUID(), '-', ''))
FROM (
    SELECT patient_data.pid
    FROM patient_data
    WHERE NOT EXISTS (
        SELECT 1
        FROM lists
        WHERE lists.pid = patient_data.pid
          AND lists.type = 'medication'
          AND lists.activity = 1
          AND (
              lists.enddate IS NULL
              OR lists.enddate >= CURDATE()
          )
    )
) AS patients_missing_medications
CROSS JOIN (
    SELECT 'Cetirizine 10 mg' AS title
    UNION ALL
    SELECT 'Vitamin D3 1000 IU'
) AS demo_medications;

COMMIT;
