<?php
return [
    'routes' => [
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
        [
            'name' => 'Pin#pin',
            'url' => '/pin',
            'verb' => 'POST',
        ],
        [
            'name' => 'Pin#unpin',
            'url' => '/pin',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'Pin#getStatus',
            'url' => '/pin/{fileId}/status',
            'verb' => 'GET',
        ],
    ],
];