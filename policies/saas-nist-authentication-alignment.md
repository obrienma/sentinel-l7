# SaaS API Security Policy — NIST SP 800-63B-4 Authentication Alignment

**Policy ID:** SAAS-NIST-001
**Effective Date:** 2026-07-16
**Scope:** SaaS API activity events ingested via Xylem-L6 and evaluated for compliance risk under the `saas` domain.
**Source:** NIST SP 800-63B-4, *Digital Identity Guidelines: Authentication and Authenticator Management* — supersedes the withdrawn SP 800-63B as of August 2025. Note the retitling from the prior revision's "Authentication and Lifecycle Management."

---

## 1. Purpose

This policy grounds Sentinel-L7's evaluation of session- and location-based SaaS API risk signals in NIST's current authentication guidance, which frames authentication confidence as a continuous, risk-based property rather than a single pass/fail event at login.

---

## 2. Risk-Based and Continuous Authentication

Rather than treating authentication as complete at the moment of initial login, current guidance recognizes that the confidence a system holds in a subject's identity should be continuously informed by contextual signals observed throughout a session — including network origin, device characteristics, and behavioral consistency with the subject's established pattern. A successful initial authentication does not itself vouch for the legitimacy of all subsequent activity within that session.

**Detection indicator — first-seen network origin:** an API call originating from a network address never previously observed for a given identity is treated as a contextual risk signal. It does not by itself indicate compromise, but represents a departure from established baseline warranting inclusion in the identity's risk profile for that session.

---

## 3. Geographic and Temporal Plausibility

A subject authenticated from one geographic location who is then observed acting from a location that could not plausibly be reached in the elapsed time represents a strong signal that the authenticator is under the control of more than one actor, or has been compromised. This "impossible travel" pattern is a widely adopted heuristic among identity providers precisely because it requires no assumption about *how* a credential was obtained — only that its observed usage pattern is physically inconsistent with a single legitimate holder.

**Detection indicator:** two authenticated actions attributed to the same identity, separated by a travel distance and time delta inconsistent with any available mode of transportation, are treated as a high-confidence risk signal warranting immediate elevation, distinct from the lower-confidence first-seen-origin signal above.

---

## 4. Evaluation Notes

Both signals in this policy are contextual risk indicators under a continuous-evaluation model, not independent violations — consistent with NIST's guidance that authentication assurance is a property maintained across a session, not established once and assumed to persist.
