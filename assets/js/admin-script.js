/**
 * Quick Search Replace Admin Script
 *
 * @package QuickSearchReplace
 */

document.addEventListener(
	'DOMContentLoaded',
	function () {
		var selectAll = document.getElementById( 'qsrdb-select-all' );
		var deselectAll = document.getElementById( 'qsrdb-deselect-all' );

		if (selectAll) {
			selectAll.addEventListener(
				'click',
				function (e) {
					e.preventDefault();
					document.querySelectorAll( '#qsrdb-tables input[type="checkbox"]' ).forEach(
						function (el) {
							el.checked = true;
						}
					);
				}
			);
		}

		if (deselectAll) {
			deselectAll.addEventListener(
				'click',
				function (e) {
					e.preventDefault();
					document.querySelectorAll( '#qsrdb-tables input[type="checkbox"]' ).forEach(
						function (el) {
							el.checked = false;
						}
					);
				}
			);
		}
	}
);