# SaaS API Security Policy — MITRE ATT&CK Technique Alignment

**Policy ID:** SAAS-MITRE-001
**Effective Date:** 2026-07-16
**Scope:** SaaS API activity events ingested via Xylem-L6 and evaluated for compliance risk under the `saas` domain.
**Source:** MITRE ATT&CK — technique references cited by ID for precise, checkable grounding rather than framework-level narrative.

---

## 1. Purpose

This policy maps Sentinel-L7's SaaS API activity signals to specific, named adversary techniques, so that a scored event can be described in terms of a recognized technique ID rather than only an internal heuristic label.

---

## 2. T1110.004 — Brute Force: Credential Stuffing

Credential stuffing is the automated use of previously breached username/password pairs against a target system's authentication endpoint, relying on the tendency of individuals to reuse credentials across services. Unlike traditional brute-forcing, the attacker is not guessing — they are testing known-valid pairs sourced from an unrelated prior breach, which produces a comparatively high success rate even at low per-attempt probability.

**Maps to:** sustained-velocity authentication or API-call signal (see SAAS-OWASP-001 §2).

---

## 3. T1078 — Valid Accounts

Valid Accounts describes adversary use of legitimate, unmodified credentials to gain and maintain access — a technique that is comparatively difficult to detect precisely because the authentication itself succeeds through intended mechanisms. Detection therefore depends on identifying anomalies in *how* a valid account is being used, not on the authentication event itself failing.

**Maps to:** first-seen network origin and impossible-travel signals (see SAAS-NIST-001 §2–3), both of which detect anomalous use of an otherwise-valid, successfully-authenticated identity.

---

## 4. T1548 — Abuse Elevation Control Mechanism

This technique class covers methods by which an adversary — or a compromised or over-permissioned legitimate identity — circumvents mechanisms intended to enforce least-privilege, gaining access to functions or data beyond their assigned level. In an API context, this manifests as a caller successfully invoking functionality outside their established permission pattern, whether through a genuine authorization gap or through an account whose privileges were escalated by other means.

**Maps to:** scope-escalation signal (see SAAS-OWASP-001 §3).

---

## 5. Evaluation Notes

Technique references in this policy provide identification, not attribution — a signal matching a technique's behavioral pattern indicates the pattern is consistent with that technique, not that a specific known adversary group is confirmed present.
