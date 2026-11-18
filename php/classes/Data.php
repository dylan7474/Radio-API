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
 * Main Data Storage, Data means Podcasts, Episodes and Stations
 */
class Data {

	private $id, $redis, $own_streams, $preloaded = false;
	private $radio, $stream, $podcasts, $table; 

	/**
	 * Generate Data (the main Radiolist)
	 * @param $id the id of the user
	 * @param $preload Load all JSON from disk, else redis is used if available
	 */
	public function __construct(int $id, bool $preload = false ){
		$this->id = $id;
		// fork: ".v2" because items are now keyed by their stable "sid" (old cache was keyed by position)
		$this->redis = new Cache('radios_podcasts.v2.' . $this->id );
		$this->own_streams = new OwnStreams();
		
		if( !$this->redis->keyExists( 'types' ) || $preload ){
			$this->preloadAll();
			if( !$this->redis->keyExists( 'types' ) ){
				$this->constructTable();
			}
		}
	}

	/**
	 * Load files from disk
	 */
	private function preloadAll() : void {
		$radio = null;
		if(is_file( __DIR__ . '/../data/radios_'. $this->id .'.json' )){
			$radio = json_decode( file_get_contents( __DIR__ . '/../data/radios_'. $this->id .'.json' ), true);
			if(is_null($radio)){ // on json error, move file and create new
				rename(__DIR__ . '/../data/radios_'. $this->id .'.json', __DIR__ . '/../data/radios_'. $this->id .'.error.json');
			}
		}
		$this->radio = self::ensureSids(is_null($radio) ? array() : $radio);

		$podcasts = null;
		if(is_file( __DIR__ . '/../data/podcasts_'. $this->id .'.json' )){
			$podcasts = json_decode( file_get_contents( __DIR__ . '/../data/podcasts_'. $this->id .'.json'  ), true);
			if(is_null($podcasts)){ 
				rename(__DIR__ . '/../data/podcasts_'. $this->id .'.json', __DIR__ . '/../data/podcasts_'. $this->id .'.error.json');
			}
		}
		$this->podcasts = self::ensureSids(is_null($podcasts) ?  array() : $podcasts);

		$this->preloaded = true;
	}

	/**
	 * Generate the main Table of Stations
	 */
	private function constructTable() : void {
		if(!$this->preloaded){ // load data, in not already done
			$this->preloadAll();
		}

		// generate Table
		$this->table = array();
		$this->table['types'] = array(
			1 => 'Radio',
			3 => 'Podcast'
		);
		$this->table['items'] = array();

		// add radio, podcasts
		$this->addTypeToTable( 1, $this->radio );
		$this->addTypeToTable( 3, $this->podcasts );

		// add "own streams"
		if( Config::STREAM_JSON ){
			$this->table['types'][2] = 'Stream';
			$this->addTypeToTable( 2, $this->own_streams->getStreams() );
		}

		// save in redis
		//	if using own stream, we need to give a ttl, else the system won't reload the list of own streams
		$this->redis->arraySet( 'types', $this->table['types'], Config::STREAM_JSON ? Config::CACHE_EXPIRE : 0 );
		$this->redis->arraySet( 'items', $this->table['items'], Config::STREAM_JSON ? Config::CACHE_EXPIRE : 0 );
	}

	/**
	 * Add a type to table
	 * 	Helper for constructTable
	 */
	private function addTypeToTable( int $tid, array $data ) : void{
		// fork: the ID the radio sees (and keeps in its presets) is the item's stable "sid",
		//	not its position, so moving or deleting items does not change other presets.
		//	"pos" keeps the list order so the radio can show items in that order.
		$data = self::ensureSids(array_values($data));
		foreach( $data as $pos => $d ){
			$idd = $d['sid'] + 1000 * $tid;
			$this->table['items'][$idd] = $d;
			$this->table['items'][$idd]['tid'] = $tid;
			$this->table['items'][$idd]['pos'] = $pos;
			if( $pos >= 998 ){ // only 999 per type!!
				break;
			}
		}
	}

	/**
	 * fork: give every item a stable id "sid" (0 - 998), which the radio uses for presets.
	 * 	- items that already have a valid, unused sid keep it
	 * 	- items without one take the sid of the same item in $old (same url, else same name),
	 * 		so a page that does not know about sids (the classic /gui/) keeps them too
	 * 	- a list with no sids at all (from before this fork) gets sid = position,
	 * 		which is exactly the id the radio used before, so existing presets keep working
	 * 	- anything else gets the next unused number (numbers of deleted items are only
	 * 		reused once all 999 have been used)
	 */
	public static function ensureSids(array $list, array $old = array()) : array {
		$list = array_values($list);
		$valid = fn($v) => is_int($v) && $v >= 0 && $v <= 998;

		$anySid = false;
		foreach(array_merge($list, $old) as $it){
			if(is_array($it) && isset($it['sid']) && $valid($it['sid'])){ $anySid = true; break; }
		}
		if(!$anySid){
			foreach($list as $pos => &$it){ $it['sid'] = min($pos, 998); }
			unset($it);
			return $list;
		}

		$used = array();
		foreach($list as $pos => &$it){ // keep valid, unique sids
			if(isset($it['sid']) && $valid($it['sid']) && !isset($used[$it['sid']])){
				$used[$it['sid']] = true;
			}
			else {
				unset($it['sid']);
			}
		}
		unset($it);

		$oldFree = array_values(array_filter($old, fn($o) => is_array($o) && isset($o['sid']) && $valid($o['sid'])));
		foreach(array('url', 'name') as $field){ // inherit from the previous version of the list
			foreach($list as &$it){
				if(isset($it['sid'])) continue;
				foreach($oldFree as $k => $o){
					if(($o[$field] ?? null) === ($it[$field] ?? false) && !isset($used[$o['sid']])){
						$it['sid'] = $o['sid'];
						$used[$o['sid']] = true;
						unset($oldFree[$k]);
						break;
					}
				}
			}
			unset($it);
		}

		$max = -1;
		foreach(array_merge($list, $old) as $it){
			if(is_array($it) && isset($it['sid']) && $valid($it['sid'])) $max = max($max, $it['sid']);
		}
		foreach($list as &$it){ // new items: next number, then lowest free
			if(isset($it['sid'])) continue;
			$n = $max + 1;
			if($n > 998 || isset($used[$n])){
				for($n = 0; $n <= 998 && isset($used[$n]); $n++);
			}
			$it['sid'] = min($n, 998);
			$used[$it['sid']] = true;
			$max = max($max, $it['sid']);
		}
		unset($it);
		return $list;
	}

	/**
	 * Returns List of type, indexed by typeID
	 */
	public function getTypes() : array {
		return $this->redis->arrayGet('types');
	}

	/**
	 * Returns List of categories for a typeID
	 */
	public function getCategories(?int $tid = null) : array {
		$cats = array_filter(
			$this->redis->arrayGet('items'),
			fn($i) => (is_null($tid) || $tid == $i['tid']) && !empty($i["category"])
		);
		return array_unique(array_map(fn($i) => $i["category"], $cats));
	}

	/**
	 * Returns list of items in this type, indexed by id!
	 * 	Filter for a category or set "null" to return all categories
	 */
	public function getListOfItems(?int $tid = null, ?string $cat = null ) : array {
		return array_filter(
			$this->redis->arrayGet('items'),
			fn($i) => (is_null($tid) || $tid == $i['tid']) && ( (is_null($cat) && empty($i['category'])) || $cat == $i['category'])
		);
	}

	/**
	 * Get data of one item by its id.
	 */
	public function getById( int $id ) : array {
		if( !$this->redis->arrayKeyExists('items', strval($id)) ){
			return array();
		}
		return $this->redis->arrayKeyGet('items', strval($id));
	}

	/**
	 * Generate Link for station
	 * @param $id radio station id
	 * @param $mac users radio mac
	 */
	public function getStationURL( int $id, string $mac ) : string {
		$station = $this->getById($id);
		if(empty($station)){
			return "";
		}
		if(!empty($station['type']) && $station['type'] == 'nc' ){
			return Config::RADIO_DOMAIN . 'm3u.php?id=' . $id . '&mac=' . $mac;
		}
		else if(!empty($station['proxy'])){
			return Config::RADIO_DOMAIN . 'stream.php?id=' . $id . '&mac=' . $mac;
		}
		else{
			return $station['url'];
		}
	}

	/**
	 * Generate Link for podcast
	 * @param $id podcast id
	 * @param $eid episode id
	 * @param $mac users radio mac
	 * @param $sloppy (default=false) do not perform endURL lookup
	 */
	public function getPodcastURL( int $id, int $eid, string $mac, bool $sloppy = false ) : string {
		$ed = PodcastLoader::getEpisodeData( $id, $eid, $this );

		if(empty($ed)){
			return "";
		}

		if($ed['proxy']){
			return Config::RADIO_DOMAIN . 'stream.php?id=' . $id . '&eid=' . $eid . '&mac=' . $mac;
		}
		else if($ed['finalurl'] && !$sloppy){
			return Helper::getFinalUrl($ed['episode']['url']);
		}
		else{
			return $ed['episode']['url'];
		}
	}

	/**
	 * Backend Raw Access
	 */
	public function getRadioList() : array {
		if(!$this->preloaded){
			$this->preloadAll();
		}
		return $this->radio;
	}
	public function getPodcastList() : array {
		if(!$this->preloaded){
			$this->preloadAll();
		}
		return $this->podcasts;
	}
	public function setRadioList(array $radios) : void {
		if(!$this->preloaded){
			$this->preloadAll();
		}
		$this->radio = self::ensureSids($radios, $this->radio);
		file_put_contents( __DIR__ . '/../data/radios_'. $this->id .'.json', json_encode($this->radio, JSON_PRETTY_PRINT), LOCK_EX);
		$this->constructTable(); // update redis
	}
	public function setPodcastList(array $pods) : void {
		if(!$this->preloaded){
			$this->preloadAll();
		}
		$this->podcasts = self::ensureSids($pods, $this->podcasts);
		file_put_contents( __DIR__ . '/../data/podcasts_'. $this->id .'.json', json_encode($this->podcasts, JSON_PRETTY_PRINT), LOCK_EX);
		$this->constructTable(); // update redis
	}
}
?>