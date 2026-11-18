<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 * JSON API for the web page in /app/. Uses the same login session as /gui/.
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting( !empty($_ENV['DEV']) && $_ENV['DEV'] == 'dev' ? E_ALL : 0 );

require_once(__DIR__ . '/../classes/autoload.php');

header('Content-Type: application/json;charset=UTF-8');
header('Cache-Control: no-store');

function reply($data, int $status = 200) : void {
	http_response_code($status);
	die(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function body() : array {
	$b = json_decode(file_get_contents('php://input'), true);
	return is_array($b) ? $b : array();
}

function str($v) : string {
	return is_string($v) ? $v : (is_scalar($v) ? strval($v) : '');
}

function cleanUrl($v) : string {
	$v = trim(str($v));
	return $v === '' ? '' : Inner::filterURL($v);
}

function cleanCategory($v) : string {
	$v = trim(str($v));
	return $v === '' ? '' : Inner::filterCategory($v);
}

// the stable id the radio uses for presets (see Data::ensureSids); only kept if valid
function keepSid(array $item) : array {
	return isset($item['sid']) && is_int($item['sid']) && $item['sid'] >= 0 && $item['sid'] <= 998
		? array('sid' => $item['sid']) : array();
}

$login = new Login();
$action = str($_GET['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if($action === 'login' && $isPost){
	$code = trim(str(body()['code'] ?? ''));
	if(Helper::checkValue($code, Id::CODE_PREG)){
		$login->loginByCode($code);
	}
	reply(array('ok' => $login->isLoggedIn()), $login->isLoggedIn() ? 200 : 403);
}
if(!$login->isLoggedIn()){
	reply(array('ok' => false, 'error' => 'login'), 401);
}

$id = $login->getId();

switch($action){

	case 'logout':
		$login->logout();
		reply(array('ok' => true));

	case 'state':
		$data = new Data($id);
		$all = $login->getAll();
		reply(array(
			'ok' => true,
			'code' => $all['code'],
			'version' => Config::VERSION,
			'radios' => array_values($data->getRadioList()),
			'podcasts' => array_values($data->getPodcastList()),
			'logoCache' => Config::USE_LOGO_CACHE,
			'maxItems' => 999
		));

	case 'radios':
		if(!$isPost) reply(array('ok' => false), 405);
		$out = array();
		foreach(body()['items'] ?? array() as $r){
			if(!is_array($r) || trim(str($r['name'] ?? '')) === '') continue;
			$url = cleanUrl($r['url'] ?? '');
			if($url === '' || $url === 'invalid') reply(array('ok' => false, 'error' => 'Station "'.str($r['name']).'" has no valid stream URL'), 400);
			$logo = cleanUrl($r['logo'] ?? '');
			$out[] = array(
				'name' => Inner::filterName(trim(str($r['name']))),
				'url' => $url,
				'logo' => $logo === 'invalid' ? '' : $logo,
				'desc' => trim(str($r['desc'] ?? '')) === '' ? '' : Inner::filterName(trim(str($r['desc']))),
				'proxy' => !empty($r['proxy']),
				'type' => str($r['type'] ?? '') === 'nc' ? 'nc' : 'radio',
				'category' => cleanCategory($r['category'] ?? '')
			) + keepSid($r);
			if(count($out) >= 999) break;
		}
		$data = new Data($id);
		$data->setRadioList($out);
		reply(array('ok' => true, 'radios' => array_values($data->getRadioList())));

	case 'podcasts':
		if(!$isPost) reply(array('ok' => false), 405);
		$out = array();
		foreach(body()['items'] ?? array() as $p){
			if(!is_array($p) || trim(str($p['name'] ?? '')) === '') continue;
			$url = cleanUrl($p['url'] ?? '');
			if($url === '' || $url === 'invalid') reply(array('ok' => false, 'error' => 'Podcast "'.str($p['name']).'" has no valid feed URL'), 400);
			$out[] = array(
				'name' => Inner::filterName(trim(str($p['name']))),
				'url' => $url,
				'finalurl' => !empty($p['finalurl']),
				'proxy' => !empty($p['proxy']),
				'type' => str($p['type'] ?? '') === 'nc' ? 'nc' : 'rss',
				'category' => cleanCategory($p['category'] ?? '')
			) + keepSid($p);
			if(count($out) >= 999) break;
		}
		$data = new Data($id);
		$data->setPodcastList($out);
		reply(array('ok' => true, 'podcasts' => array_values($data->getPodcastList())));

	case 'search':
		$rb = new RadioBrowser($id);
		reply(array('ok' => true, 'results' => $rb->searchAdvanced(array(
			'name' => str($_GET['name'] ?? ''),
			'tag' => str($_GET['tag'] ?? ''),
			'countrycode' => str($_GET['countrycode'] ?? ''),
			'order' => str($_GET['order'] ?? 'clickcount'),
			'limit' => 60
		))));

	case 'countries':
		reply(array('ok' => true, 'countries' => (new RadioBrowser($id))->countries()));

	case 'last':
		$last = array_values((new RadioBrowser($id))->lastStations());
		usort($last, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
		reply(array('ok' => true, 'results' => $last));

	case 'podsearch':
		reply(podcastSearch(str($_GET['q'] ?? '')));

	case 'probe':
		reply(probe(str($_GET['url'] ?? '')));

	default:
		reply(array('ok' => false, 'error' => 'unknown action'), 404);
}

/**
 * Find podcasts with Apple's iTunes Search API (free, no key): the same directory
 * and the same request SleepRadioPi uses (sleepradiopi/playback/podcasts.py).
 */
function podcastSearch(string $q) : array {
	$q = trim(substr($q, 0, 200));
	if($q === ''){
		return array('ok' => true, 'results' => array());
	}
	$cache = new Cache('podcast-search');
	$key = 'q.' . sha1(strtolower($q));
	if($cache->keyExists($key)){
		return array('ok' => true, 'results' => $cache->arrayGet($key));
	}
	$ch = curl_init('https://itunes.apple.com/search?media=podcast&entity=podcast&limit=30&term=' . rawurlencode($q));
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_USERAGENT => 'Radio-API/' . Config::VERSION,
		CURLOPT_HTTPHEADER => array('Accept: application/json'),
		CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	unset($ch);
	$data = is_string($raw) ? json_decode($raw, true) : null;
	if($status !== 200 || !is_array($data)){
		return array('ok' => false, 'error' => 'The podcast directory (iTunes) did not answer');
	}
	$out = array();
	$seen = array();
	foreach($data['results'] ?? array() as $o){
		$url = trim(str($o['feedUrl'] ?? ''));
		$title = trim(html_entity_decode(str($o['collectionName'] ?? $o['trackName'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if($url === '' || $title === '' || isset($seen[$url]) || !preg_match('#^https?://#i', $url)) continue;
		$seen[$url] = true;
		$out[] = array(
			'name' => mb_substr($title, 0, 200),
			'url' => $url,
			'author' => mb_substr(trim(str($o['artistName'] ?? '')), 0, 200),
			'logo' => str($o['artworkUrl100'] ?? ''),
			'genre' => str($o['primaryGenreName'] ?? ''),
			'episodes' => intval($o['trackCount'] ?? 0)
		);
	}
	$cache->arraySet($key, $out, 3600);
	return array('ok' => true, 'results' => $out);
}

/**
 * Check a stream URL from phobos: does it answer, what is it, and (for playlists)
 * which stream does it point at. Reads at most 32 KB.
 */
function probe(string $url, int $depth = 0) : array {
	if(!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)){
		return array('ok' => false, 'note' => 'Not an http(s) URL');
	}
	$buf = '';
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 6,
		CURLOPT_USERAGENT => 'Radio-API/' . Config::VERSION,
		CURLOPT_HTTPHEADER => array('Icy-MetaData: 0'),
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buf) {
			$buf .= $chunk;
			return strlen($buf) > 32768 ? 0 : strlen($chunk); // stop early: streams never end
		}
	));
	curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$type = strtolower(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]);
	$final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	$err = curl_errno($ch);
	unset($ch);

	if($buf === '' && ($err !== 0 && $err !== CURLE_WRITE_ERROR)){
		return array('ok' => false, 'note' => 'No answer from the server');
	}
	if($status >= 400){
		return array('ok' => false, 'note' => 'Server says ' . $status);
	}

	$path = strtolower(parse_url($final, PHP_URL_PATH) ?? '');
	$isPlaylist = preg_match('#(mpegurl|scpls|x-scpls|xspf)#', $type) || preg_match('#\.(m3u8?|pls|xspf)$#', $path)
		|| (str_starts_with(ltrim($buf), '#EXTM3U') || str_starts_with(ltrim($buf), '[playlist]'));
	$isHls = str_contains($buf, '#EXT-X-');

	if($isPlaylist && !$isHls){
		if($depth < 2 && preg_match('#(https?://[^\s"<>]+)#i', $buf, $m)){
			$inner = probe($m[1], $depth + 1);
			$inner['note'] = 'Playlist → ' . ($inner['note'] ?? '');
			return $inner;
		}
		return array('ok' => false, 'note' => 'Playlist with no stream inside');
	}

	$audio = str_starts_with($type, 'audio/') || $type === 'application/ogg' || $type === 'video/mp2t' || $isHls;
	return array(
		'ok' => $audio,
		'url' => $final,
		'type' => $isHls ? 'HLS' : $type,
		'note' => $audio ? ($isHls ? 'HLS stream (the radio may not play these)' : 'Stream answers (' . $type . ')')
			: 'Answers, but it is not audio (' . ($type ?: 'unknown type') . ')'
	);
}
?>
