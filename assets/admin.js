(function () {
	'use strict';

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', CRDR.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(CRDR.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}
	function digits(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	/* ---------------------------------------------------------------- sync */
	var syncBtn = document.getElementById('crdr-sync');
	if (syncBtn) {
		var status = document.getElementById('crdr-sync-status');
		var bar = document.getElementById('crdr-progress');
		syncBtn.addEventListener('click', function () {
			syncBtn.disabled = true;
			bar.hidden = false;
			status.textContent = 'در حال شروع…';
			post('crdr_sync_start').then(function (res) {
				if (!res.success) { throw new Error((res.data && res.data.message) || 'خطا'); }
				loop(res.data);
			}).catch(fail);
		});
		var loop = function (s) {
			var pct = s.total > 0 ? Math.min(100, Math.round(100 * s.done / s.total)) : 100;
			bar.firstElementChild.style.width = (s.phase === 'orders' ? Math.min(85, pct * 0.85) : s.phase === 'finalize' ? 90 : s.phase === 'stats' ? 96 : 100) + '%';
			if (s.phase === 'done') {
				status.textContent = 'انجام شد: ' + digits(s.customers) + ' مشتری تحلیل شد. صفحه دوباره بارگذاری می‌شود…';
				setTimeout(function () { location.reload(); }, 700);
				return;
			}
			status.textContent = s.phase === 'finalize' ? 'در حال امتیازدهی به مشتریان…'
				: s.phase === 'stats' ? 'در حال ساخت نمودارها و تحلیل محصولات…'
				: 'خواندن سفارش‌ها: ' + digits(s.done) + ' از ' + digits(s.total);
			post('crdr_sync_step').then(function (res) {
				if (!res.success) { throw new Error((res.data && res.data.message) || 'خطا'); }
				loop(res.data);
			}).catch(fail);
		};
		var fail = function (e) {
			status.textContent = 'خطا: ' + e.message;
			syncBtn.disabled = false;
		};
	}

	/* --------------------------------- per-customer actions (list rows + detail) */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('button[data-act]');
		if (!btn) { return; }
		var host = btn.closest('[data-id]');
		if (!host) { return; }
		var id = host.getAttribute('data-id');
		var act = btn.getAttribute('data-act');
		var msg = host.querySelector('.crdr-row-msg');
		var show = function (ok, text) { msg.className = 'crdr-row-msg ' + (ok ? 'ok' : 'err'); msg.textContent = text; };
		var send = function (extra) {
			btn.disabled = true;
			post('crdr_contact', Object.assign({ id: id, type: act }, extra || {})).then(function (res) {
				btn.disabled = false;
				if (res.success) { show(true, res.data.message); if (host.tagName === 'TR') { host.classList.add('crdr-done'); } }
				else { show(false, (res.data && res.data.message) || 'خطا'); }
			}).catch(function () { btn.disabled = false; show(false, 'خطای ارتباط با سرور'); });
		};
		if (act === 'sms' || act === 'sms_coupon') {
			post('crdr_preview', { id: id, coupon: act === 'sms_coupon' ? 1 : 0 }).then(function (res) {
				if (!res.success) { return show(false, (res.data && res.data.message) || 'خطا'); }
				var d = res.data;
				if (window.confirm('ارسال به ' + d.phone + ' با «' + d.provider + '»:\n\n' + d.message + '\n\nارسال شود؟')) { send(); }
			});
		} else if (act === 'called') {
			var note = window.prompt('یادداشت تماس (اختیاری):', '');
			if (note !== null) { send({ note: note }); }
		} else if (act === 'optout') {
			if (window.confirm('دیگر هیچ پیامی برای این مشتری ارسال نشود؟')) { send(); }
		} else { send(); }
	});

	/* ----------------------------------------------------------- campaigns */
	var modal = document.getElementById('crdr-modal');
	if (modal) {
		var body = document.getElementById('crdr-modal-body');
		modal.querySelector('.crdr-modal-x').addEventListener('click', function () { modal.hidden = true; });
		modal.addEventListener('click', function (e) { if (e.target === modal) { modal.hidden = true; } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { modal.hidden = true; } });

		document.addEventListener('click', function (ev) {
			var mode = ev.target.closest('.crdr-mode');
			if (mode) {
				var card = mode.closest('.crdr-pb');
				post('crdr_pb_mode', { id: card.dataset.id, mode: mode.dataset.mode }).then(function (res) {
					if (res.success) {
						card.querySelectorAll('.crdr-mode').forEach(function (b) { b.classList.toggle('is-on', b === mode); });
					} else {
						window.alert((res.data && res.data.message) || 'تغییر حالت کمپین انجام نشد.');
					}
				});
				return;
			}
			var open = ev.target.closest('.crdr-pb-open');
			if (!open) { return; }
			var card2 = open.closest('.crdr-pb');
			var id = card2.dataset.id;
			var mode2 = (card2.querySelector('.crdr-mode.is-on') || {}).dataset;
			body.innerHTML = '<p>در حال بارگذاری پیش‌نمایش…</p>';
			modal.hidden = false;
			post('crdr_pb_preview', { id: id }).then(function (res) {
				if (!res.success) { body.innerHTML = '<p>' + esc((res.data && res.data.message) || 'خطا') + '</p>'; return; }
				render(res.data, card2.querySelector('h3').textContent, id, mode2 ? mode2.mode : 'off');
			});
		});

		var render = function (p, title, id, mode) {
			// The server decides again on every run; this only mirrors its answer.
			var badText = p.template_errors && p.template_errors.length;
			var overridable = p.blocked === 'outside_window' && p.can_override && !badText && mode !== 'off';
			var runnable = mode !== 'off' && p.audience > 0 && p.today < p.cap && !badText && (!p.blocked || overridable);
			var html = '<h2>' + esc(title) + '</h2>';
			html += '<p>مخاطب فعلی: <strong>' + digits(p.audience) + ' نفر</strong>' + (p.audience ? ' · میانگین سبد ' + esc(p.aov_fmt) : '') + '<br>سقف امروز: ' + digits(p.today) + ' از ' + digits(p.cap) + ' ارسال‌شده · ' + digits(p.holdout) + '٪ گروه شاهد (پیام نمی‌گیرند تا اثر واقعی سنجیده شود) · سرویس پیامک: ' + esc(p.provider) + '</p>';
			if (badText) { html += '<p class="crdr-bad">متن پیام این کمپین اشکال دارد و ارسال نمی‌شود. از «ویرایش» اصلاحش کنید:<br>' + p.template_errors.map(esc).join('<br>') + '</p>'; }
			else if (p.blocked && mode !== 'off') { html += '<p class="crdr-bad">' + esc(p.blocked_message) + '</p>'; }
			if (overridable) {
				html += '<p><label><input type="checkbox" id="crdr-override"> ارسال خارج از ساعت مجاز (فقط مدیر سایت؛ این اقدام با نام شما ثبت می‌شود)</label></p>';
			}
			if (p.samples.length) {
				html += '<h3>نمونه پیام‌ها</h3>';
				p.samples.forEach(function (s) { html += '<div class="crdr-sample"><strong>' + esc(s.name || 'مشتری') + ':</strong> ' + esc(s.message) + '<div class="crdr-muted">' + digits(s.parts) + ' بخش پیامک · کد تخفیف در ارسال واقعی ساخته می‌شود</div></div>'; });
			} else if (p.audience > 0) { html += '<p class="crdr-muted">برای نمونه، مشتری مناسبی پیدا نشد (مثلاً محصول مکمل ندارند).</p>'; }
			if (p.audience > 0) {
				html += '<div class="crdr-impact"><div class="crdr-impact-t">سناریوی فروش اضافه (نه پیش‌بینی)</div><div class="crdr-impact-row">' +
					['محتاطانه', 'میانی', 'خوش‌بینانه'].map(function (n, i) { return '<div><span class="crdr-impact-v">' + esc(p.scenario_fmt[i]) + '</span><span class="crdr-muted">' + n + '</span></div>'; }).join('') + '</div></div>';
			}
			if (mode === 'off') { html += '<p class="crdr-muted">برای اجرا، حالت کمپین را روی «دستی» یا «خودکار» بگذارید.</p>'; }
			html += '<p><button type="button" class="button button-primary" id="crdr-run"' + (runnable ? '' : ' disabled') + '>اجرای حالا (حداکثر تا سقف امروز)</button></p><div id="crdr-runlog" class="crdr-runlog" hidden></div>';
			body.innerHTML = html;
			var runBtn = document.getElementById('crdr-run');
			if (!runBtn) { return; }
			runBtn.addEventListener('click', function () {
				var ov = document.getElementById('crdr-override');
				var override = !!(ov && ov.checked);
				if (overridable && !override) { window.alert('الان خارج از ساعت مجاز ارسال است. برای ادامه، گزینه‌ی «ارسال خارج از ساعت مجاز» را تیک بزنید.'); return; }
				if (override && !window.confirm('این ارسال خارج از ساعت مجاز انجام می‌شود و با نام شما ثبت خواهد شد. مطمئنید؟')) { return; }
				if (!window.confirm('پیامک‌ها واقعاً ارسال می‌شوند. ادامه می‌دهید؟')) { return; }
				runBtn.disabled = true;
				var log = document.getElementById('crdr-runlog');
				log.hidden = false;
				var tot = { sent: 0, held_out: 0, skipped: 0, failed: 0, unknown: 0 };
				var line = function (t) { log.innerHTML += esc(t) + '<br>'; log.scrollTop = log.scrollHeight; };
				var step = function () {
					post('crdr_pb_run', { id: id, override: override ? 1 : 0 }).then(function (res) {
						if (!res.success) { line('خطا'); return; }
						var r = res.data;
						if (r.blocked) { line('اجرا انجام نشد: ' + r.error); return; }
						['sent', 'held_out', 'skipped', 'failed', 'unknown'].forEach(function (k) { tot[k] += (r[k] || 0); });
						var did = r.sent + r.held_out + r.skipped + r.failed + (r.unknown || 0);
						line('ارسال: ' + digits(tot.sent) + ' · گروه شاهد: ' + digits(tot.held_out) + ' · ردشده: ' + digits(tot.skipped) + ' · ناموفق: ' + digits(tot.failed) + (tot.unknown ? ' · نتیجه نامشخص: ' + digits(tot.unknown) : ''));
						if (r.error) { line('پیام خطا: ' + r.error); }
						if (did > 0 && r.left > 0 && !r.error) { setTimeout(step, 400); } else { line('پایان. ' + (r.left <= 0 ? 'به سقف امروز رسید.' : '')); }
					}).catch(function () { line('خطای ارتباط با سرور؛ وضعیت را در «نتایج» کمپین بررسی کنید و دوباره اجرا نکنید.'); });
				};
				step();
			});
		};
	}
})();
