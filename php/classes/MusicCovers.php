<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: album covers for the Music menu from MusicBrainz + the Cover Art Archive, for albums
 * that have no cover file and no picture inside their tracks. Run by utils/music-covers.php
 * (never while a radio browses: MusicBrainz allows about one request a second).
 *
 * 1. The CD's disc ID (tag MUSICBRAINZ_DISCID, written by the ripper) names the exact CD:
 *    its releases' front covers, GB first.
 * 2. Else a search by album + artist tags (or the folder names), only a match of 90 or more:
 *    the release group's front cover.
 * The cover (250 px) is kept as media/music-art/<id>.jpg|png, where Music::art() finds it.
 * An album with none gets a ".nocover" marker, so it isn't asked again (unless --retry).
 */
class MusicCovers {

	private const UA = 'Radio-API-dj/1.0 ( https://github.com/dylan7474/Radio-API )';
	private const ART_DIR = __DIR__ . '/../media/music-art';

	private float $lastMb = 0;
	public array $log = array();

	public function __construct(private bool $retry = false, private bool $dryRun = false) {}

	/**
	 * Every folder with tracks directly in it (an album, or one CD of a set), relative paths
	 */
	public static function albumFolders() : array {
		$out = array();
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(Music::ROOT, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
			RecursiveIteratorIterator::SELF_FIRST);
		foreach($it as $f){
			if($f->isFile() && Music::isAudio($f->getFilename())){
				$rel = ltrim(substr($f->getPath(), strlen(Music::ROOT)), '/');
				if($rel !== '') $out[$rel] = true;
			}
		}
		$out = array_keys($out);
		natcasesort($out);
		return array_values($out);
	}

	private static function isDiscFolder(string $rel) : bool {
		return preg_match('/^(cd|disc|disk)\s*\d+$/i', basename($rel)) === 1;
	}

	private static function artFile(string $rel) : ?string {
		$key = Music::idFor($rel);
		foreach(array('jpg', 'png') as $x){
			if(is_file(self::ART_DIR . '/' . $key . '.' . $x)) return self::ART_DIR . '/' . $key . '.' . $x;
		}
		return null;
	}

	/**
	 * One album folder: 'had' (already has art), 'found', 'none' or 'skip' (no cover last time)
	 */
	public function cover(string $rel) : string {
		if(!is_null(self::artFile($rel)) || !is_null(Music::art($rel))){
			return 'had'; // (Music::art finds cover files and pictures inside the tracks)
		}
		$key = Music::idFor($rel);
		if(is_file(self::ART_DIR . '/' . $key . '.removed')){
			return 'skip'; // its cover was removed on the page: never fetch one again
		}
		if(!$this->retry && is_file(self::ART_DIR . '/' . $key . '.nocover')){
			return 'skip';
		}

		$tags = $this->tags($rel);
		$bytes = null; $how = '';
		if(!empty($tags['musicbrainz_discid'])){
			list($bytes, $how) = $this->byDiscId($tags['musicbrainz_discid']);
		}
		if(is_null($bytes)){
			list($album, $artist) = $this->albumAndArtist($rel, $tags);
			if($album !== ''){
				list($bytes, $how) = $this->bySearch($album, $artist);
			}
		}

		if(is_null($bytes)){
			$this->log[] = "  none   $rel";
			if(!$this->dryRun) @touch(self::ART_DIR . '/' . $key . '.nocover');
			return 'none';
		}
		$this->log[] = "  found  $rel ($how)";
		if(!$this->dryRun){
			$this->save($rel, $bytes);
			// one CD of a set: the set's folder (shown in the menu) gets the cover too
			if(self::isDiscFolder($rel) && is_null(self::artFile(dirname($rel)))){
				$this->save(dirname($rel), $bytes);
			}
		}
		return 'found';
	}

	private function save(string $rel, string $bytes) : void {
		if(!is_dir(self::ART_DIR)) @mkdir(self::ART_DIR, 0755, true);
		$key = Music::idFor($rel);
		$x = str_starts_with($bytes, "\x89PNG") ? 'png' : 'jpg';
		file_put_contents(self::ART_DIR . '/' . $key . '.' . $x, $bytes);
		@unlink(self::ART_DIR . '/' . $key . '.none');
		@unlink(self::ART_DIR . '/' . $key . '.nocover');
	}

	/**
	 * The first track's tags, lower-case names
	 */
	private function tags(string $rel) : array {
		$tracks = Music::tracksUnder($rel);
		if(empty($tracks)) return array();
		$out = array(); $rs = 0;
		exec('ffprobe -v error -show_entries format_tags -of json ' . escapeshellarg(Music::abs($tracks[0])) . ' 2>/dev/null', $out, $rs);
		$j = json_decode(implode("\n", $out), true);
		return array_change_key_case(is_array($j['format']['tags'] ?? null) ? $j['format']['tags'] : array(), CASE_LOWER);
	}

	/**
	 * Album and artist to search for: the tags, else the folder names
	 * 	("Artist/Album", "Artist/Artist - Album" or "Artist - Album")
	 */
	private function albumAndArtist(string $rel, array $tags) : array {
		$dir = self::isDiscFolder($rel) ? dirname($rel) : $rel;
		$album = trim($tags['album'] ?? '');
		$artist = trim($tags['album_artist'] ?? $tags['albumartist'] ?? $tags['artist'] ?? '');
		if($album === ''){
			$name = basename($dir);
			if(preg_match('/^(.+?)\s+-\s+(.+)$/', $name, $m)){
				$artist = $artist !== '' ? $artist : $m[1];
				$album = $m[2];
			}
			else {
				$album = $name;
				if($artist === '' && dirname($dir) !== '.') $artist = basename(dirname($dir));
			}
		}
		// "(Disc 1)", "CD 2", "[Remastered]" don't help the search
		$album = trim(preg_replace('/\s*[\(\[]?\b(cd|disc|disk)\s*\d+[\)\]]?\s*$/i', '', $album));
		$album = trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $album));
		if(preg_match('/^various( artists)?$/i', $artist)) $artist = '';
		return array($album, $artist);
	}

	private function byDiscId(string $discId) : array {
		if(preg_match('/^[A-Za-z0-9._-]{20,40}$/', $discId) !== 1) return array(null, '');
		$j = $this->mb('discid/' . rawurlencode($discId) . '?fmt=json&inc=release-groups');
		$releases = $j['releases'] ?? array();
		// GB first, then Europe / worldwide, then the rest
		$rank = fn($r) => array_search($r['country'] ?? '', array('GB', 'XE', 'XW'), true);
		usort($releases, function($a, $b) use ($rank){
			$ra = $rank($a); $rb = $rank($b);
			return ($ra === false ? 9 : $ra) <=> ($rb === false ? 9 : $rb);
		});
		foreach($releases as $r){
			if(!empty($r['cover-art-archive']['front'])){
				$b = $this->caa('release/' . $r['id']);
				if(!is_null($b)) return array($b, 'disc ID, ' . ($r['country'] ?? '?') . ' release');
			}
		}
		foreach($releases as $r){
			if(!empty($r['release-group']['id'])){
				$b = $this->caa('release-group/' . $r['release-group']['id']);
				if(!is_null($b)) return array($b, 'disc ID, release group');
			}
		}
		return array(null, '');
	}

	private function bySearch(string $album, string $artist) : array {
		$q = 'releasegroup:"' . addcslashes($album, '"\\') . '"' . ($artist !== '' ? ' AND artist:"' . addcslashes($artist, '"\\') . '"' : '');
		$j = $this->mb('release-group/?fmt=json&limit=5&query=' . rawurlencode($q));
		foreach($j['release-groups'] ?? array() as $g){
			if(($g['score'] ?? 0) < 90) break;
			$b = $this->caa('release-group/' . $g['id']);
			if(!is_null($b)) return array($b, 'search ' . $g['score'] . '%: ' . ($g['title'] ?? ''));
		}
		return array(null, '');
	}

	/**
	 * A MusicBrainz request: at most one a second, and waits when it says it is busy
	 */
	private function mb(string $path) : array {
		for($try = 0; $try < 6; $try++){
			$wait = $this->lastMb + 1.1 - microtime(true);
			if($wait > 0) usleep((int)($wait * 1000000));
			$this->lastMb = microtime(true);
			list($status, $body) = self::get('https://musicbrainz.org/ws/2/' . $path);
			if($status === 200){
				$j = json_decode($body, true);
				return is_array($j) ? $j : array();
			}
			if($status !== 503 && $status !== 429 && $status !== 0) return array(); // 404 = not known
			sleep(2 * ($try + 1));
		}
		return array();
	}

	/**
	 * The front cover (250 px) from the Cover Art Archive, or null
	 */
	private function caa(string $path) : ?string {
		list($status, $body) = self::get('https://coverartarchive.org/' . $path . '/front-250');
		if($status !== 200 || strlen($body) < 500) return null;
		return (str_starts_with($body, "\xFF\xD8\xFF") || str_starts_with($body, "\x89PNG")) ? $body : null;
	}

	private static function get(string $url) : array {
		$buf = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
			CURLOPT_USERAGENT => self::UA,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buf) {
				$buf .= $chunk;
				return strlen($buf) > 5000000 ? 0 : strlen($chunk);
			}
		));
		curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		unset($ch);
		return array($status, $buf);
	}
}
?>
