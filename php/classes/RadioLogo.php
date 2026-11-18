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

class RadioLogo {

	const BASE_DIR = __DIR__ . '/../media';
	const BASE_URL = Config::RADIO_DOMAIN . 'media/';

	private bool $useImageCache;

	public function __construct(){
		// check if image cache active and if cache folder writable
		$this->useImageCache = Config::USE_LOGO_CACHE && is_writable(self::BASE_DIR);
	}

	public function clearCache() : bool {
		if($this->useImageCache){
			$ok = true;
			foreach(scandir(self::BASE_DIR) as $d){
				if(preg_match('/^[a-f0-9]{40}\.(image|error)$/', $d) === 1){
					$ok = $ok && unlink(self::BASE_DIR . '/' . $d);
				}
			}
			return $ok;
		}
		return false;
	}
	
	public function logoUrl(string $logo) : string {
		// empty or no url
		if(empty($logo) || substr($logo, 0, 4) != 'http'){
			return self::BASE_URL . 'station.png'; // fork: our neutral radio icon, not the KIMB logo
		}
		
		// fork: our own addresses (album art from music.php, media/...) are already local
		if(str_starts_with($logo, Config::RADIO_DOMAIN) || str_starts_with($logo, Config::DOMAIN)){
			return $logo;
		}

		// fork: the station's own stored copy (media/logos/), served as a plain file
		$local = self::localFile($logo);
		if(!is_null($local)){
			return self::BASE_URL . 'logos/' . $local;
		}

		// do not use cache
		if(!$this->useImageCache){
			return $logo;
		}

		// create hash, cache file access
		$namehash = sha1($logo);
		$cacheurl = Config::RADIO_DOMAIN . 'image.php?hash=' . $namehash;

		// return file from cache if available
		if(is_file(self::BASE_DIR . '/' . $namehash . '.image')){
			return $cacheurl;
		}
		// if download error: our plain icon (fork; the radio could not open the address either)
		else if(is_file(self::BASE_DIR . '/' . $namehash . '.error')){
			return self::BASE_URL . 'station.png';
		}
		else {
			// try download
			if($this->fetchLogo($logo)){
				return $cacheurl;
			}
			else {
				// create error file
				file_put_contents(self::BASE_DIR . '/' . $namehash . '.error', '');
				return self::BASE_URL . 'station.png'; // fork
			}
		}
	}

	/**
	 * fork: stored station logos. Each logo address gets a file media/logos/<sha1 of address>.png
	 * 	(at most 256 px; the original format if it can't be converted). They are kept for good:
	 * 	"clear logo cache" leaves them, and a logo that disappears from its website stays here.
	 */
	const LOCAL_DIR = self::BASE_DIR . '/logos';

	public static function localFile(string $logo) : ?string {
		if(empty($logo) || substr($logo, 0, 4) != 'http'){
			return null;
		}
		$found = glob(self::LOCAL_DIR . '/' . sha1($logo) . '.*');
		return empty($found) ? null : basename($found[0]);
	}

	/**
	 * Store a logo address as a local file, if it isn't yet. Uses the logo cache's copy
	 * 	when there is one (no download), else downloads it. True if there is a file now.
	 */
	public static function storeLocal(string $logo) : bool {
		if(empty($logo) || !preg_match('#^https?://#i', $logo)){
			return false;
		}
		if(!is_null(self::localFile($logo))){
			return true;
		}
		if(!is_dir(self::LOCAL_DIR) && !@mkdir(self::LOCAL_DIR, 0755, true)){
			return false;
		}
		$hash = sha1($logo);
		$cached = self::BASE_DIR . '/' . $hash . '.image';
		$bytes = is_file($cached) ? file_get_contents($cached) : self::download($logo);
		if(!is_string($bytes) || strlen($bytes) < 100){
			return false;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'logo');
		file_put_contents($tmp, $bytes);
		$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		if(!str_starts_with($mime, 'image/') && preg_match('/<svg[\s>]/i', substr($bytes, 0, 2000))){
			$mime = 'image/svg+xml';
		}
		if(!str_starts_with($mime, 'image/')){
			unlink($tmp);
			return false;
		}

		$png = tempnam(sys_get_temp_dir(), 'png');
		$ok = false;
		if($mime === 'image/svg+xml'){
			$ok = self::svg2png($tmp, $png);
		}
		else if($mime === 'image/png' || $mime === 'image/jpeg' || $mime === 'image/gif'){
			// to PNG, at most 256 px (no GD here: wrapped in an SVG for rsvg-convert, as resize() does)
			list($w, $h) = self::imageDimensions($tmp);
			if($w > 0 && $h > 0){
				$scale = min(1, 256 / max($w, $h));
				$tw = max(1, (int) round($w * $scale)); $th = max(1, (int) round($h * $scale));
				$svg = tempnam(sys_get_temp_dir(), 'svg');
				file_put_contents($svg, "<svg xmlns='http://www.w3.org/2000/svg' width='".$tw."' height='".$th."' version='1.1'>"
					. "<image href='data:".$mime.";base64,".base64_encode($bytes)."' width='".$tw."' height='".$th."'/></svg>");
				$rs = 0;
				exec('rsvg-convert --format png -o ' . escapeshellarg($png) . ' ' . escapeshellarg($svg), result_code: $rs);
				unlink($svg);
				$ok = $rs === 0 && filesize($png) > 100;
			}
		}

		$exts = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
			'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico');
		if($ok){
			$done = rename($png, self::LOCAL_DIR . '/' . $hash . '.png');
		}
		else if(isset($exts[$mime])){ // could not convert: keep the original as it is
			@unlink($png);
			$done = rename($tmp, self::LOCAL_DIR . '/' . $hash . '.' . $exts[$mime]);
			$tmp = null;
		}
		else {
			@unlink($png);
			$done = false;
		}
		if(!is_null($tmp)) @unlink($tmp);
		if($done) @chmod(self::LOCAL_DIR . '/' . $hash . '.' . ($ok ? 'png' : $exts[$mime]), 0644);
		return $done;
	}

	private static function download(string $url) : string|false {
		$buf = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
			CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Radio-API/' . Config::VERSION . ')',
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buf) {
				$buf .= $chunk;
				return strlen($buf) > 5000000 ? 0 : strlen($chunk);
			}
		));
		curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		unset($ch);
		return $status === 200 ? $buf : false;
	}

	private function fetchLogo(string $logo) : bool {
		//download
		$image = file_get_contents($logo);
		if($image === false){
			return false;
		}

		// file names
		$namehash = sha1($logo);
		$filename = self::BASE_DIR . '/' . $namehash . '.image';
		// store
		file_put_contents($filename, $image);

		// mime
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mimetype = finfo_file($finfo, $filename);
		// fork: small SVGs are often not recognised (e.g. the BBC's network logos)
		if( substr($mimetype, 0, 6) !== 'image/' && preg_match('/<svg[\s>]/i', substr($image, 0, 2000)) ){
			$mimetype = 'image/svg+xml';
		}

		// its not a image file
		if( substr($mimetype, 0, strlen('image/')) !== 'image/'){
			unlink($filename);
			return false;
		}

		$tmpName = tempnam(sys_get_temp_dir(), 'conv');
		if($mimetype == 'image/svg+xml'){
			if( self::svg2png($filename, $tmpName) ){
				return rename($tmpName, $filename);
			}
			else {
				unlink($filename);
				return false;
			}
		}
		else {

			// resize file 
			//	will only return true if resize done (false on error or if already small enough)
			if(self::resize($filename, $mimetype, $tmpName)){
				rename($tmpName, $filename);
			}

			return true;
		}
	}

	private static function svg2png(string $inputSVG, string $outputPNG) : bool {
		$command = array(
			'rsvg-convert',
			'--width', '256',
			'--height', '256',
			'--keep-aspect-ratio',
			'--background-color', 'white',
			'--format', 'png',
			'-o', '"'.$outputPNG.'"',
			'"'.$inputSVG.'"'
		);
		$rs = 0;
		exec(implode(' ', $command), result_code:$rs);
		return $rs === 0;
	}

	private static function imageDimensions(string $file) : array {
		$finfo = finfo_open(FILEINFO_CONTINUE);
		$info = finfo_file($finfo, $file);
		
		// file info (including dimensions as ", 000 x 000,")
		if(preg_match('/,(\d+)x(\d+),/', str_replace(' ', '', $info), $matches) === 1){
			$width = intval($matches[1]);
			$height = intval($matches[2]);

			return [$width, $height];
		}
		else{
			return [0, 0];
		}
	}

	private static function resize(string $inputFile, string $inputMime, string $outputPNG) : bool {
		// determine image dimensions
		list($width, $height) = self::imageDimensions($inputFile);

		// error
		if($width == 0 || $height == 0){
			// no resize 
			return false;
		}

		// do not resize if smaller than 256 px
		if( $width <= 256 && $height <= 256 ){
			// resize not necessary
			return false;
		}
	
		// create an svg with image
		$svgFile = "<svg xmlns='http://www.w3.org/2000/svg'
				width='".$width."' height='".$height."' version='1.1'>
			<image href='data:".$inputMime.";base64,".base64_encode(file_get_contents($inputFile))."'
				width='".$width."' height='".$height."' />
		</svg>";

		// write to tmp
		$inputSVG = tempnam(sys_get_temp_dir(), 'svg');
		file_put_contents($inputSVG, $svgFile);

		// create small png
		return self::svg2png($inputSVG, $outputPNG);
	}

}

?>