# GDPR Data Processing Compliance Policy

**Policy ID:** GDPR-001  
**Effective Date:** 2025-01-01  
**Scope:** All processing of personal data belonging to EU and EEA data subjects, regardless of where processing occurs.

---

## 1. Purpose

This policy defines the organization's obligations under the General Data Protection Regulation (GDPR) (EU) 2016/679, as it applies to the processing of personal financial data within the Sentinel-L7 platform. It establishes the lawful basis for processing, data minimization requirements, retention limits, and breach response obligations.

---

## 2. Lawful Basis for Processing

All processing of personal data must have a documented lawful basis under Article 6 GDPR. For financial transaction monitoring, the applicable bases are:

Legal obligation (Article 6(1)(c)): Processing necessary to comply with AML, BSA, and other regulatory requirements constitutes a legal obligation. Transaction monitoring for regulatory compliance does not require consent.

Legitimate interests (Article 6(1)(f)): Fraud detection and financial crime prevention may be pursued under legitimate interests, provided a Legitimate Interests Assessment (LIA) has been conducted and documented, and the processing does not override the data subject's fundamental rights.

Consent is not the appropriate basis for regulatory compliance processing and must not be relied upon where legal obligation or legitimate interests apply.

---

## 3. Data Minimization and Purpose Limitation

Personal data collected for transaction monitoring must be limited to what is strictly necessary for the stated compliance purpose (Article 5(1)(c)). Fields such as full transaction narrative text, biometric identifiers, or health data must not be included in transaction fingerprints or compliance event records unless directly required by a specific regulatory obligation.

Data collected for AML/BSA compliance must not be repurposed for marketing, profiling, or product development without a separate lawful basis and, where required, explicit consent.

---

## 4. Automated Decision-Making

Where the Sentinel-L7 platform makes automated decisions that produce legal or similarly significant effects on data subjects—such as flagging an account for investigation or blocking a transaction—data subjects have the right under Article 22 GDPR to:

- Obtain human review of the decision
- Express their point of view
- Contest the decision

The organization must document all automated decision-making logic, maintain an audit trail of decisions, and provide a clear mechanism for data subjects to request human review.

---

## 5. Anomaly Scoring and Profiling

Behavioral anomaly scoring constitutes profiling under Article 4(4) GDPR. Anomaly scores derived from transaction history must be treated as personal data. Profiling for financial crime prevention is permissible under legitimate interests or legal obligation, but must be disclosed in the organization's privacy notice.

High anomaly scores (above 0.80 on Sentinel-L7's scale) that result in account restriction or investigation triggers require that the data subject be notified of the restriction and the existence of the automated decision unless notification would prejudice a regulatory investigation under Article 23 GDPR derogations.

---

## 6. Data Retention

Personal data in compliance event records must not be retained longer than necessary for the purpose for which it was collected. For AML/BSA compliance records, the retention period aligns with the BSA five-year requirement. After the retention period expires, personal data must be securely deleted or anonymized.

Anonymized data (where re-identification is not reasonably possible) is not subject to GDPR retention limits and may be retained for statistical or training purposes.

---

## 7. Data Breach Notification

In the event of a personal data breach affecting EU data subjects, the organization must notify the competent supervisory authority within 72 hours of becoming aware of the breach (Article 33 GDPR). Where the breach is likely to result in a high risk to individuals' rights and freedoms, affected data subjects must also be notified without undue delay (Article 34 GDPR).

A breach includes unauthorized access, disclosure, alteration, or destruction of personal data—including transaction records, compliance event logs, and audit narratives containing personal data.

---

## 8. International Data Transfers

Personal data of EU data subjects must not be transferred to countries outside the EEA unless an adequate transfer mechanism is in place: an adequacy decision by the European Commission, Standard Contractual Clauses (SCCs), or Binding Corporate Rules (BCRs).

Where compliance event data is processed by third-party AI providers (e.g., Gemini Flash via Google), the organization must ensure an appropriate data processing agreement and transfer mechanism is in place with the provider.
