# Radio-API

> Silicon Frontier, Frontier Silicon, or Frontier Nuvola (Smart) Internet Radio alternative API

see https://github.com/kimbtech/WiFi-RadioAPI for information about the (XML) API used by the radios  
see https://hub.docker.com/r/kimbtechnologies/radio_api for the Docker Image

## About
This is an alternative API for Frontier Nuvola (Frontier Silicon) internet radios, it can be placed on a server and will host the list of internet radio stations, podcasts etc. which then can be found in the radio's menu.

The main idea is to redirect the HTTP request of the radio to another server, where own stations and podcasts can be added.
This redirect is possible by manipulating the DNS queries.

> This *Radio-API* uses the [RadioBrowser](https://www.radio-browser.info/) to provide a list of radio stations.
> In addition, it allows each user to define their *own list of radio stations and podcasts*.
> Audio streams from Nextcloud shares are supported, too. 

&rarr; [Have a look at **screenshots**](./screenshots/Readme.md)

## This fork (dylan7474/Radio-API)
A fork of [KIMB-technologies/Radio-API](https://github.com/KIMB-technologies/Radio-API) for a home
network, with extra features on top of the upstream code. Most changes live in new files
(`php/app/`, `relay.php`, `music.php`, new classes), which keeps it easy to merge from upstream.
Tested with a Majority Fitzwilliam 2 (JSON API) and a Roberts radio (older XML API).

### What it adds
- **A new web page at `/app/`** (the classic `/gui/` still works).
	- On a LAN it **lists the radios to choose from**, so there's no GUI-Code to type (the code is still available as a fallback).
	- Stations in folders, logos, an in-browser **Play/Test** button (playlists are resolved on the server), and an explicit Save with Undo.
	- **Find** tab: Radio-Browser search (name, tag, country, order; broken stations hidden) and **podcast search** (iTunes Search API).
	- The radios show the lists in A-Z order, folders first, with numbers sorted naturally (`80s` before `1940s`).
- **Stable preset IDs.** Every station keeps its own id (`sid`), so deleting or reordering stations no longer moves the presets stored on the radio.
- **Favourites and Recently played** on the radio's main menu. ★ a station on the page to add it to Favourites. Recently played holds the last 20 real plays per radio (`classes/Recent.php`).
- **Convert on the server** (`relay.php`, `classes/Relay.php`).
	- Streams a radio can't decode (HLS `.m3u8`, WAV) are converted to MP3 128k with ffmpeg, only while a radio is listening.
	- It's switched on automatically for HLS addresses and for Radio-Browser HLS stations, and can be ticked by hand per station.
	- It sends the now-playing text (ICY `StreamTitle`).
- **Logos.**
	- A neutral stand-in icon (`utils/art/station.svg`) instead of the upstream logo.
	- A logo finder (Radio-Browser, exact name match).
	- **Upload a picture** as a station's logo, with an optional **background** (white, black or any colour) for logos that are hard to see, such as white on clear. It works on uploads and on logos phobos already stores. The picture is made in the browser (at most 256 px) and kept as `media/logos/up-<sha1>.png`.
	- Every logo is stored as a local PNG in `media/logos/` (at most 256 px), so the radios never depend on the station's website.
	- JSON radios get logos in the lists too.
- **Music menu** (`music.php`, `classes/Music.php`). A music library mounted read-only at `/music` appears on the radio as *Music*:
	- artist / album / track browsing
	- **Play all** and **Shuffle** for any folder, plus **Shuffle all music**
	- each track converted to one MP3 192k stream at playing speed, with the track name as the now-playing text
	- album art from cover files or embedded pictures, and for the rest **covers from MusicBrainz / the Cover Art Archive**: `docker exec --user www-data radio_api php /music-covers.php` (`--dry-run`, `--retry`, `--limit N`). It finds the exact CD by its disc ID tag (`MUSICBRAINZ_DISCID`), else searches by album and artist (only matches of 90% or more), and stores 250 px covers in `media/music-art/`.
- **Music tab on the page.** Browse the music library with covers (artist folders show their first album's cover), and play tracks, *Play all* or *Shuffle* in the browser.
	- Per album: **Change / Add a cover…** (a picture from this computer or phone, at most 256 px, kept as `media/music-art/<id>.png`) and **Remove cover** for a wrong match (the cover job then never fetches one for it again: `.removed`).
	- **Albums without a cover** lists the albums still missing one (the CDs of a set as one album; a set's cover is used for each CD).
	- Cover addresses carry the cover's time (`&v=`), so a radio shows a changed cover, not a cached old one.
- **Now playing** on the page: a box under the header shows each radio's "▶ Playing now" for streams through phobos (music and converted stations, with the track and album; `classes/NowPlaying.php`, renewed every 30 s, removed when the radio leaves), otherwise the station it last started (internet stations play straight from the station, so phobos can't tell whether they are still on). Refreshed every 15 s while the page is open.
- **Podcast pictures** on the page and the radios: from the iTunes search when added there, else the feed's `<itunes:image>` / `<image>`, else Apple's directory artwork for the same feed. Looked up once per podcast on Save, kept as a local file like the station logos.
- **Radios can share one list** (`classes/ListShare.php`, `data/list_links.json`). In the page's *This radio* dialog (press the radio's name), choose "The same list as …": both radios then show and edit the same stations, podcasts and Favourites. Station ids stay the same, so presets keep working. Recently played stays per radio. The follower's own list is kept; going back to "Its own list" gives it a copy of the shared list as it is then.
- **Nightly station check** (`classes/StationCheck.php`, `utils/station-check.php`). crond in the container checks every station at 02:00 (UTC); results are in `data/station_health.json` and the last run in `data/station-check.log`.
	- After 2 failed nights in a row a station is marked "✗ not working" on the page. If Radio-Browser has a working station with exactly the same name at another address, the page offers "Use the new address".
	- Addresses on the home network are never checked, because a probe would retune an SDR receiver.
	- Run it by hand: `docker exec --user www-data radio_api php /station-check.php`.
- **An empty list no longer breaks a JSON radio.** It shows a hint line instead (an empty list made the Majority report "network error").

### Running it
Build the image from this repository. The upstream Docker Hub image doesn't include these features.
```sh
docker build -f utils/Dockerfile -t radio-api-dj .
```
Then use `docker-compose.yml` with `image: radio-api-dj` and these settings:
- `CONF_USE_JSON_CACHE=true` (no Redis needed)
- add a volume for the Music menu: `- "/path/to/music:/music:ro"`

The image is built on a **pinned** `kimbtechnologies/php_nginx` tag. Change it on purpose, because `latest` has broken the build before.

### Deploying (phobos) and testing
- `deploy/deploy-phobos.sh` builds `origin/master` on the server and switches `~/radio-api/compose.yaml` to the new image. It then checks:
	- the health check and the classic GUI
	- that the nightly jobs are scheduled
	- `deploy/smoke-test.sh`, which covers every known radio's XML and JSON menus, the page and its API, Now playing, the Music tab, a logo upload, `relay.php`, the Music menu (4 s of real playback) and that ffmpeg stops when the listener leaves.

  If any check fails, it switches back to the previous image. It keeps only the current and the previous image.
- Run the smoke test on its own at any time on the server: `~/src/Radio-API/deploy/smoke-test.sh`. It is read-only for the radios: it never sends `&play` and never starts a converted station.
- Never push tags to this fork: upstream's `docker-build.yml` would try to publish them.

## Supported Devices
Radio-API supports the XML-based API used by older Radios (roughly the ones supporting the UNDOK app) **and** the newer JSON-based API used by newer Radios (roughly the ones using the OKTIV app).

Radios used for testing during development:
- Hama DIR3100MS (older, XML API)
- Majority Touro (newer, JSON API)

## Usage
- First [set up](./Setup.md) *Radio-API* and change the DNS resolver of the radio (e.g., as described there).
- Afterwards start the radio and open "Internet Radio" (or "Podcasts").
- The *Radio-API* should provide a list of:
	- **Podcast**
		- This is the user defined list of podcasts.
		- The list available of podcasts can be changed using the GUI.
		- The list of episodes for each podcast is cached for `CONF_CACHE_EXPIRE` seconds.
		- The URL of a podcast can be an Atom RSS link or a link to a Nextcloud share.
		- Nextcloud share:
			- The system can fetch and stream audiofiles from Nextcloud shares.
			- The link of the share needs a to look like `mycloud.example.com/s/<token>/`. 
			- All files in the shared folder will be shown in the radio as episode.
			- The share must not have a password.
			- There is no support for sub folders in shares, only the files in the share are shown.
		- Episodes get a `*` in front of their name if they have not yet been listened to.
	- **Radio**
		- This is the user defined list of internet radio stations.
		- The list of stations can be changed in the GUI.
		- A radio station should be an URL to some stream like MP3, M3U etc.
	- **Radio-Browser**
		- This allows to browse the radio stations in [RadioBrowser](https://www.radio-browser.info/).
		- Stations can be browsed by country (and state), language, tags, clicks and votes.
		- The stations recently opened via the radio are shown in *My Last*.
		- *Using Radio-Browser will send http requests and such usage data to the [RadioBrowser API](https://api.radio-browser.info/)!*
		- In the GUI it is also possible to search for stations in RadioBrowser and add them to the user defined stations. Stations from *My Last* are shown in the GUI, too.
	- **Stream** (if enabled in `docker-compose.yml`, see [Own Streams](./Setup.md#own-streams))
		- This is a list of server specific streams.
		- The list is fetched from a custom url, provided in the Docker Container setup.
		- The Streams are shared across all radios using the same *Radio-API* setup.
	- **GUI-Code**
		- This code is like a password to access the GUI for this radio and edit the radio stations and streams.
- Some newer radios may have *Internet Radio* and *Podcasts* as different modes, both modes provide the same content with Radio-API and will show podcasts and radio stations together.
- Webinterface (GUI):
	- The GUI can be opened via a webbrowser at `radio.example.com/gui/`.
	- The GUI provides the editable lists of radio stations and podcasts.
	- A preview of the items shown by the radio is provided by the GUI, too.
		- The preview is also shown when opening `radio.example.com` in an browser and this browser has already logged into the GUI.
		- The `*` to mark new episodes can be toggled by the &check;/ &cross; in the preview.
	- Additional information texts describe the options for radio stations and podcasts.

### Notes
- This is a private project and has no connections to Frontier Nuvola/ Frontier Silicon.
- There is a limit of 1000 items per list: 1000 radio stations, 1000 streams, 1000 podcasts.
	Items from RadioBrowser do not count against this limit.
	Adding more than 200 user defined radio stations or podcasts is not recommended.
- Nobody should host a public DNS resolver resolving wrong IPs. Some type of access control is recommended.

## Setup
See [Setup.md](./Setup.md) including hints regarding updates, backups (i.e., Im- & Export), and troubleshooting.
