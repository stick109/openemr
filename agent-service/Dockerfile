# syntax=docker/dockerfile:1.7

# OpenEMR Agent Sidecar
#
# Builds the FastAPI application defined in ``agent_service/`` and runs it
# under uvicorn on port 8010.  The image is intentionally slim: only the
# Python runtime plus the project's declared dependencies.

FROM python:3.11-slim AS base

# Standard Python runtime hardening / clean output
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# Install runtime dependencies.  ``curl`` is used by the HEALTHCHECK below.
# Build tooling is needed because some pure-Python wheels for langgraph /
# numpy may need to be compiled against the slim base.
RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        build-essential \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Create an unprivileged user.  uid/gid 10001 keeps us out of the way of
# any host-side numeric ranges that may be reserved.
RUN groupadd --system --gid 10001 agent \
    && useradd --system --uid 10001 --gid agent --home /app --shell /usr/sbin/nologin agent

WORKDIR /app

# Install dependencies first so the layer is cached when only source changes.
COPY pyproject.toml ./
# Create a stub package directory so ``pip install -e .`` can resolve the
# project's own metadata before the real source is copied.  The real source
# replaces this in the next COPY step.
RUN mkdir -p agent_service && touch agent_service/__init__.py
RUN pip install --upgrade pip \
    && pip install -e .

# Copy application source.
COPY agent_service/ ./agent_service/

# Drop privileges.
RUN chown -R agent:agent /app
USER agent

EXPOSE 8010

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8010/healthz || exit 1

CMD ["uvicorn", "agent_service.main:app", "--host", "0.0.0.0", "--port", "8010"]
