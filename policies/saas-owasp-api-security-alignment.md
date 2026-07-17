# SaaS API Security Policy — OWASP API Security Top 10 Alignment

**Policy ID:** SAAS-OWASP-001
**Effective Date:** 2026-07-16
**Scope:** SaaS API activity events ingested via Xylem-L6 and evaluated for compliance risk under the `saas` domain.
**Source:** OWASP API Security Top 10 (2023) — confirmed current edition as of mid-2026; the API-specific list is maintained independently of the general OWASP Top 10 and should not be conflated with it.

---

## 1. Purpose

This policy grounds Sentinel-L7's evaluation of SaaS API activity signals in the OWASP API Security Top 10's risk taxonomy, so that scored events can be traced back to a named, industry-recognized risk category rather than an ungrounded heuristic.

---

## 2. Broken Authentication

Broken Authentication covers failures in verifying that a caller is who they claim to be, including insufficient protection against automated credential-guessing attacks. Authentication endpoints that do not rate-limit or otherwise throttle repeated failed attempts allow an attacker to test large numbers of credential pairs — obtained from prior breaches elsewhere — against a target system at volume.

**Detection indicator:** a sustained rate of authentication or API calls from a single identity that substantially exceeds that identity's established baseline within a short window is treated as a signal of possible credential-stuffing or brute-force activity, warranting elevated scrutiny even absent a confirmed successful compromise.

---

## 3. Broken Function Level Authorization

Broken Function Level Authorization occurs when an API fails to properly restrict which authenticated identities may invoke which functions or endpoints — commonly manifesting as a caller reaching administrative or privileged operations that their assigned role should not permit. This differs from object-level authorization failures (accessing another user's data) in that the exposure is to an entire class of *operation*, not a specific record.

**Detection indicator:** an identity invoking an API scope or permission level materially broader than its established historical pattern is treated as a candidate scope-escalation event, regardless of whether the underlying authorization check technically succeeded — a technically-permitted but behaviorally novel escalation still warrants review.

---

## 4. Evaluation Notes

Events scored against this policy are evaluated for pattern match against the two categories above; a match does not itself constitute a confirmed violation, but elevates the event for downstream review consistent with Sentinel-L7's semantic-cache / AI-analysis / rule-based-fallback pipeline.
