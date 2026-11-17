/**
 * Rasad dashboard client — no jQuery, no dependencies.
 * Reads window.rasadAdmin = {rest, nonce, defaults, strings} and renders
 * cards + SVG chart + tables from the cached REST endpoints.
 */
(function () {
	'use strict';

	var cfg = window.rasadAdmin || {};
	var REST = (cfg.rest || '').replace(/\/$/, '');
	var STR = cfg.strings || {};
	var state = {
		from: (cfg.defaults && cfg.defaults.from) || '',
		to: (cfg.defaults && cfg.defaults.to) || '',
		preset: '7d'
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	var nf = null;
	try {
		nf = new Intl.NumberFormat('fa-IR');
	} catch (e) {
		nf = null;
	}

	function num(n) {
		n = Number(n) || 0;
		return nf ? nf.format(n) : String(n);
	}

	function pct(value) {
		return num(Math.round(value * 1000) / 10) + '٪';
	}

	function dur(seconds) {
		seconds = Math.max(0, Math.round(Number(seconds) || 0));
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return num(m) + ':' + (s < 10 ? num(0) : '') + num(s);
	}

	function esc(text) {
		var div = document.createElement('div');
		div.textContent = text === null || text === undefined ? '' : String(text);
		return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function status(message, isError) {
		var el = qs('.rasad-status');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'rasad-status' + (isError ? ' is-error' : '');
	}

	function api(path, params) {
		var query = [];
		Object.keys(params || {}).forEach(function (key) {
			var value = params[key];
			if (value !== '' && value !== null && value !== undefined) {
				query.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
			}
		});
		var url = REST + '/' + path + (query.length ? '?' + query.join('&') : '');

		return fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' }
		}).then(function (res) {
			if (!res.ok) {
				throw new Error('HTTP ' + res.status);
			}
			return res.json();
		});
	}

	function rangeParams() {
		if (state.preset) {
			return { preset: state.preset };
		}
		return { from: state.from, to: state.to };
	}

	/* ------------------------------------------------------------------ */
	/* Renderers                                                           */
	/* ------------------------------------------------------------------ */

	function renderCards(overview) {
		var current = overview.current || {};
		var deltas = overview.deltas || {};
		var metrics = [
			{ key: 'views', fmt: num, lowerBetter: false },
			{ key: 'visitors', fmt: num, lowerBetter: false },
			{ key: 'sessions', fmt: num, lowerBetter: false },
			{ key: 'bounce_rate', fmt: pct, lowerBetter: true },
			{ key: 'avg_duration', fmt: dur, lowerBetter: false }
		];

		metrics.forEach(function (metric) {
			var card = qs('.rasad-card[data-metric="' + metric.key + '"]');
			if (!card) {
				return;
			}
			qs('.rasad-card-value', card).textContent = metric.fmt(current[metric.key]);

			var delta = deltas[metric.key];
			var deltaEl = qs('.rasad-card-delta', card);
			deltaEl.className = 'rasad-card-delta';

			if (delta === null || delta === undefined) {
				deltaEl.textContent = STR.prev || '';
				return;
			}

			var up = delta > 0;
			var down = delta < 0;
			var good = metric.lowerBetter ? down : up;
			var bad = metric.lowerBetter ? up : down;

			if (good) {
				deltaEl.className += ' is-up';
			} else if (bad) {
				deltaEl.className += ' is-down';
			}

			var arrow = up ? '▲ ' : (down ? '▼ ' : '');
			var sign = delta > 0 ? '+' : '';
			deltaEl.textContent = (arrow || '— ') + sign + num(delta) + '٪ ' + (STR.prev || '');
		});
	}

	function renderChart(series) {
		var host = qs('.rasad-chart');
		if (!host) {
			return;
		}

		var points = series.points || [];
		host.setAttribute('data-granularity', series.granularity || '');

		if (!points.length) {
			host.innerHTML = '<p class="rasad-empty">' + esc(STR.no_data || '') + '</p>';
			return;
		}

		var W = 1000;
		var H = 260;
		var padX = 46;
		var padTop = 16;
		var padBottom = 34;
		var plotW = W - padX - 12;
		var plotH = H - padTop - padBottom;

		var max = 1;
		points.forEach(function (p) {
			max = Math.max(max, p.views || 0, p.visitors || 0);
		});
		max = niceMax(max);

		function x(i) {
			if (points.length === 1) {
				return padX + plotW / 2;
			}
			return padX + (plotW * i) / (points.length - 1);
		}

		function y(v) {
			return padTop + plotH - (plotH * (Number(v) || 0)) / max;
		}

		function line(key) {
			return points.map(function (p, i) {
				return x(i).toFixed(1) + ',' + y(p[key]).toFixed(1);
			}).join(' ');
		}

		var svg = [];
		svg.push('<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(STR.views || '') + '">');

		// Horizontal grid + y labels (4 steps).
		for (var g = 0; g <= 4; g++) {
			var gy = padTop + (plotH * g) / 4;
			var gv = Math.round(max * (1 - g / 4));
			svg.push('<line class="rasad-grid-line" x1="' + padX + '" y1="' + gy + '" x2="' + (W - 12) + '" y2="' + gy + '" />');
			svg.push('<text class="rasad-axis-label" x="' + (padX - 6) + '" y="' + (gy + 4) + '" text-anchor="end">' + esc(num(gv)) + '</text>');
		}

		// Area under views.
		var area = line('views') + ' ' + (x(points.length - 1)).toFixed(1) + ',' + (padTop + plotH) + ' ' + x(0).toFixed(1) + ',' + (padTop + plotH);
		svg.push('<polygon class="rasad-area-views" points="' + area + '" />');
		svg.push('<polyline class="rasad-line-views" points="' + line('views') + '" />');
		svg.push('<polyline class="rasad-line-visitors" points="' + line('visitors') + '" />');

		// X labels (sampled to ~8).
		var step = Math.max(1, Math.ceil(points.length / 8));
		points.forEach(function (p, i) {
			if (i % step !== 0 && i !== points.length - 1) {
				return;
			}
			var label = String(p.label || '');
			if (series.granularity === 'hour') {
				label = label.split(' ')[1] || label;
			} else {
				label = displayDay(label).slice(5);
			}
			svg.push('<text class="rasad-axis-label" x="' + x(i).toFixed(1) + '" y="' + (H - 10) + '" text-anchor="middle">' + esc(label) + '</text>');
		});

		// Legend.
		svg.push('<text class="rasad-legend-chip" x="' + (W - 140) + '" y="14" fill="currentColor">■ ' + esc(STR.views || 'بازدید') + '</text>');
		svg.push('<text class="rasad-legend-chip" x="' + (W - 70) + '" y="14" fill="currentColor">▰ ' + esc(STR.visitors || 'بازدیدکننده') + '</text>');
		svg.push('</svg>');

		host.innerHTML = svg.join('');
	}

	function niceMax(value) {
		var pow = Math.pow(10, Math.floor(Math.log(value) / Math.LN10));
		var scaled = value / pow;
		var step = scaled <= 1 ? 1 : (scaled <= 2 ? 2 : (scaled <= 5 ? 5 : 10));
		return step * pow;
	}

	function fillTable(name, rows, cells) {
		var table = qs('.rasad-table[data-table="' + name + '"]');
		if (!table) {
			return;
		}
		var body = qs('tbody', table);

		if (!rows || !rows.length) {
			body.innerHTML = '<tr><td class="rasad-empty" colspan="99">' + esc(STR.no_data || '') + '</td></tr>';
			return;
		}

		body.innerHTML = rows.map(function (row) {
			return '<tr>' + cells.map(function (cell) {
				return '<td title="' + esc(cell.title ? cell.title(row) : '') + '">' + cell.render(row) + '</td>';
			}).join('') + '</tr>';
		}).join('');
	}

	function renderPages(data) {
		var pageCell = {
			render: function (row) { return esc(row.title || row.path); },
			title: function (row) { return row.path; }
		};

		fillTable('pages', data.top, [
			pageCell,
			{ render: function (row) { return num(row.views); } },
			{ render: function (row) { return num(row.visitors); } }
		]);
		fillTable('entries', data.entries, [
			pageCell,
			countCell('count'), countCell('visitors')
		]);
		fillTable('posts', data.posts, [pageCell, countCell('views'), countCell('visitors')]);
		fillTable('post_types', data.post_types, [textCell('post_type'), countCell('views'), countCell('visitors')]);
		fillTable('exits', data.exits, [
			pageCell,
			countCell('count'), countCell('visitors')
		]);
	}

	function renderReferrers(data) {
		var groups = data.groups || {};
		var labels = {
			1: STR.direct || 'مستقیم',
			2: STR.search || 'جستجو',
			3: STR.social || 'شبکهٔ اجتماعی',
			4: STR.other || 'سایر',
			5: STR.campaign || 'کمپین'
		};

		var host = qs('.rasad-ref-groups');
		if (host) {
			host.innerHTML = Object.keys(labels).map(function (type) {
				var views = groups[type] || 0;
				return '<span class="rasad-chip">' + esc(labels[type]) + '<strong>' + num(views) + '</strong></span>';
			}).join('');
		}

		fillTable('referrers', data.sources, [
			{ render: function (row) { return esc(row.name || row.domain); }, title: function (row) { return row.domain; } },
			countCell('views'), countCell('visitors')
		]);
		fillTable('campaigns', data.campaigns, [textCell('name'), countCell('views'), countCell('visitors')]);
		fillTable('search_engines', data.search_engines, [textCell('name'), countCell('views'), countCell('visitors')]);
		fillTable('social_networks', data.social_networks, [textCell('name'), countCell('views'), countCell('visitors')]);
		fillTable('terms', data.terms, [
			{ render: function (row) { return esc(row.term); } },
			countCell('views'), countCell('visitors')
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Live tab (Phase 5): 15s polling, visibility-aware, backoff on errors */
	/* ------------------------------------------------------------------ */

	var live = {
		active: false,
		timer: null,
		errors: 0,
		delay: 15000,
		pending: false
	};

	function renderLive(data) {
		var countEl = qs('.rasad-live-count');
		if (countEl) {
			countEl.textContent = num(data.online || 0);
		}

		fillTable('live', data.visitors || [], [
			{ render: function (row) { return '<span class="rasad-vk">' + esc(String(row.id || '').slice(0, 8)) + '\u2026</span>'; } },
			{ render: function (row) { return esc(row.page || '\u2014'); }, title: function (row) { return row.page || ''; } },
			{ render: function (row) { return esc([row.country, row.city].filter(Boolean).join(' / ') || '\u2014'); } },
			{ render: function (row) { return esc(row.device || '\u2014'); } },
			{ render: function (row) { return esc(row.referrer || STR.direct || '\u2014'); } },
			{ render: function (row) { return num(row.pageviews); } }
		]);

		feedRows(data.feed || []);
	}

	function feedRows(rows) {
		fillTable('feed', rows, [
			{ render: function (row) { return esc(row.time || ''); } },
			{ render: function (row) { return esc(row.page || ''); }, title: function (row) { return row.page || ''; } },
			{ render: function (row) { return '<span class="rasad-vk">' + esc(String(row.id || '').slice(0, 8)) + '\u2026</span>'; } },
			{ render: function (row) { return esc(row.country || '\u2014'); } },
			{ render: function (row) { return esc(row.referrer || STR.direct || '\u2014'); } }
		]);
	}

	function liveStatus(message, isError) {
		var el = qs('.rasad-live-error');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'rasad-live-error' + (isError ? ' is-error' : '');
	}

	function liveSchedule(ms) {
		clearTimeout(live.timer);
		live.timer = setTimeout(livePoll, ms);
	}

	function livePoll() {
		if (!live.active || live.pending) {
			return;
		}
		// Never poll in a background tab; visibilitychange restarts us.
		if (document.visibilityState === 'hidden') {
			clearTimeout(live.timer);
			return;
		}
		live.pending = true;
		api('live').then(function (data) {
			live.pending = false;
			if (!live.active) { return; }
			live.errors = 0;
			live.delay = 15000;
			renderLive(data || {});
			liveStatus('');
			liveSchedule(15000);
		}).catch(function () {
			live.pending = false;
			if (!live.active) { return; }
			live.errors += 1;
			// Exponential backoff: 30s, 60s, 60s… (capped).
			live.delay = Math.min(15000 * Math.pow(2, live.errors), 60000);
			liveStatus(STR.error || '', live.errors > 1);
			liveSchedule(live.delay);
		});
	}

	function startLive() {
		if (live.active) {
			return;
		}
		live.active = true;
		live.errors = 0;
		livePoll();
	}

	function stopLive() {
		live.active = false;
		clearTimeout(live.timer);
	}

	function setTab(name) {
		qsa('.rasad-tab').forEach(function (tab) {
			var on = tab.getAttribute('data-rasad-tab') === name;
			tab.classList.toggle('is-active', on);
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		qsa('[data-rasad-panel]').forEach(function (panel) {
			panel.hidden = panel.getAttribute('data-rasad-panel') !== name;
		});

		if (name === 'live') {
			startLive();
		} else {
			stopLive();
		}
	}

	document.addEventListener('visibilitychange', function () {
		if (!live.active) {
			return;
		}
		if (document.visibilityState === 'visible') {
			live.errors = 0;
			live.delay = 15000;
			livePoll();
		} else {
			clearTimeout(live.timer);
		}
	});

	/* ------------------------------------------------------------------ */
	/* Load pipeline                                                       */
	/* ------------------------------------------------------------------ */

	var loadVersion = 0;
	function textCell(key) { return { render: function (row) { return esc(row[key]); } }; }
	function countCell(key) { return { render: function (row) { return num(row[key]); } }; }
	function renderAudience(data) {
		['countries', 'cities', 'devices', 'browsers', 'os'].forEach(function (key) {
			fillTable(key, data[key], [textCell('name'), countCell('views'), countCell('visitors')]);
		});
	}
	function renderWoo(data) {
		['daily', 'products', 'referrers'].forEach(function (key) {
			fillTable('woo_' + key, data[key], [textCell('name'), textCell('currency'), countCell('product_views'), countCell('add_to_cart'), countCell('checkouts'), countCell('orders'), countCell('quantity'), { render: function (row) { return esc(row.revenue); } }]);
		});
	}
	function updateExport() {
		var link = qs('.rasad-export');
		if (!link) { return; }
		var url = new URL(link.href);
		['from', 'to', 'preset'].forEach(function (key) { url.searchParams.delete(key); });
		var params = rangeParams();
		Object.keys(params).forEach(function (key) { url.searchParams.set(key, params[key]); });
		url.searchParams.set('report', qs('.rasad-export-report').value);
		link.href = url.toString();
	}
	function load() {
		var version = ++loadVersion;
		status(STR.loading || '…');
		var params = rangeParams();
		var routes = ['overview', 'series', 'pages', 'referrers', 'audience'];
		if (cfg.woo) { routes.push('woo'); }
		Promise.all(routes.map(function (route) { return api(route, params); })).then(function (all) {
			if (version !== loadVersion) { return; }
			state.from = all[0].range.from; state.to = all[0].range.to;
			syncInputs(); updateExport();
			renderCards(all[0]); renderChart(all[1]); renderPages(all[2]); renderReferrers(all[3]); renderAudience(all[4]);
			if (cfg.woo) { renderWoo(all[5]); }
			status('');
		}).catch(function (error) {
			if (version === loadVersion) { status((STR.error || 'error') + ' (' + error.message + ')', true); }
		});
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function setRange(from, to, preset, activeButton) {
		state.from = from;
		state.to = to;
		state.preset = preset;
		qsa('.rasad-range').forEach(function (btn) {
			btn.classList.toggle('is-active', btn === activeButton);
		});
		syncInputs(); updateExport();
		load();
	}

	function shiftDays(day, days) {
		var parts = String(day).split('-');
		var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
		date.setDate(date.getDate() + days);
		var mm = date.getMonth() + 1;
		var dd = date.getDate();
		return date.getFullYear() + '-' + (mm < 10 ? '0' + mm : mm) + '-' + (dd < 10 ? '0' + dd : dd);
	}

	function presetRange(preset) {
		var today = (cfg.defaults && cfg.defaults.to) || '';
		switch (preset) {
			case 'today':
				return { from: today, to: today };
			case 'yesterday':
				return { from: shiftDays(today, -1), to: shiftDays(today, -1) };
			case '30d':
				return { from: shiftDays(today, -29), to: today };
			case '90d':
				return { from: shiftDays(today, -89), to: today };
			case '7d':
			default:
				return { from: shiftDays(today, -6), to: today };
		}
	}

	var isJalali = !!(cfg.defaults && cfg.defaults.jalali);
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(a) { return a[0] + '-' + pad(a[1]) + '-' + pad(a[2]); }
	function dayDate(day) { var a = day.split('-').map(Number); return new Date(Date.UTC(a[0], a[1] - 1, a[2])); }
	function g2j(gy, gm, gd) {
		var offsets = [0,31,59,90,120,151,181,212,243,273,304,334];
		var jy = gy <= 1600 ? 0 : 979; gy -= gy <= 1600 ? 621 : 1600;
		var gy2 = gm > 2 ? gy + 1 : gy;
		var days = 365 * gy + Math.floor((gy2+3)/4) - Math.floor((gy2+99)/100) + Math.floor((gy2+399)/400) - 80 + gd + offsets[gm-1];
		jy += 33*Math.floor(days/12053); days %= 12053;
		jy += 4*Math.floor(days/1461); days %= 1461;
		jy += Math.trunc((days-1)/365); if (days > 365) { days = (days-1)%365; }
		return days < 186 ? [jy,1+Math.floor(days/31),1+days%31] : [jy,7+Math.floor((days-186)/30),1+(days-186)%30];
	}
	function j2g(jy, jm, jd) {
		jy += 1595;
		var days = 365*jy + Math.floor(jy/33)*8 + Math.floor((jy%33+3)/4) + jd + (jm < 7 ? (jm-1)*31 : (jm-7)*30+186) - 355668;
		var gy = 400*Math.floor(days/146097); days %= 146097;
		if (days > 36524) { days--; gy += 100*Math.floor(days/36524); days %= 36524; if (days >= 365) { days++; } }
		gy += 4*Math.floor(days/1461); days %= 1461;
		if (days > 365) { gy += Math.floor((days-1)/365); days = (days-1)%365; }
		var leap = gy%4 === 0 && gy%100 !== 0 || gy%400 === 0;
		var lengths = [31,leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31], gm = 1;
		while (gm < 12 && days >= lengths[gm-1]) { days -= lengths[gm-1]; gm++; }
		return [gy,gm,days+1];
	}
	function displayDay(day) { var a = String(day).slice(0,10).split('-').map(Number); return iso(isJalali ? g2j(a[0],a[1],a[2]) : a); }
	function parseInput(value) {
		value = String(value).replace(/[۰-۹]/g,function (c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }).replace(/[٠-٩]/g,function (c) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(c); });
		if (!/^\d{4}[-/]\d{1,2}[-/]\d{1,2}$/.test(value)) { return ''; }
		var a = value.split(/[-/]/).map(Number);
		if (a[0] < (isJalali ? 1167 : 1789) || a[0] > (isJalali ? 1640 : 2261) || a[1]<1 || a[1]>12 || a[2]<1 || a[2]>31) { return ''; }
		var g = isJalali ? j2g(a[0],a[1],a[2]) : a, day = iso(g);
		if (dayDate(day).toISOString().slice(0,10) !== day || displayDay(day) !== iso(a)) { return ''; }
		return day;
	}
	function syncInputs() {
		qs('.rasad-from').value = displayDay(state.from).replace(/-/g,'/');
		qs('.rasad-to').value = displayDay(state.to).replace(/-/g,'/');
	}
	function initPicker(input) {
		var wrap = document.createElement('span'); wrap.className = 'rasad-date-field';
		input.parentNode.insertBefore(wrap,input); wrap.appendChild(input);
		var toggle = document.createElement('button'); toggle.type = 'button'; toggle.textContent = '▦'; toggle.setAttribute('aria-label',STR.pick_date); toggle.setAttribute('aria-expanded','false'); wrap.appendChild(toggle);
		var popup = document.createElement('div'); popup.className = 'rasad-picker'; popup.hidden = true; popup.setAttribute('role','group'); popup.setAttribute('aria-label',STR.pick_date); wrap.appendChild(popup);
		var year, month;
		function close() { popup.hidden = true; toggle.setAttribute('aria-expanded','false'); }
		function draw() {
			var first = isJalali ? j2g(year,month,1) : [year,month,1];
			var nextY = month === 12 ? year+1 : year, nextM = month === 12 ? 1 : month+1;
			var next = isJalali ? j2g(nextY,nextM,1) : [nextY,nextM,1];
			var start = dayDate(iso(first)), length = Math.round((dayDate(iso(next))-start)/86400000);
			var weekStart = isJalali ? 6 : Number(cfg.weekStart || 0), offset = (start.getUTCDay()-weekStart+7)%7;
			popup.innerHTML = '<div class="rasad-picker-head"><button type="button" data-move="-1" aria-label="'+esc(STR.previous_month)+'">‹</button><strong>'+esc(num(year)+' / '+num(month))+'</strong><button type="button" data-move="1" aria-label="'+esc(STR.next_month)+'">›</button></div>';
			var grid = document.createElement('div'); grid.className = 'rasad-picker-grid'; popup.appendChild(grid);
			var weekdays = ['ی','د','س','چ','پ','ج','ش'];
			for (var w=0;w<7;w++) { var heading = document.createElement('span'); heading.textContent = weekdays[(weekStart+w)%7]; grid.appendChild(heading); }
			for (var i=0;i<offset;i++) { grid.appendChild(document.createElement('span')); }
			for (var d=1;d<=length;d++) {
				(function (day) {
					var g = isJalali ? j2g(year,month,day) : [year,month,day], value = iso(g);
					var button = document.createElement('button'); button.type = 'button'; button.textContent = num(day); button.disabled = value > cfg.defaults.to;
					button.setAttribute('aria-label',iso([year,month,day]));
					if (parseInput(input.value) === value) { button.className = 'is-selected'; }
					button.addEventListener('click',function () { input.value = iso([year,month,day]).replace(/-/g,'/'); close(); input.focus(); }); grid.appendChild(button);
				})(d);
			}
			qsa('[data-move]',popup).forEach(function (button) { button.addEventListener('click',function () { month += Number(button.dataset.move); if (month<1) { month=12; year--; } if (month>12) { month=1; year++; } draw(); }); });
		}
		toggle.addEventListener('click',function () { if (!popup.hidden) { close(); return; } var day = parseInput(input.value) || cfg.defaults.to, parts = displayDay(day).split('-').map(Number); year=parts[0]; month=parts[1]; draw(); popup.hidden=false; toggle.setAttribute('aria-expanded','true'); });
		wrap.addEventListener('keydown',function (event) { if (event.key === 'Escape') { close(); toggle.focus(); } });
		document.addEventListener('click',function (event) { if (!wrap.contains(event.target)) { close(); } });
	}

	function init() {
		if (!REST || !qs('.rasad-dash')) {
			return;
		}

		qsa('.rasad-tab').forEach(function (tab) {
			tab.addEventListener('click', function () {
				setTab(tab.getAttribute('data-rasad-tab') || 'stats');
			});
		});

		qsa('.rasad-range').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var preset = btn.getAttribute('data-range') || '7d';
				if (preset === 'custom') { qs('.rasad-from').focus(); return; }
				var range = presetRange(preset);
				setRange(range.from, range.to, preset, btn);
			});
		});

		var form = qs('.rasad-custom-range');
		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				var from = parseInput(qs('.rasad-from').value), to = parseInput(qs('.rasad-to').value);
				if (!from || !to || from > to || to > cfg.defaults.to || (dayDate(to) - dayDate(from)) / 86400000 > 399) {
					status(STR.invalid_date || '', true); return;
				}
				setRange(from, to, '', null);
			});
			qs('.rasad-export-report').addEventListener('change', updateExport);
			qsa('.rasad-from,.rasad-to').forEach(initPicker);
			qs('.rasad-date-hint').textContent = (isJalali ? STR.jalali : STR.gregorian) + ' — YYYY/MM/DD';
			syncInputs(); updateExport();
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
