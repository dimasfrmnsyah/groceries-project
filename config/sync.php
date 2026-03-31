<?php
return [
    'enabled'   => env('SYNC_ENABLED', false),
    'base_url'  => env('SYNC_BASE_URL', ''),            
    'device_id' => env('SYNC_DEVICE_ID', 'offline-001'),
    'pull_limit'=> env('SYNC_PULL_LIMIT', 10000),
    'tables'    => env('SYNC_TABLES', ''),       
    'api_key'   => env('SYNC_API_KEY', ''),           
];
