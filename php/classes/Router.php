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
 * Routing for Radio-API XML Index
 */
class Router {

	private Id $radioId;
	private Output $out;
	private Data $data;
	private UnRead $unread;
	private RadioBrowser $radio_browser;

	/**
	 * Generate Objects
	 */
	public function __construct( Id $id, ClientType $type ){
		$this->radioId = $id;
		$this->out = $type == ClientType::RadioJSON ? new OutputJSON() : new OutputXML();
		$this->data = new Data($this->radioId->getId());
		$this->unread = new UnRead($this->radioId->getId());
		$this->radio_browser = new RadioBrowser($this->radioId);
	}

	/**
	 * Handle the get requests
	 */
	public function handleGet(string $uri) : void {
		// only one station or episode (play this)
		if( isset( $_GET['sSearchtype'] ) && ($_GET['sSearchtype'] == 3 || $_GET['sSearchtype'] == 5 ) && !empty($_GET['Search'])){
			$currentUrl = Config::RADIO_DOMAIN . '?sSearchtype=' . $_GET['sSearchtype'] . '&Search=' . $_GET['Search'];
			$play = isset($_GET['play']);

			// fork: a music library folder or track?
			if( preg_match(Music::ID_PREG, $_GET['Search']) === 1 ){
				$this->listMusicItem($_GET['Search'], $play);
				$this->out->currentUrl($currentUrl, 'Play Music');
			}
			// is an ID from Radio-Browser??
			else if( RadioBrowser::matchStationID($_GET['Search']) ){ 
				$this->radio_browser->handleStationPlay($this->out, $_GET['Search'], $play);
				$this->out->currentUrl($currentUrl, 'Play Station');
			}
			// podcast episode ID? "3000-3999"+"X"+"[0-9]+" 
			else if( preg_match('/^(3\d\d\d)X(\d+)$/', $_GET['Search'], $parts ) === 1 ){ 
				$this->listPodcastEpisode(intval($parts[1]), intval($parts[2]), play: $play);
				$this->out->currentUrl($currentUrl, 'Play Episode');
			}
			// radio or "own stream" ID (Range 1000 - 2999)
			else if( preg_match('/^(1|2)\d\d\d$/', $_GET['Search'], $parts ) === 1){ 
				$this->listPlayItem(intval($_GET['Search']), intval($parts[1]), play: $play);
				$this->out->currentUrl($currentUrl, 'Play Stream');
			}
			else {
				$this->out->addDir( 'No item found for this ID!', Config::RADIO_DOMAIN . '?go=initial');
				$this->out->currentUrl(Config::RADIO_DOMAIN . '?sSearchtype=' . $_GET['sSearchtype'] . '&Search=Error', 'Error');
			}
		}
		// radio browser browsing
		else if( $uri == '/radio-browser' && !empty( $_GET['by'] ) && !empty( $_GET["term"] ) ){
			$offset = isset($_GET["offset"]) && is_numeric($_GET["offset"]) ? intval($_GET["offset"]) : 0;
			$this->radio_browser->handleBrowse($this->out, $_GET['by'], $_GET["term"], $offset);
		}
		// (Un)Read for podcast episodes (used in GUI)
		else if( !empty($_GET['toggleUnRead']) && is_string($_GET['toggleUnRead']) ){
			$this->out->addDir('TOGGLE-UN-READ-' . $this->unread->toggleById($_GET['toggleUnRead'], $this->data), '');
			// GUI only, so no current URL needed
		}
		// fork: the music library (folders -> albums -> tracks)
		else if( $uri == '/music' ){
			$this->listMusic(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
		}
		// fork: favourites and recently played
		else if( $uri == '/list' && isset($_GET['fav']) ){
			$this->listFavourites();
		}
		else if( $uri == '/list' && isset($_GET['recent']) ){
			$this->listRecent();
		}
		// list of stations or podcasts (= the type) and possibly selecting a category
		else if( $uri == '/list' ){ 
			$this->out->prevUrl(Config::RADIO_DOMAIN . '?go=initial');

			if( !empty($_GET['tid']) && is_numeric($_GET['tid']) && array_key_exists($_GET['tid'], $this->data->getTypes()) ){
				$tid = intval($_GET['tid']);
			}
			else if( empty($_GET['tid']) && !empty($_GET['cat']) ) {
				$tid = null; 
			}
			else{
				$tid = null; 

				$this->out->addDir( 'No item found for this tID or Category!', Config::RADIO_DOMAIN . '?go=initial');
				$this->out->currentUrl(Config::RADIO_DOMAIN . 'list?tid=Error&cat=Error', 'Error');
			}
			
			// list items of a podcast
			if( $tid == 3 && isset($_GET['id']) && preg_match('/^\d+$/', $_GET['id']) === 1 ){
				$this->listPodcast(intval($_GET['id']));
			}
			// list items of type (or all of category), possibly filter by category
			else{
				$this->listDirectory(
					$tid, 
					isset($_GET['cat']) && preg_match( '/^[0-9A-Za-z \-\,]{0,200}$/', $_GET['cat'] ) === 1 ? $_GET['cat'] : null
				);
			}
		}
		// special case for JSON client: empty URI requires to show "airable index"
		else if(empty($uri) && $this->out instanceof OutputJSON) {
			$this->out->index();
		}
		// list of types (startpage)
		else{ 
			$this->out->currentUrl(Config::RADIO_DOMAIN . 'index?go=initial', 'List');

			// add local types
			foreach( $this->data->getTypes() as $tid => $name ){
				$this->out->addDir( $name, Config::RADIO_DOMAIN . 'list?tid=' . $tid );
			}
			// add RadioBrowser
			$this->out->addDir( 'Radio-Browser', Config::RADIO_DOMAIN . 'radio-browser?by=none&term=none' );

			// fork: Favourites and Recently played first (sort keys "0", "1" come before names)
			if( count($this->favourites()) > 0 ){
				$this->out->addDir( 'Favourites', Config::RADIO_DOMAIN . 'list?fav=1', sortKey: '0' );
			}
			if( count((new Recent($this->radioId->getId()))->list()) > 0 ){
				$this->out->addDir( 'Recently played', Config::RADIO_DOMAIN . 'list?recent=1', sortKey: '1' );
			}
			if( Music::available() ){
				$this->out->addDir( 'Music', Config::RADIO_DOMAIN . 'music', sortKey: '2' );
			}

			// add code (for gui)
			$this->out->addDir( 'GUI-Code: ' . $this->radioId->getCode(), Config::RADIO_DOMAIN . '?go=initial', true );

			// add Favorites category, if exists
			$allCats = $this->data->getCategories();
			if( in_array( 'Favorites', $allCats ) || in_array( 'Favoriten', $allCats ) ) {
				$name = in_array( 'Favoriten', $allCats ) ? 'Favoriten' : 'Favorites';
				$this->out->addDir( $name, Config::RADIO_DOMAIN . 'list?cat=' . $name );
			}

			// Log unknown request
			if(
				preg_match('/^\/setupapp\/[A-Za-z0-9\-\_]+\/asp\/BrowseXML\/loginXML.asp/i', $uri) === 0 &&
				(!isset( $_GET['go'] ) || $_GET['go'] != "initial") &&
				$uri != '/index' // JSON radio requests normal index 
			){
				file_put_contents(
					Config::LOG_DIR . '/requests.log',
					date('d.m.Y H:i:s') . " : " . json_encode( $_GET ) . PHP_EOL,
					FILE_APPEND
				);
			}
		}
	}

	private function listPodcastEpisode(int $id, int $eid, int $tid = 3, bool $play = false) : void {
		$this->out->prevUrl(Config::RADIO_DOMAIN . 'list?tid='.$tid.'&id='.$id);

		$ed = PodcastLoader::getEpisodeData( $id, $eid, $this->data );
		if( $ed != array() ){
			$this->unread->openItem($id, $ed['episode']['url']);

			$this->out->addEpisode(
				$id, $eid,
				$ed['title'], $ed['episode']['title'],
				$this->data->getPodcastURL($id, $eid, $this->radioId->getMac()),
				$ed['episode']['desc'],
				$ed['logo'],
				status: $play ? OutputPlayStatus::Play : OutputPlayStatus::Full
			);
		}
	}

	private function listPlayItem(int $id, int $tid = 1, bool $play = false) : void {
		$sta = $this->data->getById( $id );

		$this->out->prevUrl(
			Config::RADIO_DOMAIN . 'list?tid=' . $tid .
			(empty($sta['category']) ? '' : '&cat=' . rawurlencode($sta['category']))
		);

		if( $sta !== array() && $play ){ // fork: remember for "Recently played"
			(new Recent($this->radioId->getId()))->add('own', strval($id), $sta['name'] ?? '', $sta['url'] ?? '', $sta['logo'] ?? '');
		}

		if( $sta !== array() ){
			// radio station or stream with "live" == true
			if( !isset($sta["live"]) || $sta["live"] ){
				$this->out->addStation(
					$id,
					$sta['name'],
					$this->data->getStationURL($id, $this->radioId->getMac()),
					status: $play ? OutputPlayStatus::Play : OutputPlayStatus::Full,
					desc: $sta['desc'] ?? '',
					logo: $sta['logo'] ?? ''
				);
			}
			else { // "live" == false
				$this->out->addEpisode(
					$id, null,
					$sta['name'], $sta['name'],
					$this->data->getStationURL($id, $this->radioId->getMac()),
					$sta['desc'] ?? '',
					$sta['logo'] ?? '',
					status: $play ? OutputPlayStatus::Play : OutputPlayStatus::Full
				);
			}
		}
	}

	private function listPodcast(int $id) : void {
		$this->unread->searchItem($id);

		$pod = $this->data->getById($id);
		$this->out->prevUrl(
			Config::RADIO_DOMAIN . 'list?tid=3' .
			(empty($pod['category']) ? '' : '&cat=' . rawurlencode($pod['category']))
		);

		$pd = PodcastLoader::getPodcastDataById( $id, $this->data );

		foreach( $pd['episodes'] as $eid => $e ){
			$this->out->addEpisode(
				$id, $eid,
				$pd['title'],
				$this->unread->knownItemMark($id, $e['url']) . $e['title'],
				$this->data->getPodcastURL($id, $eid, $this->radioId->getMac(), sloppy: true),
				$e['desc'], $pd['logo'],
				!$this->unread->knownItem($id, $e['url']),
				OutputPlayStatus::Info
			);
		}

		$this->out->currentUrl(Config::RADIO_DOMAIN . 'list?tid=3&id=' . $id, $pd['title']);
	}
		
	private function listDirectory(?int $tid = null, ?string $cat = null) : void {
		$this->out->currentUrl(
			Config::RADIO_DOMAIN . 'list?' . 
				(is_null($tid) ? '' : '&tid=' . $tid) .
				(is_null($cat) ? '' : '&cat=' . rawurlencode($cat)),
			'Categories'
		);

		// first add categories if in "root" folder of type
		if(is_null($cat) && !is_null($tid)){
			foreach( $this->data->getCategories( $tid ) as $category ){
				$this->out->addDir( $category, Config::RADIO_DOMAIN . 'list?tid=' . $tid . '&cat=' . rawurlencode($category) );
			}
		}
		else{
			$this->out->prevUrl(Config::RADIO_DOMAIN . (is_null($tid) ? '?go=initial' : 'list?tid=' . $tid));
		}

		// then add items
		foreach( $this->data->getListOfItems( $tid, $cat ) as $id => $item ){
			if($item['tid'] == 1 || ($item['tid'] == 2 && $item['live'])){ // radio station, or live "own stream"
				$this->out->addStation(
					$id,
					$item['name'],
					$this->data->getStationURL($id, $this->radioId->getMac()),
					OutputPlayStatus::Info,
					logo: $item['logo'] ?? '', // fork: logos in lists too, not only when playing
					sortKey: self::orderKey($item)
				);
			}
			else if($item['tid'] == 3){ // podcast
				$this->out->addPodcast(
					$id,
					$item['name'],
					Config::RADIO_DOMAIN . 'list?tid=3&id=' . $id,
					sortKey: self::orderKey($item)
				);
			}
			else if ($item['tid'] == 2 && !$item['live']) { // file based "own stream"
				$this->out->addEpisode(
					$id, null,
					$item['name'], $item['name'],
					$this->data->getStationURL($id, $this->radioId->getMac()),
					status: OutputPlayStatus::Info
				);
			}
		}
		$this->addEmptyNote($tid, $cat); // fork
	}

	/**
	 * fork: one folder of the music library: its folders, then "Play all" and its tracks
	 */
	private function listMusic(string $id) : void {
		Music::debugLog('list'); // TEMPORARY button test
		$rel = $id === '' ? '' : Music::pathFor($id);
		if(is_null($rel)){
			$this->out->prevUrl(Config::RADIO_DOMAIN . '?go=initial');
			$this->out->addDir('Not found - the music may have moved', Config::RADIO_DOMAIN . 'music');
			return;
		}
		$name = $rel === '' ? 'Music' : basename($rel);
		$this->out->currentUrl(Config::RADIO_DOMAIN . 'music' . ($rel === '' ? '' : '?id=' . Music::idFor($rel)), $name);
		$parent = $rel === '' ? null : (dirname($rel) === '.' ? '' : dirname($rel));
		$this->out->prevUrl(is_null($parent) ? Config::RADIO_DOMAIN . '?go=initial'
			: Config::RADIO_DOMAIN . 'music' . ($parent === '' ? '' : '?id=' . Music::idFor($parent)));

		$e = Music::entries($rel);
		foreach($e['dirs'] as $d){
			$this->out->addDir(basename($d), Config::RADIO_DOMAIN . 'music?id=' . Music::idFor($d));
		}
		$mac = $this->radioId->getMac();
		if($rel !== '' && (count($e['tracks']) > 1 || (count($e['tracks']) > 0 && count($e['dirs']) > 0)
				|| (count($e['tracks']) === 0 && count(Music::tracksUnder($rel)) > 0 && count($e['dirs']) <= 4))){
			$all = Music::tracksUnder($rel);
			$this->out->addStation(Music::idFor($rel), 'Play all - ' . count($all) . ' tracks',
				Config::RADIO_DOMAIN . 'music.php?id=' . Music::idFor($rel) . '&mac=' . $mac,
				OutputPlayStatus::Info, logo: Config::RADIO_DOMAIN . 'music.php?art=' . Music::idFor($rel), sortKey: '0000');
		}
		// shuffle: a folder with more than one track under it (album, artist), or the whole library
		$under = $rel === '' ? -1 : count(Music::tracksUnder($rel));
		if($rel === '' || $under > 1){
			$label = $rel === '' ? 'Shuffle all music' : 'Shuffle - ' . $under . ' tracks';
			$this->out->addStation(Music::shuffleIdFor($rel), $label,
				Config::RADIO_DOMAIN . 'music.php?id=' . Music::shuffleIdFor($rel) . '&mac=' . $mac,
				OutputPlayStatus::Info, logo: Config::RADIO_DOMAIN . 'music.php?art=' . Music::idFor($rel), sortKey: '0001');
		}
		foreach($e['tracks'] as $n => $t){
			$this->out->addStation(Music::idFor($t), Music::title($t),
				Config::RADIO_DOMAIN . 'music.php?id=' . Music::idFor($t) . '&mac=' . $mac,
				OutputPlayStatus::Info, logo: Config::RADIO_DOMAIN . 'music.php?art=' . Music::idFor($t),
				sortKey: sprintf('%04d', $n + 2));
		}
		if(count($e['dirs']) === 0 && count($e['tracks']) === 0){
			$this->out->addDir('Empty folder', Config::RADIO_DOMAIN . 'music');
		}
	}

	/**
	 * fork: play one music folder ("Play all") or track by its id
	 */
	private function listMusicItem(string $id, bool $play) : void {
		Music::debugLog('item'); // TEMPORARY button test
		$rel = Music::pathFor($id);
		if(is_null($rel)){
			$this->out->addDir('Not found - the music may have moved', Config::RADIO_DOMAIN . 'music');
			return;
		}
		$isDir = is_dir(Music::abs($rel));
		$shuffle = Music::isShuffle($id);
		$parent = dirname($rel) === '.' ? '' : dirname($rel);
		$this->out->prevUrl(Config::RADIO_DOMAIN . 'music' . ($isDir ? '?id=' . $id : ($parent === '' ? '' : '?id=' . Music::idFor($parent))));
		$name = $isDir ? ($rel === '' ? 'All music' : Music::albumOf($rel)) : Music::title($rel);
		if($shuffle) $name = 'Shuffle: ' . $name;
		$url = Config::RADIO_DOMAIN . 'music.php?id=' . $id . '&mac=' . $this->radioId->getMac();
		$art = Config::RADIO_DOMAIN . 'music.php?art=' . Music::idFor($rel);
		$this->out->addStation($id, $name, $url,
			status: $play ? OutputPlayStatus::Play : OutputPlayStatus::Full,
			desc: $isDir ? ($rel === '' ? 'Your whole music library' : count(Music::tracksUnder($rel)) . ' tracks') : Music::albumOf($rel), logo: $art);
		if($play){
			(new Recent($this->radioId->getId()))->add('mu', $id, $isDir ? ($shuffle ? $name : 'Album: ' . $name) : $name . ' - ' . Music::albumOf($rel), $url, $art);
		}
	}

	/**
	 * fork: stations with the star, in list order: [id => item]
	 */
	private function favourites() : array {
		return array_filter(
			$this->data->getListOfItems(1, null) + $this->allInFolders(1),
			fn($i) => !empty($i['fav'])
		);
	}

	private function allInFolders(int $tid) : array {
		$out = array();
		foreach($this->data->getCategories($tid) as $cat){
			if($cat !== '' && $cat !== null) $out += $this->data->getListOfItems($tid, $cat);
		}
		return $out;
	}

	private function listFavourites() : void {
		$this->out->prevUrl(Config::RADIO_DOMAIN . '?go=initial');
		$this->out->currentUrl(Config::RADIO_DOMAIN . 'list?fav=1', 'Favourites');
		$favs = $this->favourites();
		foreach($favs as $id => $item){
			$this->out->addStation(
				$id, $item['name'],
				$this->data->getStationURL($id, $this->radioId->getMac()),
				OutputPlayStatus::Info,
				logo: $item['logo'] ?? '',
				sortKey: self::orderKey($item)
			);
		}
		if(count($favs) === 0){
			$host = parse_url(Config::DOMAIN, PHP_URL_HOST) ?: 'the server';
			$this->out->addDir('No favourites - star stations at ' . $host, Config::RADIO_DOMAIN . '?go=initial');
		}
	}

	private function listRecent() : void {
		$this->out->prevUrl(Config::RADIO_DOMAIN . '?go=initial');
		$this->out->currentUrl(Config::RADIO_DOMAIN . 'list?recent=1', 'Recently played');
		$n = 0;
		foreach((new Recent($this->radioId->getId()))->list() as $e){
			$key = sprintf('%04d', $n);
			if($e['kind'] === 'own'){
				$item = $this->data->getById(intval($e['id']));
				if($item === array()) continue; // deleted since
				$this->out->addStation(
					intval($e['id']), $item['name'],
					$this->data->getStationURL(intval($e['id']), $this->radioId->getMac()),
					OutputPlayStatus::Info, logo: $item['logo'] ?? '', sortKey: $key
				);
			}
			else if($e['kind'] === 'mu' && !is_null(Music::pathFor($e['id']))){
				$this->out->addStation($e['id'], $e['name'],
					Config::RADIO_DOMAIN . 'music.php?id=' . $e['id'] . '&mac=' . $this->radioId->getMac(),
					OutputPlayStatus::Info, logo: Config::RADIO_DOMAIN . 'music.php?art=' . $e['id'], sortKey: $key);
			}
			else if($e['kind'] === 'rb' && RadioBrowser::matchStationID($e['id'])){
				$url = Relay::needed($e['url']) ? Relay::rbUrl($e['id'], $this->radioId->getMac()) : $e['url'];
				$this->out->addStation($e['id'], $e['name'], $url, OutputPlayStatus::Info, logo: $e['logo'] ?? '', sortKey: $key);
			}
			else continue;
			$n++;
		}
		if($n === 0){
			$this->out->addDir('Nothing played yet', Config::RADIO_DOMAIN . '?go=initial');
		}
	}

	/**
	 * fork: a list with no entries makes JSON radios (e.g. Majority) show "network error",
	 * 	so an empty folder gets one line that says where to add things.
	 */
	private function addEmptyNote(?int $tid, ?string $cat) : void {
		$items = $this->data->getListOfItems($tid, $cat);
		$folders = is_null($cat) && !is_null($tid) ? array_filter($this->data->getCategories($tid)) : array();
		if(count($items) === 0 && count($folders) === 0){
			$host = parse_url(Config::DOMAIN, PHP_URL_HOST) ?: 'the server';
			$this->out->addDir('Empty - add on the web page ' . $host, Config::RADIO_DOMAIN . '?go=initial');
		}
	}

	/**
	 * fork: sort key for items in lists (folders always come first: "dir==" < "pod=="/"ra==")
	 */
	private static function orderKey(array $item) : string {
		// the user asked for A-Z on the radios (folders first): '' makes Output sort by name.
		// (to follow the page's list order instead: sprintf('%04d', $item['pos']))
		return '';
	}
}
?>