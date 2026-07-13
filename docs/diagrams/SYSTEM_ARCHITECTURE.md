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
            CE[ComplianceEngine]
            CM[ComplianceManager\nService Manager]
            ES[EmbeddingService]
            VCS[VectorCacheService]
        end

        subgraph Drivers
            OLD[OllamaDriver]
            GD[GeminiDriver]
            OD[OpenRouterDriver]
            VAD[VertexAIDriver]
        end
    end

    subgraph Workers["Background Processes"]
        W[sentinel:consume\nStream Worker]
        R[sentinel:reclaim\nPEL Reclaimer]
        WA[sentinel:watch-axioms\nAxiom Worker]
    end

    subgraph External["External Services"]
        GemEmbed["Gemini\nEmbedding API"]
        GemAI["Gemini Flash\nAI Analysis"]
        OllamaAI["Ollama qwen3.5\nAI Analysis (default)"]
        VertexAI["Vertex AI\nClaude Sonnet 4.6"]
        UV["Upstash Vector\nns:default + ns:policies"]
        Redis["Upstash Redis\nStreams\ntransactions + synapse:axioms"]
        PG["Neon PostgreSQL\ncompliance_events"]
    end

    Home --> HC
    Login --> AC
    Dash --> DC
    DC --> AM
    AM --> HIR

    W --> CE
    CE --> CM
    CM --> OLD & GD & OD & VAD
    OLD --> OllamaAI
    GD --> GemAI
    OD --> GemAI
    VAD --> VertexAI
    CE --> ES --> GemEmbed
    CE --> VCS --> UV
    W --> Redis
    R --> Redis
    WA --> Redis
    WA --> CM
    WA --> PG
    OLD --> ES
    OLD --> VCS
    GD --> ES
    GD --> VCS
    VAD --> ES
    VAD --> VCS

    classDef frontend fill:#0f172a,stroke:#3b82f6,color:#93c5fd
    classDef controller fill:#0f172a,stroke:#6366f1,color:#a5b4fc
    classDef service fill:#0f172a,stroke:#10b981,color:#6ee7b7
    classDef external fill:#0f172a,stroke:#f59e0b,color:#fcd34d
    classDef worker fill:#0f172a,stroke:#ef4444,color:#fca5a5

    class Home,Login,Dash frontend
    class HC,AC,DC,HIR,AM controller
    class CE,CM,ES,VCS,OLD,GD,OD,VAD service
    class GemEmbed,GemAI,OllamaAI,VertexAI,UV,Redis external
    class W,R,WA worker
```

## Service Dependency Graph

```mermaid
graph LR
    W[sentinel:consume] --> CE[ComplianceEngine]
    W --> ES[EmbeddingService]
    W --> VCS[VectorCacheService]
    W --> Redis[(Redis Stream\ntransactions)]

    CE --> CM[ComplianceManager]
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
    OD --> GemAI
    VAD --> VertexAI((Vertex AI\nClaude Sonnet 4.6))
    VAD --> ES
    VAD --> VCS
    ES --> GemEmbed((Gemini Embed))
    VCS --> UV((Upstash Vector\nns:default + ns:policies))

    R[sentinel:reclaim] --> Redis

    WA[sentinel:watch-axioms] --> APS[AxiomProcessorService]
    WA --> ASS[AxiomStreamService]
    WA --> AxRedis[(Redis Stream\nsynapse:axioms)]
    APS --> CM
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
