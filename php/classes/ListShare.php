<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: radios that share one list of stations and podcasts. data/list_links.json maps a radio
 * to the radio whose list it uses: {"2": 1} = radio 2 shows (and edits) radio 1's list.
 * Data uses the owner's list for a radio that follows one; everything else (Recently played,
 * names, codes) stays per radio. One level only: an owner never follows another radio.
 * A radio's own files stay on disk while it follows; when it stops, it gets a copy of the
 * shared list as it is then (with the same station ids, so its presets keep working).
 */
class ListShare {

	private const FILE = __DIR__ . '/../data/list_links.json';

	/**
	 * follower id => owner id
	 */
	public static function links() : array {
		$j = is_file(self::FILE) ? json_decode(file_get_contents(self::FILE), true) : null;
		$out = array();
		foreach(is_array($j) ? $j : array() as $f => $o){
			if(is_numeric($f) && is_int($o) && intval($f) !== $o) $out[intval($f)] = $o;
		}
		return $out;
	}

	/**
	 * The radio whose list this radio uses (itself, if it has its own)
	 */
	public static function ownerOf(int $id) : int {
		return self::links()[$id] ?? $id;
	}

	/**
	 * Radios that use this radio's list
	 */
	public static function followers(int $owner) : array {
		return array_keys(array_filter(self::links(), fn($o) => $o === $owner));
	}

	/**
	 * Every radio id that has connected (data/table.json)
	 */
	public static function knownIds() : array {
		return array_map('intval', array_keys(Id::getTableData()['ids'] ?? array()));
	}

	/**
	 * Let radio $id use $owner's list ($owner 0 = its own list again). Null if done, else why not.
	 */
	public static function set(int $id, int $owner) : ?string {
		$links = self::links();
		$current = $links[$id] ?? 0;
		if($owner === $current) return null;

		if($owner === 0){
			// its own list again: start from the shared list as it is now
			$shared = new Data($current);
			$radios = $shared->getRadioList();
			$pods = $shared->getPodcastList();
			unset($links[$id]);
			self::write($links);
			$own = new Data($id, true);
			$own->setRadioList($radios);
			$own->setPodcastList($pods);
			return null;
		}

		if($owner === $id || !in_array($owner, self::knownIds(), true)) return 'No such radio';
		if(isset($links[$owner])) return 'That radio uses another radio\'s list itself';
		if(!empty(self::followers($id))) return 'Other radios use this radio\'s list; they would lose it';
		$links[$id] = $owner;
		self::write($links);
		return null;
	}

	private static function write(array $links) : void {
		$out = array();
		foreach($links as $f => $o) $out[strval($f)] = $o;
		file_put_contents(self::FILE, json_encode((object) $out, JSON_PRETTY_PRINT), LOCK_EX);
	}
}
?>
