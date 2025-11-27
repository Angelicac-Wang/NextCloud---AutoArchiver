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
    ],
];