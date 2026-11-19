<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: what a radio is playing right now, for the page. Only streams that pass through phobos
 * (music.php, relay.php) are known for sure: they set an entry when they start, renew it every
 * 30 s while the radio listens, and remove it when it leaves. A radio plays other stations
 * straight from the station, so for those the page shows the last one started (Recent).
 *
 * Each stream has its own token, so an older stream ending never removes a newer stream's entry;
 * a short connection that started while another stream played (a probe) hands the entry back.
 * One small file per radio in the container's temp folder (not the shared cache: that saves a
 * whole group at once, so two radios playing would overwrite each other). Gone on restart: fine.
 */
class NowPlaying {

	public const STALE = 75; // seconds without a renewal = the stream ended without saying so
	private const BEAT = 30;

	private string $token;
	private array $entry = array();
	private ?array $prev = null;
	private int $lastBeat = 0;

	public function __construct(private int $radioId){
		$this->token = bin2hex(random_bytes(8));
	}

	private static function file(int $radioId) : string {
		return sys_get_temp_dir() . '/radio-api-nowplaying-' . $radioId . '.json';
	}

	private static function write(int $radioId, array $e) : void {
		$f = self::file($radioId);
		$tmp = $f . '.' . getmypid();
		if(file_put_contents($tmp, json_encode($e)) !== false) rename($tmp, $f);
	}

	/**
	 * This stream is now playing: kind music|relay, name (track / station), detail (album / description), art
	 */
	public function set(string $kind, string $name, string $detail = '', string $art = '') : void {
		if(empty($this->entry)){ // what was playing when this stream started (for clear())
			$cur = self::get($this->radioId);
			$this->prev = !is_null($cur) && ($cur['token'] ?? '') !== $this->token ? $cur : null;
		}
		$this->entry = array('kind' => $kind, 'name' => $name, 'detail' => $detail, 'art' => $art,
			'started' => time(), 'token' => $this->token);
		$this->beat(true);
	}

	/**
	 * Still playing (called while sending audio; writes at most every 30 s)
	 */
	public function beat(bool $now = false) : void {
		if(empty($this->entry) || (!$now && time() - $this->lastBeat < self::BEAT)) return;
		$this->lastBeat = time();
		$this->entry['updated'] = time();
		self::write($this->radioId, $this->entry);
	}

	/**
	 * The stream ended: remove the entry, if it is still this stream's
	 */
	public function clear() : void {
		if(empty($this->entry)) return;
		$cur = self::get($this->radioId, false);
		if(!is_null($cur) && ($cur['token'] ?? '') === $this->token){
			if(!is_null($this->prev) && time() - $this->entry['started'] < 20){
				self::write($this->radioId, $this->prev); // a short probe: the stream before it is still on
			}
			else @unlink(self::file($this->radioId));
		}
		$this->entry = array();
	}

	/**
	 * A radio's current entry, or null (none, or not renewed for STALE seconds)
	 */
	public static function get(int $radioId, bool $freshOnly = true) : ?array {
		$f = self::file($radioId);
		$e = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
		if(!is_array($e)) return null;
		if($freshOnly && time() - intval($e['updated'] ?? 0) > self::STALE) return null;
		return $e;
	}
}
?>
