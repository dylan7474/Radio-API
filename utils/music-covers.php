#!/usr/local/bin/php
<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * Fetch album covers for the Music menu (see php/classes/MusicCovers.php):
 *
 * 	docker exec --user www-data radio_api php /music-covers.php [--dry-run] [--retry] [--limit N]
 *
 * --dry-run  only say what it would find (nothing is written)
 * --retry    ask again for albums that had no cover last time
 * --limit N  stop after N albums were looked up online
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting(E_ALL);

require_once('/php-code/classes/autoload.php');

if(trim(shell_exec('whoami') ?? '') !== 'www-data'){
	die('Run as user www-data (docker exec --user www-data ...)' . PHP_EOL);
}
if(!is_dir(Music::ROOT)){
	die('No music library at ' . Music::ROOT . PHP_EOL);
}

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$retry = in_array('--retry', $args, true);
$li = array_search('--limit', $args, true);
$limit = $li === false ? PHP_INT_MAX : max(1, intval($args[$li + 1] ?? 1));

$mc = new MusicCovers($retry, $dry);
$count = array('had' => 0, 'found' => 0, 'none' => 0, 'skip' => 0);
$asked = 0;
$folders = MusicCovers::albumFolders();
echo count($folders) . ' album folders' . ($dry ? ' (dry run: nothing is written)' : '') . PHP_EOL;
foreach($folders as $rel){
	if($asked >= $limit) break;
	$r = $mc->cover($rel);
	$count[$r]++;
	if($r === 'found' || $r === 'none') $asked++;
	foreach($mc->log as $line) echo $line . PHP_EOL;
	$mc->log = array();
}
printf('had art already: %d   found now: %d   none found: %d   skipped (none last time): %d%s',
	$count['had'], $count['found'], $count['none'], $count['skip'], PHP_EOL);
?>
