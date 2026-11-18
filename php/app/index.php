<?php
/**
 * Radio-API (fork: dylan7474/Radio-API) - new web page.
 * The page is static; all data comes from ./api.php.
 *
 * released under the terms of GNU Public License Version 3
 * https://www.gnu.org/licenses/gpl-3.0.txt
 */
define('HAMARadio', 'Radio');
error_reporting( !empty($_ENV['DEV']) && $_ENV['DEV'] == 'dev' ? E_ALL : 0 );
require_once(__DIR__ . '/../classes/autoload.php');
Config::checkAccess();
$v = htmlspecialchars(Config::VERSION);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Radio stations</title>
<link rel="icon" href="../favicon.ico">
<link rel="stylesheet" href="app.css?v=<?= $v ?>">
</head>
<body>

<section id="login" class="login" hidden>
	<form id="loginForm" class="card">
		<h1>Radio stations</h1>
		<p>On the radio, open <b>Internet Radio</b>. The last line shows <b>GUI-Code: Z…</b>. Type that code here.</p>
		<label>Code <input id="loginCode" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Zxxxx" maxlength="5" required></label>
		<button class="primary" type="submit">Log in</button>
		<p id="loginMsg" class="msg" role="alert"></p>
	</form>
</section>

<section id="app" hidden>
	<header class="top">
		<h1>Radio stations</h1>
		<span class="code" title="This radio's GUI-Code">Radio <b id="radioCode"></b></span>
		<nav class="tabs" role="tablist">
			<button role="tab" data-tab="stations" aria-selected="true">Stations</button>
			<button role="tab" data-tab="find">Find stations</button>
			<button role="tab" data-tab="podcasts">Podcasts</button>
		</nav>
		<span class="spacer"></span>
		<a class="quiet" href="../gui/" title="The original page: merging radios, import and export">Classic page</a>
		<button id="logout" class="quiet">Log out</button>
	</header>

	<main>
		<div id="tab-stations" class="tab">
			<div class="toolbar">
				<button id="addStation" class="primary">+ Add a station</button>
				<input id="filterStations" type="search" placeholder="Filter your stations">
			</div>
			<p class="hint">The radio shows folders first, then your stations in this order. Presets stay with their station when you move it.</p>
			<p class="hint" id="presetHint" hidden>⚠ You deleted a station. If it was on a preset, that preset stops working until you store a new one on it.</p>
			<ol id="stationList" class="items"></ol>
			<p class="empty" id="stationsEmpty" hidden>No stations yet. Use <b>Find stations</b> or <b>+ Add a station</b>.</p>
		</div>

		<div id="tab-find" class="tab" hidden>
			<form id="searchForm" class="searchbar">
				<input id="sName" type="search" placeholder="Station name, e.g. BBC Radio 4">
				<input id="sTag" type="search" placeholder="Genre or tag, e.g. jazz">
				<select id="sCountry"><option value="">Any country</option></select>
				<select id="sOrder">
					<option value="clickcount">Most listened</option>
					<option value="votes">Most votes</option>
					<option value="bitrate">Best quality</option>
					<option value="name">Name A-Z</option>
				</select>
				<button class="primary" type="submit">Search</button>
			</form>
			<p class="hint">Stations come from Radio-Browser. Stations that failed its last check are hidden. Press ▶ to listen here before adding.</p>
			<div id="searchStatus" class="msg"></div>
			<ol id="results" class="items results"></ol>
			<details id="lastBox">
				<summary>Recently played on the radio (from Radio-Browser)</summary>
				<ol id="lastList" class="items results"></ol>
			</details>
		</div>

		<div id="tab-podcasts" class="tab" hidden>
			<form id="podSearch" class="searchbar">
				<input id="pq" type="search" placeholder="Find a podcast, e.g. In Our Time">
				<button class="primary" type="submit">Search</button>
				<button id="addPodcast" type="button">+ Add by feed address</button>
			</form>
			<p class="hint">Podcasts are found in Apple's podcast directory (iTunes), the same one Sleep Radio uses.</p>
			<div id="podStatus" class="msg"></div>
			<ol id="podResults" class="items results"></ol>
			<h2 class="listhead" id="podListHead">Your podcasts</h2>
			<ol id="podcastList" class="items"></ol>
			<p class="empty" id="podcastsEmpty" hidden>No podcasts yet. Add one with its RSS feed address.</p>
		</div>
	</main>

	<div id="savebar" class="savebar" hidden>
		<span id="saveText">Unsaved changes</span>
		<button id="undo" class="quiet">Undo all</button>
		<button id="save" class="primary">Save to the radio</button>
	</div>

	<div id="player" class="player" hidden>
		<img id="pLogo" alt="">
		<div class="ptext"><b id="pName"></b><small id="pNote"></small></div>
		<input id="pVol" type="range" min="0" max="1" step="0.05" value="0.7" aria-label="Volume">
		<button id="pStop" class="primary">■ Stop</button>
		<audio id="audio" preload="none"></audio>
	</div>
	<div id="toast" class="toast" role="status" aria-live="polite"></div>
</section>

<dialog id="stationDlg">
	<form method="dialog" id="stationForm" class="dlg">
		<h2 id="stationDlgTitle">Station</h2>
		<label>Name <input name="name" required maxlength="200"></label>
		<label>Stream address
			<span class="row"><input name="url" type="url" required placeholder="http://… (.mp3, .aac, .m3u or .pls)"><button type="button" class="testBtn">Test</button></span>
		</label>
		<p class="msg testMsg"></p>
		<label>Logo address (optional)
			<span class="row"><input name="logo" type="url" placeholder="http://…/logo.png"><img class="logoPreview" alt=""></span>
		</label>
		<label>Description (optional) <input name="desc" maxlength="200"></label>
		<label>Folder on the radio (optional) <input name="category" list="stationFolders" maxlength="100" placeholder="Leave empty for the top level"></label>
		<datalist id="stationFolders"></datalist>
		<fieldset>
			<legend>Advanced</legend>
			<label class="check"><input type="checkbox" name="proxy"> Play through phobos (for https streams the radio cannot open)</label>
			<label class="check"><input type="checkbox" name="nc"> This is a Nextcloud share of music files, not a stream</label>
		</fieldset>
		<div class="dlgbtns">
			<button type="button" class="quiet cancelBtn">Cancel</button>
			<button value="ok" class="primary">OK</button>
		</div>
	</form>
</dialog>

<dialog id="podcastDlg">
	<form method="dialog" id="podcastForm" class="dlg">
		<h2 id="podcastDlgTitle">Podcast</h2>
		<label>Name <input name="name" required maxlength="200"></label>
		<label>Feed address (RSS) <input name="url" type="url" required placeholder="https://…/feed.xml"></label>
		<label>Folder on the radio (optional) <input name="category" list="podcastFolders" maxlength="100"></label>
		<datalist id="podcastFolders"></datalist>
		<fieldset>
			<legend>Advanced</legend>
			<label class="check"><input type="checkbox" name="nc"> This is a Nextcloud share, not an RSS feed</label>
			<label class="check"><input type="checkbox" name="finalurl"> Follow redirects before giving the episode to the radio</label>
			<label class="check"><input type="checkbox" name="proxy"> Play through phobos</label>
		</fieldset>
		<div class="dlgbtns">
			<button type="button" class="quiet cancelBtn">Cancel</button>
			<button value="ok" class="primary">OK</button>
		</div>
	</form>
</dialog>

<dialog id="confirmDlg">
	<form method="dialog" class="dlg">
		<p id="confirmText"></p>
		<div class="dlgbtns">
			<button type="button" class="quiet cancelBtn">Cancel</button>
			<button value="ok" class="danger">Delete</button>
		</div>
	</form>
</dialog>

<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
