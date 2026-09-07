/**
 * GCO Supplier Stock Sync - Admin JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Manual Sync Trigger
		var $syncBtn = $('#gco-ss-run-sync-now');
		var $syncSpinner = $('#gco-ss-sync-spinner');
		var $syncMessage = $('#gco-ss-sync-message');

		if ($syncBtn.length) {
			$syncBtn.on('click', function(e) {
				e.preventDefault();

				if ($syncBtn.prop('disabled')) {
					return;
				}

				$syncBtn.prop('disabled', true);
				$syncSpinner.addClass('is-active');
				$syncMessage.removeClass('success error').text(gco_ss_admin.strings.running || 'Sync in progress... Please wait.');

				$.ajax({
					url: gco_ss_admin.ajax_url,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'gco_stock_sync_manual_run',
						nonce: gco_ss_admin.manual_run_nonce
					},
					success: function(response) {
						$syncSpinner.removeClass('is-active');

						if (response.success) {
							$syncMessage.addClass('success').text(response.data.message || 'Sync completed successfully.');
							setTimeout(function() {
								window.location.reload();
							}, 1500);
						} else {
							$syncBtn.prop('disabled', false);
							var errMsg = (response.data && response.data.message) ? response.data.message : 'Sync failed.';
							$syncMessage.addClass('error').text(errMsg);
						}
					},
					error: function(xhr, status, error) {
						$syncSpinner.removeClass('is-active');
						$syncBtn.prop('disabled', false);
						$syncMessage.addClass('error').text('AJAX Error: ' + (error || 'Network error occurred.'));
					}
				});
			});
		}

		// 2. Test Connection Handler
		$(document).on('click', '.gco-ss-test-connection-btn', function(e) {
			e.preventDefault();

			var $btn = $(this);
			var $wrapper = $btn.closest('.gco-ss-test-conn-wrapper');
			var $spinner = $wrapper.find('.gco-ss-test-spinner');
			var $result = $wrapper.find('.gco-ss-test-result');
			var supplierKey = $btn.data('supplier');
			var inputName = $btn.data('input');
			var feedUrl = inputName ? $('input[name="' + inputName + '"]').val() : '';

			if ($btn.prop('disabled')) {
				return;
			}

			$btn.prop('disabled', true);
			$spinner.addClass('is-active');
			$result.empty();

			$.ajax({
				url: gco_ss_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'gco_stock_sync_test_connection',
					nonce: gco_ss_admin.test_connection_nonce,
					supplier_key: supplierKey,
					feed_url: feedUrl
				},
				success: function(response) {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);

					if (response.success) {
						$result.html('<span class="gco-ss-badge status-success" style="vertical-align: middle;">' +
							(response.data.message || 'Connected successfully!') + '</span>');
					} else {
						var errMsg = (response.data && response.data.message) ? response.data.message : 'Connection failed.';
						$result.html('<span class="gco-ss-badge status-failed" style="vertical-align: middle;">' +
							errMsg + '</span>');
					}
				},
				error: function(xhr, status, error) {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);
					$result.html('<span class="gco-ss-badge status-failed" style="vertical-align: middle;">AJAX Error: ' +
						(error || 'Network error') + '</span>');
				}
			});
		});

		// 3. Copy to Clipboard Button for Cron Commands
		$(document).on('click', '.gco-ss-copy-btn', function(e) {
			e.preventDefault();

			var $copyBtn = $(this);
			var textToCopy = $copyBtn.data('copy');
			var originalText = $copyBtn.text();

			if (!textToCopy) {
				return;
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(textToCopy).then(function() {
					showCopiedState($copyBtn, originalText);
				}).catch(function() {
					fallbackCopy(textToCopy, $copyBtn, originalText);
				});
			} else {
				fallbackCopy(textToCopy, $copyBtn, originalText);
			}
		});

		function showCopiedState($btn, originalText) {
			$btn.text(gco_ss_admin.strings.copied || 'Copied!').addClass('button-primary');
			setTimeout(function() {
				$btn.text(originalText).removeClass('button-primary');
			}, 2000);
		}

		function fallbackCopy(text, $btn, originalText) {
			var $tempInput = $('<textarea>');
			$('body').append($tempInput);
			$tempInput.val(text).select();
			try {
				document.execCommand('copy');
				showCopiedState($btn, originalText);
			} catch (err) {
				alert(gco_ss_admin.strings.copy_failed || 'Could not copy to clipboard.');
			}
			$tempInput.remove();
		}

		// 4. Cron Mode Toggle Visibility
		function updateCronModeVisibility() {
			var mode = $('input[name="gco_stock_sync_settings[cron_mode]"]:checked').val();
			var $intervalRow = $('#gco_ss_sync_interval').closest('tr');

			if (mode === 'system_cron') {
				$intervalRow.css('opacity', '0.5');
			} else {
				$intervalRow.css('opacity', '1');
			}
		}

		$('input[name="gco_stock_sync_settings[cron_mode]"]').on('change', updateCronModeVisibility);
		updateCronModeVisibility();
	});
})(jQuery);
