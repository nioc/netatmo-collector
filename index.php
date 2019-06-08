#!/usr/bin/php
<?php

chdir(__DIR__);
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
if ($netatmo->hasError) {
    exit(1);
}

// Authentication with Netatmo server (OAuth2)
if (!$netatmo->getExistingTokens()) {
    $logger->warn('No existing token found, try to get it with username and password');
    // No existing token found, connect with username and password read from stdin
    function prompt($question)
    {
        echo "\r\n$question: ";
        $stdin = fopen('php://stdin', 'r');
        $response = fgets($stdin);
        fclose($stdin);
        return trim($response);
    }
    $username = prompt('Your netatmo account username');
    $password = prompt('Your netatmo account password');
    if (!$netatmo->getToken($username, $password)) {
        exit(1);
    }
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
