#!/usr/bin/php
<?php

require __DIR__.'/vendor/autoload.php';
$config = include 'config.php';
require 'Netatmo.php';

// Initialize logger
Logger::configure('config.xml');
$logger = Logger::getLogger('main');
$logger->info('Start Netatmo collect');
$logger->debug('Log level: '.$logger->getEffectiveLevel()->toString());

// Get optionnal start date from script argument
$startTimestamp = null;
if ($argc > 1) {
    $startDate = date_create_from_format('Y-m-d', $argv[1]);
    if ($startDate === false) {
        $logger->error('Argument must be a valid start date as Y-m-d, provided: ' . $argv[1]);
        exit(1);
    }
    $startDate->setTime(0, 0);
    $startTimestamp = $startDate->getTimestamp();
    $logger->info('Provided start date: ' . $startDate->format('Y-m-d H:i:sP'));
}

// Initialize wrapper
$netatmo = new Netatmo($config);

// Authentication with Netatmo server (OAuth2)
if (!$netatmo->getToken()) {
    exit(1);
}

// Retrieve user's Weather Stations Information
if (!$netatmo->getStations()) {
    exit(1);
}

// For each stations request measures for every modules
foreach ($netatmo->devices as $device) {
    $logger->debug('Handling device: ' . $device['station_name']);

    // First, get main indoor module
    $netatmo->getMeasures($startTimestamp, $device, null);
        
    // Then for its modules
    foreach ($device['modules'] as $module) {
        $netatmo->getMeasures($startTimestamp, $device, $module);
    }
}

$logger->info('End Netatmo collect');
