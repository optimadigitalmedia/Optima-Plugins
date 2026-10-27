/* Optima Hours: keeps the "Open now" line, today's row and the holiday notice
   bar true in the visitor's browser, even on a cached page. The plan comes
   from the server with every moment already worked out in the site's
   timezone (window.optimaHours), so nothing here does timezone maths. The
   steps mirror Optima_Hours::status() in PHP; keep the two in step. */
(function () {
	var data = window.optimaHours;
	if (!data || !data.days) {
		return;
	}
	var t = data.t || {};

	function fmt(s) {
		var args = [].slice.call(arguments, 1), n = 0;
		return s.replace(/%(?:(\d)\$)?s/g, function (m, pos) {
			return args[pos ? pos - 1 : n++];
		});
	}

	function todayIndex(now) {
		for (var i = 0; i < data.days.length; i++) {
			if (data.days[i].s <= now && now < data.days[i].e) {
				return i;
			}
		}
		return -1;
	}

	function status(now) {
		var days = data.days, i = todayIndex(now);
		if (i < 0) {
			return null;
		}
		var today = days[i], holiday = today.c || t.holiday, j, k, iv;
		for (j = i - 1; j <= i; j++) {
			if (!days[j]) {
				continue;
			}
			for (k = 0; k < days[j].iv.length; k++) {
				iv = days[j].iv[k];
				if (iv[0] <= now && now < iv[1]) {
					return {
						open: true,
						text: (j === i && today.k === 'special') ? fmt(t.openSpecial, iv[3], holiday) : fmt(t.openUntil, iv[3])
					};
				}
			}
		}
		var next = null;
		for (j = i; j < days.length && !next; j++) {
			for (k = 0; k < days[j].iv.length; k++) {
				iv = days[j].iv[k];
				if (iv[0] > now) {
					next = [j === i ? t.today : (j === i + 1 ? t.tomorrow : days[j].n), iv[2]];
					break;
				}
			}
		}
		var text;
		if (today.k === 'closed') {
			text = next ? fmt(t.closedForOpens, holiday, next[0], next[1]) : fmt(t.closedFor, holiday);
		} else {
			text = next ? fmt(t.closedOpens, next[0], next[1]) : t.closed;
		}
		return { open: false, text: text };
	}

	function hidden(id) {
		try {
			return window.sessionStorage.getItem('optimaHoursBar') === id;
		} catch (e) {
			return false;
		}
	}

	function barMessage(now) {
		var list = data.bar || [], i, c;
		for (i = 0; i < list.length; i++) {
			c = list[i];
			if (c.s <= now && now < c.e) {
				return { id: c.id, text: c.today, note: c.note };
			}
		}
		for (i = 0; i < list.length; i++) {
			c = list[i];
			if (c.ahead && c.s > now && c.s - now <= 604800) {
				return { id: c.id, text: c.ahead, note: c.note };
			}
		}
		return null;
	}

	function makeBar() {
		var bar = document.createElement('div');
		bar.className = 'optima-hours-bar';
		bar.id = 'optima-hours-bar';
		bar.setAttribute('role', 'region');
		bar.setAttribute('aria-label', t.barLabel || 'Holiday hours');
		bar.innerHTML = '<p><strong class="optima-hours-bar-text"></strong><span class="optima-hours-bar-note"></span></p>' +
			'<button type="button" class="optima-hours-bar-close"><span aria-hidden="true">&times;</span></button>';
		bar.querySelector('button').setAttribute('aria-label', t.barHide || 'Hide this notice');
		document.body.insertBefore(bar, document.body.firstChild);
		return bar;
	}

	function update() {
		var now = Math.floor(Date.now() / 1000), st = status(now), i = todayIndex(now);

		if (st) {
			[].forEach.call(document.querySelectorAll('[data-optima-hours-status]'), function (el) {
				el.classList.toggle('is-open', st.open);
				el.classList.toggle('is-closed', !st.open);
				var txt = el.querySelector('.optima-hours-status-text');
				if (txt && txt.textContent !== st.text) {
					txt.textContent = st.text;
				}
			});
		}

		if (i >= 0) {
			var key = data.days[i].w;
			[].forEach.call(document.querySelectorAll('.optima-hours-table tr[data-day]'), function (row) {
				var on = row.getAttribute('data-day') === key;
				row.classList.toggle('is-today', on);
				if (on) {
					row.setAttribute('aria-current', 'date');
				} else {
					row.removeAttribute('aria-current');
				}
			});
		}

		var msg = barMessage(now), bar = document.getElementById('optima-hours-bar');
		if (!msg || hidden(msg.id)) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}
		if (!bar) {
			bar = makeBar();
		}
		bar.setAttribute('data-id', msg.id);
		bar.querySelector('.optima-hours-bar-text').textContent = msg.text;
		bar.querySelector('.optima-hours-bar-note').textContent = msg.note ? ' ' + msg.note : '';
		bar.hidden = false;
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.optima-hours-bar-close');
		if (!btn) {
			return;
		}
		var bar = btn.closest('.optima-hours-bar');
		try {
			window.sessionStorage.setItem('optimaHoursBar', bar.getAttribute('data-id'));
		} catch (err) {}
		bar.hidden = true;
		if (window.optimaTrack) {
			window.optimaTrack('hours_notice_hide', { link_label: bar.querySelector('.optima-hours-bar-text').textContent });
		}
	});

	update();
	window.setInterval(update, 60000);
})();
