#!/usr/local/bin/php
<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * Check that every station in every list still plays (see php/classes/StationCheck.php).
 * Runs every night (crond in the container: /etc/periodic/daily/station-check), or by hand:
 *
 * 	docker exec --user www-data radio_api php /station-check.php
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting(E_ALL);

require_once('/php-code/classes/autoload.php');
Config::setRedisServer();

if(trim(shell_exec('whoami') ?? '') !== 'www-data'){
	die('Run as user www-data (docker exec --user www-data ...)' . PHP_EOL);
}

echo 'Station check ' . date('Y-m-d H:i') . PHP_EOL;
$c = StationCheck::run(function($line){ echo $line . PHP_EOL; });
printf('working: %d   failed once: %d   not working: %d   not checked (home network): %d%s',
	$c['ok'], $c['failed'], $c['broken'], $c['local'], PHP_EOL);
?>
