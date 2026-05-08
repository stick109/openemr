# Additional Requirements: Port the OpenEMR Patient Dashboard to a Modern Framework

OpenEMR is one of the most widely used open-source electronic health record systems in the world, built in PHP since 2001 and actively maintained on GitHub today. It works. Clinics depend on it. Your job is not to redesign it — it is to reimplement it.

The existing patient dashboard is a PHP-rendered, server-side application. The UX has already been addressed ([May 2025](https://www.capminds.com/blog/7-ui-ux-enhancements-in-openemr-that-elevate-healthcare-delivery/)). What has not changed is the underlying technology. Your challenge is to port the dashboard to a modern framework of your choosing, consuming OpenEMR's existing REST and FHIR API as your data layer. You are not touching the backend. You are not redesigning the interface. You are moving the presentation layer to a better tool and making the case for why that tool is the right one.

## By the end of the week you should have:

- **Authentication** — Login via OAuth2/OpenID Connect
- **Patient header** — The persistent identity bar: name, date of birth, sex, MRN, and active status
- **Clinical cards** — Allergies, Problem List, Medications, Prescriptions, and Care Team, each pulling live data from the FHIR API
- **One additional section of your choice** — Encounter history, lab results, vitals, immunizations, upcoming appointments, or patient notes are all backed by the existing API

## Deliverable

A working reimplementation of the patient dashboard in a modern language and/or framework. Feature parity with the original is the standard. You must also be able to explain why you chose your framework, what you gained by moving away from PHP, and what tradeoffs came with that choice. ***You must document your defense in PATIENT_DASHBOARD_MIGRATION.md and put that file in your repo.*** That defense is part of the grade.

---

*The framework decision is yours. The UX decision is yours. Own both.*

## Additional Clarifications and Implementation Notes

The following sections were added after a call with the assigning stakeholders.  

Information in the following sections clarifies (and in some cases overrides) the preceding sections of this document.

### Submission timing

- The dashboard migration is required for the Final Submission, due Sunday at noon Central.
- It is not required for the Early Submission, but it should not be deferred until after early submission. The deliverable is an actual migration plus documentation, not documentation alone.
- `PATIENT_DASHBOARD_MIGRATION.md` remains required and should explain the chosen framework or language, why the migration was made, what was gained by moving away from PHP, and what tradeoffs came with that choice.

### Migration scope

- Migrate only the patient dashboard features listed in this requirement: authentication, patient header, required clinical cards, and one additional API-backed section.
- Do not migrate every card or component on the existing patient page.
- The rest of OpenEMR must remain available. This is a partial modernization of the application, not a replacement app.
- The migrated dashboard should remain part of the same codebase and broader user experience, so a user can navigate from the new dashboard to existing OpenEMR pages.
- The migrated dashboard page itself should not mix frontend stacks. For example, a React implementation should be React-only on that page and should not embed legacy PHP frontend components.

### Authentication and API boundary

- Do not change OpenEMR backend APIs.
- Do not replace the existing authentication backend. Use the authentication and OAuth2/OpenID Connect systems already present in OpenEMR.
- The implementation work should stay concentrated in the presentation layer and in how the new frontend interacts with the existing REST and FHIR APIs.

### Intended user perspective

- The intended interpretation is the clinician-facing patient dashboard: the view a primary care physician uses when viewing one of their patients.

### Setup caveats

- Enabling API or client access in OpenEMR may be brittle. Creating an API client may produce a client ID and client secret through an admin UI form, but that form may fail, crash, or silently do nothing.
- A workaround may require using browser developer tools while logged in as an admin, inspecting the failing form, and issuing the equivalent request manually with JavaScript or `fetch`.
- Relevant OpenEMR admin areas are likely under configuration and system settings.
- Some OpenEMR documentation may be stale, so implementation should be verified against the running application.

### Ambiguities and conflicts

- The original written spec says “feature parity with the original is the standard,” but this has been overridden as: the listed features need to be implemented and unspecified cards may be left off. The practical interpretation is feature parity for the specified dashboard subset, not full parity for every existing patient-page component.
- “Login via OAuth2/OpenID Connect”: backend authentication and APIs should not be changed. Use OpenEMR’s existing OAuth2/OpenID Connect flow from the new frontend. You *DO NOT* need to build a replacement authentication system.
