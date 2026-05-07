### Easy Development Docker Environment
The instructions for The Easy Development Docker environment can be found at [CONTRIBUTING.md](../../CONTRIBUTING.md#code-contributions-local-development).

**Refreshing the openemr container after host source edits:** the `openemr-local:latest` image bakes PHP source via `Dockerfile.railway` (`COPY . /var/www/localhost/htdocs/openemr/`), so host edits do not appear in the running container until the image is rebuilt. Run `docker compose --project-name openemr build openemr` followed by `docker compose --project-name openemr up -d --no-deps openemr` from this directory.
