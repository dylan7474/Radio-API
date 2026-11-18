<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: a music library on the server (phobos: /mnt/media/Audio/CD Music, mounted read-only
 * at /music) as a "Music" menu on the radios: folders -> albums -> tracks, "Play all".
 *
 * Every folder and track has a fixed id "mu" + 30 hex (from its path), so the radio can
 * keep it on a preset; ids are 32 characters, which radios remember, and never all-hex
 * like Radio-Browser's. The id -> path index is rebuilt by walking the library if needed.
 */
class Music {

	public const ROOT = '/music';
	public const ID_PREG = '/^m[us][0-9a-f]{30}$/'; // mu = a folder/track, ms = shuffle that folder
	private const AUDIO = array('mp3', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'wma', 'aif', 'aiff');

	public static function available() : bool {
		return is_dir(self::ROOT) && count(self::entries('')) > 0;
	}

	public static function idFor(string $rel) : string {
		return 'mu' . substr(md5($rel), 0, 30);
	}

	public static function shuffleIdFor(string $rel) : string {
		return 'ms' . substr(md5($rel), 0, 30);
	}

	public static function isShuffle(string $id) : bool {
		return str_starts_with($id, 'ms');
	}

	public static function isAudio(string $name) : bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::AUDIO, true);
	}

	/**
	 * Absolute path for a path relative to the library, or null if outside / missing
	 */
	public static function abs(string $rel) : ?string {
		$p = realpath(self::ROOT . '/' . $rel);
		if($p === false || ($p !== self::ROOT && !str_starts_with($p, self::ROOT . '/'))){
			return null;
		}
		return $p;
	}

	/**
	 * One folder: ['dirs' => [rel...], 'tracks' => [rel...]], both in natural order
	 */
	public static function entries(string $rel) : array {
		$abs = self::abs($rel);
		$out = array('dirs' => array(), 'tracks' => array());
		if(is_null($abs) || !is_dir($abs)){
			return $out;
		}
		foreach(scandir($abs) as $n){
			if($n === '.' || $n === '..' || $n[0] === '.') continue;
			$r = ltrim($rel . '/' . $n, '/');
			if(is_dir($abs . '/' . $n)) $out['dirs'][] = $r;
			else if(self::isAudio($n)) $out['tracks'][] = $r;
		}
		$nat = fn($a, $b) => strnatcasecmp(basename($a), basename($b));
		usort($out['dirs'], $nat);
		usort($out['tracks'], $nat);
		self::remember(array_merge(array($rel), $out['dirs'], $out['tracks']));
		return $out;
	}

	/**
	 * Every track in a folder and its subfolders (CD1, CD2, ...), in order
	 */
	public static function tracksUnder(string $rel, int $depth = 0) : array {
		$e = self::entries($rel);
		$tracks = $e['tracks'];
		if($depth < 3){
			foreach($e['dirs'] as $d){
				$tracks = array_merge($tracks, self::tracksUnder($d, $depth + 1));
			}
		}
		return $tracks;
	}

	/**
	 * Path for an id (folder or track), or null
	 */
	public static function pathFor(string $id) : ?string {
		if(!preg_match(self::ID_PREG, $id)){
			return null;
		}
		$id = 'mu' . substr($id, 2); // a shuffle id names the same folder
		$c = new Cache('music-index');
		if($c->arrayKeyExists('index', $id)){
			$rel = $c->arrayKeyGet('index', $id);
			if(is_string($rel) && !is_null(self::abs($rel))) return $rel;
		}
		// not known yet (e.g. a preset after a restart): walk the library once
		$found = self::walk('', $id, 0);
		return $found;
	}

	private static function walk(string $rel, string $id, int $depth) : ?string {
		if(self::idFor($rel) === $id) return $rel;
		$e = self::entries($rel);
		foreach($e['tracks'] as $t){
			if(self::idFor($t) === $id) return $t;
		}
		if($depth < 5){
			foreach($e['dirs'] as $d){
				$r = self::walk($d, $id, $depth + 1);
				if(!is_null($r)) return $r;
			}
		}
		return null;
	}

	private static function remember(array $rels) : void {
		// one write per listing (the whole index is one cache entry)
		$c = new Cache('music-index');
		$idx = $c->arrayGet('index');
		$changed = false;
		foreach($rels as $r){
			$id = self::idFor($r);
			if(!isset($idx[$id])){ $idx[$id] = $r; $changed = true; }
		}
		if($changed) $c->arraySet('index', $idx);
	}

	/**
	 * A track's name for the radio: the file name without extension and track number dots
	 * 	("05. I'm Losing You.flac" -> "05 I'm Losing You")
	 */
	public static function title(string $rel) : string {
		$n = pathinfo($rel, PATHINFO_FILENAME);
		$n = preg_replace('/^(\d{1,3})\s*[.\-_]\s*/', '$1 ', $n);
		return trim(str_replace('_', ' ', $n));
	}

	/**
	 * The album a track or folder belongs to, for "now playing": "Artist - Album"
	 */
	public static function albumOf(string $rel) : string {
		$dir = self::isAudio($rel) ? dirname($rel) : $rel;
		$parts = array_values(array_filter(explode('/', $dir), fn($p) => $p !== '' && $p !== '.'));
		// skip CD1 / mp3 / flac style subfolders
		while(count($parts) > 1 && preg_match('/^(cd|disc|disk)\s*\d+$|^(mp3|flac)$/i', end($parts))){
			array_pop($parts);
		}
		return implode(' - ', array_slice($parts, -2));
	}

	/**
	 * Album art for a folder or track: a cover file in the folder (or the album above), else
	 * 	the picture inside the first track (FLAC and MP3 often carry one). Stored in
	 * 	media/music-art/<id>.jpg|png so it is found once. Null if there is none.
	 */
	public static function art(string $rel) : ?string {
		$dir = self::isAudio($rel) ? dirname($rel) : $rel;
		$artDir = __DIR__ . '/../media/music-art';
		$key = self::idFor($dir);
		foreach(array('jpg', 'png') as $x){
			if(is_file($artDir . '/' . $key . '.' . $x)) return $artDir . '/' . $key . '.' . $x;
		}
		if(is_file($artDir . '/' . $key . '.none')) return null;
		if(!is_dir($artDir)) @mkdir($artDir, 0755, true);

		$candidates = array($dir, dirname($dir));
		foreach($candidates as $d){
			$abs = self::abs($d);
			if(is_null($abs) || !is_dir($abs)) continue;
			foreach(scandir($abs) as $n){
				if(preg_match('/^(cover|folder|front|albumart[^.]*)\.(jpe?g|png)$/i', $n, $m)){
					$x = strtolower($m[2]) === 'png' ? 'png' : 'jpg';
					if(@copy($abs . '/' . $n, $artDir . '/' . $key . '.' . $x)) return $artDir . '/' . $key . '.' . $x;
				}
			}
		}
		$tracks = self::tracksUnder($dir);
		if(!empty($tracks)){
			$out = $artDir . '/' . $key . '.jpg';
			$rs = 0;
			exec('ffmpeg -hide_banner -loglevel error -nostdin -y -i ' . escapeshellarg(self::abs($tracks[0]))
				. ' -an -frames:v 1 -vf "scale=\'min(256,iw)\':-2" ' . escapeshellarg($out) . ' 2>/dev/null', result_code: $rs);
			if($rs === 0 && is_file($out) && filesize($out) > 100) return $out;
			@unlink($out);
		}
		@touch($artDir . '/' . $key . '.none');
		return null;
	}
}
?>
