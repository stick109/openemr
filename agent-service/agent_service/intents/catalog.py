"""Intent definitions and catalog for the sidecar chart copilot (M7).

The intent catalog is the single source of truth for which buttons /
goals the agent loop accepts and what each one is allowed to do.
Concretely, it ports the PHP
``OpenEMR\\Services\\Agent\\AgentIntentCatalog`` constant table into
Python while adding two pieces of policy that did not exist on the PHP
side:

- An explicit per-intent ``allowed_tools`` allow-list, so the executor
  (M6) can reject any tool call the active intent did not pre-authorize
  (defense-in-depth: smallest necessary set).
- An explicit per-intent ``allowed_source_types`` set, so the source-type
  cap on the run context can be derived from the intent rather than
  hard-coded by the caller.

The PHP side modelled caps as ``max_records`` / ``max_documents`` /
``lookback_days``.  The Python side normalises this to
``max_rows`` / ``lookback_days`` (matching the ``CopilotRunContext`` /
tool registry vocabulary).  ``max_rows`` is the PHP ``max_records``
value verbatim; ``max_documents`` is intentionally dropped because the
sidecar's evidence pipeline does not separate "records" from
"documents" -- the executor's per-tool ``max_rows`` already bounds the
total result size, and the PHP "documents" cap was never wired to a
distinct enforcement point.

Design notes
------------
- :class:`IntentDefinition` is a frozen, slotted dataclass, mirroring
  :class:`agent_service.tools.definition.ToolDefinition`.  Frozen
  semantics give us cheap immutability, slots cut memory, and
  ``__post_init__`` runs structural validation at construction time.
- :class:`IntentCatalog` cross-validates ``allowed_tools`` against an
  injectable :class:`agent_service.tools.registry.ToolRegistry` so
  typos in the catalog blow up at boot, not at first user request.
- The default catalog is constructed by :func:`default_catalog`, which
  returns a fresh :class:`IntentCatalog` instance each call so tests do
  not share mutable state.
"""

from __future__ import annotations

from collections.abc import Iterable
from dataclasses import dataclass
from typing import Final

from agent_service.tools.registry import ToolRegistry, default_registry

__all__ = [
    "IntentCatalog",
    "IntentDefinition",
    "UnknownIntentError",
    "UnknownToolReferenceError",
    "default_catalog",
]


# ---------------------------------------------------------------------------
# Errors
# ---------------------------------------------------------------------------


class UnknownIntentError(KeyError):
    """Raised by :meth:`IntentCatalog.get` when ``intent_id`` is unknown.

    Subclasses :class:`KeyError` so generic dict-style ``except KeyError``
    handlers still work, but call sites that care can pin the exact type.
    """

    def __init__(self, intent_id: str) -> None:
        self.intent_id = intent_id
        super().__init__(f"Unknown intent_id: {intent_id!r}")


class UnknownToolReferenceError(ValueError):
    """Raised when an :class:`IntentDefinition` lists a tool the registry does not know.

    This is a programmer-error class -- it should fire at process boot
    when :func:`default_catalog` constructs the catalog, never at
    request time.
    """


# ---------------------------------------------------------------------------
# Intent definition
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class IntentDefinition:
    """Immutable description of a single cataloged agent intent.

    Attributes
    ----------
    intent_id
        Stable, snake_case identifier used on the wire and for log
        correlation (e.g. ``"basic_patient_data"``).  Must match the
        PHP ``AgentIntentCatalog`` constant exactly.
    label
        Human-readable button caption (e.g. ``"Basic patient data"``).
    goal_template
        LLM-facing user-goal seed (e.g. ``"Show me basic patient data."``).
        Treated as the *initial goal* of the agent loop, not a
        hard-coded tool plan.
    allowed_tools
        Tuple of tool names (as registered in the M5 tool registry)
        that the executor will accept while this intent is active.
        Smallest necessary set: every additional tool widens the
        attack surface.
    max_rows
        Per-run row cap, passed through to the
        :class:`agent_service.auth.copilot_run_context.CopilotRunContext`
        ``max_rows`` field at mint time.  The executor enforces this
        on every tool call.
    lookback_days
        Date-window cap, ditto.  ``0`` disables the lookback window
        entirely (used by ``basic_patient_data`` and ``show_source``,
        which return point-in-time snapshots).
    allowed_source_types
        Source-taxonomy tags this intent is allowed to surface
        citations for.  Used by the executor to enforce
        ``CopilotRunContext.allowed_source_types``.
    is_source_drilldown
        ``True`` only for the ``show_source`` intent.  This branch of
        the agent loop bypasses the evidence-query path and instead
        returns the verbatim source row for a previously surfaced
        citation; UI-side flow is therefore "select a citation and
        click Show source", not "ask a question".
    """

    intent_id: str
    label: str
    goal_template: str
    allowed_tools: tuple[str, ...]
    max_rows: int
    lookback_days: int
    allowed_source_types: tuple[str, ...]
    is_source_drilldown: bool = False

    def __post_init__(self) -> None:
        self._validate_intent_id(self.intent_id)
        self._validate_non_empty_string(self.label, field_name="label")
        self._validate_non_empty_string(
            self.goal_template,
            field_name="goal_template",
        )
        self._validate_string_tuple(
            self.allowed_tools,
            field_name="allowed_tools",
            allow_empty=False,
        )
        self._validate_string_tuple(
            self.allowed_source_types,
            field_name="allowed_source_types",
            allow_empty=True,
        )
        self._validate_non_negative_int(self.max_rows, field_name="max_rows")
        self._validate_non_negative_int(
            self.lookback_days,
            field_name="lookback_days",
        )
        if not isinstance(self.is_source_drilldown, bool):
            raise ValueError("is_source_drilldown must be a bool")

    # -- validators ---------------------------------------------------------

    @staticmethod
    def _validate_intent_id(intent_id: str) -> None:
        if not isinstance(intent_id, str) or intent_id == "":
            raise ValueError("intent_id must be a non-empty string")
        # Snake_case-ish: lower letters, digits, underscores; must start
        # with a letter.  This matches the PHP constant style and the
        # tool-name pattern.
        if not intent_id[0].islower():
            raise ValueError(
                f"intent_id must start with a lowercase letter, got {intent_id!r}",
            )
        for ch in intent_id:
            if not (ch.islower() or ch.isdigit() or ch == "_"):
                raise ValueError(
                    f"intent_id may only contain lowercase letters, "
                    f"digits, and underscores, got {intent_id!r}",
                )

    @staticmethod
    def _validate_non_empty_string(value: str, *, field_name: str) -> None:
        if not isinstance(value, str):
            raise ValueError(f"{field_name} must be a string")
        if value.strip() == "":
            raise ValueError(f"{field_name} must be a non-empty string")

    @staticmethod
    def _validate_string_tuple(
        value: tuple[str, ...],
        *,
        field_name: str,
        allow_empty: bool,
    ) -> None:
        if not isinstance(value, tuple):
            raise ValueError(f"{field_name} must be a tuple")
        if not allow_empty and value == ():
            raise ValueError(f"{field_name} must be non-empty")
        for entry in value:
            if not isinstance(entry, str) or entry.strip() == "":
                raise ValueError(
                    f"{field_name} entries must be non-empty strings",
                )

    @staticmethod
    def _validate_non_negative_int(value: int, *, field_name: str) -> None:
        # Booleans are technically ints in Python -- exclude explicitly.
        if not isinstance(value, int) or isinstance(value, bool):
            raise ValueError(f"{field_name} must be an int")
        if value < 0:
            raise ValueError(
                f"{field_name} must be >= 0, got {value}",
            )


# ---------------------------------------------------------------------------
# Catalog
# ---------------------------------------------------------------------------


class IntentCatalog:
    """An ordered, immutable-after-construction collection of intents.

    The catalog is intentionally tiny: ``get``, ``list_ids``, ``all``.
    Cross-validation against a :class:`ToolRegistry` happens at
    construction time so a typo in ``allowed_tools`` raises
    :class:`UnknownToolReferenceError` immediately rather than failing
    silently when the executor first tries to dispatch.
    """

    __slots__ = ("_intents",)

    def __init__(
        self,
        intents: Iterable[IntentDefinition],
        *,
        tool_registry: ToolRegistry | None = None,
    ) -> None:
        registry = tool_registry if tool_registry is not None else default_registry()
        known_tools = set(registry.list_names())

        self._intents: dict[str, IntentDefinition] = {}
        for intent in intents:
            if not isinstance(intent, IntentDefinition):
                raise TypeError(
                    "IntentCatalog expects IntentDefinition instances, "
                    f"got {type(intent).__name__}",
                )
            if intent.intent_id in self._intents:
                raise ValueError(
                    f"Duplicate intent_id: {intent.intent_id!r}",
                )
            unknown = sorted(set(intent.allowed_tools) - known_tools)
            if unknown:
                raise UnknownToolReferenceError(
                    f"Intent {intent.intent_id!r} references unknown tool(s): "
                    f"{unknown!r}. Known tools: {sorted(known_tools)!r}.",
                )
            self._intents[intent.intent_id] = intent

    # -- lookup ----------------------------------------------------------

    def get(self, intent_id: str) -> IntentDefinition:
        """Return the definition registered under ``intent_id``.

        Raises
        ------
        UnknownIntentError
            If ``intent_id`` is not registered.
        """
        try:
            return self._intents[intent_id]
        except KeyError as exc:
            raise UnknownIntentError(intent_id) from exc

    def __contains__(self, intent_id: object) -> bool:
        return isinstance(intent_id, str) and intent_id in self._intents

    def __len__(self) -> int:
        return len(self._intents)

    def list_ids(self) -> list[str]:
        """Return all registered intent IDs, sorted alphabetically."""
        return sorted(self._intents)

    def all(self) -> tuple[IntentDefinition, ...]:
        """Return all registered intents as an immutable tuple, ID-sorted."""
        return tuple(self._intents[intent_id] for intent_id in sorted(self._intents))


# ---------------------------------------------------------------------------
# Default catalog
# ---------------------------------------------------------------------------


# Per-intent definitions.  ``intent_id``, ``label``, ``goal_template``,
# and ``max_rows`` (== PHP ``max_records``) are ported verbatim from
# ``src/Services/Agent/AgentIntentCatalog.php``.
#
# ``allowed_tools`` is *not* in the PHP catalog; it is an additional
# defense-in-depth allow-list keyed off the action-verb tool names the
# M5 stub registry exposes.  ``get_source_detail`` is included on every
# intent except where explicitly noted, because a drilldown on a
# previously surfaced citation is always permitted.
#
# ``allowed_source_types`` mirrors the ``source_types`` tuple on the
# corresponding M5 stub tool, so the executor's source-type cap matches
# the data the tool can plausibly return.
#
# Where PHP ``lookback_days`` was 0 (basic_patient_data, show_source) we
# preserve the 0 -- those intents return a point-in-time snapshot and a
# date window would be meaningless.

_BASIC_PATIENT_DATA: Final[IntentDefinition] = IntentDefinition(
    intent_id="basic_patient_data",
    label="Basic patient data",
    goal_template="Show me basic patient data.",
    allowed_tools=("get_basic_patient_data", "get_source_detail"),
    # PHP max_records=10.
    max_rows=10,
    # PHP lookback_days=0 (point-in-time snapshot).
    lookback_days=0,
    allowed_source_types=("patient_record",),
)


_CURRENT_MEDICATIONS: Final[IntentDefinition] = IntentDefinition(
    intent_id="current_medications",
    label="Current medications",
    goal_template="Show me current medications.",
    allowed_tools=("get_current_medications", "get_source_detail"),
    # PHP max_records=25.
    max_rows=25,
    # PHP lookback_days=365.
    lookback_days=365,
    allowed_source_types=("medications",),
)


_ALLERGIES_TO_CONFIRM: Final[IntentDefinition] = IntentDefinition(
    intent_id="allergies_to_confirm",
    label="Allergies to confirm",
    goal_template="Show me allergies to confirm.",
    allowed_tools=("get_active_allergies", "get_source_detail"),
    # PHP max_records=25.
    max_rows=25,
    # PHP lookback_days=365.
    lookback_days=365,
    allowed_source_types=("allergies",),
)


_RECENT_EVENTS: Final[IntentDefinition] = IntentDefinition(
    intent_id="recent_events",
    label="Recent events",
    goal_template="Show me recent events.",
    allowed_tools=("get_recent_events", "get_source_detail"),
    # PHP max_records=30.
    max_rows=30,
    # PHP lookback_days=180.
    lookback_days=180,
    allowed_source_types=(
        "encounters",
        "labs",
        "vitals",
        "procedures",
    ),
)


_CHANGED_SINCE_LAST_VISIT: Final[IntentDefinition] = IntentDefinition(
    intent_id="changed_since_last_visit",
    label="Changed since last visit",
    goal_template="Explain what changed since the last visit.",
    allowed_tools=("get_changes_since_last_visit", "get_source_detail"),
    # PHP max_records=30.
    max_rows=30,
    # PHP lookback_days=365.
    lookback_days=365,
    allowed_source_types=(
        "medications",
        "allergies",
        "problems",
        "vitals",
        "labs",
    ),
)


_SHOW_SOURCE: Final[IntentDefinition] = IntentDefinition(
    intent_id="show_source",
    label="Show source",
    goal_template="Show source evidence behind this claim.",
    # show_source is a constrained drilldown: only get_source_detail is
    # callable, and the model supplies the citation_id directly.
    allowed_tools=("get_source_detail",),
    # PHP max_records=1 -- a drilldown returns exactly one row.
    max_rows=1,
    # PHP lookback_days=0 (the citation is point-in-time).
    lookback_days=0,
    # The drilldown can land on any source type the agent has ever
    # surfaced, so we mirror the union exposed by the
    # get_source_detail stub tool.
    allowed_source_types=(
        "medications",
        "allergies",
        "problems",
        "vitals",
        "labs",
        "encounters",
        "procedures",
        "patient_record",
        "document",
    ),
    is_source_drilldown=True,
)


_DEFAULT_INTENTS: Final[tuple[IntentDefinition, ...]] = (
    _ALLERGIES_TO_CONFIRM,
    _BASIC_PATIENT_DATA,
    _CHANGED_SINCE_LAST_VISIT,
    _CURRENT_MEDICATIONS,
    _RECENT_EVENTS,
    _SHOW_SOURCE,
)


def default_catalog(
    *,
    tool_registry: ToolRegistry | None = None,
) -> IntentCatalog:
    """Return a fresh :class:`IntentCatalog` seeded with the M7 intents.

    Each call returns a **new** catalog instance so tests and runtime
    code never share mutable state by accident.  The seeded definitions
    cover every PHP intent in
    ``src/Services/Agent/AgentIntentCatalog.php`` and cross-validate
    against the M5 default tool registry.

    Parameters
    ----------
    tool_registry
        Optional tool registry to validate ``allowed_tools`` against.
        Defaults to a fresh :func:`agent_service.tools.registry.default_registry`
        instance, which is what production wiring should use.
    """
    return IntentCatalog(_DEFAULT_INTENTS, tool_registry=tool_registry)
