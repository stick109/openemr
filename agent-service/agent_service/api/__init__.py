"""FastAPI route modules for the agent sidecar.

Each submodule defines a single ``APIRouter`` that is wired into the
top-level :mod:`agent_service.main` application.  Keeping routes in
their own package avoids the monolithic ``main.py`` pattern as the
sidecar grows.
"""

from agent_service.api.copilot import router as copilot_router

__all__ = ["copilot_router"]
