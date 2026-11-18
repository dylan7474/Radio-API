<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * music.php?id=<music id>&mac=<radio>  plays a track, or a whole folder (album) track after
 *   track, as one MP3 stream (most of the library is FLAC, which not every radio plays), with
 *   each track's name as the "now playing" text.
 * music.php?art=<music id>  the album art (a cover file, or the picture inside the first track).
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting( !empty($_ENV['DEV']) && $_ENV['DEV'] == 'dev' ? E_ALL : 0 );

require_once( __DIR__ . '/classes/autoload.php' );

Config::checkAccess(); // (also loads the settings, which the cache needs)

// album art: no radio login needed (the logo cache fetches it), only a valid id
if( isset($_GET['art']) ){
	$rel = is_string($_GET['art']) ? Music::pathFor($_GET['art']) : null;
	$file = is_null($rel) ? null : Music::art($rel);
	if( is_null($file) ){
		header('Location: ' . Config::RADIO_DOMAIN . 'media/station.png');
		http_response_code(303);
		die();
	}
	header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
	header('Cache-Control: max-age=86400');
	readfile($file);
	die();
}

$auth = new Auth();
Config::checkAccess($auth->getClientID());
$radioId = $auth->auth();

$rel = is_string($_GET['id'] ?? null) ? Music::pathFor($_GET['id']) : null;
$abs = is_null($rel) ? null : Music::abs($rel);
if( is_null($abs) ){
	http_response_code(404);
	die('Not in the music library');
}
$tracks = is_dir($abs) ? Music::tracksUnder($rel, $rel === '' ? -1 : 0) : array($rel);
if( Music::isShuffle($_GET['id']) ){
	shuffle($tracks);
}
if( empty($tracks) ){
	http_response_code(404);
	die('No tracks here');
}

set_time_limit(0);
ignore_user_abort(true); // keep running after the radio leaves, to stop ffmpeg (connection_aborted() notices)
while( ob_get_level() > 0 ){ ob_end_flush(); }

$album = Music::albumOf($rel);
$shuffling = Music::isShuffle($_GET['id']);
$metaint = 16000;
$icy = ($_SERVER['HTTP_ICY_METADATA'] ?? '') === '1';
header('Content-Type: audio/mpeg');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('icy-name: ' . preg_replace('/[^ -~]/', '', $album !== '' ? $album : 'Music'));
header('icy-br: 192');
if( $icy ){
	header('icy-metaint: ' . $metaint);
}

function metaBlock(string $title) : string {
	$title = str_replace(array("'", ';', "\r", "\n"), array("\u{2019}", ',', ' ', ' '), mb_substr($title, 0, 200));
	$meta = "StreamTitle='" . $title . "';";
	$meta .= str_repeat("\0", (16 - strlen($meta) % 16) % 16);
	return chr(intdiv(strlen($meta), 16)) . $meta;
}

$untilMeta = $metaint;
$gone = false;
foreach( $tracks as $t ){
	$trackAlbum = $shuffling ? Music::albumOf($t) : $album;
	$metaBlock = metaBlock(Music::title($t) . ($trackAlbum !== '' ? ' - ' . $trackAlbum : ''));
	// each track to MP3 (no ID3 tag / Xing header in the middle of the stream)
	$cmd = array('ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-i', Music::abs($t),
		'-map', '0:a:0', '-vn', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '192k',
		'-id3v2_version', '0', '-write_xing', '0', '-f', 'mp3', 'pipe:1');
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if( !is_resource($proc) ){
		continue;
	}
	$out = $pipes[1];
	while( !feof($out) ){
		$chunk = fread($out, 8192);
		if( $chunk === false ) break;
		if( $chunk === '' ){ usleep(20000); continue; }
		if( $icy ){
			while( strlen($chunk) >= $untilMeta ){
				echo substr($chunk, 0, $untilMeta), $metaBlock;
				$chunk = substr($chunk, $untilMeta);
				$untilMeta = $metaint;
			}
			$untilMeta -= strlen($chunk);
		}
		echo $chunk;
		flush();
		if( connection_aborted() ){ $gone = true; break; }
	}
	fclose($out);
	if( proc_get_status($proc)['running'] ){
		proc_terminate($proc, 15);
		usleep(200000);
		if( proc_get_status($proc)['running'] ) proc_terminate($proc, 9);
	}
	proc_close($proc);
	if( $gone ) break;
}
?>
