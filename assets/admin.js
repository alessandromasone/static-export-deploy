/* Static Export & Deploy — dashboard */
(function ($) {
	'use strict';

	var polling = null;

	var STATUS_LABEL = {
		running: 'In esecuzione',
		done: 'Completato',
		error: 'Errore',
		cancelled: 'Annullato'
	};

	function isReady() {
		return $('#sed-start').data('ready') === 1 || $('#sed-start').data('ready') === '1';
	}

	function setRunningUI(running) {
		$('#sed-start').prop('disabled', running || !isReady());
		$('#sed-cancel').toggle(running);
	}

	function render(payload) {
		var job = payload.job;

		if (!job) {
			$('#sed-status-badge').attr('class', 'sed-badge').text('inattivo');
			$('#sed-status-label').text('Nessun export eseguito finora.');
			setRunningUI(false);
			return;
		}

		// Badge + etichetta di fase.
		$('#sed-status-badge')
			.attr('class', 'sed-badge ' + job.status)
			.text(STATUS_LABEL[job.status] || job.status);
		var phaseLabel = (job.progress && job.progress.label) ? job.progress.label : '';
		if (phaseLabel === (STATUS_LABEL[job.status] || job.status)) {
			phaseLabel = ''; // Evita "Completato Completato" su job di versioni precedenti.
		}
		$('#sed-status-label').text(phaseLabel);

		// Barra di avanzamento.
		var pct = 0;
		if (job.progress && job.progress.total > 0) {
			pct = Math.min(100, Math.round((job.progress.current / job.progress.total) * 100));
		}
		if (job.status === 'done') {
			pct = 100;
		}
		$('#sed-progress-bar').css('width', pct + '%').toggleClass('sed-bar-error', job.status === 'error');

		// Errore in formato notice nativa.
		if (job.status === 'error' && job.error) {
			$('#sed-error').show().find('p').text(job.error);
		} else {
			$('#sed-error').hide();
		}

		// Download disponibili.
		$('#sed-report-link').toggle(!!job.report);
		$('#sed-zip-raw').toggle(!!job.zip_raw);
		$('#sed-zip-main').toggle(!!job.zip_main);

		// Log.
		if (payload.log && payload.log.length) {
			var $log = $('#sed-log');
			$log.text(payload.log.join('\n'));
			$log.scrollTop($log[0].scrollHeight);
		}

		var running = job.status === 'running';
		if (running) {
			$('#sed-log-wrap').attr('open', 'open');
		}
		setRunningUI(running);

		if (running && !polling) {
			startPolling();
		}
		if (!running && polling) {
			clearInterval(polling);
			polling = null;
		}
	}

	function fetchStatus() {
		$.post(SED.ajaxUrl, { action: 'sed_status', nonce: SED.nonce }, function (res) {
			if (res && res.success) {
				render(res.data);
			}
		});
	}

	function startPolling() {
		if (!polling) {
			polling = setInterval(fetchStatus, 2500);
		}
	}

	$(function () {
		if (!$('#sed-status-card').length) {
			return;
		}

		$('#sed-start').on('click', function () {
			var $btn = $(this).prop('disabled', true);
			$.post(SED.ajaxUrl, { action: 'sed_start', nonce: SED.nonce }, function (res) {
				if (res && res.success) {
					render(res.data);
					startPolling();
				} else {
					$btn.prop('disabled', !isReady());
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Errore di avvio.';
					$('#sed-error').show().find('p').text(msg);
				}
			});
		});

		$('#sed-cancel').on('click', function () {
			if (!window.confirm('Annullare l\'export in corso?')) {
				return;
			}
			$.post(SED.ajaxUrl, { action: 'sed_cancel', nonce: SED.nonce }, function (res) {
				if (res && res.success) {
					render(res.data);
				}
			});
		});

		fetchStatus();
		if ($('#sed-status-card').data('status') === 'running') {
			startPolling();
		}
	});
})(jQuery);
