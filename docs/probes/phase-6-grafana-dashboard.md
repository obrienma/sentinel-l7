# Probes — Phase 6: Grafana Dashboard for Sentinel (TraceQL Metrics)

See: docs/journal.md#phase-6

---
type: cloze
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, traceql]
---
The Sentinel-L7 dashboard's timeseries panels are {{c1::TraceQL metrics}} queries
read straight from Tempo's {{c2::local-blocks}} metrics-generator — no Prometheus
{{c3::remote_write}}, so the wide span attributes are the query dimensions and no
metric cardinality is committed at write time.

Extra: sentinel-l7 · Phase 6 · Pattern: Wide-Events Querying via TraceQL Metrics
See: docs/journal.md#phase-6

---
type: cloze
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, anti-pattern]
---
The anti-goal avoided was {{c1::pre-aggregating business attributes into Prometheus
counters}} (e.g. `axioms_by_domain_total{domain=…}`): once the counter exists people
query it instead of the spans, which degrades the {{c2::wide-attribute}} story over
time. Prometheus is reserved for alert/SLO signals only.

Extra: sentinel-l7 · Phase 6 · Anti-Pattern Avoided: Pre-Aggregating into Prometheus Counters
See: docs/journal.md#phase-6

---
type: cloze
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, tempo]
---
Tempo 2.7's `local_blocks` processor defaults {{c1::filter_server_spans}} to
{{c2::true}}, which keeps only {{c3::SERVER}}-kind spans for metrics. Sentinel's
`axiom.process` spans are {{c4::INTERNAL}} kind, so every metric query returned empty
(while trace *search* still worked) until it was set to `false`.

Extra: sentinel-l7 · Phase 6 · Challenge: INTERNAL Spans Dropped by filter_server_spans
See: docs/journal.md#phase-6

---
type: cloze
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, traceql]
---
In Tempo, `quantile_over_time` / `histogram_over_time` work only over the
{{c1::duration}} intrinsic, not arbitrary numeric span attributes — so the
anomaly-score and confidence panels fall back to {{c2::avg/max/min_over_time}}.
Span-duration metric values are returned in {{c3::seconds}}.

Extra: sentinel-l7 · Phase 6 · Challenge: TraceQL Quantiles Don't Apply to Span Attributes
See: docs/journal.md#phase-6

---
type: cloze
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, spans]
---
`recordException()` adds an {{c1::exception}} span *event* but does **not** set span
{{c2::status=error}} (`span.error` stays unset), so the "AI Errors" panel must filter
on {{c3::event:name = "exception"}} to catch driver failures.

Extra: sentinel-l7 · Phase 6 · Challenge: AI Failures Aren't status=error
See: docs/journal.md#phase-6

---
type: basic
deck: Rhizome::observability
tags: [sentinel-l7, observability, phase-6, decision]
---
Q: Why bump the shared Tempo from 2.6.1 to 2.7.2 instead of downgrading the
anomaly-score / confidence panels to tables, and why not add a Prometheus
`MeterProvider` to Sentinel?

A: 2.7.2 unlocks `avg/min/max_over_time` over span attributes (a 500 error on 2.6.1),
which is what those panels need. The bump is low-risk because EventHorizon's panels are
PromQL and so Tempo-version-independent — nothing existing regressed. A `MeterProvider`
was rejected because pre-aggregating business attributes into Prometheus counters is the
migration plan's explicit anti-goal; keeping everything on wide spans preserves the
arbitrary-cardinality query story.

Extra: sentinel-l7 · Phase 6 · Decision: Bump Tempo 2.6.1 → 2.7.2
See: docs/journal.md#phase-6
