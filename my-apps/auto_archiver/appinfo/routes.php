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
    ],
];