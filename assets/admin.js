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
		return num(m) + ':' + num(s < 10 ? '0' + s : s);
	}

	function esc(text) {
		var div = document.createElement('div');
		div.textContent = text === null || text === undefined ? '' : String(text);
		return div.innerHTML;
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
			{ key: 'avg_duration', fmt: dur, lowerBetter: true }
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
		var area = 'points:' + line('views') + ' ' + (x(points.length - 1)).toFixed(1) + ',' + (padTop + plotH) + ' ' + x(0).toFixed(1) + ',' + (padTop + plotH);
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
				label = label.slice(5); // MM-DD
			}
			svg.push('<text class="rasad-axis-label" x="' + x(i).toFixed(1) + '" y="' + (H - 10) + '" text-anchor="middle">' + esc(label) + '</text>');
		});

		// Legend.
		svg.push('<text class="rasad-legend-chip" x="' + (W - 140) + '" y="14" fill="currentColor">■ ' + esc(STR.views || 'بازدید') + '</text>');
		svg.push('<text class="rasad-legend-chip" x="' + (W - 70) + '" y="14" fill="currentColor">_dash ' + esc(STR.visitors || 'بازدیدکننده') + '</text>');
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
			{ render: function (row) { return num(row.count); } }
		]);
		fillTable('exits', data.exits, [
			pageCell,
			{ render: function (row) { return num(row.count); } }
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
			{ render: function (row) { return num(row.views); } }
		]);
		fillTable('terms', data.terms, [
			{ render: function (row) { return esc(row.term); } },
			{ render: function (row) { return num(row.views); } }
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Live tab (Phase 5): 15s polling, visibility-aware, backoff on errors */
	/* ------------------------------------------------------------------ */

	var live = {
		active: false,
		timer: null,
		errors: 0,
		delay: 15000
	};

	function renderLive(data) {
		var countEl = qs('.rasad-live-count');
		if (countEl) {
			countEl.textContent = num(data.online || 0);
		}

		fillTable('live', data.visitors || [], [
			{ render: function (row) { return '<span class="rasad-vk">' + esc(String(row.id || '').slice(0, 8)) + '\u2026</span>'; } },
			{ render: function (row) { return esc(row.page || '\u2014'); }, title: function (row) { return row.page || ''; } },
			{ render: function (row) { return esc(row.country || '\u2014'); } },
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
		if (!live.active) {
			return;
		}
		// Never poll in a background tab; visibilitychange restarts us.
		if (document.visibilityState === 'hidden') {
			clearTimeout(live.timer);
			return;
		}
		api('live').then(function (data) {
			live.errors = 0;
			live.delay = 15000;
			renderLive(data || {});
			liveStatus('');
			liveSchedule(15000);
		}).catch(function () {
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

	function load() {
		status(STR.loading || '…');
		var params = rangeParams();

		Promise.all([
			api('overview', params),
			api('series', params),
			api('pages', params),
			api('referrers', params)
		]).then(function (all) {
			renderCards(all[0]);
			renderChart(all[1]);
			renderPages(all[2]);
			renderReferrers(all[3]);
			status('');
		}).catch(function (error) {
			status((STR.error || 'error') + ' (' + error.message + ')', true);
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
		var fromInput = qs('.rasad-from');
		var toInput = qs('.rasad-to');
		if (fromInput) {
			fromInput.value = from || '';
		}
		if (toInput) {
			toInput.value = to || '';
		}
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

	function init() {
		if (!REST) {
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
				var range = presetRange(preset);
				setRange(range.from, range.to, preset, btn);
			});
		});

		var form = qs('.rasad-custom-range');
		if (form) {
			var calBtn = qs('.rasad-calendar', form);
			if (calBtn) {
				calBtn.addEventListener('click', function () {
					var from = qs('.rasad-from', form).value;
					var to = qs('.rasad-to', form).value;
					if (from && to) {
						setRange(from, to, '', null);
					}
				});
			}
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
