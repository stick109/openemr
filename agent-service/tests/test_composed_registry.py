"""Tests for the M13-follow-up composed production tool registry.

These tests pin the contract of
:func:`agent_service.tools.composed_registry.compose_production_registry`
and the production wiring of
:func:`agent_service.api.copilot.get_registry_builder`.

Coverage:

* The composed registry exposes the full union of M10 + M11 + M12 tool
  names and every executor is wired (no ``executor=None`` stubs).
* Calling a composed-registry tool through M6's
  :func:`agent_service.tools.executor.execute_tool` succeeds when the
  underlying repository is mocked.
* :func:`agent_service.api.copilot.get_registry_builder` returns a
  working builder when settings + DB env vars are configured.
* :func:`agent_service.api.copilot.get_registry_builder` degrades
  gracefully (empty registry, no crash) when DB env vars are missing.

No test reaches a real database -- every repository call is mocked
through ``MagicMock(spec=OpenEmrReadRepository)`` and every connection
factory is replaced with a stub.
"""

from __future__ import annotations

import os
from collections.abc import Iterator
from datetime import datetime, timezone
from typing import Any
from unittest.mock import MagicMock

import pytest

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.repository.openemr import (
    OpenEmrReadRepository,
    RepositoryConfigurationError,
)
from agent_service.schemas.evidence import (
    EvidenceSourceType,
    PatientDemographics,
)
from agent_service.tools.composed_registry import compose_production_registry
from agent_service.tools.executor import execute_tool
from agent_service.tools.patient_evidence_tools import (
    PATIENT_EVIDENCE_TOOL_NAMES,
)
from agent_service.tools.registry import ToolRegistry
from agent_service.tools.source_drilldown import SOURCE_DETAIL_TOOL_NAME
from agent_service.tools.document_tools import DOCUMENT_TOOL_NAMES


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


PATIENT_ID = 42
TOKEN_EXPIRES_AT = 2_000_000_000  # well past 2026
FROZEN_NOW = datetime(2030, 1, 1, tzinfo=timezone.utc)


_REQUIRED_DB_ENV_VARS = (
    "OPENEMR_DB_NAME",
    "OPENEMR_DB_USER_RO",
    "OPENEMR_DB_PASS_RO",
)


def _frozen_clock() -> datetime:
    return FROZEN_NOW


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
    allowed_source_types: list[str] | None = None,
) -> CopilotRunContext:
    return CopilotRunContext(
        user_id=17,
        username="dr.smith",
        patient_id=PATIENT_ID,
        encounter_id=100,
        allowed_tools=allowed_tools
        or [
            *PATIENT_EVIDENCE_TOOL_NAMES,
            SOURCE_DETAIL_TOOL_NAME,
            *DOCUMENT_TOOL_NAMES,
        ],
        allowed_source_types=allowed_source_types
        or [
            *(t.value for t in EvidenceSourceType),
            "patient_record",
            "medications",
            "allergies",
            "problems",
            "vitals",
            "labs",
            "encounters",
            "procedures",
            "documents",
            "guidelines",
        ],
        max_rows=50,
        lookback_days=365,
        expires_at=TOKEN_EXPIRES_AT,
        request_id="req-test",
        trace_id="trace-test",
        key_version="v1",
    )


def _make_repository_mock() -> MagicMock:
    return MagicMock(spec=OpenEmrReadRepository)


def _demographics_record() -> PatientDemographics:
    return PatientDemographics(
        citation_id="demographics:patient_data:42",
        age=45,
        sex="female",
        preferred_language="english",
        pronouns="she/her",
        primary_provider_npi="1234567890",
    )


# Canonical union of every tool name that production wiring should
# expose. Pinned in one place so adding a future tool requires updating
# the test constant explicitly.
_ALL_EXPECTED_TOOL_NAMES: tuple[str, ...] = (
    *PATIENT_EVIDENCE_TOOL_NAMES,
    SOURCE_DETAIL_TOOL_NAME,
    *DOCUMENT_TOOL_NAMES,
)


# ---------------------------------------------------------------------------
# compose_production_registry -- registry shape
# ---------------------------------------------------------------------------


class TestComposedRegistryShape:
    def test_returns_a_tool_registry(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        assert isinstance(registry, ToolRegistry)

    def test_registry_contains_every_expected_tool_name(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        assert sorted(registry.list_names()) == sorted(_ALL_EXPECTED_TOOL_NAMES)

    def test_registry_contains_m10_patient_evidence_names(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        for name in PATIENT_EVIDENCE_TOOL_NAMES:
            assert name in registry

    def test_registry_contains_m11_source_drilldown(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        assert SOURCE_DETAIL_TOOL_NAME in registry

    def test_registry_contains_m12_document_tool_names(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        for name in DOCUMENT_TOOL_NAMES:
            assert name in registry

    def test_every_tool_has_executor_wired(self) -> None:
        repo = _make_repository_mock()
        registry = compose_production_registry(_make_context(), repository=repo)
        for name in registry.list_names():
            tool = registry.get(name)
            assert tool.executor is not None, (
                f"composed registry should not contain inert stubs; "
                f"tool {name!r} has executor=None"
            )

    def test_returns_fresh_registry_per_call(self) -> None:
        repo = _make_repository_mock()
        a = compose_production_registry(_make_context(), repository=repo)
        b = compose_production_registry(_make_context(), repository=repo)
        assert a is not b


# ---------------------------------------------------------------------------
# compose_production_registry -- execute through M6
# ---------------------------------------------------------------------------


class TestExecuteThroughExecutor:
    def test_basic_patient_data_succeeds_through_execute_tool(self) -> None:
        """A composed-registry tool runs through M6 with a mock repo."""
        repo = _make_repository_mock()
        repo.get_demographics.return_value = _demographics_record()
        registry = compose_production_registry(_make_context(), repository=repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "get_basic_patient_data",
            {},
            registry=registry,
            clock=_frozen_clock,
        )

        assert outcome.tool_name == "get_basic_patient_data"
        assert outcome.error_class is None
        assert outcome.result_count == 1
        assert len(outcome.citations) == 1
        # Repository was reached through the closure, with patient_id
        # injected by M6 from the context (not from model args).
        repo.get_demographics.assert_called_once()
        kwargs = repo.get_demographics.call_args.kwargs
        assert kwargs["context"] is ctx

    def test_source_drilldown_succeeds_through_execute_tool(self) -> None:
        """``get_source_detail`` runs through the composed registry."""
        repo = _make_repository_mock()
        # Repo returns ``None`` -> tool yields a typed warning bag.
        repo.get_source_detail.return_value = None
        registry = compose_production_registry(_make_context(), repository=repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": "encounter:form_encounter:1234"},
            registry=registry,
            clock=_frozen_clock,
        )

        assert outcome.tool_name == SOURCE_DETAIL_TOOL_NAME
        assert outcome.error_class is None
        assert outcome.result_count == 0
        repo.get_source_detail.assert_called_once()


# ---------------------------------------------------------------------------
# get_registry_builder integration
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _clear_registry_builder_caches() -> Iterator[None]:
    """Ensure each test sees a fresh ``_cached_repository`` + ``Settings``.

    The production builder caches both the repository and the settings
    via ``lru_cache``; we want every test in this module to control its
    own environment, so we clear both caches before *and* after each
    test. Not clearing afterwards would leak state into the rest of the
    test suite.
    """
    from agent_service.api import copilot as copilot_api
    from agent_service import config as config_module

    copilot_api._cached_repository.cache_clear()
    config_module.get_settings.cache_clear()
    yield
    copilot_api._cached_repository.cache_clear()
    config_module.get_settings.cache_clear()


def _set_full_db_env(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AGENT_SHARED_SECRET", "test-shared-secret")
    monkeypatch.setenv("OPENEMR_DB_HOST", "localhost")
    monkeypatch.setenv("OPENEMR_DB_PORT", "3306")
    monkeypatch.setenv("OPENEMR_DB_NAME", "openemr")
    monkeypatch.setenv("OPENEMR_DB_USER_RO", "openemr_ro")
    monkeypatch.setenv("OPENEMR_DB_PASS_RO", "shh")


class TestGetRegistryBuilder:
    def test_builder_with_full_settings_yields_real_registry(
        self,
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from agent_service.api import copilot as copilot_api

        _set_full_db_env(monkeypatch)

        # Replace the connection factory so ``from_settings`` does not
        # try to dial real PyMySQL during repository construction.
        # ``OpenEmrReadRepository`` exposes ``_build_pymysql_factory`` at
        # module scope; we patch it to return a no-op callable that the
        # repository never actually invokes in this test.
        monkeypatch.setattr(
            "agent_service.repository.openemr._build_pymysql_factory",
            lambda settings: (lambda: None),
        )

        builder = copilot_api.get_registry_builder()
        registry = builder(_make_context())

        assert isinstance(registry, ToolRegistry)
        # All tools advertised; every executor wired.
        assert sorted(registry.list_names()) == sorted(_ALL_EXPECTED_TOOL_NAMES)
        for name in registry.list_names():
            assert registry.get(name).executor is not None

    def test_builder_with_missing_db_settings_yields_empty_registry(
        self,
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from agent_service.api import copilot as copilot_api

        # Settings has the shared secret but no DB env vars -- the M9
        # repository factory will raise ``RepositoryConfigurationError``.
        monkeypatch.setenv("AGENT_SHARED_SECRET", "test-shared-secret")
        for var in _REQUIRED_DB_ENV_VARS:
            monkeypatch.delenv(var, raising=False)

        builder = copilot_api.get_registry_builder()
        registry = builder(_make_context())

        assert isinstance(registry, ToolRegistry)
        # Empty registry -> every tool call surfaces ``tool_unknown``,
        # but the API itself stays alive.
        assert registry.list_names() == []

    def test_builder_with_missing_shared_secret_yields_empty_registry(
        self,
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from agent_service.api import copilot as copilot_api

        # ``get_settings`` itself raises when AGENT_SHARED_SECRET is
        # absent. The builder must still hand back a usable callable.
        monkeypatch.delenv("AGENT_SHARED_SECRET", raising=False)

        builder = copilot_api.get_registry_builder()
        registry = builder(_make_context())

        assert isinstance(registry, ToolRegistry)
        assert registry.list_names() == []

    def test_repository_is_cached_across_builder_calls(
        self,
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        """``_cached_repository`` runs once per process under steady state."""
        from agent_service.api import copilot as copilot_api

        _set_full_db_env(monkeypatch)

        construction_calls: list[int] = []

        original_from_settings = OpenEmrReadRepository.from_settings

        def _counting_from_settings(
            settings: Any,
            **kwargs: Any,
        ) -> OpenEmrReadRepository:
            construction_calls.append(1)
            return original_from_settings(
                settings,
                connection_factory=lambda: None,
                **kwargs,
            )

        monkeypatch.setattr(
            OpenEmrReadRepository,
            "from_settings",
            classmethod(lambda cls, settings, **kwargs: _counting_from_settings(settings, **kwargs)),
        )

        # First call constructs the repo; second call should reuse it.
        copilot_api.get_registry_builder()
        copilot_api.get_registry_builder()

        assert sum(construction_calls) == 1
