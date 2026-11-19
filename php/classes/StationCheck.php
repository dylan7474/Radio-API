<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: does a station still play? probe() is the page's Test button; run() is the nightly
 * check of every station in every list (utils/station-check.php, started by crond in the
 * container). Results in data/station_health.json, by stream address:
 * 	{ok, note, last (time), fails (in a row), since (first failure), suggest {url, name}}
 * A station counts as "not working" after 2 failed checks in a row. For those, Radio-Browser
 * is asked for a working station with exactly the same name at another address (suggest).
 * Addresses on the home network are never checked: a probe would retune an SDR receiver.
 */
class StationCheck {

	private const FILE = __DIR__ . '/../data/station_health.json';
	public const FAILS_BROKEN = 2;

	public static function health() : array {
		$j = is_file(self::FILE) ? json_decode(file_get_contents(self::FILE), true) : null;
		return is_array($j) ? $j : array();
	}

	/**
	 * True for an address on the home network (private / local / .local / .lan)
	 */
	public static function isLocal(string $url) : bool {
		$host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
		if($host === '' || $host === 'localhost' || preg_match('/\.(local|lan|home|internal)$/', $host)) return true;
		$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
		if(!filter_var($ip, FILTER_VALIDATE_IP)) return false; // can't resolve: check it (it will fail)
		return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	/**
	 * Fetch the start of a stream (following playlists): ok if it is audio
	 */
	public static function probe(string $url, int $depth = 0) : array {
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
				$inner = self::probe($m[1], $depth + 1);
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

	/**
	 * Check every station of every list once. Returns lines for the log.
	 */
	public static function run(?callable $say = null) : array {
		$say = $say ?? fn($s) => null;
		$ids = ListShare::knownIds();
		$links = ListShare::links();
		$stations = array(); // url => name
		foreach($ids as $id){
			if(isset($links[$id])) continue; // uses another radio's list
			foreach((new Data($id))->getRadioList() as $r){
				if(($r['type'] ?? 'radio') === 'nc' || empty($r['url'])) continue;
				$stations[$r['url']] = $stations[$r['url']] ?? ($r['name'] ?? '');
			}
		}

		$old = self::health();
		$new = array();
		$now = time();
		$counts = array('ok' => 0, 'failed' => 0, 'broken' => 0, 'local' => 0);
		foreach($stations as $url => $name){
			if(self::isLocal($url)){ $counts['local']++; continue; }
			$p = self::probe($url);
			if(!$p['ok']){ // one more try a little later: a server can be busy for a moment
				sleep(5);
				$p = self::probe($url);
			}
			$h = $old[$url] ?? array();
			if($p['ok']){
				$new[$url] = array('ok' => true, 'note' => $p['note'], 'last' => $now, 'fails' => 0);
				$counts['ok']++;
				continue;
			}
			$fails = intval($h['fails'] ?? 0) + 1;
			$e = array('ok' => false, 'note' => $p['note'], 'last' => $now, 'fails' => $fails,
				'since' => ($h['ok'] ?? true) ? $now : intval($h['since'] ?? $now));
			if($fails >= self::FAILS_BROKEN){
				$counts['broken']++;
				$s = self::suggest($name, $url);
				if(!is_null($s)) $e['suggest'] = $s;
			}
			else $counts['failed']++;
			$new[$url] = $e;
			$say(sprintf('  %s  %s: %s%s', $fails >= self::FAILS_BROKEN ? 'NOT WORKING' : 'failed once', $name, $p['note'],
				isset($e['suggest']) ? ' (new address found)' : ''));
		}
		file_put_contents(self::FILE, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
		return $counts;
	}

	/**
	 * A working station on Radio-Browser with the same name but another address, or null
	 */
	private static function suggest(string $name, string $url) : ?array {
		$name = trim($name);
		$ids = ListShare::knownIds();
		if($name === '' || empty($ids)) return null;
		$found = (new RadioBrowser($ids[0]))->searchAdvanced(array('name' => $name, 'order' => 'votes', 'limit' => 20));
		foreach($found as $s){
			if(mb_strtolower(trim($s['name'])) !== mb_strtolower($name) || $s['url'] === '' || $s['url'] === $url) continue;
			if(self::isLocal($s['url'])) continue;
			if(self::probe($s['url'])['ok']) return array('url' => $s['url'], 'name' => $s['name']);
		}
		return null;
	}
}
?>
