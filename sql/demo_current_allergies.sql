-- Adds demo current allergies to any patient that does not already have
-- an active allergy entry in the OpenEMR issue list.

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
    reaction,
    verification,
    severity_al,
    uuid
)
SELECT
    NOW(),
    'allergy',
    demo_allergies.title,
    CURDATE(),
    NULL,
    1,
    '',
    patients_missing_allergies.pid,
    demo_allergies.reaction,
    'confirmed',
    demo_allergies.severity_al,
    UNHEX(REPLACE(UUID(), '-', ''))
FROM (
    SELECT patient_data.pid
    FROM patient_data
    WHERE NOT EXISTS (
        SELECT 1
        FROM lists
        WHERE lists.pid = patient_data.pid
          AND lists.type = 'allergy'
          AND lists.activity = 1
          AND (
              lists.enddate IS NULL
              OR lists.enddate >= CURDATE()
          )
    )
) AS patients_missing_allergies
CROSS JOIN (
    SELECT
        'Penicillin' AS title,
        'hives' AS reaction,
        'mild' AS severity_al
) AS demo_allergies;

COMMIT;
