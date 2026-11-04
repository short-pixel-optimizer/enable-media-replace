'use strict';

/*
 * Enable Media Replace: the Bulk Enhance filter of the Media Library grid
 * and of the media modal. Adds the dropdown of the list view
 * (MediaLibraryFilterController::addFilterDropdown()) to the attachments
 * browser toolbar; the selected value travels as `query[emr_bulk]` in the
 * `query-attachments` request, where MediaLibraryFilterController
 * filters it. Port of ShortPixel Image Optimizer's shortpixel-media.js.
 * Options and the query argument come from `emrMediaFilter` (localized).
 */
(function () {
	if (typeof wp === 'undefined' || typeof wp.media === 'undefined' || typeof wp.media.view === 'undefined' || typeof emrMediaFilter === 'undefined') {
		return;
	}

	var EmrBulkFilter = wp.media.view.AttachmentFilters.extend({
		id: 'emr-media-filter',

		createFilters: function () {
			var filters = {};
			var priority = 10;
			Object.keys(emrMediaFilter.filters).forEach(function (key) {
				var props = {};
				props[emrMediaFilter.queryVar] = (key === 'all') ? null : key;
				filters[key] = {
					text: emrMediaFilter.filters[key],
					props: props,
					priority: priority
				};
				priority += 10;
			});
			this.filters = filters;
		}
	});

	var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend({
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.call(this);
			this.toolbar.set('emrBulkFilter', new EmrBulkFilter({
				controller: this.controller,
				model: this.collection.props,
				priority: -75
			}).render());
		}
	});
})();
