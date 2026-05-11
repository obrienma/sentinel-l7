Write an Architecture Decision Record for $ARGUMENTS.

Follow the format established in docs/adr/. The next ADR number is determined by listing docs/adr/ and incrementing the highest number.

File naming: docs/adr/{NNNN}-{kebab-case-title}.md

Required sections, in order:
- H1 title: "ADR-{NNNN}: {Title}"
- Bold metadata: Date (today), Status (Accepted / Proposed / Deprecated / Superseded)
- ## Context — the problem, the forces at play, and why a decision was needed. Be specific: name the failure mode, the race condition, the operational gap. No solution content in this section.
- ## Decision — what was chosen and exactly how it works. Include code-level detail (method names, exception types, log levels) so the ADR is self-contained. If the decision has multiple parts, enumerate them.
- ## Alternatives Considered — at least two alternatives with explicit pros, cons, and the reason each was rejected.
- ## Consequences — split into Positive and Negative / Trade-offs. Be honest about what the decision does NOT solve. Note any follow-on work it defers or creates.

Style rules:
- Write in past tense for Context and Consequences; present tense for Decision.
- Consequences must include at least one Negative entry. If the tradeoffs are genuinely minor, state that explicitly rather than omitting the section.
- Cross-reference related ADRs by number where relevant.
- Do not pad with generic statements. Every sentence should be specific to this decision.
