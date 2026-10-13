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
		var step = function () {
			post('crdr_sync_step').then(function (res) {
				if (!res.success) { throw new Error((res.data && res.data.message) || 'خطا'); }
				loop(res.data);
			}).catch(fail);
		};
		var loop = function (s) {
			if (s.phase === 'failed') { // the server stopped it: never loop on a failed sync
				fail(new Error('به‌روزرسانی نیمه‌کاره ماند. تا تکمیل آن ارسال پیامک متوقف است؛ دوباره «تحلیل سفارش‌ها» را بزنید.'));
				return;
			}
			if (s.busy) { // another request is working on it: wait and ask again
				status.textContent = 'پردازش دیگری در حال کار است؛ چند ثانیه صبر کنید…';
				setTimeout(step, 3000);
				return;
			}
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
			step();
		};
		var fail = function (e) {
			status.textContent = 'خطا: ' + e.message;
			syncBtn.disabled = false;
		};
	}

	/* a button that explains the consequence first (data-confirm = the text); nothing happens unless the person agrees */
	document.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-confirm]');
		if (b && !window.confirm(b.getAttribute('data-confirm'))) { ev.preventDefault(); }
	});

	/* --------------------------------- per-customer actions (list rows + detail) */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('button[data-act]');
		if (!btn) { return; }
		var host = btn.closest('[data-id]');
		if (!host) { return; }
		var id = host.getAttribute('data-id');
		var key = host.getAttribute('data-k') || ''; // who this row was when the page was drawn: the server compares it with who the id is NOW
		var act = btn.getAttribute('data-act');
		var msg = host.querySelector('.crdr-row-msg');
		var show = function (ok, text) { msg.className = 'crdr-row-msg ' + (ok ? 'ok' : 'err'); msg.textContent = text; };
		var send = function (extra) {
			btn.disabled = true;
			post('crdr_contact', Object.assign({ id: id, k: key, type: act }, extra || {})).then(function (res) {
				btn.disabled = false;
				if (res.success) { show(true, res.data.message); if (host.tagName === 'TR') { host.classList.add('crdr-done'); } }
				else { show(false, (res.data && res.data.message) || 'خطا'); }
			}).catch(function () { btn.disabled = false; show(false, 'خطای ارتباط با سرور'); });
		};
		if (act === 'sms' || act === 'sms_coupon') {
			post('crdr_preview', { id: id, k: key, coupon: act === 'sms_coupon' ? 1 : 0 }).then(function (res) {
				if (!res.success) { return show(false, (res.data && res.data.message) || 'خطا'); }
				var d = res.data;
				if (window.confirm((d.site_warning ? '⚠ ' + d.site_warning + '\n\n' : '') + 'ارسال به ' + d.phone + ' با «' + d.provider + '»:\n\n' + d.message + '\n\nارسال شود؟')) { send({ ack: d.site_ack || '' }); } // site_ack: the one-time permission the server issued with this preview (only on a site that is not the recorded one)
			}).catch(function () { show(false, 'خطای ارتباط با سرور'); });
		} else if (act === 'called') {
			var note = window.prompt('یادداشت تماس (اختیاری):', '');
			if (note !== null) { send({ note: note }); }
		} else if (act === 'optout') {
			if (window.confirm('دیگر هیچ پیامی برای این مشتری ارسال نشود؟\n\nاین کار از داخل افزونه قابل برگشت نیست و برای همه‌ی شماره‌ها و ایمیل‌هایی که افزونه او را با آن‌ها یکی می‌داند ثبت می‌شود.')) { send(); }
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
				if (mode.classList.contains('is-on')) { return; }
				var warn = { manual: 'حالت «دستی»: پیامکی نمی‌رود مگر وقتی خودتان «پیش‌نمایش و اجرا» را بزنید و تأیید کنید.', auto: 'حالت «خودکار»: از این به بعد خود افزونه هر ساعت، فقط در ساعت‌های مجاز تنظیمات و تا سقف روزانه، پیامک واقعی به مشتریان این کمپین می‌فرستد؛ بدون اینکه شما چیزی بزنید. پیش‌نمایش را دیده‌اید و از متن پیام مطمئنید؟' }[mode.dataset.mode];
				if (warn && !window.confirm(warn + '\n\nادامه می‌دهید؟')) { return; }
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
			html += '<p>مخاطب فعلی: <strong>' + digits(p.audience) + ' نفر</strong>' + (p.audience ? ' · میانگین سبد ' + esc(p.aov_fmt) : '') + '<br>سقف امروز: ' + digits(p.today) + ' از ' + digits(p.cap) + ' (پذیرفته‌شده، در حال ارسال یا نتیجه‌ی نامشخص) · ' + digits(p.holdout) + '٪ گروه شاهد (پیام نمی‌گیرند تا اثر واقعی سنجیده شود) · سرویس پیامک: ' + esc(p.provider) + '</p>';
			if (p.site_warning && p.blocked !== 'site_unconfirmed') { html += '<p class="crdr-bad">' + esc(p.site_warning) + '</p>'; } // (when the server already refuses, its own message below says the same)
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
				if (!window.confirm((p.site_warning ? '⚠ ' + p.site_warning + '\n\n' : '') + 'پیامک‌ها واقعاً ارسال می‌شوند. ادامه می‌دهید؟')) { return; }
				runBtn.disabled = true;
				var log = document.getElementById('crdr-runlog');
				log.hidden = false;
				var ack = p.site_ack || '';
				var tot = { sent: 0, held_out: 0, skipped: 0, failed: 0, unknown: 0 };
				var line = function (t) { log.innerHTML += esc(t) + '<br>'; log.scrollTop = log.scrollHeight; };
				var step = function () {
					post('crdr_pb_run', { id: id, override: override ? 1 : 0, ack: ack }).then(function (res) {
						if (!res.success) { line('خطا'); return; }
						var r = res.data;
						ack = r.site_ack || ''; // each step spends one permission and the server hands out the next
						if (r.blocked) { line('اجرا انجام نشد: ' + r.error); return; }
						['sent', 'held_out', 'skipped', 'failed', 'unknown'].forEach(function (k) { tot[k] += (r[k] || 0); });
						var did = r.sent + r.held_out + r.skipped + r.failed + (r.unknown || 0);
						line('پذیرفته‌شده توسط سرویس پیامک: ' + digits(tot.sent) + ' · گروه شاهد: ' + digits(tot.held_out) + ' · ردشده: ' + digits(tot.skipped) + ' · ناموفق: ' + digits(tot.failed) + (tot.unknown ? ' · نتیجه نامشخص: ' + digits(tot.unknown) : ''));
						if (r.error) { line('پیام خطا: ' + r.error); }
						if (did > 0 && r.left > 0 && !r.error) { setTimeout(step, 400); } else { line('پایان. ' + (r.left <= 0 ? 'به سقف امروز رسید.' : '')); }
					}).catch(function () { line('خطای ارتباط با سرور؛ وضعیت را در «نتایج» کمپین بررسی کنید و دوباره اجرا نکنید.'); });
				};
				step();
			});
		};
	}
})();
