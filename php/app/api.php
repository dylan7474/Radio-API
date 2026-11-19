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

// names the user gave the radios on the page (data/radio_names.json: {"<id>": "Kitchen"})
function radioNames() : array {
	$f = __DIR__ . '/../data/radio_names.json';
	$n = is_file($f) ? json_decode(file_get_contents($f), true) : null;
	return is_array($n) ? $n : array();
}

// every radio that has connected: [{id, code, name, stations}]
function knownRadios() : array {
	$table = Id::getTableData();
	$names = radioNames();
	$out = array();
	$links = ListShare::links();
	foreach($table['ids'] ?? array() as $rid => $v){
		if(!is_array($v) || empty($v[1])) continue;
		$owner = $links[intval($rid)] ?? intval($rid);
		$file = __DIR__ . '/../data/radios_' . $owner . '.json';
		$list = is_file($file) ? json_decode(file_get_contents($file), true) : array();
		$out[] = array(
			'id' => intval($rid),
			'code' => $v[1],
			'name' => $names[strval($rid)] ?? '',
			'stations' => is_array($list) ? count($list) : 0,
			'shares' => $owner !== intval($rid) ? $owner : 0 // uses this radio's list (0 = its own)
		);
	}
	return $out;
}

// for the page: which list this radio uses, who else uses it, and what it could share
function shareInfo(int $id) : array {
	$radios = knownRadios();
	$names = array();
	foreach($radios as $r) $names[$r['id']] = $r['name'];
	$name = fn($rid) => ($names[$rid] ?? '') !== '' ? $names[$rid] : 'Radio ' . $rid;
	$owner = ListShare::ownerOf($id);
	$links = ListShare::links();
	return array(
		'owner' => $owner !== $id ? array('id' => $owner, 'name' => $name($owner)) : null,
		'followers' => array_map(fn($f) => array('id' => $f, 'name' => $name($f)), ListShare::followers($id)),
		// radios whose list this one could use: not itself, and not one that uses another's list
		'choices' => array_values(array_map(fn($r) => array('id' => $r['id'], 'name' => $name($r['id'])),
			array_filter($radios, fn($r) => $r['id'] !== $id && !isset($links[$r['id']]))))
	);
}

// for the page: a music folder's cover address, or null. Albums (tracks or CDs inside) are
//	looked at once (cover file, picture in the first track); artist folders only use a stored cover.
function musicArt(string $rel) : ?string {
	$has = Music::cachedArt($rel);
	if(is_null($has)){
		$e = Music::entries($rel, false);
		$album = count($e['tracks']) > 0 || count(array_filter($e['dirs'], fn($d) => preg_match('/^(cd|disc|disk)\s*\d+$/i', basename($d)))) > 0;
		$has = $album ? Music::art($rel) : null;
		if(!$album){ // an artist's folder: the first of its albums that has a stored cover
			foreach($e['dirs'] as $d){
				if(!is_null(Music::cachedArt($d))) return Music::artUrl($d, '../');
			}
		}
	}
	return is_null($has) ? null : Music::artUrl($rel, '../');
}

// for the page: the nightly check's result for each station ("health"; none if never checked)
function withHealth(array $list) : array {
	$h = StationCheck::health();
	foreach($list as &$r){
		if(isset($h[$r['url'] ?? ''])) $r['health'] = $h[$r['url']];
	}
	unset($r);
	return $list;
}

// for the page: "logo_local" = phobos's stored copy of the logo (or "" if it couldn't be stored yet)
function withLocalLogos(array $list) : array {
	foreach($list as &$r){
		$f = empty($r['logo']) ? null : RadioLogo::localFile($r['logo']);
		$r['logo_local'] = is_null($f) ? '' : '../media/logos/' . $f;
	}
	unset($r);
	return $list;
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
// fork: LAN-only, so no code to type: list the known radios and let the page pick one
//	(the GUI-Code login above stays as a fallback; access is still limited by CONF_ALLOWED_DOMAIN)
if($action === 'who'){
	reply(array('ok' => true, 'radios' => knownRadios()));
}
if($action === 'pick' && $isPost){
	$want = intval(body()['id'] ?? -1);
	foreach(knownRadios() as $r){
		if($r['id'] === $want){
			$login->loginByCode($r['code']);
			reply(array('ok' => $login->isLoggedIn()), $login->isLoggedIn() ? 200 : 403);
		}
	}
	reply(array('ok' => false, 'error' => 'No such radio'), 404);
}

if(!$login->isLoggedIn()){
	reply(array('ok' => false, 'error' => 'login'), 401);
}

$id = $login->getId();

switch($action){

	case 'rename':
		if(!$isPost) reply(array('ok' => false), 405);
		$name = trim(str(body()['name'] ?? ''));
		$names = radioNames();
		if($name === '') unset($names[strval($id)]);
		else $names[strval($id)] = mb_substr(Inner::filterName($name), 0, 60);
		file_put_contents(__DIR__ . '/../data/radio_names.json', json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
		reply(array('ok' => true, 'name' => $names[strval($id)] ?? ''));

	// use another radio's list of stations and podcasts ({owner: id}), or its own again ({owner: 0})
	case 'share':
		if(!$isPost) reply(array('ok' => false), 405);
		$err = ListShare::set($id, intval(body()['owner'] ?? 0));
		if(!is_null($err)) reply(array('ok' => false, 'error' => $err), 400);
		reply(array('ok' => true, 'share' => shareInfo($id)));

	case 'logout':
		$login->logout();
		reply(array('ok' => true));

	case 'state':
		$data = new Data($id);
		$all = $login->getAll();
		reply(array(
			'ok' => true,
			'code' => $all['code'],
			'id' => $id,
			'name' => radioNames()[strval($id)] ?? '',
			'version' => Config::VERSION,
			'radios' => withHealth(withLocalLogos(array_values($data->getRadioList()))),
			'podcasts' => array_values($data->getPodcastList()),
			'share' => shareInfo($id),
			'music' => Music::available(),
			'checked' => is_file(__DIR__ . '/../data/station_health.json') ? filemtime(__DIR__ . '/../data/station_health.json') : 0,
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
				'fav' => !empty($r['fav']),
				'relay' => !empty($r['relay']),
				'type' => str($r['type'] ?? '') === 'nc' ? 'nc' : 'radio',
				'category' => cleanCategory($r['category'] ?? '')
			) + keepSid($r);
			if(count($out) >= 999) break;
		}
		$data = new Data($id);
		$data->setRadioList($out);
		reply(array('ok' => true, 'radios' => withHealth(withLocalLogos(array_values($data->getRadioList())))));

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

	case 'recent':
		$data = new Data($id);
		$out = array();
		foreach((new Recent($id))->list() as $e){
			if($e['kind'] === 'own'){
				$item = $data->getById(intval($e['id']));
				if($item === array()) continue; // deleted since
				$e['name'] = $item['name']; $e['url'] = $item['url']; $e['logo'] = $item['logo'] ?? '';
				$lf = RadioLogo::localFile($e['logo']);
				if(!is_null($lf)) $e['logo'] = '../media/logos/' . $lf;
				$e['sid'] = $item['sid'] ?? null;
			}
			$out[] = $e;
		}
		reply(array('ok' => true, 'results' => $out));

	case 'recent_clear':
		if(!$isPost) reply(array('ok' => false), 405);
		(new Recent($id))->clear();
		reply(array('ok' => true));

	case 'last':
		$last = array_values((new RadioBrowser($id))->lastStations());
		usort($last, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
		reply(array('ok' => true, 'results' => $last));

	case 'podsearch':
		reply(podcastSearch(str($_GET['q'] ?? '')));

	case 'findlogo':
		reply(findLogo(str($_GET['url'] ?? ''), str($_GET['name'] ?? ''), $id));

	// a logo picture from the page: {png: "data:image/png;base64,..."} (made in the browser, at most
	//	256 px, with its background) -> stored in media/logos/, its address goes in the station's logo
	case 'logoupload':
		if(!$isPost) reply(array('ok' => false), 405);
		$png = str(body()['png'] ?? '');
		$bytes = str_starts_with($png, 'data:image/png;base64,') ? base64_decode(substr($png, 22), true) : false;
		$name = is_string($bytes) ? RadioLogo::storeUpload($bytes) : null;
		if(is_null($name)) reply(array('ok' => false, 'error' => 'That picture could not be used (it must be a PNG of at most 512 px)'), 400);
		reply(array('ok' => true, 'logo' => Config::DOMAIN . 'media/logos/' . $name, 'logo_local' => '../media/logos/' . $name));

	// the music library, one folder: its folders (with covers) and tracks, for the Music tab
	case 'music':
		if(!Music::available()) reply(array('ok' => false, 'error' => 'No music library on phobos'), 404);
		$mid = str($_GET['id'] ?? '');
		$rel = $mid === '' ? '' : Music::pathFor($mid);
		if(is_null($rel) || !is_dir(Music::abs($rel))) reply(array('ok' => false, 'error' => 'That folder is not in the music library any more'), 404);
		$e = Music::entries($rel);
		$mac = rawurlencode(Id::getTableData()['ids'][strval($id)][0] ?? '');
		$play = fn($mid2) => '../music.php?id=' . $mid2 . '&mac=' . $mac;
		$crumbs = array(); $acc = '';
		foreach($rel === '' ? array() : explode('/', $rel) as $part){
			$acc = ltrim($acc . '/' . $part, '/');
			$crumbs[] = array('id' => Music::idFor($acc), 'name' => $part);
		}
		$all = $rel === '' ? 0 : count(Music::tracksUnder($rel));
		reply(array(
			'ok' => true,
			'id' => $rel === '' ? '' : Music::idFor($rel),
			'name' => $rel === '' ? 'Music' : basename($rel),
			'crumbs' => $crumbs,
			'art' => $rel === '' ? null : musicArt($rel),
			'canCover' => $rel !== '',
			'count' => $all,
			'playAll' => $all > 0 ? $play(Music::idFor($rel)) : null,
			'shuffle' => $all > 1 ? $play(Music::shuffleIdFor($rel)) : null,
			'dirs' => array_map(fn($d) => array('id' => Music::idFor($d), 'name' => basename($d), 'art' => musicArt($d)), $e['dirs']),
			'tracks' => array_map(fn($t) => array('id' => Music::idFor($t), 'name' => Music::title($t), 'url' => $play(Music::idFor($t))), $e['tracks'])
		));

	// album folders that have no cover (after the cover job and what's in the folders)
	case 'musicmissing':
		if(!Music::available()) reply(array('ok' => false, 'error' => 'No music library on phobos'), 404);
		$out = array();
		foreach(MusicCovers::albumFolders() as $rel){
			if(!is_null(Music::cachedArt($rel)) || !is_null(Music::art($rel))) continue;
			// one CD of a set: show the set (its cover is used for every CD)
			$show = preg_match('/^(cd|disc|disk)\s*\d+$/i', basename($rel)) ? dirname($rel) : $rel;
			$out[$show] = array('id' => Music::idFor($show), 'name' => $show);
		}
		reply(array('ok' => true, 'albums' => array_values($out)));

	// a cover for a music folder from the page: {id, png: "data:image/png;base64,..."}; or {id, remove: true}
	case 'musicart':
		if(!$isPost) reply(array('ok' => false), 405);
		$b = body();
		$rel = Music::pathFor(str($b['id'] ?? ''));
		if(is_null($rel) || $rel === '' || !is_dir(Music::abs($rel))) reply(array('ok' => false, 'error' => 'Not a music folder'), 404);
		if(!empty($b['remove'])){
			Music::removeArt($rel);
			reply(array('ok' => true, 'art' => null));
		}
		$png = str($b['png'] ?? '');
		$bytes = str_starts_with($png, 'data:image/png;base64,') ? base64_decode(substr($png, 22), true) : false;
		if(!is_string($bytes) || !RadioLogo::validPng($bytes)) reply(array('ok' => false, 'error' => 'That picture could not be used (it must be a PNG of at most 512 px)'), 400);
		Music::setArt($rel, $bytes);
		reply(array('ok' => true, 'art' => musicArt($rel)));

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
 * A logo for a station from Radio-Browser that really downloads as a picture
 * (PNG/JPEG/GIF/WebP/SVG preferred over .ico, which some radios can't show).
 */
function findLogo(string $url, string $name, int $id) : array {
	if($url === '' && $name === ''){
		return array('ok' => false, 'note' => 'No address or name');
	}
	$candidates = (new RadioBrowser($id))->logoCandidates($url, $name);
	$ico = null;
	foreach(array_slice($candidates, 0, 8) as $logo){
		$type = imageType($logo);
		if($type === null) continue;
		if(str_contains($type, 'icon')){ $ico = $ico ?? $logo; continue; }
		return array('ok' => true, 'logo' => $logo, 'type' => $type);
	}
	if(!is_null($ico)){
		return array('ok' => true, 'logo' => $ico, 'type' => 'icon', 'note' => 'Only a small icon was found');
	}
	return array('ok' => false, 'note' => count($candidates) ? 'Logos found, but none could be downloaded' : 'No logo found on Radio-Browser');
}

// the picture's type if the address really gives a picture (at most 2 MB is read), else null
function imageType(string $url) : ?string {
	$buf = '';
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
		CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6,
		CURLOPT_USERAGENT => 'Radio-API/' . Config::VERSION,
		CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buf) {
			$buf .= $chunk;
			return strlen($buf) > 2000000 ? 0 : strlen($chunk);
		}
	));
	curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	unset($ch);
	if($status !== 200 || strlen($buf) < 100){
		return null;
	}
	$type = (new finfo(FILEINFO_MIME_TYPE))->buffer($buf);
	if(!str_starts_with((string)$type, 'image/') && preg_match('/<svg[\s>]/i', substr($buf, 0, 2000))){
		$type = 'image/svg+xml'; // small SVGs are often not recognised (e.g. the BBC's network logos)
	}
	return is_string($type) && str_starts_with($type, 'image/') ? $type : null;
}

/**
 * Check a stream URL from phobos: does it answer, what is it, and (for playlists)
 * which stream does it point at. Reads at most 32 KB.
 */
function probe(string $url, int $depth = 0) : array {
	return StationCheck::probe($url, $depth); // (the nightly station check uses the same test)
}
?>
