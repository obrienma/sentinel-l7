# System Architecture Diagrams

## High-Level Component Map

```mermaid
graph TB
    subgraph Frontend["Frontend (React 19 + Inertia.js)"]
        Home["/ Home Page"]
        Login["/login"]
        Dash["/dashboard"]
    end

    subgraph Backend["Laravel 12 Web Process"]
        subgraph Controllers
            HC[HomeController]
            AC[AuthController]
            DC[DashboardController]
        end

        subgraph Middleware
            HIR[HandleInertiaRequests\nflash props]
            AM[auth middleware]
        end

        subgraph Services
            TPS[TransactionProcessorService]
            APS[AxiomProcessorService]
            CM[ComplianceManager\nService Manager]
            ES[EmbeddingService]
            VCS[VectorCacheService]
            TAS[ThreatAnalysisService\nTier 3 fallback]
            ATAS[AxiomThreatAnalysisService\nTier 3 fallback]
        end

        subgraph Drivers
            OLD[OllamaDriver]
            GD[GeminiDriver]
            OD[OpenRouterDriver]
            VAD[VertexAIDriver]
        end
    end

    subgraph Workers["Background Processes\n(XAUTOCLAIM inline each loop — no dedicated reclaimer, ADR-0022)"]
        W[sentinel:watch\nTransaction Worker]
        WA[sentinel:watch-axioms\nAxiom Worker]
    end

    subgraph External["External Services"]
        EmbedAI["Active EmbeddingDriver\nOllama nomic-embed-text 768-dim (default, ADR-0025)\nor Gemini embedding-001 1536-dim"]
        GemAI["Gemini Flash\nAI Analysis"]
        OllamaAI["Ollama qwen3.5\nAI Analysis (default, ADR-0027)"]
        OpenRouterAI["OpenRouter\nAI Analysis"]
        VertexAI["Vertex AI\nClaude Sonnet 4.6 (ADR-0030)"]
        UV["Upstash Vector\nns:transactions + ns:policies (ADR-0026)"]
        Redis["Upstash Redis\nStreams\ntransactions + synapse:axioms"]
        PG["Neon PostgreSQL\ncompliance_events"]
    end

    Home --> HC
    Login --> AC
    Dash --> DC
    DC --> AM
    AM --> HIR

    W --> TPS
    TPS --> CM
    TPS --> ES
    TPS --> VCS
    TPS --> TAS
    CM --> OLD & GD & OD & VAD
    OLD --> OllamaAI
    GD --> GemAI
    OD --> OpenRouterAI
    VAD --> VertexAI
    ES --> EmbedAI
    VCS --> UV
    W --> Redis
    WA --> Redis
    WA --> APS
    APS --> CM
    APS --> ATAS
    APS --> PG
    OLD --> ES
    OLD --> VCS
    GD --> ES
    GD --> VCS
    OD --> ES
    OD --> VCS
    VAD --> ES
    VAD --> VCS

    classDef frontend fill:#0f172a,stroke:#3b82f6,color:#93c5fd
    classDef controller fill:#0f172a,stroke:#6366f1,color:#a5b4fc
    classDef service fill:#0f172a,stroke:#10b981,color:#6ee7b7
    classDef external fill:#0f172a,stroke:#f59e0b,color:#fcd34d
    classDef worker fill:#0f172a,stroke:#ef4444,color:#fca5a5

    class Home,Login,Dash frontend
    class HC,AC,DC,HIR,AM controller
    class TPS,APS,CM,ES,VCS,TAS,ATAS,OLD,GD,OD,VAD service
    class EmbedAI,GemAI,OllamaAI,OpenRouterAI,VertexAI,UV,Redis,PG external
    class W,WA worker
```

## Service Dependency Graph

```mermaid
graph LR
    W["sentinel:watch\n(XAUTOCLAIM inline, ADR-0022)"] --> TPS[TransactionProcessorService]
    W --> Redis[(Redis Stream\ntransactions)]

    TPS --> ES[EmbeddingService]
    TPS --> VCS[VectorCacheService]
    TPS --> CM[ComplianceManager]
    TPS -.->|embedding/vector failure| TAS[ThreatAnalysisService\nTier 3 fallback]

    CM --> OLD[OllamaDriver]
    CM --> GD[GeminiDriver]
    CM --> OD[OpenRouterDriver]
    CM --> VAD[VertexAIDriver]

    OLD --> OllamaAI((Ollama qwen3.5\ndefault))
    OLD --> ES
    OLD --> VCS
    GD --> GemAI((Gemini Flash))
    GD --> ES
    GD --> VCS
    OD --> OpenRouterAI((OpenRouter))
    OD --> ES
    OD --> VCS
    VAD --> VertexAI((Vertex AI\nClaude Sonnet 4.6))
    VAD --> ES
    VAD --> VCS
    ES --> EmbedAI((Active EmbeddingDriver\nOllama nomic-embed-text\ndefault, or Gemini))
    VCS --> UV((Upstash Vector\nns:transactions + ns:policies))

    WA["sentinel:watch-axioms\n(XAUTOCLAIM inline, ADR-0022)"] --> APS[AxiomProcessorService]
    WA --> ASS[AxiomStreamService]
    WA --> AxRedis[(Redis Stream\nsynapse:axioms)]
    APS --> CM
    APS -.->|AI failure| ATAS[AxiomThreatAnalysisService\nTier 3 fallback]
    APS --> PG[(Neon PostgreSQL\ncompliance_events)]
    ASS --> AxRedis

    IN[sentinel:ingest] --> ES
    IN --> VCS
    IN --> PolicyFiles[("policies/*.md")]
```

## Auth Flow

```mermaid
sequenceDiagram
    participant B as Browser
    participant L as Laravel
    participant S as Session

    B->>L: GET /dashboard
    L->>S: Check auth
    S-->>L: Not authenticated
    L-->>B: Redirect → /login (302)

    B->>L: GET /login
    L-->>B: Inertia render Login.jsx

    B->>L: POST /login {email, password}
    L->>L: Auth::attempt()
    L->>S: session()->regenerate()
    L-->>B: Redirect → /dashboard (302)

    B->>L: GET /dashboard
    L->>S: Check auth ✓
    L-->>B: Inertia render Dashboard.jsx
```
