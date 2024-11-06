<?php declare(strict_types=1);

const ROOT     = __DIR__ . '/';
const SETTINGS = [ 
    'api' => [ 
        'namespace' => 'kingsoft\\api'
    ],
    'db'  => [ 
        'hostname' => 'localhost',
        'username' => 'root',
        'password' => 'password',
        'database' => 'database'
    ]
];

require ROOT . 'vendor/autoload.php';