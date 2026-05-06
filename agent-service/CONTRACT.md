# Agent Sidecar HTTP Contract

> **Status:** Frozen (S1)
> **Date:** 2026-05-06
> **Version:** 1.0.0

This document is the canonical reference for the HTTP interface between the
OpenEMR PHP host and the Python agent sidecar. Both sides must implement
against this spec -- no field may be added, removed, or renamed without
bumping the version and updating both implementations.

---

## Authentication

Every request to the sidecar must include a shared secret in the
`X-Agent-Secret` header.

| Header           | Required | Description                          |
|------------------|----------|--------------------------------------|
| `X-Agent-Secret` | Yes      | Shared secret configured at deploy   |

**Behavior on missing or invalid secret:**

| Condition        | HTTP Status | Body                                                        |
|------------------|-------------|-------------------------------------------------------------|
| Header missing   | `401`       | `{"error": "unauthorized", "detail": "Missing X-Agent-Secret header"}` |
| Secret incorrect | `403`       | `{"error": "forbidden", "detail": "Invalid X-Agent-Secret value"}`     |

Authentication is evaluated **before** any other processing. A failed auth
check must not trigger document parsing, LLM calls, or database writes.

---

## Health Check

### `GET /healthz`

Returns the sidecar's readiness status. No authentication required.

**Response (HTTP 200):**

```json
{
  "status": "ok"
}
```

Use this endpoint for container orchestration liveness/readiness probes.

---

## Run Agent

### `POST /api/agent/run`

Submit a document for extraction, guideline retrieval, and clinical summary
generation.

**Content-Type:** `application/json`

### Request Body

| Field          | Type     | Required | Constraints                        | Description                                          |
|----------------|----------|----------|------------------------------------|------------------------------------------------------|
| `patient_id`   | `int`    | Yes      | Positive (> 0)                     | OpenEMR internal patient ID (`pid`)                  |
| `file_path`    | `string` | Yes      | Non-empty, valid path              | Absolute path to the uploaded file on the shared volume |
| `doc_type`     | `string` | Yes      | Enum: `lab_pdf`, `intake_form`, `auto` | Document classification hint                         |
| `encounter_id` | `int`    | Yes      | Positive (> 0)                     | OpenEMR encounter ID the document belongs to         |
| `trace_id`     | `string` | Yes      | UUID v4 format                     | Correlation ID for distributed tracing / observability |

**Example request:**

```json
{
  "patient_id": 42,
  "file_path": "/var/shared/uploads/lab_20260506_001.pdf",
  "doc_type": "lab_pdf",
  "encounter_id": 1087,
  "trace_id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d"
}
```

### Success Response (HTTP 200)

| Field                    | Type       | Description                                                       |
|--------------------------|------------|-------------------------------------------------------------------|
| `extracted`              | `object`   | Structured extraction result; schema varies by `doc_type` (see below) |
| `evidence`               | `array`    | Retrieved guideline snippets with citation metadata               |
| `answer`                 | `string`   | Natural-language clinical summary suitable for clinician review   |
| `citations`              | `array`    | List of `Citation` objects (see below)                            |
| `cost_usd`               | `float`    | Estimated cost of this run in USD                                 |
| `latency_ms_per_step`    | `object`   | Timing breakdown keyed by step name (values are `int` milliseconds) |
| `tool_sequence`          | `array`    | Ordered list of tool/worker names invoked during the run          |
| `extraction_confidence`  | `float`    | Model confidence in the extraction, range `[0.0, 1.0]`           |

**Example success response:**

```json
{
  "extracted": {
    "hemoglobin": 13.5,
    "wbc": 7200,
    "platelets": 250000,
    "units": {
      "hemoglobin": "g/dL",
      "wbc": "cells/uL",
      "platelets": "cells/uL"
    }
  },
  "evidence": [
    {
      "guideline": "AMA Lab Reference Ranges 2025",
      "snippet": "Normal hemoglobin for adult males: 13.5-17.5 g/dL",
      "relevance_score": 0.94
    }
  ],
  "answer": "CBC results are within normal limits. Hemoglobin 13.5 g/dL, WBC 7200 cells/uL, platelets 250k cells/uL.",
  "citations": [
    {
      "source_type": "pdf_bbox",
      "page": 1,
      "bbox": [72, 200, 540, 230]
    },
    {
      "source_type": "guideline",
      "chunk_id": "ama-lab-ref-2025-cbc-003",
      "source_url": "https://guidelines.example.org/ama-lab-ref-2025",
      "snippet": "Normal hemoglobin for adult males: 13.5-17.5 g/dL"
    }
  ],
  "cost_usd": 0.0037,
  "latency_ms_per_step": {
    "pdf_parse": 120,
    "ocr": 0,
    "extraction": 830,
    "guideline_retrieval": 210,
    "summary_generation": 640
  },
  "tool_sequence": [
    "pdf_parser",
    "lab_extractor",
    "guideline_rag",
    "summary_writer"
  ],
  "extraction_confidence": 0.96
}
```

### Citation Object

A citation links a piece of the answer back to its source. The `source_type`
discriminator determines which fields are present.

#### Variant: `pdf_bbox`

Points to a bounding box in the uploaded PDF.

| Field         | Type     | Required | Description                                                  |
|---------------|----------|----------|--------------------------------------------------------------|
| `source_type` | `string` | Yes      | Literal `"pdf_bbox"`                                         |
| `page`        | `int`    | Yes      | 1-based page number in the source PDF                        |
| `bbox`        | `array`  | Yes      | Bounding box as `[x0, y0, x1, y1]` in PDF points (72 dpi)   |

#### Variant: `guideline`

Points to a chunk from the guideline knowledge base.

| Field         | Type     | Required | Description                                                  |
|---------------|----------|----------|--------------------------------------------------------------|
| `source_type` | `string` | Yes      | Literal `"guideline"`                                        |
| `chunk_id`    | `string` | Yes      | Unique identifier of the guideline chunk in the vector store |
| `source_url`  | `string` | Yes      | URL of the source guideline document                         |
| `snippet`     | `string` | Yes      | Verbatim text excerpt from the guideline                     |

### Error Response (HTTP 4xx / 5xx)

All error responses share a common envelope.

| Field      | Type     | Description                                 |
|------------|----------|---------------------------------------------|
| `error`    | `string` | Machine-readable error code (see table)     |
| `detail`   | `string` | Human-readable explanation                  |
| `trace_id` | `string` | Echo of the request `trace_id` for correlation |

**Error codes:**

| HTTP Status | `error` Value           | When                                          |
|-------------|-------------------------|-----------------------------------------------|
| `400`       | `invalid_request`       | Malformed JSON, missing required fields, or constraint violation |
| `401`       | `unauthorized`          | `X-Agent-Secret` header missing               |
| `403`       | `forbidden`             | `X-Agent-Secret` value incorrect              |
| `404`       | `file_not_found`        | `file_path` does not exist on the shared volume |
| `415`       | `unsupported_media`     | File is not a supported format                |
| `422`       | `extraction_failed`     | Document parsed but extraction could not complete |
| `429`       | `rate_limited`          | Too many concurrent requests                  |
| `500`       | `internal_error`        | Unexpected server error                       |
| `503`       | `model_unavailable`     | Upstream LLM or embedding service unreachable |

**Example error response:**

```json
{
  "error": "invalid_request",
  "detail": "patient_id must be a positive integer",
  "trace_id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d"
}
```

---

## `extracted` Object Schemas by `doc_type`

The shape of the `extracted` field depends on the `doc_type` sent in the
request. Each variant is documented below so that both the PHP consumer and
the Python producer agree on field names and types.

### `lab_pdf`

| Field      | Type     | Description                              |
|------------|----------|------------------------------------------|
| `tests`    | `array`  | List of `LabTest` objects (see below)    |
| `lab_name` | `string` | Name of the laboratory                   |
| `lab_date` | `string` | Collection date in ISO 8601 (`YYYY-MM-DD`) |

**`LabTest` object:**

| Field           | Type      | Description                         |
|-----------------|-----------|-------------------------------------|
| `name`          | `string`  | Test name (e.g., "Hemoglobin")      |
| `value`         | `float`   | Numeric result                      |
| `unit`          | `string`  | Unit of measure (e.g., "g/dL")      |
| `reference_low` | `float`   | Lower bound of normal range         |
| `reference_high`| `float`   | Upper bound of normal range         |
| `flag`          | `string`  | `"normal"`, `"high"`, or `"low"`    |

### `intake_form`

| Field               | Type     | Description                                      |
|---------------------|----------|--------------------------------------------------|
| `demographics`      | `object` | Patient-reported demographics (name, dob, etc.)  |
| `medications`       | `array`  | List of current medication strings                |
| `allergies`         | `array`  | List of reported allergy strings                  |
| `chief_complaint`   | `string` | Primary reason for visit                         |
| `medical_history`   | `array`  | List of past medical conditions                  |
| `surgical_history`  | `array`  | List of past surgical procedures                 |
| `family_history`    | `array`  | List of family medical history entries            |
| `social_history`    | `object` | Smoking, alcohol, exercise, occupation            |

### `auto`

When `doc_type` is `auto`, the sidecar first classifies the document and then
applies the matching schema above. The `extracted` object will include an
additional field:

| Field            | Type     | Description                                      |
|------------------|----------|--------------------------------------------------|
| `detected_type`  | `string` | The `doc_type` value the classifier resolved to  |

The remaining fields follow the schema for the `detected_type`.

---

## Implementation Notes

1. **Shared volume:** Both the PHP host and the sidecar must mount the same
   volume. The PHP side writes files; the sidecar reads them. File paths in
   requests are absolute paths within that volume.

2. **Timeouts:** The PHP caller should use a generous timeout (recommended
   60 seconds) because LLM inference and guideline retrieval can be slow.
   The sidecar should stream progress internally but returns a single
   JSON response.

3. **Idempotency:** Repeated calls with the same `trace_id` are not
   guaranteed to be idempotent. Each call triggers a fresh run. The caller
   is responsible for deduplication.

4. **Content-Type:** Both request and response use `application/json` with
   UTF-8 encoding. The sidecar must reject requests without a
   `Content-Type: application/json` header with HTTP 415.

5. **Versioning:** This contract is version `1.0.0`. Future breaking changes
   require a version bump. The sidecar should expose its contract version
   at `GET /healthz` in the future if needed.
