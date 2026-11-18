<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * relay.php?id=<station id>&mac=<radio>: plays a station the radio cannot decode itself
 * (HLS like the BBC's, or WAV like a home SDR receiver) by converting it to MP3 with ffmpeg,
 * only while the radio is listening. Only stations marked "relay" in the radio's own list.
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting( !empty($_ENV['DEV']) && $_ENV['DEV'] == 'dev' ? E_ALL : 0 );

require_once( __DIR__ . '/classes/autoload.php' );

$auth = new Auth();
Config::checkAccess($auth->getClientID());
$radioId = $auth->auth();
$data = new Data($radioId->getId());

$id = $_GET['id'] ?? '';
$rb = $_GET['rb'] ?? '';
if( is_string($rb) && RadioBrowser::matchStationID($rb) ){
	// a Radio-Browser station: only one the rules say the radio can't play (not an open relay)
	$rbs = (new RadioBrowser($radioId))->stationById($rb);
	$url = '';
	if( !is_null($rbs) ){
		foreach( array($rbs['url'] ?? '', $rbs['url_resolved'] ?? '') as $u ){
			if( Relay::needed($u, $rbs) ){ $url = $u; break; }
		}
	}
	$station = $url === '' ? array() : array('name' => $rbs['name'] ?? 'Radio', 'url' => $url, 'relay' => true);
}
else {
	$station = is_string($id) && preg_match('/^\d{4}$/', $id) ? $data->getById((int)$id) : array();
}
$url = $station['url'] ?? '';

if( empty($station['relay']) || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) ){
	http_response_code(404);
	die('Not a relayed station');
}

set_time_limit(0);
ignore_user_abort(true); // keep running after the radio leaves, to stop ffmpeg below (connection_aborted() notices)
while( ob_get_level() > 0 ){ ob_end_flush(); }

$cmd = array(
	'ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin',
	'-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5',
	'-user_agent', 'Radio-API/' . Config::VERSION,
	'-i', $url,
	'-vn', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '128k',
	'-f', 'mp3', 'pipe:1'
);
$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
if( !is_resource($proc) ){
	http_response_code(502);
	die('Could not start the converter');
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no'); // nginx: pass it on as it comes
header('icy-name: ' . preg_replace('/[^ -~]/', '', $station['name'] ?? 'Radio'));
header('icy-br: 128');

// "now playing" text (ICY metadata), if the radio asks for it: the station's name and
// description, so the radio shows that instead of "Not Specified"
$metaint = 16000;
$icy = ($_SERVER['HTTP_ICY_METADATA'] ?? '') === '1';
$metaBlock = '';
if( $icy ){
	$title = trim(($station['name'] ?? '') . (empty($station['desc']) ? '' : ' - ' . $station['desc']));
	$title = str_replace(array("'", ';', "\r", "\n"), array("\u{2019}", ',', ' ', ' '), mb_substr($title, 0, 200));
	$meta = "StreamTitle='" . $title . "';";
	$meta .= str_repeat("\0", (16 - strlen($meta) % 16) % 16);
	$metaBlock = chr(intdiv(strlen($meta), 16)) . $meta;
	header('icy-metaint: ' . $metaint);
	header('icy-description: ' . preg_replace('/[^ -~]/', '', $station['desc'] ?? ''));
}

$out = $pipes[1];
$untilMeta = $metaint;
while( !feof($out) ){
	$chunk = fread($out, 8192);
	if( $chunk === false ){
		break;
	}
	if( $chunk === '' ){
		usleep(20000);
		continue;
	}
	if( $icy ){ // a metadata block after every $metaint bytes of audio
		while( strlen($chunk) >= $untilMeta ){
			echo substr($chunk, 0, $untilMeta), $metaBlock;
			$chunk = substr($chunk, $untilMeta);
			$untilMeta = $metaint;
		}
		$untilMeta -= strlen($chunk);
	}
	echo $chunk;
	flush();
	if( connection_aborted() ){ // the radio stopped or changed station
		break;
	}
}

// stop ffmpeg (and with it the source stream, e.g. so the receiver is free again)
fclose($out);
$status = proc_get_status($proc);
if( $status['running'] ){
	proc_terminate($proc, 15);
	usleep(300000);
	if( proc_get_status($proc)['running'] ){
		proc_terminate($proc, 9);
	}
}
proc_close($proc);
?>
