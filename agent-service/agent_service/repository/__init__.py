"""Read-only OpenEMR repository layer for the sidecar (M9).

Step M9 of ``Clinical Co-Pilot Migration to Python Sidecar.md`` ports a
narrowed slice of the PHP ``SqlEvidenceRecordRepository`` into Python so
the read-only evidence tools introduced in M10 / M11 can issue parameterized
SQL against an OpenEMR schema using a least-privilege credential.

The repository is deliberately surface-area-minimal:

* No generic ``query(sql)`` -- every method is a named, explicit query.
* Patient identity is **always** read from the verified
  :class:`agent_service.auth.copilot_run_context.CopilotRunContext`. It
  is never accepted as a method parameter, so a tool author cannot
  accidentally (or maliciously) pass a different patient's ID.
* Row caps and lookback windows live in the SQL ``LIMIT`` / ``WHERE``
  clauses, not in Python-side post-filtering. The DB never streams
  more rows than the policy allows.
* Construction fails closed: missing DB credentials raise
  :class:`RepositoryConfigurationError` rather than silently using
  defaults that might point at a writable account.
"""

from agent_service.repository.openemr import (
    OpenEmrReadRepository,
    RepositoryConfigurationError,
    parse_source_id,
)


__all__ = [
    "OpenEmrReadRepository",
    "RepositoryConfigurationError",
    "parse_source_id",
]
