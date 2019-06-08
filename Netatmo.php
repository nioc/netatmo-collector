<?php

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/vendor/netatmo/netatmo-api-php/src/Netatmo/autoload.php';
require_once __DIR__.'/Storage.php';

/**
 * Netatmo API wrapper
 */
class Netatmo
{
    private $logger;
    private $client;
    private $isMocked;
    public $devices;
    private $todayTimestamp;
    private $storage;
    public $hasError;
    const MAX_REQUESTS_BY_MODULE = 50;
    const WAITING_TIME_BEFORE_NEXT_MODULE = 10;
    const COLLECT_INTERVAL = 1000;
    const TOKENS_FILE = 'tokens.json';

    /**
     * Initialize a Netatmo wrapper
     *
     * @param array $config Associative array including application and account information
     */
    public function __construct($config)
    {
        $this->logger = Logger::getLogger('Netatmo');
        $this->todayTimestamp = time();

        // Initialize Netatmo client
        $this->hasError = false;
        if (!key_exists('client_id', $config) || !key_exists('client_secret', $config) || $config['client_id'] === '' || $config['client_secret'] === '') {
            $this->logger->error('Client id or secret id is not set, please update config.php');
            $this->hasError = true;
            return;
        }
        $configNetatmo = [];
        $configNetatmo['client_id'] = $config['client_id'];
        $configNetatmo['client_secret'] = $config['client_secret'];
        $configNetatmo['scope'] = Netatmo\Common\NAScopes::SCOPE_READ_STATION;
        $this->isMocked =  $config['mock'];
        if ($this->isMocked) {
            $this->logger->warn('API is mocked');
        } else {
            $this->client = new Netatmo\Clients\NAWSApiClient($configNetatmo);
        }

        // Initialize database access
        $this->storage = new Storage();
        $this->storage->connect($config['host'], $config['port'], $config['database'], $config['retentionDuration']);
    }

    /**
     * Retrieve previous tokens
     *
     * @return Authentication result
     */
    public function getExistingTokens()
    {
        if (!$this->isMocked) {
            $this->logger->debug('Try to get existing tokens');
            $tokensString = @file_get_contents($this::TOKENS_FILE);
            if ($tokensString === false) {
                // File not found
                $this->logger->debug('No tokens file found');
                return false;
            }
            $tokens = json_decode($tokensString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON
                $this->logger->debug('Invalid tokens file');
                return false;
            }
            if (!array_key_exists('refresh_token', $tokens)) {
                // Missing refresh token
                $this->logger->debug('Missing refresh token');
                return false;
            }
            if (!is_null($tokens['expires_at']) && $tokens['expires_at'] < time()) {
                // access_token expired.
                $this->logger->debug('Access token expired');
                unset($tokens['access_token']);
            }
            $this->client->setTokensFromStore($tokens);
            $this->logger->debug('Using existing tokens');
        }
        return true;
    }

    /**
     * Authentication with Netatmo server (OAuth2)
     *
     * @param string $username Netatmo account username
     * @param string $password Netatmo account password
     *
     * @return Authentication result
     */
    public function getToken($username, $password)
    {
        if (!$this->isMocked) {
            try {
                $this->logger->debug('Request token with provided username and password');
                $this->client->setVariable('username', $username);
                $this->client->setVariable('password', $password);
                $tokens = $this->client->getAccessToken();
            } catch (Netatmo\Exceptions\NAClientException $e) {
                $this->logger->error('An error occured while trying to get tokens, check your username and password');
                $this->logger->debug('Reason: '.$e->getMessage());
                return false;
            }
            $this->logger->debug('Token received');
            $this->logger->trace('Token: '.json_encode($tokens));
            // Store tokens
            $tokens['expires_at'] = time() + $tokens['expires_in'] - 30;
            $this->logger->debug('Access token expires at ' . $tokens['expires_at']);
            file_put_contents($this::TOKENS_FILE, json_encode($tokens));
        }
        return true;
    }

    /**
     * Retrieve user's Weather Stations Information
     *
     * @return Query result
     */
    public function getStations()
    {
        try {
            $this->logger->debug('Request stations');
            if (!$this->isMocked) {
                $data = $this->client->getData(null, true);
            } else {
                $this->logger->debug('Mocked: mock/stations.json');
                $data = json_decode(file_get_contents('mock/stations.json'), true);
            }
            $this->logger->debug('Stations received');
            // $this->logger->trace('Stations: '.json_encode($data));
        } catch (Netatmo\Exceptions\NAClientException $e) {
            $this->logger->error('An error occured while retrieving data');
            $this->logger->debug('Reason: '.$e->getMessage());
            return false;
        }
        if (empty($data['devices'])) {
            $this->logger->error('No devices affiliated to user');
            return false;
        }
        $this->devices = $data['devices'];
        $this->logger->info('Found ' . count($this->devices) . ' devices');
        return true;
    }

    /**
     * Request measures for a specific device/module from provided timestamp
     *
     * @param int $startTimestamp (optional) starting timestamp of requested measurements
     * @param array $device associative array including information about device to get measures
     * @param array $module (optional) associative array including information about module to get measures
     * @return void
     */
    public function getMeasures($startTimestamp, $device, $module)
    {
        $deviceId = $device['_id'];
        $deviceName = $device['station_name'];
        // default values for module
        $moduleId = null;
        $moduleName = $device['module_name'];
        $moduleType = $device['type'];
        if ($module) {
            // if module provided, override default values
            $moduleId = $module['_id'];
            $moduleName = $module['module_name'];
            $moduleType = $module['type'];
        }
        $this->logger->info("Request measures for device: $deviceName, module: $moduleName ($moduleType)");
        // Requested data type depends on the module's type
        switch ($moduleType) {
            case 'NAMain':
                //main indoor module
                $type = 'temperature,Co2,humidity,noise,pressure';
                break;
            case 'NAModule1':
                // outdoor module
                $type = 'temperature,humidity';
                break;
            case 'NAModule2':
                // wind gauge module
                $type = 'WindStrength,WindAngle,GustStrength,GustAngle';
                break;
            case 'NAModule3':
                // rain gauge module
                $type = 'rain';
                break;
            default:
                // other (including additional indoor module)
                $type = 'temperature,Co2,humidity';
                break;
        }
        $fieldKeys = explode(',', $type);
        
        if ($startTimestamp) {
            $lastTimestamp = $startTimestamp;
        } else {
            $lastTimestamp = time() - 24*3600*30;
            // Get last fetched timestamp
            try {
                $last = $this->storage->get_last_fetch_date($deviceName . '-' . $moduleName, $fieldKeys[0]);
                if ($last !== null) {
                    $lastTimestamp = $last;
                }
            } catch (Exception $e) {
                $this->logger->error('Can not get last fetch timestamp');
                // Can not access database, exit script
                return;
            }
        }
        $hasError = false;
        $requestCount = 0;

        // Get measures by requesting API until max requests is reached, all data are reiceved or an error occurs
        do {
            $requestCount++;
            $measures = [];
            try {
                $this->logger->debug('Starting timestamp: ' . $lastTimestamp . ' (' . date('Y-m-d H:i:sP', $lastTimestamp) . ')');
                if (!$this->isMocked) {
                    $measures = $this->client->getMeasure($deviceId, $moduleId, 'max', $type, $lastTimestamp, $this->todayTimestamp, 1024, false, true);
                // file_put_contents("mock2/$moduleType.json", json_encode($measures));
                } else {
                    $this->logger->debug("Mocked: mock/$moduleType.json");
                    $measures = json_decode(file_get_contents("mock/$moduleType.json"), true);
                }
                // $this->logger->trace('Measure: '. json_encode($measures));
            } catch (Netatmo\Exceptions\NAClientException $e) {
                $hasError = true;
                $this->logger->error("An error occured while retrieving device $deviceName / module: $moduleName ($moduleType) measurements");
                $this->logger->debug('Reason: '.$e->getMessage());
            }
    
            // Store module measures in database
            $points = [];
            foreach ($measures as $timestamp => $values) {
                $dt = new DateTime();
                $dt->setTimestamp($timestamp);
                // $this->logger->trace('Handling values for ' . $dt->format('Y-m-d H:i:sP'));
                $fields = [];
                foreach ($values as $key => $val) {
                    if (array_key_exists($key, $fieldKeys)) {
                        // $this->logger->trace('.   ' . $fieldKeys[$key] . ': ' . $val);
                        $fields[$fieldKeys[$key]] = (float) $val;
                    }
                }
                array_push($points, $this->storage->createPoint($deviceName . '-' . $moduleName, $timestamp, null, [], $fields));
                $lastTimestamp = max($lastTimestamp, $timestamp);
                // $this->logger->trace('Max timestamp is now : ' . $lastTimestamp . ' (' . date('Y-m-d H:i:sP', $lastTimestamp) . ' )');
            }
            try {
                $this->storage->writePoints($points);
            } catch (Exception $e) {
                $hasError = true;
                $this->logger->error("Can not write device $deviceName / module: $moduleName ($moduleType) measurements");
            }
        } while ($lastTimestamp <= ($this->todayTimestamp - $this::COLLECT_INTERVAL) && !$hasError && $requestCount < $this::MAX_REQUESTS_BY_MODULE);
        // Wait some seconds before continue to avoid reaching user limit API
        sleep($this::WAITING_TIME_BEFORE_NEXT_MODULE);
    }
}
