---
id: sentinel-l7-2026-06-16T0900-grafana-dashboard
repo: sentinel-l7
title: "Grafana Dashboard for Sentinel (TraceQL Metrics)"
date: 2026-06-16
phase: 6
tags: [traceql, wide-events, tempo, observability, prometheus]
cross_ref: observability
cross_ref_id: sentinel-l7-2026-06-16T0900-grafana-dashboard
files: [rhizome-observability/grafana/provisioning/dashboards/sentinel-l7-service.json, rhizome-observability/tempo.yaml, rhizome-observability/docker-compose.yml]
---

# Grafana Dashboard for Sentinel (TraceQL Metrics)

Built the "Sentinel-L7 Service" Grafana dashboard (in the `rhizome-observability`
repo), modelled on the existing EventHorizon dashboard. The deliverable is the
Phase-5 dashboard slice for Sentinel from the OTel migration plan: 9 panels over
the wide `axiom.process` / `axiom.ai_analysis` spans shipped in OTel Phase 2.

### Pattern: Wide-Events Querying via TraceQL Metrics
Every timeseries panel is a **TraceQL metrics** query against Tempo's
`local-blocks` metrics-generator — `rate() by (.risk_level | .domain | .routed_to_ai)`
and `quantile_over_time(duration, …)` — read directly through the Tempo datasource,
no Prometheus `remote_write`. This is the wide-events model on a pillar backend: the
high-cardinality attributes already on the spans (`source_id`, `anomaly_score`,
`domain`, `risk_level`) are the query dimensions, so no metric dimension is
pre-committed at write time. EventHorizon's dashboard is mostly PromQL only because
Node auto-instrumentation handed it RED metrics for free; Sentinel has no such free
metrics, so TraceQL metrics off the spans is the equivalent.

### Anti-Pattern Avoided: Pre-Aggregating Business Attributes into Prometheus Counters
The tempting shortcut was to emit `axioms_by_domain_total{domain=…}` /
`axiom_confidence` counters from a `MeterProvider` and chart them with PromQL (exactly
how EventHorizon's RED panels look). That bakes the cardinality decision into the
write path and, per the migration plan's anti-goal, degrades the wide-attribute story
over time — once the counter exists people query it instead of the spans. Kept all
business dimensions as span attributes queried via TraceQL; reserved Prometheus for
nothing here.

### Challenge: INTERNAL Spans Dropped by `filter_server_spans`
After bumping Tempo to 2.7.2, every metrics query returned zero series while trace
*search* still found the spans, and `tempo_metrics_generator_spans_received_total`
was absent. Root cause: Tempo 2.7's `local_blocks` processor defaults
`filter_server_spans: true`, which keeps only `SpanKind=SERVER` spans for metrics.
Sentinel's `axiom.process` / `axiom.ai_analysis` spans are `INTERNAL` (plain
`spanBuilder`, no kind set), so they were silently excluded. Fix: set
`filter_server_spans: false` in `tempo.yaml`. On Tempo 2.6.1 the same config worked
because the default was `false` — a version-drift gotcha.

### Challenge: TraceQL Quantiles Don't Apply to Span Attributes
`quantile_over_time(.anomaly_score, .95)` returns empty on both 2.6.1 and 2.7.2, while
`quantile_over_time(duration, .95)` works — Tempo's quantile/histogram functions only
operate on the `duration` intrinsic, not arbitrary numeric attributes. Latency keeps
true percentiles (off span `duration`); the anomaly-score and AI-confidence panels
fall back to `avg`/`max`/`min_over_time` (which themselves 500'd on 2.6.1 and only
work from 2.7.2 — the actual payoff of the version bump). Span-duration metric values
come back in **seconds**, so the latency panel unit is `s`.

### Challenge: AI Failures Aren't `status=error`
The "AI Errors" panel first filtered on `{ … status=error }` and matched nothing.
`AxiomProcessorService::routeToAi()` calls `recordException()` on a driver failure,
which adds an `exception` span *event* but never flips span status (`span.error` is
unset). Re-pointed the panel at `{ … event:name = "exception" }`, which matched the
failures. The AI driver throws in dev because the API key is a placeholder — which is
also why the AI-by-driver and AI-confidence panels are empty (`ai.driver` /
`ai.confidence` are success-path-only attributes); documented the enablement steps.

### Decision: Bump Tempo 2.6.1 → 2.7.2 (shared infra)
Accepted a shared-stack version bump rather than downgrading the attribute panels to
tables. 2.7.2 unlocks `avg/min/max_over_time` over attributes (a 500 on 2.6.1); the
bump is low-risk because EventHorizon's panels are PromQL (Tempo-version-independent),
so nothing existing regressed. Rejected adding a Sentinel `MeterProvider` (anti-goal).
Deferred: real attribute percentiles (need a later Tempo or a histogram metric) and
the Loki logs panel (Sentinel still logs via Monolog, not OTLP→Loki — Phase 5).
