"""M25 acceptance helper: capture a sample tool-choice trace and audit PHI.

Run with::

    OPENAI_API_KEY= py -3.11 -m scripts.m25_acceptance_capture

Outputs a small JSON document to stdout containing:

* ``sample_trace`` -- tool_sequence, verifier outcome, and a redacted
  response excerpt for the ``current_medications_happy`` fixture (M22
  copilot-tools suite).
* ``phi_audit`` -- pass/fail counts and any hits for the JSONL file
  ``agent_service/eval/m25-runs.jsonl`` produced by the
  ``--record-runs`` flag on the extraction (week2) suite.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any

# Ensure the package is importable when run from agent-service/.
HERE = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(HERE))

from agent_service.answer.builder import ResponseBuilder  # noqa: E402
from agent_service.eval.copilot_tools_suite import (  # noqa: E402
    DEFAULT_COPILOT_TOOLS_FIXTURES_DIR,
    _build_registry_for_fixture,
    _build_scripted_turns,
    _make_request,
    _make_run_context,
    load_fixtures,
)
from agent_service.eval.recorder_capture import RecordingEventRecorder  # noqa: E402
from agent_service.clients.tool_choice import FakeLLMToolChoiceClient  # noqa: E402
from agent_service.intents.catalog import default_catalog  # noqa: E402
from agent_service.loop import AgentLoop, AgentLoopConfig  # noqa: E402
from agent_service.observability._phi_scanner import scan_event_field_for_phi  # noqa: E402
from agent_service.verifier import AnswerVerifier  # noqa: E402


def _capture_sample_trace() -> dict[str, Any]:
    fixtures = load_fixtures(DEFAULT_COPILOT_TOOLS_FIXTURES_DIR)
    target = next(
        f for f in fixtures if f.fixture_id == "current_medications_happy"
    )
    catalog = default_catalog()
    intent = catalog.get(target.intent_id)
    registry = _build_registry_for_fixture(intent=intent, fixture=target)
    context = _make_run_context(intent=intent)
    scripted = _build_scripted_turns(fixture=target, intent=intent)
    client = FakeLLMToolChoiceClient(script=scripted)
    recorder = RecordingEventRecorder()
    loop = AgentLoop(
        intent_catalog=catalog,
        registry_builder=lambda _ctx: registry,
        response_builder=ResponseBuilder(),
        verifier=AnswerVerifier(),
        llm_client=client,
        config=AgentLoopConfig(
            max_iterations=8,
            max_wall_time_s=30.0,
            max_tool_calls=12,
        ),
        event_recorder=recorder,
    )
    result = loop.run(
        request=_make_request(intent, target.user_goal),
        context=context,
    )
    return {
        "fixture_id": target.fixture_id,
        "intent_id": intent.intent_id,
        "halt_reason": result.halt_reason,
        "tool_sequence": [
            {
                "tool_name": rec.tool_name,
                "result_count": rec.result_count,
                "error_class": rec.error_class,
            }
            for rec in result.tool_sequence
        ],
        "verification_status": result.response.verification_status,
        "claims": [
            {"text": c.text, "citation_ids": list(c.citation_ids), "certainty": c.certainty}
            for c in result.response.claims
        ],
        "citation_ids": list(result.response.citation_ids),
        "citations": [
            {
                "source_type": s.source_type,
                "source_id": s.source_id,
                "label": s.label,
            }
            for s in result.response.citations
        ],
        "event_types": [e.event_type for e in recorder.events],
    }


def _scan_jsonl(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {"path": str(path), "exists": False}
    total = 0
    hits: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as fh:
        for lineno, line in enumerate(fh, start=1):
            line = line.strip()
            if not line:
                continue
            total += 1
            line_hits = scan_event_field_for_phi(line)
            if line_hits:
                hits.append({"line": lineno, "hits": line_hits})
    return {
        "path": str(path),
        "exists": True,
        "lines": total,
        "phi_hits": hits,
        "phi_clean": not hits,
    }


def main() -> int:
    sample = _capture_sample_trace()
    record_path = HERE / "agent_service" / "eval" / "m25-runs.jsonl"
    audit = _scan_jsonl(record_path)
    payload = {"sample_trace": sample, "phi_audit": audit}
    json.dump(payload, sys.stdout, indent=2, sort_keys=False, default=str)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
