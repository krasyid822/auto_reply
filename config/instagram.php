<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API (Instagram comment auto-reply)
    |--------------------------------------------------------------------------
    | Bundle: "Instagram Login with Facebook" -> base graph.facebook.com
    */

    'api_base' => env('INSTAGRAM_API_BASE', 'https://graph.facebook.com'),

    'api_version' => env('FACEBOOK_API_VERSION', env('API_GRAPH_VERSION', 'v26.0')),

    'client_id' => env('FACEBOOK_CLIENT_ID', env('APP_ID')),

    'client_secret' => env('FACEBOOK_CLIENT_SECRET', env('APP_SECRET')),

    'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),

    'calls_per_hour_limit' => (int) env('INSTAGRAM_CALLS_PER_HOUR', 200),

    'scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FACEBOOK_SCOPES', 'instagram_basic,instagram_manage_comments,pages_show_list,pages_read_engagement,pages_manage_metadata,business_management'))
    ))),
];
