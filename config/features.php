<?php

/*
|--------------------------------------------------------------------------
| Sentinel-L7 Feature Flags
|--------------------------------------------------------------------------
|
| Each flag defaults to OFF on production and ON everywhere else.
| Any flag can be overridden explicitly in .env regardless of environment.
|
| Usage (PHP):   config('features.dashboard_access')
| Usage (Vue):   usePage().props.features.dashboard_access
|                OR  useFeature('dashboard_access')   (see composable)
|
| See FEATURE_FLAGS.md for full usage documentation.
|
*/

$nonProduction = env('APP_ENV', 'production') !== 'production';

return [

    /*
     | Show the environment badge (DEV / STAGING) in the top-right corner.
     | Hard-coded off for production; can be force-enabled for testing.
     */
    'env_badge' => (bool) env('FEATURE_ENV_BADGE', $nonProduction),

    /*
     | Show the "Dashboard" CTA on the landing page and unlock the /dashboard route.
     | The dashboard itself will require OAuth — the flag controls visibility only.
     */
    'dashboard_access' => (bool) env('FEATURE_DASHBOARD_ACCESS', $nonProduction),

    /*
     | Additional flags for future features — add here, then read in Vue or PHP.
     | Example:
     |
     |   'ai_debug_panel' => (bool) env('FEATURE_AI_DEBUG_PANEL', $nonProduction),
     */

];
