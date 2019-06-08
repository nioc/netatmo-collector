<?php
    return [
        // script configuration
        // false indicate the script will call Netatmo API
        'mock' => false,
        // influxDB database configuration
        'host' => 'localhost',
        'port' => '8086',
        'database' => 'netatmo',
        'retentionDuration' => '1825d',
        // Netatmo application
        'client_id' => '',
        'client_secret' => ''
    ];
