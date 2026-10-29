(function () {
	'use strict';

	var cfg = window.kashiwazakiNextGenImages;
	if (!cfg) {
		return;
	}
	var P = 'kashiwazaki-next-gen-images-';

	function byId(id) { return document.getElementById(P + id); }
	function sleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }

	function post(action, data) {
		var body = new FormData();
		body.append('action', cfg.prefix + action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.text(); })
			.then(function (text) {
				var json;
				try { json = JSON.parse(text); } catch (e) { throw new Error(cfg.i18n.serverError); }
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || cfg.i18n.serverError);
				}
				return json.data;
			});
	}

	/* ---- ツールチップ（WAI-ARIA の Tooltip パターン）----
	 * ホバーとフォーカスで表示（CSS）。Esc で閉じる。タッチでは「？」をタップで開閉する。 */
	function closeTips(except) {
		document.querySelectorAll('.' + P + 'tip.is-open').forEach(function (tip) {
			if (tip !== except) { tip.classList.remove('is-open'); }
		});
	}
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		closeTips(null);
		document.querySelectorAll('.' + P + 'tip').forEach(function (tip) {
			if (tip.matches(':hover') || tip.contains(document.activeElement)) { tip.classList.add('is-dismissed'); }
		});
	});
	document.addEventListener('mouseout', function (e) {
		var tip = e.target.closest && e.target.closest('.' + P + 'tip');
		if (tip && !tip.contains(e.relatedTarget)) { tip.classList.remove('is-dismissed'); }
	});
	document.addEventListener('focusout', function (e) {
		var tip = e.target.closest && e.target.closest('.' + P + 'tip');
		if (tip) { tip.classList.remove('is-dismissed'); }
	});
	document.addEventListener('click', function (e) {
		var button = e.target.closest && e.target.closest('.' + P + 'tip-button');
		if (button) {
			e.preventDefault();
			var tip = button.parentNode;
			closeTips(tip);
			tip.classList.remove('is-dismissed');
			tip.classList.toggle('is-open');
			return;
		}
		closeTips(null);
	});

	/* ---- スライダーの現在値の表示 ---- */
	document.querySelectorAll('.' + P + 'range input[type="range"]').forEach(function (input) {
		var out = input.parentNode.querySelector('output');
		if (!out) { return; }
		var suffix = out.getAttribute('data-suffix') || '';
		function update() {
			out.textContent = input.value + suffix;
			input.setAttribute('aria-valuetext', input.value + suffix);
		}
		input.addEventListener('input', update);
		update();
	});

	/* ---- メディアライブラリの「今すぐ変換」ボタン ---- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.' + P + 'convert-button');
		if (!btn) { return; }
		e.preventDefault();
		var cell = btn.closest('.' + P + 'cell');
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = cfg.i18n.working;
		var force = btn.getAttribute('data-force') === '1';
		var token = '';
		(function run(tries) {
			post('convert', { id: btn.getAttribute('data-id'), force: force ? 1 : 0, force_token: token })
				.then(function (d) {
					token = d.force_token || '';
					if ((d.state === 'partial' || d.state === 'locked') && tries < 8) {
						return sleep(d.state === 'locked' ? 2000 : 0).then(function () { run(tries + 1); });
					}
					if (cell && d.status_html) {
						cell.innerHTML = d.status_html; // サーバーがエスケープ済みで返す固定の表示 HTML
					}
				})
				.catch(function (err) {
					btn.disabled = false;
					btn.textContent = original;
					window.alert(err.message);
				});
		})(0);
	});

	/* ---- 診断タブ ---- */
	function bindSimple(btnId, action, outId, onDone) {
		var btn = byId(btnId);
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			btn.disabled = true;
			var out = byId(outId);
			if (out) { out.textContent = cfg.i18n.working; }
			post(action, {}).then(function (d) {
				if (out) { out.textContent = ''; }
				onDone(d);
				btn.disabled = false;
			}).catch(function (err) {
				if (out) { out.textContent = err.message; }
				btn.disabled = false;
			});
		});
	}
	bindSimple('engine-test', 'engine_test', 'engine-test-result', function () { window.location.reload(); });
	bindSimple('mime-test', 'mime_test', 'mime-test-result', function (d) {
		['webp', 'avif'].forEach(function (f) {
			var cell = byId('mime-' + f);
			if (cell && d[f]) { cell.textContent = d[f]; }
		});
	});

	/* ---- 一括処理（変換・削除）----
	 * 進み具合はサーバーに保存されている。ここでは「少しずつ進める」呼び出しを繰り返し、結果を表示するだけ。
	 * だから別のページへ移っても、戻れば同じ進捗が出る。管理画面のどのページでも、見ている間は処理が進む。 */
	var startBtn = byId('start');
	var stopBtn = byId('stop');
	var restartBtn = byId('restart');
	var removeBtn = byId('remove');
	var looping = false;
	var pollTimer = null;
	var metaTimer = null;
	var current = null;
	var lastRev = -1;
	var lastId = '';
	var skew = 0; // サーバーの時計との差（秒）。経過時間を、画面を更新しなくても数えるために使う。

	function pad(n) { return n < 10 ? '0' + n : String(n); }
	function clock(sec) {
		sec = Math.max(0, Math.floor(sec));
		var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
		return (h ? h + ':' + pad(m) : m) + ':' + pad(s);
	}

	/* 経過時間・残りの目安・いま処理している画像を、1 秒ごとに更新する（数字が止まって見えないように）。 */
	function renderMeta() {
		var job = current;
		var metas = document.querySelectorAll('.' + P + 'meta');
		if (!job || !metas.length) { return; }
		var nowSrv = Date.now() / 1000 - skew;
		var end = (job.state === 'done' && job.finished) ? job.finished : nowSrv;
		var elapsed = Math.max(0, end - job.started);
		var parts = [];
		if (job.state === 'running') {
			parts.push(cfg.i18n.elapsed + ' ' + clock(elapsed));
			if (job.done >= 5 && elapsed >= 8 && job.total > job.done) {
				var remain = (job.total - job.done) / (job.done / elapsed);
				parts.push(remain < 60 ? cfg.i18n.eta_soon : cfg.i18n.eta.replace('%s', Math.ceil(remain / 60)));
			}
			if (job.current) { parts.push(cfg.i18n.now + ': ' + job.current); }
		} else if (job.state === 'done') {
			parts.push(cfg.i18n.took + ' ' + clock(elapsed));
		}
		var text = parts.join('　・　');
		metas.forEach(function (el) { el.textContent = text; });
	}

	function render(job) {
		if (!job) { return; }
		// 古い応答（先に出した要求の遅れて届いたもの）で、新しい表示を戻さない。
		if (job.id === lastId && job.rev < lastRev) { return; }
		lastId = job.id;
		lastRev = job.rev;
		current = job;
		skew = Date.now() / 1000 - job.now;

		var running = job.state === 'running';
		var text = job.percent + '% — ' + job.text;
		document.querySelectorAll('.' + P + 'progress').forEach(function (box) { box.setAttribute('aria-valuenow', job.percent); });
		document.querySelectorAll('.' + P + 'progress-bar').forEach(function (bar) {
			bar.style.width = job.percent + '%';
			bar.classList.toggle('is-done', job.state === 'done');
			bar.classList.toggle('is-running', running && !job.stopping);
		});
		document.querySelectorAll('.' + P + 'status').forEach(function (el) {
			el.textContent = text;
			el.classList.toggle('is-running', running && !job.stopping);
		});
		document.querySelectorAll('.' + P + 'log').forEach(function (list) {
			list.textContent = '';
			job.log.forEach(function (row) {
				var li = document.createElement('li');
				li.textContent = row.m;
				if (row.e) { li.className = 'is-error'; }
				list.appendChild(li);
			});
		});
		var box = byId('job');
		if (box) { box.hidden = false; }

		var pausedConvert = job.state === 'paused' && job.kind === 'convert';
		var pausedRemove = job.state === 'paused' && job.kind === 'remove';
		if (startBtn) {
			startBtn.disabled = running;
			startBtn.textContent = running ? cfg.i18n.working : (pausedConvert ? cfg.i18n.resume : cfg.i18n.start);
		}
		if (stopBtn) {
			stopBtn.disabled = !running || job.stopping;
			stopBtn.textContent = job.stopping ? cfg.i18n.stopping : cfg.i18n.stop;
		}
		if (restartBtn) { restartBtn.hidden = !pausedConvert; }
		if (removeBtn) {
			removeBtn.disabled = running;
			if (!removeBtn.getAttribute('data-label')) { removeBtn.setAttribute('data-label', removeBtn.textContent); }
			removeBtn.textContent = pausedRemove ? cfg.i18n.resume : removeBtn.getAttribute('data-label');
		}
		renderMeta();
		if (running) { startTickers(); } else { stopTickers(); }
	}

	/* 処理が走っている間、(1) 1 秒ごとに状態を取り直す  (2) 経過時間を 1 秒ごとに更新する。
	 * 実際に進める呼び出し(tick)は長い（数秒）ので、その応答を待たずに、数字を滑らかに動かす。 */
	function startTickers() {
		if (!pollTimer) {
			pollTimer = setInterval(function () {
				post('job_status', {}).then(function (d) { render(d.job); }).catch(function () { /* 次の回に任せる */ });
			}, 1000);
		}
		if (!metaTimer) { metaTimer = setInterval(renderMeta, 1000); }
	}
	function stopTickers() {
		if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
		if (metaTimer) { clearInterval(metaTimer); metaTimer = null; }
	}

	function showError(message) {
		document.querySelectorAll('.' + P + 'status').forEach(function (el) { el.textContent = message; });
	}

	function loop() {
		if (looping) { return; }
		looping = true;
		(function step(failures) {
			post('job_tick', {}).then(function (d) {
				render(d.job);
				if (d.job && d.job.state === 'running') {
					// 他のタブが処理中なら、少し待ってからもう一度見に行く。
					setTimeout(function () { step(0); }, d.busy ? 3000 : 50);
				} else {
					looping = false;
				}
			}).catch(function (err) {
				if (failures < 3) {
					setTimeout(function () { step(failures + 1); }, 4000 * (failures + 1));
				} else {
					looping = false;
					showError(err.message);
				}
			});
		})(0);
	}

	function startJob(kind, extra) {
		var data = { kind: kind };
		Object.keys(extra || {}).forEach(function (k) { data[k] = extra[k]; });
		return post('job_start', data).then(function (d) { render(d.job); loop(); }).catch(function (err) { showError(err.message); });
	}

	if (startBtn) {
		startBtn.addEventListener('click', function () {
			var resuming = current && current.state === 'paused' && current.kind === 'convert';
			var force = byId('force') && byId('force').checked;
			var retry = byId('retry') && byId('retry').checked;
			startJob('convert', { force: force ? 1 : 0, retry_failed: retry ? 1 : 0, restart: resuming ? 0 : 1 });
		});
	}
	if (restartBtn) {
		restartBtn.addEventListener('click', function () {
			if (!window.confirm(cfg.i18n.confirmRestart)) { return; }
			var force = byId('force') && byId('force').checked;
			var retry = byId('retry') && byId('retry').checked;
			startJob('convert', { force: force ? 1 : 0, retry_failed: retry ? 1 : 0, restart: 1 });
		});
	}
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			stopBtn.disabled = true;
			post('job_stop', {}).then(function (d) { render(d.job); }).catch(function (err) { showError(err.message); });
		});
	}
	if (removeBtn) {
		removeBtn.addEventListener('click', function () {
			var resuming = current && current.state === 'paused' && current.kind === 'remove';
			if (!resuming && !window.confirm(cfg.i18n.confirmRemove)) { return; }
			startJob('remove', { restart: resuming ? 0 : 1 });
		});
	}

	if (cfg.job) {
		render(cfg.job);
		if (cfg.job.state === 'running') { loop(); }
	}
})();
