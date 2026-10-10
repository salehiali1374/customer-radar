/*
 * Tiny dependency-free SVG chart library for Customer Radar.
 * Every chart is described by JSON in a data-chart attribute:
 *   { type: 'stacked'|'bars'|'hbars'|'donut'|'line'|'heat', ... }
 * Charts follow the page direction: in RTL the time axis runs from right (oldest) to left (newest).
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var RTL = document.documentElement.dir === 'rtl' || document.body.classList.contains('rtl');
	var PALETTE = ['#2563eb', '#f59e0b', '#0d9488', '#7c3aed', '#dc2626', '#16a34a', '#64748b', '#db2777'];

	function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

	function compact(v) {
		var a = Math.abs(v);
		if (a >= 1e9) { return fa(trim(v / 1e9)) + ' میلیارد'; }
		if (a >= 1e6) { return fa(trim(v / 1e6)) + ' میلیون'; }
		if (a >= 1e3) { return fa(trim(v / 1e3)) + ' هزار'; }
		return fa(trim(v));
	}
	function trim(x) { return (Math.round(x * 10) / 10).toString(); }
	function full(v) { return fa(Math.round(v).toLocaleString('en-US')); }

	function el(name, attrs, parent, text) {
		var e = document.createElementNS(NS, name);
		Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
		if (text !== undefined) { e.textContent = text; }
		if (parent) { parent.appendChild(e); }
		return e;
	}

	function svg(host, w, h, label) {
		host.innerHTML = '';
		var s = el('svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', role: 'img', 'aria-label': label || '', style: 'max-width:100%;height:auto;display:block' });
		host.appendChild(s);
		return s;
	}

	function niceMax(v) {
		if (v <= 0) { return 1; }
		var p = Math.pow(10, Math.floor(Math.log10(v)));
		var f = v / p;
		var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return n * p;
	}

	function fmtFor(cfg) {
		if (cfg.unit === 'percent') { return function (v) { return fa(trim(v)) + '٪'; }; }
		if (cfg.unit === 'count') { return function (v) { return full(v); }; }
		return compact;
	}

	/* --------------------------------------------------- bars / stacked bars */
	function bars(host, cfg) {
		var W = 640, H = 250, ml = 12, mr = 50, mt = 14, mb = 54;
		var s = svg(host, W, H, cfg.title);
		var series = cfg.series;
		var n = cfg.labels.length;
		var stacked = cfg.type === 'stacked';
		var totals = cfg.labels.map(function (_, i) {
			return stacked ? series.reduce(function (a, x) { return a + (x.values[i] || 0); }, 0) : Math.max.apply(null, series.map(function (x) { return x.values[i] || 0; }));
		});
		var max = niceMax(Math.max.apply(null, totals.concat([1])));
		var f = fmtFor(cfg);
		var pw = W - ml - mr, ph = H - mt - mb;
		for (var t = 0; t <= 4; t++) {
			var y = mt + ph - ph * t / 4;
			el('line', { x1: ml, x2: W - mr, y1: y, y2: y, stroke: '#e5e7eb', 'stroke-width': 1 }, s);
			el('text', { x: W - mr + 6, y: y + 4, 'font-size': 11, fill: '#6b7280', 'text-anchor': 'start' }, s, f(max * t / 4));
		}
		var slot = pw / n, bw = Math.max(4, slot * (stacked ? 0.66 : 0.66 / series.length));
		cfg.labels.forEach(function (lab, i) {
			var pos = RTL ? (n - 1 - i) : i;
			var cx = ml + slot * pos + slot / 2;
			var acc = 0;
			series.forEach(function (sr, k) {
				var v = sr.values[i] || 0;
				var bh = ph * v / max;
				var x = stacked ? cx - bw / 2 : cx - (series.length * bw) / 2 + k * bw;
				var yy = stacked ? mt + ph - acc - bh : mt + ph - bh;
				var r = el('rect', { x: x, y: yy, width: bw, height: Math.max(0, bh), fill: sr.color || PALETTE[k], rx: 2 }, s);
				el('title', {}, r, lab + ' — ' + sr.name + ': ' + (cfg.unit === 'percent' ? fa(trim(v)) + '٪' : full(v)));
				acc += bh;
			});
			if (n <= 14 || i % Math.ceil(n / 12) === 0) {
				var tx = el('text', { x: cx, y: H - mb + 14, 'font-size': 10, fill: '#4b5563', 'text-anchor': 'end', transform: 'rotate(-35 ' + cx + ' ' + (H - mb + 14) + ')' }, s, lab);
			}
		});
		legend(s, series, W, H);
	}

	function legend(s, series, W, H) {
		if (series.length < 2) { return; }
		var x = RTL ? W - 14 : 14, y = H - 8;
		series.forEach(function (sr, k) {
			var w = 12 + sr.name.length * 6 + 18;
			var gx = RTL ? x - w : x;
			el('rect', { x: RTL ? gx + w - 12 : gx, y: y - 9, width: 10, height: 10, fill: sr.color || PALETTE[k], rx: 2 }, s);
			el('text', { x: RTL ? gx + w - 16 : gx + 15, y: y, 'font-size': 11, fill: '#374151', 'text-anchor': RTL ? 'end' : 'start' }, s, sr.name);
			x += RTL ? -w : w;
		});
	}

	/* -------------------------------------------------------------- hbars */
	function hbars(host, cfg) {
		var rows = cfg.labels.length, rowH = 28, W = 640, H = rows * rowH + 16, labW = 190;
		var s = svg(host, W, H, cfg.title);
		var max = Math.max.apply(null, cfg.values.concat([1]));
		var f = fmtFor(cfg);
		cfg.labels.forEach(function (lab, i) {
			var y = 8 + i * rowH;
			var bwMax = W - labW - 90;
			var bw = Math.max(2, bwMax * cfg.values[i] / max);
			var x = RTL ? W - labW - bw : labW;
			el('text', { x: RTL ? W - 4 : 4, y: y + 15, 'font-size': 12, fill: '#374151', 'text-anchor': RTL ? 'end' : 'start' }, s, lab);
			var r = el('rect', { x: RTL ? W - labW - bw : labW, y: y + 2, width: bw, height: 16, fill: (cfg.colors && cfg.colors[i]) || PALETTE[0], rx: 3 }, s);
			el('title', {}, r, lab + ': ' + (cfg.unit === 'percent' ? fa(trim(cfg.values[i])) + '٪' : full(cfg.values[i])));
			el('text', { x: RTL ? W - labW - bw - 6 : labW + bw + 6, y: y + 15, 'font-size': 11, fill: '#6b7280', 'text-anchor': RTL ? 'end' : 'start' }, s, f(cfg.values[i]));
		});
	}

	/* -------------------------------------------------------------- donut */
	function donut(host, cfg) {
		var W = 640, H = 250, cx = RTL ? 470 : 170, cy = 125, R = 100, r = 62;
		var s = svg(host, W, H, cfg.title);
		var total = cfg.values.reduce(function (a, b) { return a + b; }, 0) || 1;
		var ang = -Math.PI / 2;
		cfg.values.forEach(function (v, i) {
			if (v <= 0) { return; }
			var a2 = ang + 2 * Math.PI * v / total;
			var large = a2 - ang > Math.PI ? 1 : 0;
			var p = [
				'M', cx + R * Math.cos(ang), cy + R * Math.sin(ang),
				'A', R, R, 0, large, 1, cx + R * Math.cos(a2), cy + R * Math.sin(a2),
				'L', cx + r * Math.cos(a2), cy + r * Math.sin(a2),
				'A', r, r, 0, large, 0, cx + r * Math.cos(ang), cy + r * Math.sin(ang), 'Z'
			].join(' ');
			var path = el('path', { d: p, fill: cfg.colors[i] || PALETTE[i % PALETTE.length], stroke: '#fff', 'stroke-width': 1 }, s);
			el('title', {}, path, cfg.labels[i] + ': ' + full(v) + ' (' + fa(trim(100 * v / total)) + '٪)');
			ang = a2;
		});
		el('text', { x: cx, y: cy - 2, 'font-size': 20, 'font-weight': 600, fill: '#111827', 'text-anchor': 'middle' }, s, full(total));
		el('text', { x: cx, y: cy + 16, 'font-size': 11, fill: '#6b7280', 'text-anchor': 'middle' }, s, cfg.center || 'مشتری');
		var lx = RTL ? 330 : 310, row = 0;
		cfg.labels.forEach(function (lab, i) {
			if (cfg.values[i] <= 0) { return; }
			var y = 22 + row * 24; row++;
			el('rect', { x: RTL ? lx + 2 : lx, y: y - 10, width: 11, height: 11, fill: cfg.colors[i] || PALETTE[i % PALETTE.length], rx: 2 }, s);
			el('text', { x: RTL ? lx - 4 : lx + 17, y: y, 'font-size': 12, fill: '#374151', 'text-anchor': RTL ? 'end' : 'start' }, s, lab + ' — ' + full(cfg.values[i]));
		});
	}

	/* --------------------------------------------------------------- line */
	function line(host, cfg) {
		var W = 640, H = 240, ml = 12, mr = 46, mt = 16, mb = 40;
		var s = svg(host, W, H, cfg.title);
		var all = [];
		cfg.series.forEach(function (sr) { sr.values.forEach(function (v) { if (v !== null) { all.push(v); } }); });
		var max = cfg.max || niceMax(Math.max.apply(null, all.concat([1])));
		var f = fmtFor(cfg);
		var pw = W - ml - mr, ph = H - mt - mb, n = cfg.labels.length;
		for (var t = 0; t <= 4; t++) {
			var y = mt + ph - ph * t / 4;
			el('line', { x1: ml, x2: W - mr, y1: y, y2: y, stroke: '#e5e7eb' }, s);
			el('text', { x: W - mr + 6, y: y + 4, 'font-size': 11, fill: '#6b7280' }, s, f(max * t / 4));
		}
		function X(i) { var pos = RTL ? n - 1 - i : i; return ml + pw * (n === 1 ? 0.5 : pos / (n - 1)); }
		cfg.series.forEach(function (sr, k) {
			var d = '', started = false;
			sr.values.forEach(function (v, i) {
				if (v === null) { started = false; return; }
				var y = mt + ph - ph * Math.min(v, max) / max;
				d += (started ? ' L ' : ' M ') + X(i) + ' ' + y; started = true;
			});
			el('path', { d: d, fill: 'none', stroke: sr.color || PALETTE[k], 'stroke-width': 2.4, 'stroke-linejoin': 'round' }, s);
			sr.values.forEach(function (v, i) {
				if (v === null) { return; }
				var y = mt + ph - ph * Math.min(v, max) / max;
				var c = el('circle', { cx: X(i), cy: y, r: 3.6, fill: sr.color || PALETTE[k] }, s);
				el('title', {}, c, cfg.labels[i] + ' — ' + (cfg.unit === 'percent' ? fa(trim(v)) + '٪' : full(v)) + (cfg.sizes ? ' (نمونه: ' + full(cfg.sizes[i]) + ')' : ''));
			});
		});
		cfg.labels.forEach(function (lab, i) {
			if (n > 12 && i % 2) { return; }
			el('text', { x: X(i), y: H - mb + 16, 'font-size': 10.5, fill: '#4b5563', 'text-anchor': 'middle' }, s, lab);
		});
		if (cfg.xlabel) { el('text', { x: ml + pw / 2, y: H - 6, 'font-size': 11, fill: '#6b7280', 'text-anchor': 'middle' }, s, cfg.xlabel); }
	}

	/* --------------------------------------------------------------- heat */
	function heat(host, cfg) {
		var rows = cfg.rows.length, cols = cfg.cols.length;
		var cw = Math.min(54, Math.floor(480 / cols)), ch = 30, lw = cfg.labW || 120, th = 26;
		var W = lw + cw * cols + 20, H = th + ch * rows + 10;
		var s = svg(host, W, H, cfg.title);
		var flat = [];
		cfg.values.forEach(function (r) { r.forEach(function (v) { if (v !== null) { flat.push(v); } }); });
		var max = cfg.max || Math.max.apply(null, flat.concat([1]));
		var base = cfg.color || [37, 99, 235];
		cfg.cols.forEach(function (c, j) {
			var pos = RTL ? cols - 1 - j : j;
			el('text', { x: (RTL ? 20 : lw) + cw * pos + cw / 2, y: 16, 'font-size': 10.5, fill: '#4b5563', 'text-anchor': 'middle' }, s, c);
		});
		cfg.rows.forEach(function (rl, i) {
			var y = th + ch * i;
			el('text', { x: RTL ? W - 4 : lw - 6, y: y + ch / 2 + 4, 'font-size': 11, fill: '#374151', 'text-anchor': RTL ? 'end' : 'end' }, s, rl);
			cfg.values[i].forEach(function (v, j) {
				var pos = RTL ? cols - 1 - j : j;
				var x = (RTL ? 20 : lw) + cw * pos;
				if (v === null) { el('rect', { x: x + 1, y: y + 1, width: cw - 2, height: ch - 2, fill: '#f3f4f6', rx: 3 }, s); return; }
				var a = Math.min(1, v / max);
				var fill = 'rgba(' + base.join(',') + ',' + (0.07 + 0.93 * a) + ')';
				var rect = el('rect', { x: x + 1, y: y + 1, width: cw - 2, height: ch - 2, fill: fill, rx: 3 }, s);
				el('title', {}, rect, rl + ' / ' + cfg.cols[j] + ': ' + (cfg.unit === 'percent' ? fa(trim(v)) + '٪' : full(v)));
				el('text', { x: x + cw / 2, y: y + ch / 2 + 4, 'font-size': 10.5, fill: a > 0.55 ? '#fff' : '#1f2937', 'text-anchor': 'middle' }, s, cfg.unit === 'percent' ? fa(Math.round(v)) : (v ? full(v) : '·'));
			});
		});
	}

	var DRAW = { bars: bars, stacked: bars, hbars: hbars, donut: donut, line: line, heat: heat };

	function init() {
		document.querySelectorAll('[data-chart]').forEach(function (host) {
			try {
				var cfg = JSON.parse(host.getAttribute('data-chart'));
				if (DRAW[cfg.type]) { DRAW[cfg.type](host, cfg); }
			} catch (e) { host.textContent = 'نمودار قابل نمایش نیست.'; }
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
