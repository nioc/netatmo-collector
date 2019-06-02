# Netatmo collector

[![license: AGPLv3](https://img.shields.io/badge/license-AGPLv3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![GitHub release](https://img.shields.io/github/release/nioc/netatmo-collector.svg)](https://github.com/nioc/netatmo-collector/releases/latest)
[![Codacy grade](https://img.shields.io/codacy/grade/ef9c7195ad5945309bb13b5899d63cd8.svg)](https://www.codacy.com/app/nioc/netatmo-collector)

Netatmo collector is a PHP script for requesting measures from Netatmo devices.

## Key features
- Automatic script (can be used with cron for example),
- Initialization step (providing a start date and wait for the magic),
- Store measures into an InfluxDB database,
- Explore data with Grafana.

## Installation

### Script

Download this project and extract files to directory of your choice.
Configure a Netatmo [application](https://dev.netatmo.com/myaccount/createanapp) and user account informations in `config.php`.

### Dependencies

Install dependencies with [composer](https://getcomposer.org/): `composer install`.

### Grafana

You need a [Grafana server](https://grafana.com/grafana/download) working.
Create a new dashboard with Influxdb or import it from file (soon).

## Usage

### Initialization

In order to collect oldest measures, script accepts a start date (YYYY-MM-DD) as an optional argument. 
Open a shell, go in script directory and execute it: `php -f index.php 2018-12-31`.

### Scheduling repeated executions

Add to your scheduler (cron for exemple) following command (change the path `/usr/local/bin/netatmo-collect/` according to your installation):
````
# /etc/cron.d/netatmo-collect: crontab fragment for requesting Netatmo measures
# Requesting Netatmo measures and storing to database every 12 hours
 0 */12    * * *     root   php -f /usr/local/bin/netatmo-collect/index.php >> /var/log/syslog 2>&1
````

### Logs

Log settings can be found in `config.xml` file.
In production mode, the default configuration use a file (`netatmo-collect.log`) for logging at level `INFO`.
For debugging, you can output to console and set a more verbose level (`DEBUG` or even `TRACE`) by overriding the `root` section:
````
  <root>
    <level value="DEBUG"/>
    <appender_ref ref="console"/>
  </root>
````

## Versioning

This project is maintained under the [semantic versioning](https://semver.org/) guidelines.

See the [releases](https://github.com/nioc/netatmo-collector/releases) on this repository for changelog.

## Contributing

Pull requests are welcomed.

## Credits

- **[Nioc](https://github.com/nioc/)** - *Initial work*

See also the list of [contributors](https://github.com/nioc/netatmo-collector/contributors) to this project.

This project is powered by the following components:
- [Netatmo-API-PHP](https://github.com/Netatmo/Netatmo-API-PHP)
- [influxdb-php](https://github.com/influxdata/influxdb-php) (MIT)
- [Apache log4php](http://logging.apache.org/log4php/) (Apache License)

## License

This project is licensed under the GNU Affero General Public License v3.0 - see the [LICENSE](LICENSE.md) file for details.
