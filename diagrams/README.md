# OpenEMR Major Components

## Simplified Component View

![Simplified OpenEMR components](openemr-simplified-components.svg)

This six-component view groups the system into users and clients, request routing, core runtime behavior, application logic, data/configuration, and external integrations. It is intended as the quickest orientation for the system.

Source file: [openemr-simplified-components.drawio](openemr-simplified-components.drawio)

## Data Access View

![OpenEMR data access components](openemr-data-access-components.svg)

This flow identifies application data callers as the entry point and shows the downstream components used to read and write data: services and validators, legacy SQL helpers, newer Doctrine-backed access, active site connection context, schema lifecycle assets, and MySQL/MariaDB.

Source file: [openemr-data-access-components.drawio](openemr-data-access-components.drawio)

## Configuration View

![OpenEMR configuration components](openemr-configuration-components.svg)

This flow identifies bootstrap and configuration loaders as the entry point and shows the downstream configuration sources they use: site files, environment/default mappings, container service config, dependency metadata, persisted settings, and module extension config.

Source file: [openemr-configuration-components.drawio](openemr-configuration-components.drawio)

## Detailed Component View

![OpenEMR major components](openemr-major-components.svg)

This diagram summarizes the major runtime surfaces and shared components visible in this repository. OpenEMR is a PHP application with a large legacy browser UI, newer REST/FHIR/OAuth2 routing, a patient portal, CLI/setup tooling, and a gradual move toward namespaced service and container infrastructure.

The main compatibility boundary is `interface\globals.php`. Classic browser pages, portal flows, many CLI commands, and the API site setup path use it to establish the active site, session state, path globals, database access, global settings, and module/event hooks.

The API path enters through `apis\dispatch.php` and `oauth2\authorize.php`, then runs through `ApiApplication`, `OEHttpKernel`, route finders, security checks, REST/FHIR controllers, services, validators, and response rendering. The newer `public\index.php` path uses `FallbackRouter` as a bridge back into historical dispatchers and literal PHP entry points.

Data and configuration are split between MySQL/MariaDB, site-scoped files under `sites`, Composer/config-driven service wiring under `config`, and external systems for payments, messaging, pharmacies, labs, and interoperability.

Source file: [openemr-major-components.drawio](openemr-major-components.drawio)
