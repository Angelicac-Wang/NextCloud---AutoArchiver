<?php
return [
    'routes' => [
        [
            'name' => 'Page#index',
            'url' => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'Ping#touch',
            'url' => '/ping/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'Restore#restore',
            'url' => '/restore/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'Notification#extend',
            'url' => '/extend/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'Notification#extend7Days',
            'url' => '/extend7days/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'Notification#dismiss',
            'url' => '/dismiss/{fileId}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'Notification#getStatistics',
            'url' => '/statistics',
            'verb' => 'GET',
        ],
    ],
];