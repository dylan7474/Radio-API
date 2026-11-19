<?php
/** 
 * Radio-API
 * https://github.com/KIMB-technologies/Radio-API
 * 
 * (c) 2019 - 2026 KIMB-technologies 
 * https://github.com/KIMB-technologies/
 * 
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * Loads Podcasts from RSS or Nextcloud links, caches and parses them
 */
class PodcastLoader {

	/**
	 * Redis Cache of Podcasts
	 */
	private static $redis = null;

	/**
	 * Redis Setup
	 */
	private static function loadRedis(){
		if( is_null(self::$redis) ){
			self::$redis = new Cache('podcast_loader');
		}
	}

	/**
	 * Nextcloud share loader
	 */
	private static function loadFromNextcloud( string $url, bool $sort = true ) : array {
		// "<server base path> / <possibly index.php> /s/ <token>"
		if(preg_match('/^(https?\:\/\/.*)(?<!index\.php)(\/index\.php)?\/s\/([0-9A-Za-z]+)/', $url, $matches) !== 1){
			return array();
		}
		$server = $matches[1]; // the base path to nextcloud
		$useindex = !empty($matches[2]); // used index.php to access files?
		$share = $matches[3]; // the token/ name of share

		// do webdav request
		$cont = stream_context_create( array(
			"http" => array(
				'method' => "PROPFIND",
				"header" => "Authorization: Basic " . base64_encode($share . ':')
			)
		));
		$data = file_get_contents( $server . '/public.php/webdav/', false, $cont );

		// parse webdav XML
		$data = json_decode(json_encode(
				simplexml_load_string( $data, 'SimpleXMLElement', 0, "d", true)
			), true );
	
		$poddata = array(
			'title' => '',
			'logo' => '',
			'episodes' => array()
		);
		$episodes = array();

		if( !is_array($data) ){
			return $poddata;
		}

		// iterate files
		foreach($data["response"] as $r){

			// get data from xml
			$mime = $r["propstat"]["prop"]["getcontenttype"] ?? '';
			$href = $r["href"] ?? '';

			// get filename
			$filename = urldecode(substr( $href, strrpos( $href, '/' ) + 1 ));
			// get streaming/ web url
			if(Config::LEGACY_NEXTCLOUD){ // old NC share download link (before v31)
				$streamurl = $server . ($useindex ? '/index.php' : '') . '/s/'. $share .'/download?path=%2F&files=' . rawurlencode( $filename );
			}
			else{ // new NC share download link (starting v31)
				$streamurl = $server . '/public.php/dav/files/' . $share . '/' . rawurlencode( $filename );
			}

			// is this an audio file?
			if( str_starts_with($mime, 'audio/') ){
				$episodes[] = array(
					'title' => $filename,
					'desc'  => '',
					'url' => $streamurl
				);
			}
			// it this a logo?
			else if(str_starts_with($mime, 'image/') && str_starts_with($filename, "logo") ){
				$poddata['logo'] = $streamurl;
			}
		}

		if($sort){
			usort($episodes, fn($x, $y) => strnatcasecmp($x['title'], $y['title']));
		}
		// reindex episodes, starts with 1
		$poddata['episodes'] = array_combine(range(1, count($episodes)), $episodes);

		return $poddata;
	}

	/**
	 * fork: a podcast's picture from its feed's text: <itunes:image href>, else <image><url>, '' if none
	 */
	public static function feedImage(string $rss) : string {
		$head = substr($rss, 0, ($p = stripos($rss, '<item')) === false ? 300000 : $p); // the channel, not an episode
		if(preg_match('#<itunes:image[^>]*\bhref\s*=\s*["\']([^"\']+)["\']#i', $head, $m)) return html_entity_decode(trim($m[1]));
		if(preg_match('#<image>.*?<url>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</url>#is', $head, $m)) return html_entity_decode(trim($m[1]));
		return '';
	}

	/**
	 * fork: fetch a feed (at most 2 MB, 30 s: some feeds, e.g. LibriVox, are slow) and give its picture's address, '' if none
	 */
	public static function findImage(string $feedUrl) : string {
		if(!preg_match('#^https?://#i', $feedUrl)) return '';
		$buf = '';
		$ch = curl_init($feedUrl);
		curl_setopt_array($ch, array(
			CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Radio-API/' . Config::VERSION . ')',
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buf) {
				$buf .= $chunk;
				// the picture is near the top: stop at the first episode
				return strlen($buf) > 2000000 || stripos($buf, '<item') !== false ? 0 : strlen($chunk);
			}
		));
		curl_exec($ch);
		unset($ch);
		$img = self::feedImage($buf);
		return preg_match('#^https?://#i', $img) ? $img : '';
	}

	/**
	 * fork: a podcast's artwork from Apple's podcast directory (iTunes), for feeds without a picture:
	 * 	searched by name, used only if a result has exactly this feed address. '' if none.
	 */
	public static function itunesImage(string $name, string $feedUrl) : string {
		$name = trim($name);
		if($name === '' || $feedUrl === '') return '';
		$ch = curl_init('https://itunes.apple.com/search?' . http_build_query(array('media' => 'podcast', 'entity' => 'podcast', 'limit' => 25, 'term' => mb_substr($name, 0, 100))));
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
			CURLOPT_USERAGENT => 'Radio-API/' . Config::VERSION));
		$j = json_decode((string) curl_exec($ch), true);
		unset($ch);
		$norm = fn($u) => rtrim(preg_replace('#^https?://#i', '', strtolower(trim($u))), '/');
		foreach($j['results'] ?? array() as $r){
			if($norm($r['feedUrl'] ?? '') === $norm($feedUrl)){
				$img = (string) ($r['artworkUrl600'] ?? $r['artworkUrl100'] ?? '');
				return preg_match('#^https?://#i', $img) ? $img : '';
			}
		}
		return '';
	}

	/**
	 * RSS/ Atom loader
	 */
	private static function loadFromFeed( string $url ) : array{
		$rss = file_get_contents( $url );
		$data = json_decode(json_encode( simplexml_load_string( $rss, 'SimpleXMLElement', LIBXML_NOCDATA ) ), true );
				
		$poddata = array(
			'title' => is_array($data) ? ($data['channel']['title'] ?? '') : '',
			'logo' => is_array($data) && isset( $data['channel']['image'] ) ? $data['channel']['image']['url']
				: self::feedImage(is_string($rss) ? $rss : ''), // fork: many feeds only have <itunes:image>
			'episodes' => array()
		);

		if( !is_array($data) ){
			return $poddata;
		}

		$eid = 1;

		if( isset( $data['channel']['item']['enclosure'] ) ){ // one item is like "item" : { ... }, but should be like "item" : [ { ... } ]
			$data['channel']['item'] = array( $data['channel']['item'] );
		}

		foreach( $data['channel']['item'] as $item ){
			if( !empty( $item['enclosure'] ) ){
			
				if( count( $item['enclosure'] ) > 1){
					$url = '';
					foreach( $item['enclosure'] as $en ){
						if( substr( $en['@attributes']['type'], 0, 5) == 'audio' ){
							$url = $en['@attributes']['url'];
							break;
						}
					}
					if( empty($url) ){
						$url = $item['enclosure'][0]['@attributes']['url'];
					}
				}
				else{
					$url = $item['enclosure']['@attributes']['url'];
				}

				$poddata['episodes'][$eid] = array(
					'title' => empty( $item['title'] ) ? 'Unnamed' :  $item['title'],
					'desc'  => empty( $item['description'] ) ? '' :  $item['description'] ,
					'url' => $url 
				);
				$eid++;
			}
		}
		return $poddata;
	}

	/**
	 * Podcast from url loader, using the cache
	 */
	public static function getPodcastByUrl( string $url, bool $nextcloud ) : array {
		self::loadRedis();
		$urlKey = 'url.' . sha1($url);
		if( self::$redis->keyExists($urlKey) ){
			return self::$redis->arrayGet($urlKey);
		}

		$poddata = $nextcloud ? self::loadFromNextcloud( $url ) : self::loadFromFeed( $url );
		self::$redis->arraySet($urlKey, $poddata, Config::CACHE_EXPIRE );
		return $poddata;
	}

	/**
	 * Get informations about one episode
	 */
	public static function getEpisodeData( int $id, int $eid, Data $data ) : array{
		$pod = $data->getById( $id );
		if( $pod['tid'] !== 3 ){
			return array();
		}
		$poddata = self::getPodcastByUrl( $pod['url'], !empty($pod['type']) && $pod['type'] == 'nc' );

		if( isset( $poddata['episodes'][$eid] ) ){
			//ok
			return array(
				'episode' => $poddata['episodes'][$eid],
				'title' => $poddata['title'],
				'logo' =>  $poddata['logo'],
				'finalurl' => !empty($pod['finalurl']),
				'proxy' => !empty($pod['proxy']),
				'type' => !empty($pod['type']) && $pod['type'] == 'nc' ? 'nc' : 'rss'
			);
		} 
		else{
			return array();
		}
	}

	/**
	 * Get the Podcast by its ID
	 */
	public static function getPodcastDataById( int $id, Data $data ) : array {
		$pod = $data->getById( $id );
		if( $pod['tid'] !== 3 ){
			return array();
		}
		return self::getPodcastByUrl( $pod['url'], !empty($pod['type']) && $pod['type'] == 'nc' );
	}

	/**
	 * Get nextcloud url list from nextcloud radio station ID
	 */
	public static function getMusicById( int $id, Data $data ) : array {
		$stat = $data->getById($id);
		if( !empty($stat) && $stat['type'] == 'nc' ){ // is a nextcloud station
			self::loadRedis();

			$m3Key = 'm3u.'.sha1($stat['url']);
			if( self::$redis->keyExists($m3Key) ){
				return json_decode(self::$redis->get($m3Key));
			}
			
			$music = self::getPodcastByUrl( $stat['url'], true )['episodes'];
			$urls = array_values(array_map(fn($i) => $i['url'], $music));
			self::$redis->set( $m3Key, json_encode($urls), Config::CACHE_EXPIRE );
			
			return $urls;
		}
		else{
			return array();
		}
	}
}		
?>