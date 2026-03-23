<?php

use Laravel\Mcp\Facades\Mcp;

// Throttle to prevent abuse of Gemini embedding + Upstash on every call.
// Add ->middleware(['auth:sanctum']) here when token auth is needed.
Mcp::web('/mcp', \App\Mcp\Servers\SentinelServer::class)
    ->middleware(['throttle:60,1']);
