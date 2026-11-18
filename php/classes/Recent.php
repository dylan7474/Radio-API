<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: "Recently played" for one radio: every station it played, from its own
 * list, a preset or Radio-Browser, newest first. Kept in the cache (data/cache
 * with the JSON cache, which survives restarts).
 *
 * Entry: kind ("own" | "rb"), id (what the radio plays: 1000.. or the 32-char
 * Radio-Browser id), name, url, logo, time.
 */
class Recent {

	public const MAX = 20;

	private Cache $cache;

	public function __construct(int $radioId){
		$this->cache = new Cache('recent.' . $radioId);
	}

	public function add(string $kind, string $id, string $name, string $url, string $logo = '') : void {
		$list = array_values(array_filter($this->list(), fn($e) => !($e['kind'] === $kind && $e['id'] === $id)));
		array_unshift($list, array(
			'kind' => $kind, 'id' => $id, 'name' => $name, 'url' => $url, 'logo' => $logo, 'time' => time()
		));
		$this->cache->set('list', json_encode(array_slice($list, 0, self::MAX)));
	}

	public function list() : array {
		if(!$this->cache->keyExists('list')){
			return array();
		}
		$l = json_decode($this->cache->get('list'), true);
		return is_array($l) ? $l : array();
	}

	public function clear() : void {
		$this->cache->set('list', '[]');
	}
}
?>
