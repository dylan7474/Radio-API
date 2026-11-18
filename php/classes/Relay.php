<?php
/**
 * Radio-API (fork: dylan7474/Radio-API)
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
defined('HAMARadio') or die('Invalid Endpoint');

/**
 * fork: which streams the radios can't play themselves, so relay.php converts them to MP3.
 * Rules on the address only (no network: testing a home receiver's stream would retune it).
 */
class Relay {

	/**
	 * $rb: a Radio-Browser station record, if the url comes from there (it says "hls" itself)
	 */
	public static function needed(string $url, ?array $rb = null) : bool {
		if(!is_null($rb) && !empty($rb['hls']) && intval($rb['hls']) === 1){
			return true;
		}
		$path = strtolower((string) parse_url($url, PHP_URL_PATH));
		$query = (string) parse_url($url, PHP_URL_QUERY);
		// HLS playlists, e.g. the BBC's (some radios play some HLS, but not reliably)
		if(str_ends_with($path, '.m3u8')){
			return true;
		}
		// a Sleep Radio receiver: http://HOST:8074/audio?band=.. or ?freq=.. sends WAV
		if($path === '/audio' && preg_match('/(^|&)(band|freq)=/', $query)){
			return true;
		}
		return false;
	}

	public static function rbUrl(string $stationId, string $mac) : string {
		return Config::RADIO_DOMAIN . 'relay.php?rb=' . $stationId . '&mac=' . $mac;
	}
}
?>
