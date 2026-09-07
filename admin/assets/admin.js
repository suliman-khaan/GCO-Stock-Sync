/**
 * GCO Supplier Stock Sync - Admin JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $syncBtn = $('#gco-ss-run-sync-now');
		var $spinner = $('#gco-ss-sync-spinner');
		var $message = $('#gco-ss-sync-message');

		if (!$syncBtn.length) {
			return;
		}

		$syncBtn.on('click', function(e) {
			e.preventDefault();

			if ($syncBtn.prop('disabled')) {
				return;
			}

			// Confirm before running if enabled
			$syncBtn.prop('disabled', true);
			$spinner.addClass('is-active');
			$message.removeClass('success error').text(gco_ss_admin.strings.running || 'Sync in progress... Please wait.');

			$.ajax({
				url: gco_ss_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'gco_stock_sync_manual_run',
					nonce: gco_ss_admin.manual_run_nonce
				},
				success: function(response) {
					$spinner.removeClass('is-active');

					if (response.success) {
						$message.addClass('success').text(response.data.message || 'Sync completed successfully.');
						setTimeout(function() {
							window.location.reload();
						}, 1500);
					} else {
						$syncBtn.prop('disabled', false);
						var errMsg = (response.data && response.data.message) ? response.data.message : 'Sync failed.';
						$message.addClass('error').text(errMsg);
					}
				},
				error: function(xhr, status, error) {
					$spinner.removeClass('is-active');
					$syncBtn.prop('disabled', false);
					$message.addClass('error').text('AJAX Error: ' + (error || 'Network error occurred.'));
				}
			});
		});
	});
})(jQuery);
