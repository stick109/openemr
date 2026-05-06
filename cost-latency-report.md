# Cost / Latency Report

_Generated: 2026-05-06T20:02:44.549918+00:00_
_Records analysed: 50_

## Summary

- Total runs: **50**
- Success rate: **88.00%** (44/50)
- Refusal rate: **12.00%** (6/50)
- Error rate: **0.00%** (0/50)

## Latency

### Total latency (ms)

| Percentile | Value |
| ---------- | ----- |
| p50 | 0.00 ms |
| p95 | 1.00 ms |
| p99 | 1.00 ms |

### Per-step latency

| Step | p50 | p95 | mean |
| ---- | --- | --- | ---- |
| extract | 0.00 ms | 0.00 ms | 0.00 ms |
| finalize | 0.00 ms | 0.00 ms | 0.00 ms |
| refuse | 0.00 ms | 0.00 ms | 0.00 ms |
| retrieve | 0.00 ms | 1.00 ms | 0.09 ms |

## Cost

- Total dev spend (across 50 runs): **$0.0000**
- Mean cost per run: **$0.0000**

### Projected daily cost (at mean per-run cost)

| Documents / day | Projected cost |
| --------------- | -------------- |
| 100 | $0.0000 |
| 1,000 | $0.0000 |
| 10,000 | $0.0000 |

## Bottleneck analysis

- Highest mean latency: **retrieve** (0.09 ms mean)
- Largest p95-p50 spread: **retrieve** (1.00 ms spread)

## Retrieval stats

- Mean hits per query: **4.40**
- Queries with >= 5 hits: **88.00%** (44/50)

## Confidence stats

- Mean extraction confidence: **0.819**
- p10: **0.000**
- p50: **0.920**
- p90: **0.950**
