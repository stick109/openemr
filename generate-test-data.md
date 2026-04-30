# Generate Test Data

How to populate a local OpenEMR development instance with test doctors and
demo patients. Source of truth is [CONTRIBUTING.md](CONTRIBUTING.md) under
"Resetting OpenEMR and loading demo data" — this file is the trimmed,
actionable subset.

## Prerequisites

- Docker running locally.
- The `development-easy` stack present at [docker/development-easy](docker/development-easy/).

All commands below run from that directory:

```sh
cd docker/development-easy
```

## Step 0 — Bring the dev stack up

```sh
docker compose up --detach --wait
```

This starts the `openemr` container (plus MySQL, phpMyAdmin, CouchDB, etc.).
`--wait` blocks until the healthchecks pass, so the next step can run
immediately afterward without polling.

Verify the app is reachable:

- App: http://localhost:8300/ or https://localhost:9300/
- phpMyAdmin: http://localhost:8310/

## Step 1 — Reset the database and load demo data

```sh
docker compose exec openemr /root/devtools dev-reset-install-demodata
```

This wipes the database and reinstalls OpenEMR seeded with the official demo
dataset. The dataset includes:

- Multiple **clinician/staff users** with role-based access controls
  (this is what gives you "test doctors").
- **Demo patients** with associated encounters, demographics, and portal
  logins.

Default admin login afterward: `admin` / `pass`.

The full list of seeded user and patient-portal credentials lives on the
OpenEMR wiki:
<https://www.open-emr.org/wiki/index.php/Development_Demo#Demo_Credentials>

### Caveats

- **Destructive.** Anything currently in the dev database is erased. If you
  have local work to keep, snapshot it first:
  ```sh
  docker compose exec openemr /root/devtools backup <name>
  ```
  Restore later with `... restore <name>`.
- **CouchDB users:** if document storage is configured to use CouchDB,
  restart it after the reset:
  ```sh
  docker compose restart couchdb
  ```

## Next steps (optional)

To layer additional synthetic patients on top of the demo dataset, see the
`import-random-patients` devtool documented in [CONTRIBUTING.md](CONTRIBUTING.md)
(section "Create and add random patient data"). Synthea-generated patients
are additive and do not create new user accounts.
