<?php
require_once __DIR__.'/vendor/autoload.php';

/**
 * Storage (influxDB) wrapper
 */
class Storage
{
    private $client = null;
    private $database = null;

    /**
     * Initialize a InfluxDB wrapper
     */
    public function __construct()
    {
        $this->logger = Logger::getLogger('Storage');
    }

    /**
     * Connect to InfluxDB database, create it if not existing
     *
     * @param string $host InfluxDB server hostname (exemple: 'localhost')
     * @param string $port InfluxDB server listening port (exemple: '8086')
     * @param string $database InfluxDB database used (exemple: 'netatmo')
     * @return void
     */
    public function connect($host, $port, $database)
    {
        $this->logger->debug("Connecting to database $database (http://$host:$port)");
        try {
            $this->client = new InfluxDB\Client($host, $port);
            $this->logger->trace('InfluxDB client created');
            $this->database = $this->client->selectDB($database);
            $this->logger->trace('Database selected');
        } catch (Exception $e) {
            $this->logger->error('Can not access database');
            $this->logger->debug($e->getMessage());
            throw new Exception($e, 1);
        }
        try {
            $this->logger->trace('Check database exists');
            if (!$this->database->exists()) {
                $this->logger->trace('Database does not exist');
                $this->database->create();
                $this->database->alterRetentionPolicy(new InfluxDB\Database\RetentionPolicy('autogen', '1825d', 1, true));
                $this->logger->info('Database created successfully');
            }
        } catch (Exception $e) {
            $this->logger->error('Can not create database');
            $this->logger->debug($e->getMessage());
        }
        return;
    }

    /**
     * Get timestamp of last fetched value for specific measurement and field
     *
     * @param string $measurement Measurement to be retrieved
     * @param string $field Fieldname to use
     * @return int timestamp
     */
    public function get_last_fetch_date($measurement, $field)
    {
        $this->logger->debug("Get last fetch date for $measurement (on field $field)");
        $last = null;
        try {
            // Request last data
            $result = $this->database->query("SELECT last($field) FROM \"$measurement\"");
            $points = $result->getPoints();
            if (count($points)) {
                $last = strtotime($points[0]['time']);
            }
        } catch (Exception $e) {
            $this->logger->error('Can not access database');
            $this->logger->debug($e->getMessage());
            throw new Exception($e, 1);
        }
        $this->logger->debug("Last data was fetched $last");
        return $last;
    }

    /**
     * Prepare InfluxDB point before insertion
     *
     * @param string $measurement
     * @param int $timestamp
     * @param float $value Main value
     * @param array $tags Optionnal tags
     * @param array $values Optionnal keys/values
     * @return InfluxDB\Point
     */
    public function createPoint($measurement, $timestamp, $value, $tags, $values)
    {
        // $this->logger->trace("Create point $timestamp ($measurement)");
        return new InfluxDB\Point(
            $measurement,
            $value,
            $tags,
            $values,
            $timestamp
        );
    }

    /**
     * Write points to database
     *
     * @param InfluxDB\Point[] $points Array of points to write
     * @return void
     */
    public function writePoints($points)
    {
        $this->logger->debug('Writing '.count($points).' points');
        try {
            return $this->database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS);
        } catch (Exception $e) {
            $this->logger->error('Can not write data');
            $this->logger->debug($e->getMessage());
            throw new Exception($e, 1);
        }
    }
}
