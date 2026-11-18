/*
 * Radio-API (fork: dylan7474/Radio-API) - new web page.
 * Plain JS, no libraries. Talks to ./api.php.
 * released under the terms of GNU Public License Version 3
 */
"use strict";

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

const S = {
	radios: [], podcasts: [],
	saved: { radios: "[]", podcasts: "[]" },
	moved: false,            // order changed or something deleted: presets may shift
	countriesLoaded: false,
	playingKey: null,
	afterLogin: null
};

/* ---------- helpers ---------- */

function el(tag, attrs = {}, ...kids) {
	const e = document.createElement(tag);
	for (const [k, v] of Object.entries(attrs)) {
		if (v === null || v === undefined || v === false) continue;
		if (k === "class") e.className = v;
		else if (k.startsWith("on")) e.addEventListener(k.slice(2), v);
		else if (k === "text") e.textContent = v;
		else e.setAttribute(k, v === true ? "" : v);
	}
	for (const k of kids.flat()) if (k !== null && k !== undefined && k !== false) e.append(k);
	return e;
}

let toastTimer;
function toast(msg) {
	const t = $("#toast");
	t.textContent = msg;
	t.classList.add("show");
	clearTimeout(toastTimer);
	toastTimer = setTimeout(() => t.classList.remove("show"), 2600);
}

async function api(action, { body, params } = {}, retried = false) {
	const q = new URLSearchParams({ a: action, ...(params || {}) });
	const res = await fetch("api.php?" + q, {
		method: body ? "POST" : "GET",
		headers: body ? { "Content-Type": "application/json" } : {},
		body: body ? JSON.stringify(body) : undefined,
		credentials: "same-origin"
	});
	let data = {};
	try { data = await res.json(); } catch (e) { /* not json */ }
	if (res.status === 401) {
		// the login timed out (or phobos restarted): pick the same radio again, quietly, and retry once
		if (!retried && S.radioId !== undefined && await pickRadio(S.radioId)) return api(action, { body, params }, true);
		showLogin(S.loadedOnce ? "Choose the radio again; nothing you changed is lost." : "");
		throw new Error("login");
	}
	if (!res.ok || data.ok === false) throw new Error(data.error || ("Error " + res.status));
	return data;
}

function logoBox(url, name) {
	const box = el("div", { class: "logo", "aria-hidden": "true" });
	const initial = (name || "?").trim().charAt(0).toUpperCase() || "?";
	if (url && /^https?:/i.test(url)) {
		const img = el("img", { src: url, alt: "", loading: "lazy", referrerpolicy: "no-referrer" });
		img.addEventListener("error", () => { box.textContent = initial; });
		box.append(img);
	} else box.textContent = initial;
	return box;
}

const isDirty = (kind) => JSON.stringify(S[kind]) !== S.saved[kind];

/* ---------- choosing the radio (no code: the page is LAN-only) ---------- */

const radioLabel = (r) => r.name || ("Radio " + r.id);

async function pickRadio(id) {
	try {
		const res = await fetch("api.php?a=pick", { method: "POST", credentials: "same-origin",
			headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id }) });
		return res.ok;
	} catch (e) { return false; }
}

async function choose(r) {
	if (!(await pickRadio(r.id))) { $("#pickMsg").textContent = "✗ Could not open that radio"; return; }
	S.radioId = r.id;
	$("#login").hidden = true;
	if (S.loadedOnce) $("#app").hidden = false; else await load();
}

// one radio: go straight in; several: show the list
async function showLogin(msg = "") {
	$("#pickMsg").textContent = msg;
	let radios = [];
	try {
		const res = await fetch("api.php?a=who", { credentials: "same-origin" });
		radios = (await res.json()).radios || [];
	} catch (e) { $("#pickMsg").textContent = "✗ phobos did not answer"; }
	S.radioCount = radios.length;
	if (radios.length === 1 && !msg) return choose(radios[0]);
	$("#app").hidden = !S.loadedOnce;
	$("#login").hidden = false;
	$("#pickList").replaceChildren(...radios.map(r => el("li", {},
		el("button", { onclick: () => choose(r) },
			el("b", { text: radioLabel(r) }),
			el("small", { text: `${r.stations} station${r.stations === 1 ? "" : "s"} · code ${r.code}` })))));
	if (!radios.length) $("#pickMsg").textContent = "No radio has connected yet. Turn the radio on and open Internet Radio, then reload this page.";
}

$("#loginForm").addEventListener("submit", async (e) => {
	e.preventDefault();
	const code = $("#loginCode").value.trim();
	$("#loginMsg").textContent = "Checking…";
	try {
		await api("login", { body: { code } }, true);
		$("#login").hidden = true;
		$("#loginCode").value = "";
		if (S.loadedOnce) $("#app").hidden = false; else await load();
	} catch (err) {
		$("#loginMsg").textContent = "✗ That code was not accepted. It starts with Z and has 5 characters (capitals matter).";
	}
});

$("#logout").addEventListener("click", async () => {
	if (isDirty("radios") || isDirty("podcasts")) {
		if (!(await confirmBox("You have unsaved changes. Switch radio anyway and lose them?", "Switch"))) return;
	}
	try { await api("logout", {}, true); } catch (e) { /* ignore */ }
	S.loadedOnce = false; S.radioId = undefined;
	$("#app").hidden = true;
	showLogin(" ");
});

$("#radioName").addEventListener("click", () => {
	const dlg = $("#renameDlg"), f = $("#renameForm");
	f.name.value = S.radioName || "";
	$("#renameCode").textContent = S.radioCode || "";
	dlg.returnValue = "";
	dlg.showModal();
	dlg.onclose = async () => {
		if (dlg.returnValue !== "ok") return;
		try {
			const d = await api("rename", { body: { name: f.name.value } });
			S.radioName = d.name;
			showRadioName();
		} catch (err) { if (err.message !== "login") toast("✗ " + err.message); }
	};
});

function showRadioName() {
	$("#radioName b").textContent = S.radioName || ("Radio " + S.radioId);
}

/* ---------- loading + saving ---------- */

async function load() {
	const d = await api("state");
	S.radios = d.radios.map(r => ({ ...r, category: r.category || "" }));
	S.podcasts = d.podcasts.map(p => ({ ...p, category: p.category || "" }));
	S.saved.radios = JSON.stringify(S.radios);
	S.saved.podcasts = JSON.stringify(S.podcasts);
	S.moved = false;
	S.loadedOnce = true;
	S.radioId = d.id; S.radioCode = d.code; S.radioName = d.name;
	showRadioName();
	if (S.radioCount === undefined) {
		try { S.radioCount = ((await (await fetch("api.php?a=who", { credentials: "same-origin" })).json()).radios || []).length; } catch (e) { S.radioCount = 1; }
	}
	$("#logout").hidden = S.radioCount < 2;
	$("#login").hidden = true;
	$("#app").hidden = false;
	renderAll();
}

function renderAll() {
	renderStations();
	renderPodcasts();
	updateSavebar();
}

function updateSavebar() {
	const r = isDirty("radios"), p = isDirty("podcasts");
	$("#savebar").hidden = !(r || p);
	$("#saveText").textContent = "Unsaved changes to " + [r && "stations", p && "podcasts"].filter(Boolean).join(" and ");
	$("#presetHint").hidden = !S.moved;
}

$("#save").addEventListener("click", async () => {
	const btn = $("#save");
	btn.disabled = true;
	btn.textContent = "Saving…";
	try {
		if (isDirty("radios")) {
			const d = await api("radios", { body: { items: S.radios } });
			S.radios = d.radios;
			S.saved.radios = JSON.stringify(S.radios);
		}
		if (isDirty("podcasts")) {
			const d = await api("podcasts", { body: { items: S.podcasts } });
			S.podcasts = d.podcasts;
			S.saved.podcasts = JSON.stringify(S.podcasts);
		}
		S.moved = false;
		renderAll();
		toast("✓ Saved. The radio shows it next time you open the list.");
	} catch (err) {
		if (err.message !== "login") toast("✗ Not saved: " + err.message);
	} finally {
		btn.disabled = false;
		btn.textContent = "Save to the radio";
	}
});

$("#undo").addEventListener("click", () => {
	S.radios = JSON.parse(S.saved.radios);
	S.podcasts = JSON.parse(S.saved.podcasts);
	S.moved = false;
	renderAll();
	toast("Changes undone");
});

window.addEventListener("beforeunload", (e) => {
	if (isDirty("radios") || isDirty("podcasts")) { e.preventDefault(); e.returnValue = ""; }
});

/* ---------- tabs ---------- */

$$(".tabs button").forEach(b => b.addEventListener("click", () => {
	$$(".tabs button").forEach(x => x.setAttribute("aria-selected", x === b ? "true" : "false"));
	$$(".tab").forEach(t => t.hidden = t.id !== "tab-" + b.dataset.tab);
	if (b.dataset.tab === "find") openFind();
}));

/* ---------- grouping (top level first, then folders in order of first use) ---------- */

function groups(list) {
	const order = [""];
	for (const it of list) if (!order.includes(it.category)) order.push(it.category);
	return order
		.map(cat => ({ cat, idx: list.map((it, i) => it.category === cat ? i : -1).filter(i => i >= 0) }))
		.filter(g => g.idx.length);
}

function folderNames(list) {
	return [...new Set(list.map(i => i.category).filter(Boolean))].sort((a, b) => a.localeCompare(b));
}

/* ---------- stations ---------- */

function renderStations() {
	const list = $("#stationList");
	const filter = $("#filterStations").value.trim().toLowerCase();
	const savedByUrl = new Set(JSON.parse(S.saved.radios).map(r => r.name + "|" + r.url + "|" + r.category));
	list.replaceChildren();
	for (const g of groups(S.radios)) {
		const rows = g.idx.filter(i => !filter || (S.radios[i].name + " " + S.radios[i].desc).toLowerCase().includes(filter));
		if (!rows.length) continue;
		if (g.cat) list.append(el("li", { class: "folder", text: g.cat }));
		rows.forEach((i, pos) => {
			const r = S.radios[i];
			const key = "r" + i;
			const changed = !savedByUrl.has(r.name + "|" + r.url + "|" + r.category);
			const li = el("li", { class: "item" + (changed ? " changed" : ""), "data-i": i },
				el("span", { class: "handle", title: "Drag to move", "aria-hidden": "true", text: "⠿" }),
				logoBox(r.logo, r.name),
				el("div", { class: "info" },
					el("b", { text: r.name }),
					el("small", { text: r.desc || r.url }),
					el("div", { class: "tags" },
						r.type === "nc" ? el("span", { class: "tag", text: "Nextcloud files" }) : null,
						r.proxy ? el("span", { class: "tag", text: "through phobos" }) : null,
						changed ? el("span", { class: "tag state warn", text: "not saved" }) : null)),
				el("div", { class: "acts" },
					r.type === "nc" ? null : el("button", {
						class: S.playingKey === key ? "playing" : "", title: "Listen here", "aria-label": "Listen to " + r.name,
						text: S.playingKey === key ? "■" : "▶", onclick: () => togglePlay(key, r)
					}),
					el("button", { text: "Edit", onclick: () => editStation(i) }),
					el("button", { title: "Move up", "aria-label": "Move up", text: "↑", disabled: pos === 0 && !filter ? true : null, onclick: () => moveWithin("radios", g.idx, i, -1) }),
					el("button", { title: "Move down", "aria-label": "Move down", text: "↓", disabled: pos === rows.length - 1 && !filter ? true : null, onclick: () => moveWithin("radios", g.idx, i, 1) }),
					el("button", { title: "Delete", "aria-label": "Delete " + r.name, text: "✕", onclick: () => removeItem("radios", i) })));
			enableDrag(li, "radios");
			list.append(li);
		});
	}
	$("#stationsEmpty").hidden = S.radios.length > 0;
	$("#stationFolders").replaceChildren(...folderNames(S.radios).map(n => el("option", { value: n })));
	if (S.radios.length >= 999) toast("The radio can only show 999 stations");
}

$("#filterStations").addEventListener("input", renderStations);

function moveWithin(kind, groupIdx, i, dir) {
	const pos = groupIdx.indexOf(i);
	const j = groupIdx[pos + dir];
	if (j === undefined) return;
	const list = S[kind];
	[list[i], list[j]] = [list[j], list[i]];
	renderAll();
}

async function removeItem(kind, i) {
	const it = S[kind][i];
	if (!(await confirmBox(`Delete "${it.name}"? (Nothing is lost until you press Save.)`, "Delete"))) return;
	S[kind].splice(i, 1);
	if (kind === "radios") S.moved = true;
	renderAll();
}

/* drag and drop with pointer events: works with a mouse and on a phone */
function enableDrag(li, kind) {
	const handle = $(".handle", li);
	handle.addEventListener("pointerdown", (e) => {
		e.preventDefault();
		const from = +li.dataset.i;
		let target = null;
		li.classList.add("dragging");
		handle.setPointerCapture(e.pointerId);
		const move = (ev) => {
			const over = document.elementFromPoint(ev.clientX, ev.clientY)?.closest(".item");
			$$(".dropbefore").forEach(x => x.classList.remove("dropbefore"));
			target = over && over !== li && over.parentElement === li.parentElement ? over : null;
			if (target) target.classList.add("dropbefore");
			if (ev.clientY < 80) window.scrollBy(0, -12);
			else if (ev.clientY > innerHeight - 80) window.scrollBy(0, 12);
		};
		const up = () => {
			handle.removeEventListener("pointermove", move);
			li.classList.remove("dragging");
			$$(".dropbefore").forEach(x => x.classList.remove("dropbefore"));
			if (target) {
				const to = +target.dataset.i;
				const list = S[kind];
				const [it] = list.splice(from, 1);
				it.category = list[to > from ? to - 1 : to].category;   // dropping into a folder moves it there
				list.splice(to > from ? to - 1 : to, 0, it);
				renderAll();
			}
		};
		handle.addEventListener("pointermove", move);
		handle.addEventListener("pointerup", up, { once: true });
		handle.addEventListener("pointercancel", up, { once: true });
	});
}

/* edit / add dialog */
function editStation(i, preset = null) {
	const dlg = $("#stationDlg"), f = $("#stationForm");
	const r = i === null ? (preset || { name: "", url: "", logo: "", desc: "", proxy: false, type: "radio", category: "" }) : S.radios[i];
	$("#stationDlgTitle").textContent = i === null ? "Add a station" : "Edit station";
	f.name.value = r.name; f.url.value = r.url; f.logo.value = r.logo || ""; f.desc.value = r.desc || "";
	f.category.value = r.category || ""; f.proxy.checked = !!r.proxy; f.nc.checked = r.type === "nc";
	$(".testMsg", f).textContent = ""; $(".testMsg", f).className = "msg testMsg";
	$(".logoPreview", f).src = /^https?:/i.test(r.logo || "") ? r.logo : "";
	dlg.returnValue = "";
	dlg.showModal();
	dlg.onclose = () => {
		if (dlg.returnValue !== "ok") return;
		const item = {
			name: f.name.value.trim(), url: f.url.value.trim(), logo: f.logo.value.trim(), desc: f.desc.value.trim(),
			proxy: f.proxy.checked, type: f.nc.checked ? "nc" : "radio", category: f.category.value.trim()
		};
		if (i === null) S.radios.push(item); else S.radios[i] = item;
		renderAll();
	};
}
$("#addStation").addEventListener("click", () => editStation(null));
$("#stationForm").logo.addEventListener("input", (e) => {
	$(".logoPreview", $("#stationForm")).src = /^https?:\/\/.+/i.test(e.target.value) ? e.target.value : "";
});
$(".testBtn", $("#stationForm")).addEventListener("click", async () => {
	const f = $("#stationForm"), m = $(".testMsg", f);
	if (!f.url.value.trim()) { m.textContent = "Type a stream address first"; return; }
	m.className = "msg testMsg"; m.textContent = "Testing from phobos…";
	try {
		const d = await api("probe", { params: { url: f.url.value.trim() } });
		m.className = "msg testMsg " + (d.ok ? "ok" : "bad");
		m.textContent = (d.ok ? "✓ " : "✗ ") + d.note + (d.ok ? ". Press OK, then ▶ in the list to listen." : "");
	} catch (err) { m.className = "msg testMsg bad"; m.textContent = "✗ " + err.message; }
});

/* ---------- podcasts ---------- */

function renderPodcasts() {
	const list = $("#podcastList");
	list.replaceChildren();
	for (const g of groups(S.podcasts)) {
		if (g.cat) list.append(el("li", { class: "folder", text: g.cat }));
		g.idx.forEach((i, pos) => {
			const p = S.podcasts[i];
			const li = el("li", { class: "item", "data-i": i },
				el("span", { class: "handle", title: "Drag to move", "aria-hidden": "true", text: "⠿" }),
				logoBox("", p.name),
				el("div", { class: "info" },
					el("b", { text: p.name }),
					el("small", { text: p.url }),
					el("div", { class: "tags" },
						el("span", { class: "tag", text: p.type === "nc" ? "Nextcloud" : "RSS" }),
						p.finalurl ? el("span", { class: "tag", text: "follows redirects" }) : null,
						p.proxy ? el("span", { class: "tag", text: "through phobos" }) : null)),
				el("div", { class: "acts" },
					el("button", { text: "Edit", onclick: () => editPodcast(i) }),
					el("button", { title: "Move up", "aria-label": "Move up", text: "↑", disabled: pos === 0 ? true : null, onclick: () => moveWithin("podcasts", g.idx, i, -1) }),
					el("button", { title: "Move down", "aria-label": "Move down", text: "↓", disabled: pos === g.idx.length - 1 ? true : null, onclick: () => moveWithin("podcasts", g.idx, i, 1) }),
					el("button", { title: "Delete", "aria-label": "Delete " + p.name, text: "✕", onclick: () => removeItem("podcasts", i) })));
			enableDrag(li, "podcasts");
			list.append(li);
		});
	}
	$("#podcastsEmpty").hidden = S.podcasts.length > 0;
	$("#podcastFolders").replaceChildren(...folderNames(S.podcasts).map(n => el("option", { value: n })));
}

function editPodcast(i) {
	const dlg = $("#podcastDlg"), f = $("#podcastForm");
	const p = i === null ? { name: "", url: "", type: "rss", finalurl: false, proxy: false, category: "" } : S.podcasts[i];
	$("#podcastDlgTitle").textContent = i === null ? "Add a podcast" : "Edit podcast";
	f.name.value = p.name; f.url.value = p.url; f.category.value = p.category || "";
	f.nc.checked = p.type === "nc"; f.finalurl.checked = !!p.finalurl; f.proxy.checked = !!p.proxy;
	dlg.returnValue = "";
	dlg.showModal();
	dlg.onclose = () => {
		if (dlg.returnValue !== "ok") return;
		const item = {
			name: f.name.value.trim(), url: f.url.value.trim(), type: f.nc.checked ? "nc" : "rss",
			finalurl: f.finalurl.checked, proxy: f.proxy.checked, category: f.category.value.trim()
		};
		if (i === null) S.podcasts.push(item); else S.podcasts[i] = item;
		renderAll();
	};
}
$("#addPodcast").addEventListener("click", () => editPodcast(null));

/* podcast search: Apple's iTunes directory, as Sleep Radio does */
$("#podSearch").addEventListener("submit", async (e) => {
	e.preventDefault();
	const q = $("#pq").value.trim(), st = $("#podStatus");
	if (!q) return;
	st.className = "msg"; st.textContent = "Searching…";
	try {
		const d = await api("podsearch", { params: { q } });
		st.textContent = d.results.length ? `${d.results.length} podcasts` : "Nothing found. Try other words.";
		$("#podResults").replaceChildren(...d.results.map(p => {
			const have = S.podcasts.some(x => x.url === p.url);
			const btn = el("button", { class: have ? "" : "primary", text: have ? "✓ In your list" : "+ Add", disabled: have ? true : null,
				onclick: () => {
					S.podcasts.push({ name: p.name, url: p.url, type: "rss", finalurl: false, proxy: false, category: "" });
					btn.textContent = "✓ Added"; btn.disabled = true; btn.className = "";
					renderAll();
					toast(`Added "${p.name}". Press Save to send it to the radio.`);
				} });
			return el("li", { class: "item" },
				logoBox(p.logo, p.name),
				el("div", { class: "info" },
					el("b", { text: p.name }),
					el("small", { text: [p.author, p.genre].filter(Boolean).join(" · ") }),
					p.episodes ? el("div", { class: "tags" }, el("span", { class: "tag", text: p.episodes + " episodes" })) : null),
				el("div", { class: "acts" }, btn));
		}));
	} catch (err) {
		if (err.message !== "login") { st.className = "msg bad"; st.textContent = "✗ " + err.message; }
	}
});

// Cancel buttons are type=button so Enter in a dialog means OK, not Cancel
$$(".cancelBtn").forEach(b => b.addEventListener("click", () => b.closest("dialog").close("cancel")));

/* ---------- country names: Radio-Browser uses long official names ---------- */
const regionNames = (() => { try { return new Intl.DisplayNames(["en-GB"], { type: "region" }); } catch (e) { return null; } })();
function countryName(code, fallback = "") {
	try { return (code && regionNames && regionNames.of(code.toUpperCase())) || fallback; } catch (e) { return fallback; }
}
function stationDesc(s) {
	return [countryName(s.country), s.desc].filter(Boolean).join(", ");
}

/* ---------- confirm (a <dialog>, not window.confirm) ---------- */

function confirmBox(text, okLabel = "Delete") {
	return new Promise(resolve => {
		const dlg = $("#confirmDlg");
		$("#confirmText").textContent = text;
		$("button[value=ok]", dlg).textContent = okLabel;
		dlg.returnValue = "";
		dlg.onclose = () => resolve(dlg.returnValue === "ok");
		dlg.showModal();
	});
}

/* ---------- find stations ---------- */

async function openFind() {
	if (S.countriesLoaded) return;
	S.countriesLoaded = true;
	try {
		const d = await api("countries");
		const sel = $("#sCountry");
		d.countries
			.map(([code, name, n]) => [code, countryName(code, name), n])
			.sort((a, b) => a[1].localeCompare(b[1]))
			.forEach(([code, name, n]) => sel.append(el("option", { value: code, text: `${name} (${n})` })));
		if (!$("#results").children.length) { sel.value = "GB"; search(); }
	} catch (err) { S.countriesLoaded = false; }
}

$("#searchForm").addEventListener("submit", (e) => { e.preventDefault(); search(); });
$("#sCountry").addEventListener("change", search);
$("#sOrder").addEventListener("change", search);

let searchSeq = 0;
async function search() {
	const seq = ++searchSeq;
	const st = $("#searchStatus");
	st.className = "msg"; st.textContent = "Searching…";
	try {
		const d = await api("search", { params: {
			name: $("#sName").value.trim(), tag: $("#sTag").value.trim(),
			countrycode: $("#sCountry").value, order: $("#sOrder").value
		}});
		if (seq !== searchSeq) return;
		st.textContent = d.results.length ? `${d.results.length} stations` : "Nothing found. Try fewer words, or Any country.";
		S.results = d.results;
		renderResults($("#results"), d.results, "s");
	} catch (err) {
		if (seq === searchSeq && err.message !== "login") { st.className = "msg bad"; st.textContent = "✗ Radio-Browser did not answer: " + err.message; }
	}
}

function inList(url) { return S.radios.some(r => r.url === url); }

function renderResults(list, results, prefix) {
	list.replaceChildren(...results.map((s, n) => {
		const key = prefix + n;
		const meta = [s.codec, s.bitrate ? s.bitrate + " kbps" : "", s.votes ? s.votes + " votes" : ""].filter(Boolean).join(" · ");
		const added = inList(s.url);
		const addBtn = el("button", {
			class: added ? "" : "primary", text: added ? "✓ In your list" : "+ Add", disabled: added ? true : null,
			onclick: () => {
				S.radios.push({ name: s.name, url: s.url, logo: s.logo || "", desc: stationDesc(s), proxy: false, type: "radio", category: "" });
				addBtn.textContent = "✓ Added"; addBtn.disabled = true; addBtn.className = "";
				renderAll();
				toast(`Added "${s.name}". Press Save to send it to the radio.`);
			}
		});
		return el("li", { class: "item" },
			logoBox(s.logo, s.name),
			el("div", { class: "info" },
				el("b", { text: s.name }),
				el("small", { text: stationDesc(s) || s.url }),
				meta ? el("div", { class: "tags" }, el("span", { class: "tag", text: meta })) : null),
			el("div", { class: "acts" },
				el("button", { "aria-label": "Listen to " + s.name, title: "Listen here", text: S.playingKey === key ? "■" : "▶",
					class: S.playingKey === key ? "playing" : "", onclick: () => togglePlay(key, s) }),
				el("button", { text: "Edit + add", title: "Change the name or folder before adding", onclick: () => editStation(null, { name: s.name, url: s.url, logo: s.logo || "", desc: stationDesc(s), proxy: false, type: "radio", category: "" }) }),
				addBtn));
	}));
}

$("#lastBox").addEventListener("toggle", async (e) => {
	if (!e.target.open) return;
	try {
		const d = await api("last");
		if (!d.results.length) $("#lastList").replaceChildren(el("li", { class: "empty", text: "Nothing yet. Stations you play under Radio-Browser on the radio show up here." }));
		else { S.last = d.results; renderResults($("#lastList"), d.results, "l"); }
	} catch (err) { /* shown elsewhere */ }
});

/* ---------- player ---------- */

const audio = $("#audio");
audio.volume = 0.7;
$("#pVol").addEventListener("input", (e) => audio.volume = +e.target.value);
$("#pStop").addEventListener("click", stopPlay);

function stopPlay() {
	audio.pause();
	audio.removeAttribute("src");
	audio.load();
	S.playingKey = null;
	$("#player").hidden = true;
	refreshPlayButtons();
}

function refreshPlayButtons() {
	renderStations();
	if (S.results) renderResults($("#results"), S.results, "s");
	if (S.last) renderResults($("#lastList"), S.last, "l");
}

async function togglePlay(key, item, resolved = false) {
	if (S.playingKey === key) { stopPlay(); return; }
	S.playingKey = key;
	$("#player").hidden = false;
	$("#pName").textContent = item.name;
	$("#pNote").textContent = "Connecting…";
	$("#pLogo").src = /^https?:/i.test(item.logo || "") ? item.logo : "";
	refreshPlayButtons();
	let url = item.url;
	const tryPlay = async (u) => { audio.src = u; await audio.play(); };
	try {
		if (!resolved && /\.(m3u8?|pls|xspf)(\?|$)/i.test(url)) throw new Error("playlist");
		await tryPlay(url);
		$("#pNote").textContent = "▶ Playing in this browser (not on the radio)";
	} catch (e) {
		if (S.playingKey !== key) return;
		// the browser could not play it directly: ask phobos what it really is (playlists, redirects)
		try {
			$("#pNote").textContent = "Checking the stream…";
			const d = await api("probe", { params: { url: item.url } });
			if (S.playingKey !== key) return;
			if (!d.ok) throw new Error(d.note);
			await tryPlay(d.url);
			$("#pNote").textContent = "▶ Playing in this browser · " + d.note;
		} catch (err) {
			if (S.playingKey !== key) return;
			$("#pNote").textContent = "✗ Could not play here: " + (err.message === "login" ? "log in again" : err.message);
		}
	}
}
audio.addEventListener("error", () => { if (S.playingKey) $("#pNote").textContent = "✗ The stream stopped or cannot be played in a browser"; });

/* ---------- start ---------- */

load().catch(err => { if (err.message !== "login") showLogin("Could not load: " + err.message); });
