"""Tests for the intent catalog introduced in step M7.

Covers:

- :func:`default_catalog` lists every PHP intent ID with the exact PHP
  spelling, so the wire contract with the existing UI does not drift.
- Each intent's ``label``, ``goal_template``, and capability caps match
  the PHP ``AgentIntentCatalog`` constants verbatim.
- Each intent's ``allowed_tools`` is a subset of the M5 tool registry
  (no typos in the catalog).
- The catalog rejects an :class:`IntentDefinition` whose
  ``allowed_tools`` references an unknown tool (defense-in-depth boot
  check).
- :class:`IntentCatalog.get` raises :class:`UnknownIntentError` for
  unknown IDs.
- :class:`IntentDefinition` is frozen.
- ``show_source`` is the only intent flagged as a source drilldown.
"""

from __future__ import annotations

import pytest

from agent_service.intents import (
    IntentCatalog,
    IntentDefinition,
    UnknownIntentError,
    UnknownToolReferenceError,
    default_catalog,
)
from agent_service.tools import default_registry


# Source of truth for the wire contract: every PHP intent must show
# up in Python with the same ID.
EXPECTED_INTENT_IDS: list[str] = [
    "allergies_to_confirm",
    "basic_patient_data",
    "changed_since_last_visit",
    "current_medications",
    "recent_events",
    "show_source",
]


# Per-intent expectations sourced verbatim from
# src/Services/Agent/AgentIntentCatalog.php.  Keep this table aligned
# with the PHP catalog -- if PHP changes, both this fixture and the
# Python catalog must change together.
EXPECTED_INTENT_FIELDS: dict[str, dict[str, object]] = {
    "basic_patient_data": {
        "label": "Basic patient data",
        "goal_template": "Show me basic patient data.",
        "max_rows": 10,
        "lookback_days": 0,
        "allowed_tools": ("get_basic_patient_data", "get_source_detail"),
        "is_source_drilldown": False,
    },
    "current_medications": {
        "label": "Current medications",
        "goal_template": "Show me current medications.",
        "max_rows": 25,
        "lookback_days": 365,
        "allowed_tools": ("get_current_medications", "get_source_detail"),
        "is_source_drilldown": False,
    },
    "allergies_to_confirm": {
        "label": "Allergies to confirm",
        "goal_template": "Show me allergies to confirm.",
        "max_rows": 25,
        "lookback_days": 365,
        "allowed_tools": ("get_active_allergies", "get_source_detail"),
        "is_source_drilldown": False,
    },
    "recent_events": {
        "label": "Recent events",
        "goal_template": "Show me recent events.",
        "max_rows": 30,
        "lookback_days": 180,
        "allowed_tools": ("get_recent_events", "get_source_detail"),
        "is_source_drilldown": False,
    },
    "changed_since_last_visit": {
        "label": "Changed since last visit",
        "goal_template": "Explain what changed since the last visit.",
        "max_rows": 30,
        "lookback_days": 365,
        "allowed_tools": ("get_changes_since_last_visit", "get_source_detail"),
        "is_source_drilldown": False,
    },
    "show_source": {
        "label": "Show source",
        "goal_template": "Show source evidence behind this claim.",
        "max_rows": 1,
        "lookback_days": 0,
        "allowed_tools": ("get_source_detail",),
        "is_source_drilldown": True,
    },
}


# ---------------------------------------------------------------------------
# default_catalog()
# ---------------------------------------------------------------------------


def test_default_catalog_lists_every_php_intent() -> None:
    catalog = default_catalog()
    assert catalog.list_ids() == EXPECTED_INTENT_IDS
    assert len(catalog) == len(EXPECTED_INTENT_IDS)


def test_default_catalog_returns_independent_instances() -> None:
    """``default_catalog()`` must not share mutable state across calls."""
    a = default_catalog()
    b = default_catalog()
    assert a is not b
    # Both instances expose the same intent set; they are distinct
    # objects but content-equivalent.
    assert a.list_ids() == b.list_ids()


def test_all_returns_id_sorted_tuple() -> None:
    catalog = default_catalog()
    intents = catalog.all()
    assert isinstance(intents, tuple)
    assert [intent.intent_id for intent in intents] == EXPECTED_INTENT_IDS


# ---------------------------------------------------------------------------
# Per-intent field checks (label, goal_template, caps, allowed_tools)
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("intent_id", EXPECTED_INTENT_IDS)
def test_intent_fields_match_php_catalog(intent_id: str) -> None:
    catalog = default_catalog()
    intent = catalog.get(intent_id)
    expected = EXPECTED_INTENT_FIELDS[intent_id]
    assert intent.intent_id == intent_id
    assert intent.label == expected["label"]
    assert intent.goal_template == expected["goal_template"]
    assert intent.max_rows == expected["max_rows"]
    assert intent.lookback_days == expected["lookback_days"]
    assert intent.allowed_tools == expected["allowed_tools"]
    assert intent.is_source_drilldown == expected["is_source_drilldown"]


def test_basic_patient_data_full_definition() -> None:
    """Spot-check one intent end-to-end so the round-trip is obvious."""
    catalog = default_catalog()
    intent = catalog.get("basic_patient_data")
    assert intent.label == "Basic patient data"
    assert intent.goal_template == "Show me basic patient data."
    assert intent.allowed_tools == (
        "get_basic_patient_data",
        "get_source_detail",
    )
    assert intent.max_rows == 10
    assert intent.lookback_days == 0
    assert intent.allowed_source_types == ("patient_record",)
    assert intent.is_source_drilldown is False


# ---------------------------------------------------------------------------
# allowed_tools cross-validation against M5 registry
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("intent_id", EXPECTED_INTENT_IDS)
def test_allowed_tools_are_real_tools(intent_id: str) -> None:
    """Every ``allowed_tools`` entry must be a real tool in the M5 registry."""
    catalog = default_catalog()
    intent = catalog.get(intent_id)
    registered = set(default_registry().list_names())
    unknown = sorted(set(intent.allowed_tools) - registered)
    assert unknown == [], (
        f"{intent_id}: allowed_tools references unknown tools: {unknown!r}"
    )


def test_allowed_tools_are_minimal_set() -> None:
    """Each non-drilldown intent should reference at most its primary tool plus get_source_detail."""
    catalog = default_catalog()
    for intent in catalog.all():
        if intent.is_source_drilldown:
            assert intent.allowed_tools == ("get_source_detail",)
        else:
            assert "get_source_detail" in intent.allowed_tools, (
                f"{intent.intent_id}: every evidence intent must allow source drilldown"
            )
            # Smallest necessary set: at most one primary tool + drilldown.
            assert len(intent.allowed_tools) <= 2, (
                f"{intent.intent_id}: allowed_tools is wider than necessary: "
                f"{intent.allowed_tools!r}"
            )


# ---------------------------------------------------------------------------
# UnknownIntentError on lookup
# ---------------------------------------------------------------------------


def test_get_unknown_raises_unknown_intent_error() -> None:
    catalog = default_catalog()
    with pytest.raises(UnknownIntentError) as excinfo:
        catalog.get("nonexistent_intent")
    assert excinfo.value.intent_id == "nonexistent_intent"


def test_unknown_intent_error_is_key_error_subclass() -> None:
    """UnknownIntentError must subclass KeyError so legacy ``except KeyError`` works."""
    catalog = default_catalog()
    with pytest.raises(KeyError):
        catalog.get("nonexistent_intent")


def test_contains_uses_registered_intent_id() -> None:
    catalog = default_catalog()
    assert "basic_patient_data" in catalog
    assert "nonexistent_intent" not in catalog
    assert 42 not in catalog  # type: ignore[operator]


# ---------------------------------------------------------------------------
# show_source is the only drilldown intent
# ---------------------------------------------------------------------------


def test_show_source_is_source_drilldown() -> None:
    catalog = default_catalog()
    assert catalog.get("show_source").is_source_drilldown is True


def test_basic_patient_data_is_not_source_drilldown() -> None:
    catalog = default_catalog()
    assert catalog.get("basic_patient_data").is_source_drilldown is False


def test_only_show_source_is_drilldown() -> None:
    catalog = default_catalog()
    drilldown_ids = [
        intent.intent_id
        for intent in catalog.all()
        if intent.is_source_drilldown
    ]
    assert drilldown_ids == ["show_source"]


# ---------------------------------------------------------------------------
# IntentDefinition immutability
# ---------------------------------------------------------------------------


def test_intent_definition_is_frozen() -> None:
    catalog = default_catalog()
    intent = catalog.get("basic_patient_data")
    with pytest.raises((AttributeError, Exception)):
        intent.intent_id = "mutated"  # type: ignore[misc]


def test_intent_definition_max_rows_is_frozen() -> None:
    catalog = default_catalog()
    intent = catalog.get("basic_patient_data")
    with pytest.raises((AttributeError, Exception)):
        intent.max_rows = 9999  # type: ignore[misc]


# ---------------------------------------------------------------------------
# IntentCatalog rejects unknown tool references at boot
# ---------------------------------------------------------------------------


def _make_intent(**overrides: object) -> IntentDefinition:
    """Build a minimally valid IntentDefinition, overriding ``**overrides``."""
    kwargs: dict[str, object] = {
        "intent_id": "test_intent",
        "label": "Test intent",
        "goal_template": "Test goal.",
        "allowed_tools": ("get_basic_patient_data",),
        "max_rows": 5,
        "lookback_days": 0,
        "allowed_source_types": ("patient_record",),
        "is_source_drilldown": False,
    }
    kwargs.update(overrides)
    return IntentDefinition(**kwargs)  # type: ignore[arg-type]


def test_catalog_rejects_unknown_tool_reference() -> None:
    bogus = _make_intent(allowed_tools=("nonexistent_tool",))
    with pytest.raises(UnknownToolReferenceError) as excinfo:
        IntentCatalog([bogus])
    assert "nonexistent_tool" in str(excinfo.value)
    assert "test_intent" in str(excinfo.value)


def test_catalog_rejects_one_unknown_tool_among_known() -> None:
    bogus = _make_intent(
        allowed_tools=("get_basic_patient_data", "definitely_not_a_tool"),
    )
    with pytest.raises(UnknownToolReferenceError) as excinfo:
        IntentCatalog([bogus])
    assert "definitely_not_a_tool" in str(excinfo.value)


def test_catalog_rejects_duplicate_intent_id() -> None:
    a = _make_intent(intent_id="dup_intent")
    b = _make_intent(intent_id="dup_intent")
    with pytest.raises(ValueError) as excinfo:
        IntentCatalog([a, b])
    assert "dup_intent" in str(excinfo.value)


def test_catalog_rejects_non_intent_definition() -> None:
    with pytest.raises(TypeError):
        IntentCatalog(["not_an_intent_definition"])  # type: ignore[list-item]


# ---------------------------------------------------------------------------
# IntentDefinition validation
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    "bad_intent_id",
    [
        "",
        "Bad-Id",
        "1starts_with_digit",
        "has spaces",
        "UPPER",
    ],
)
def test_intent_definition_rejects_invalid_intent_id(bad_intent_id: str) -> None:
    with pytest.raises(ValueError):
        _make_intent(intent_id=bad_intent_id)


def test_intent_definition_rejects_blank_label() -> None:
    with pytest.raises(ValueError):
        _make_intent(label="   ")


def test_intent_definition_rejects_blank_goal_template() -> None:
    with pytest.raises(ValueError):
        _make_intent(goal_template="")


def test_intent_definition_rejects_empty_allowed_tools() -> None:
    with pytest.raises(ValueError):
        _make_intent(allowed_tools=())


@pytest.mark.parametrize("max_rows", [-1, -100])
def test_intent_definition_rejects_negative_max_rows(max_rows: int) -> None:
    with pytest.raises(ValueError):
        _make_intent(max_rows=max_rows)


@pytest.mark.parametrize("lookback_days", [-1, -365])
def test_intent_definition_rejects_negative_lookback_days(lookback_days: int) -> None:
    with pytest.raises(ValueError):
        _make_intent(lookback_days=lookback_days)
