"""Tests for the tool-registry primitives introduced in step M5.

Covers:

- :func:`default_registry` lists every stub tool.
- Each stub's ``input_schema`` is a structurally valid JSON Schema with
  no PHI / unsafe surfaces as model-supplied properties.
- :meth:`ToolRegistry.model_facing_schemas` exposes only the model-safe
  subset (``name``, ``description``, ``input_schema``) and filters by
  ``allowed`` correctly.
- :class:`ToolDefinition` rejects every forbidden top-level
  ``input_schema.properties`` key.
- Duplicate registration raises :class:`ToolRegistryError`.
- Lookup of an unknown tool raises :class:`ToolNotFoundError`.
"""

from __future__ import annotations

from typing import Any

import pytest

from agent_service.tools import (
    FORBIDDEN_INPUT_KEYS,
    ToolDefinition,
    ToolDefinitionError,
    ToolNotFoundError,
    ToolRegistry,
    ToolRegistryError,
    default_registry,
)


EXPECTED_STUB_NAMES: list[str] = [
    "get_active_allergies",
    "get_basic_patient_data",
    "get_changes_since_last_visit",
    "get_current_medications",
    "get_recent_events",
    "get_source_detail",
]


# ---------------------------------------------------------------------------
# JSON Schema structural validator (no extra dep required)
# ---------------------------------------------------------------------------


_JSON_SCALAR_TYPES: frozenset[str] = frozenset(
    {"object", "array", "string", "integer", "number", "boolean", "null"},
)


def _assert_valid_json_schema(schema: Any, *, path: str = "$") -> None:
    """Structurally check ``schema`` is a valid Draft-7-ish JSON Schema.

    The agent-service environment does not ship the ``jsonschema``
    package, so we do enough structural validation here to fail loudly
    on any malformed schema.  This is intentionally conservative: we
    only assert constraints the registry actually relies on.
    """
    assert isinstance(schema, dict), f"{path}: schema must be a dict, got {type(schema).__name__}"
    schema_type = schema.get("type")
    assert schema_type in _JSON_SCALAR_TYPES, f"{path}: invalid type {schema_type!r}"

    if schema_type == "object":
        properties = schema.get("properties", {})
        assert isinstance(properties, dict), f"{path}.properties must be a dict"
        for key, sub in properties.items():
            assert isinstance(key, str) and key != "", f"{path}.properties has bad key {key!r}"
            _assert_valid_json_schema(sub, path=f"{path}.properties.{key}")

        required = schema.get("required", [])
        assert isinstance(required, list), f"{path}.required must be a list"
        for entry in required:
            assert isinstance(entry, str), f"{path}.required has non-string {entry!r}"
            assert entry in properties, f"{path}.required references missing property {entry!r}"

        # ``additionalProperties`` may be a bool or a sub-schema.
        if "additionalProperties" in schema:
            ap = schema["additionalProperties"]
            if isinstance(ap, dict):
                _assert_valid_json_schema(ap, path=f"{path}.additionalProperties")
            else:
                assert isinstance(ap, bool), (
                    f"{path}.additionalProperties must be bool or schema, got {type(ap).__name__}"
                )

    elif schema_type == "array":
        items = schema.get("items")
        if items is not None:
            _assert_valid_json_schema(items, path=f"{path}.items")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _valid_input_schema() -> dict[str, Any]:
    return {
        "type": "object",
        "properties": {},
        "additionalProperties": False,
    }


def _make_def(**overrides: Any) -> ToolDefinition:
    """Build a ToolDefinition with safe defaults, overriding ``**overrides``."""
    kwargs: dict[str, Any] = {
        "name": "test_tool",
        "description": "Test tool description.",
        "input_schema": _valid_input_schema(),
        "required_capability": "read_basic_patient_data",
        "source_types": ("medications",),
        "read_only": True,
        "max_rows": 10,
        "executor": None,
    }
    kwargs.update(overrides)
    return ToolDefinition(**kwargs)


# ---------------------------------------------------------------------------
# default_registry()
# ---------------------------------------------------------------------------


def test_default_registry_lists_all_stub_tools() -> None:
    registry = default_registry()
    assert registry.list_names() == EXPECTED_STUB_NAMES
    assert len(registry) == len(EXPECTED_STUB_NAMES)


def test_default_registry_returns_independent_instances() -> None:
    """``default_registry()`` must not share mutable state across calls."""
    a = default_registry()
    b = default_registry()
    assert a is not b
    # Mutating one must not affect the other.
    a.register(_make_def(name="extra_tool"))
    assert "extra_tool" in a.list_names()
    assert "extra_tool" not in b.list_names()


# ---------------------------------------------------------------------------
# Stub schemas are structurally valid JSON Schema
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("name", EXPECTED_STUB_NAMES)
def test_stub_input_schema_is_valid_json_schema(name: str) -> None:
    registry = default_registry()
    tool = registry.get(name)
    _assert_valid_json_schema(tool.input_schema)


@pytest.mark.parametrize("name", EXPECTED_STUB_NAMES)
def test_stub_input_schema_has_no_forbidden_properties(name: str) -> None:
    registry = default_registry()
    tool = registry.get(name)
    properties = tool.input_schema.get("properties", {})
    leaked = set(properties) & FORBIDDEN_INPUT_KEYS
    assert leaked == set(), f"{name} leaks forbidden model-supplied keys: {sorted(leaked)!r}"


@pytest.mark.parametrize("name", EXPECTED_STUB_NAMES)
def test_stub_metadata_is_inert_and_read_only(name: str) -> None:
    registry = default_registry()
    tool = registry.get(name)
    assert tool.read_only is True
    assert tool.executor is None
    assert tool.max_rows >= 1
    assert tool.required_capability != ""
    assert len(tool.source_types) >= 1


# ---------------------------------------------------------------------------
# model_facing_schemas()
# ---------------------------------------------------------------------------


def test_model_facing_schemas_excludes_internal_fields() -> None:
    registry = default_registry()
    schemas = registry.model_facing_schemas()
    assert len(schemas) == len(EXPECTED_STUB_NAMES)
    for entry in schemas:
        assert set(entry.keys()) == {"name", "description", "input_schema"}, (
            f"unexpected keys in model-facing schema: {sorted(entry.keys())!r}"
        )
        for forbidden_field in (
            "required_capability",
            "source_types",
            "read_only",
            "max_rows",
            "executor",
        ):
            assert forbidden_field not in entry, (
                f"model-facing schema must not include {forbidden_field!r}"
            )


def test_model_facing_schemas_is_sorted_by_name() -> None:
    registry = default_registry()
    schemas = registry.model_facing_schemas()
    names = [entry["name"] for entry in schemas]
    assert names == sorted(names) == EXPECTED_STUB_NAMES


def test_model_facing_schemas_filters_by_allowed_subset() -> None:
    registry = default_registry()
    schemas = registry.model_facing_schemas(allowed=["get_basic_patient_data"])
    assert len(schemas) == 1
    assert schemas[0]["name"] == "get_basic_patient_data"


def test_model_facing_schemas_silently_drops_unknown_allowed_names() -> None:
    """Allow-list filtering ignores names that aren't registered.

    The executor (M6) is the layer that errors on unknown allow-list
    entries -- the registry just produces the model-safe surface.
    """
    registry = default_registry()
    schemas = registry.model_facing_schemas(
        allowed=["get_current_medications", "nonexistent_tool"],
    )
    names = [entry["name"] for entry in schemas]
    assert names == ["get_current_medications"]


def test_model_facing_schemas_empty_allowed_returns_empty_list() -> None:
    registry = default_registry()
    assert registry.model_facing_schemas(allowed=[]) == []


# ---------------------------------------------------------------------------
# Forbidden input keys are rejected at construction time
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    "forbidden_key",
    sorted(FORBIDDEN_INPUT_KEYS),
)
def test_tool_definition_rejects_forbidden_top_level_property(forbidden_key: str) -> None:
    bad_schema: dict[str, Any] = {
        "type": "object",
        "properties": {forbidden_key: {"type": "string"}},
        "additionalProperties": False,
    }
    with pytest.raises(ToolDefinitionError) as excinfo:
        _make_def(input_schema=bad_schema)
    assert forbidden_key in str(excinfo.value)


def test_tool_definition_lists_all_forbidden_keys_when_multiple_present() -> None:
    bad_schema: dict[str, Any] = {
        "type": "object",
        "properties": {
            "patient_id": {"type": "integer"},
            "sql": {"type": "string"},
        },
        "additionalProperties": False,
    }
    with pytest.raises(ToolDefinitionError) as excinfo:
        _make_def(input_schema=bad_schema)
    message = str(excinfo.value)
    assert "patient_id" in message
    assert "sql" in message


def test_tool_definition_accepts_safe_model_supplied_property() -> None:
    """Model-supplied ``citation_id`` is allowed -- it is not in the forbidden set."""
    safe_schema: dict[str, Any] = {
        "type": "object",
        "properties": {"citation_id": {"type": "string", "minLength": 1}},
        "required": ["citation_id"],
        "additionalProperties": False,
    }
    tool = _make_def(input_schema=safe_schema)
    assert tool.input_schema["properties"]["citation_id"]["type"] == "string"


# ---------------------------------------------------------------------------
# ToolDefinition validation: name, description, schema shape, max_rows
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    "bad_name",
    [
        "",
        "Bad-Name",
        "1starts_with_digit",
        "has spaces",
        "UPPER",
        "snake__ok_but_too_long_" + "x" * 64,
    ],
)
def test_tool_definition_rejects_invalid_names(bad_name: str) -> None:
    with pytest.raises(ToolDefinitionError):
        _make_def(name=bad_name)


def test_tool_definition_rejects_blank_description() -> None:
    with pytest.raises(ToolDefinitionError):
        _make_def(description="   ")


def test_tool_definition_rejects_non_object_input_schema() -> None:
    with pytest.raises(ToolDefinitionError):
        _make_def(input_schema={"type": "string"})


def test_tool_definition_rejects_required_referencing_missing_property() -> None:
    bad_schema: dict[str, Any] = {
        "type": "object",
        "properties": {"a": {"type": "string"}},
        "required": ["b"],
    }
    with pytest.raises(ToolDefinitionError):
        _make_def(input_schema=bad_schema)


@pytest.mark.parametrize("max_rows", [0, -1, -100])
def test_tool_definition_rejects_non_positive_max_rows(max_rows: int) -> None:
    with pytest.raises(ToolDefinitionError):
        _make_def(max_rows=max_rows)


def test_tool_definition_is_frozen() -> None:
    tool = _make_def()
    with pytest.raises((AttributeError, Exception)):
        tool.name = "mutated"  # type: ignore[misc]


# ---------------------------------------------------------------------------
# Registry behaviour: register / get / unknown
# ---------------------------------------------------------------------------


def test_register_rejects_duplicate_name() -> None:
    registry = ToolRegistry()
    registry.register(_make_def(name="dup_tool"))
    with pytest.raises(ToolRegistryError):
        registry.register(_make_def(name="dup_tool"))


def test_register_rejects_non_tool_definition() -> None:
    registry = ToolRegistry()
    with pytest.raises(TypeError):
        registry.register("not a tool")  # type: ignore[arg-type]


def test_get_unknown_raises_tool_not_found() -> None:
    registry = ToolRegistry()
    with pytest.raises(ToolNotFoundError) as excinfo:
        registry.get("nope")
    assert excinfo.value.name == "nope"


def test_tool_not_found_is_key_error_subclass() -> None:
    """ToolNotFoundError must subclass KeyError so legacy ``except KeyError`` works."""
    registry = ToolRegistry()
    with pytest.raises(KeyError):
        registry.get("nope")


def test_contains_uses_registered_name() -> None:
    registry = default_registry()
    assert "get_basic_patient_data" in registry
    assert "nonexistent_tool" not in registry
    assert 42 not in registry  # type: ignore[operator]


def test_list_names_returns_sorted_copy() -> None:
    registry = ToolRegistry()
    registry.register(_make_def(name="b_tool"))
    registry.register(_make_def(name="a_tool"))
    assert registry.list_names() == ["a_tool", "b_tool"]
