# Transaction Processing Pipeline

## Cache vs. RAG — Two Separate Vector Systems

The fingerprint/cache system and the policy RAG system are two independent vector spaces — different namespaces, different embeddings, different similarity thresholds. They never touch each other. The only place the fingerprint vector reappears is the final write-back step, and it's the same vector computed at step 2, not anything produced by RAG.

```mermaid
flowchart TD
    A["Transaction arrives"] --> B["Fingerprint embedding<br/>Bucketed transaction shape"]
    B --> C["Transactions cache search<br/>Own namespace, cosine match"]
    C -->|hit| D["Cache hit<br/>Return cached verdict"]
    C -->|miss| E["Policy query embedding<br/>New text, new vector"]
    E --> F["Policy RAG search<br/>Separate policies namespace"]
    F --> G["LLM call<br/>Prompt includes policy chunks"]
    G --> H["Write result to cache<br/>Reuses step 2 vector"]

    class A neutral
    class B,C,D,H teal
    class E,F purple
    class G coral

    classDef neutral fill:#F1EFE8,stroke:#5F5E5A,color:#2C2C2A
    classDef teal fill:#E1F5EE,stroke:#0F6E56,color:#04342C
    classDef purple fill:#EEEDFE,stroke:#534AB7,color:#26215C
    classDef coral fill:#FAECE7,stroke:#993C1D,color:#4A1B0C
```

| System | Namespace | Threshold | Embedding driver |
|---|---|---|---|
| Transaction cache | `transactions` | ≥ 0.90 (`UPSTASH_VECTOR_THRESHOLD`, ADR-0015) | Active `EmbeddingDriver` — Ollama `nomic-embed-text` default (ADR-0025) or Gemini `embedding-001` |
| Policy RAG | `policies` | ≥ 0.70 | Same `EmbeddingDriver`, different query text |

No implicit/default namespace is used anywhere — both are explicit (ADR-0026).

## Full Worker Flow

```mermaid
%%{init: {'themeVariables': {'fontSize': '10px'}, 'flowchart': {'nodeSpacing': 15, 'rankSpacing': 25}}}%%
flowchart TD
    Start(["sentinel:watch"]) --> Loop["Top of every loop iteration"]
    Loop --> Reclaim["XAUTOCLAIM\nidle ≥ sentinel.reclaim.idle_ms (30000ms)"]

    Reclaim --> ClaimedCheck{"Claimed\nmessages?"}
    ClaimedCheck -- "Yes" --> DeliveryCheck{"deliveryCount ≥\nsentinel.reclaim.delivery_count_limit (3)?"}
    DeliveryCheck -- "Yes" --> DeadLetter["Log::error + XACK\nwithout processing"]
    DeliveryCheck -- "No" --> ProcessRecord
    ClaimedCheck -- "No" --> ReadGroup

    ReadGroup["XREADGROUP COUNT 1\nBLOCK 5000ms"] --> ForEach{"New\nmessage?"}
    ForEach --> ProcessRecord

    subgraph ProcessRecord["processRecord(record)"]
        Parse["Parse transaction payload"] --> Fingerprint["Build fingerprint\nAmount|Type|Category|Time bucket|Merchant"]
        Fingerprint --> Embed["EmbeddingService\nActive EmbeddingDriver"]
        Embed --> Search["VectorCacheService.searchNamespace\nns:transactions / cosine ≥ 0.90"]

        Search --> CacheCheck{"Cache\nhit?"}

        CacheCheck -- "Hit ✓" --> EpochCheck{"Matches current\nsentinel_policy_epoch?"}
        EpochCheck -- "Yes" --> LogHit["Log cached result\n+ record cache_hit metric"]
        LogHit --> ACK1["XACK"]
        EpochCheck -- "No — stale" --> PolicyRAG

        CacheCheck -- "Miss ✗" --> PolicyRAG["Fetch policies\nns:policies / ≥ 0.70, filtered by domain"]
        PolicyRAG --> AIAnalysis["Active ComplianceDriver.analyze\nOllama qwen3.5 default / Gemini / OpenRouter / VertexAI"]
        AIAnalysis --> Upsert["VectorCacheService.upsertNamespace\nns:transactions"]
        Upsert --> LogMiss["Log AI result\n+ record cache_miss metric"]
        LogMiss --> ACK2["XACK"]
    end

    ProcessRecord --> Loop

    subgraph Fallback["Tier 3 — embedding or vector search throws"]
        RuleBased["ThreatAnalysisService\nrule-based, amount threshold, no AI"]
        RuleBased --> LogFallback["Log + record fallback metric"]
        LogFallback --> ACK3["XACK — always ack, even on fallback"]
    end

    Embed -.->|"Embedding/Vector failure"| Fallback
    Search -.->|"Embedding/Vector failure"| Fallback

    style ProcessRecord fill:#0f172a,stroke:#3b82f6
    style Fallback fill:#0f172a,stroke:#ef4444
```

## Message Lifecycle State Machine

```mermaid
stateDiagram-v2
    [*] --> New: XADD to transactions stream
    New --> Pending: Worker reads (XREADGROUP)

    state Pending {
        [*] --> Processing
        Processing --> Acknowledged: XACK (success or fallback)
        Processing --> Zombie: Worker crashed / idle ≥ 30000ms
    }

    Zombie --> Processing: Next worker's XAUTOCLAIM\n(top of its own loop — no dedicated reclaimer, ADR-0022)
    Acknowledged --> [*]
```

## Semantic Cache Logic

```mermaid
sequenceDiagram
    participant W as Worker
    participant V as Upstash Vector (transactions)
    participant P as Upstash Vector (policies)
    participant M as Active ComplianceDriver
    participant S as Redis Stream

    S->>W: Fetch transaction (XREADGROUP)

    Note over W,V: Semantic fingerprint → embedding
    W->>V: searchNamespace (cosine similarity)

    alt Similarity ≥ 0.90 and epoch matches — Cache Hit
        V-->>W: Return cached risk report
        Note over W: Skips LLM entirely (fast path)
        W->>S: XACK
    else Similarity < 0.90, or stale epoch — Cache Miss
        V-->>W: No match
        W->>P: searchNamespace ns:policies (≥ 0.70, top 3, domain filter)
        P-->>W: Policy context (AML / HIPAA / etc.)

        W->>M: Analyze transaction + policy context
        M-->>W: Structured JSON risk report

        W->>V: upsertNamespace ns:transactions
        W->>S: XACK
    end
```
