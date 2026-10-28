/**
 * Quick Search Replace Admin Script
 *
 * @package QuickSearchReplace
 */

document.addEventListener(
	'DOMContentLoaded',
	function () {
		var selectAllBtn   = document.getElementById( 'qsrdb-select-all' );
		var deselectAllBtn = document.getElementById( 'qsrdb-deselect-all' );
		var selectCoreBtn  = document.getElementById( 'qsrdb-select-core' );
		var tableFilter    = document.getElementById( 'qsrdb-table-filter' );
		var tableContainer = document.getElementById( 'qsrdb-tables-list' );
		var noTablesMsg    = document.getElementById( 'qsrdb-no-tables' );
		var countBadge     = document.getElementById( 'qsrdb-selection-badge' );
		var dryRunToggle   = document.getElementById( 'qsrdb-dry-run-toggle' );
		var dryRunBtn      = document.getElementById( 'qsrdb-btn-dry' );
		var liveRunBtn     = document.getElementById( 'qsrdb-btn-live' );
		var form           = document.getElementById( 'qsrdb-form' );

		var allCheckboxes  = document.querySelectorAll( '#qsrdb-tables-list input[type="checkbox"]' );
		var allRows        = document.querySelectorAll( '.qsrdb-table-row' );

		/**
		 * Update the selected tables counter badge.
		 */
		function updateSelectionCount() {
			if ( ! countBadge ) {
				return;
			}
			var checkedCount = document.querySelectorAll( '#qsrdb-tables-list input[type="checkbox"]:checked' ).length;
			var totalCount   = allCheckboxes.length;

			if ( window.qsrdbL10n && window.qsrdbL10n.selectedCount ) {
				countBadge.textContent = window.qsrdbL10n.selectedCount
					.replace( '%1$d', checkedCount )
					.replace( '%2$d', totalCount )
					.replace( '%d', checkedCount )
					.replace( '%d', totalCount );
			} else {
				countBadge.textContent = checkedCount + ' of ' + totalCount + ' selected';
			}
		}

		// Initial count update.
		updateSelectionCount();

		// Listen for checkbox changes.
		if ( tableContainer ) {
			tableContainer.addEventListener(
				'change',
				function ( e ) {
					if ( e.target && e.target.type === 'checkbox' ) {
						updateSelectionCount();
					}
				}
			);
		}

		// Select All handler.
		if ( selectAllBtn ) {
			selectAllBtn.addEventListener(
				'click',
				function ( e ) {
					e.preventDefault();
					allRows.forEach(
						function ( row ) {
							if ( row.style.display !== 'none' ) {
								var cb = row.querySelector( 'input[type="checkbox"]' );
								if ( cb ) {
									cb.checked = true;
								}
							}
						}
					);
					updateSelectionCount();
				}
			);
		}

		// Deselect All handler.
		if ( deselectAllBtn ) {
			deselectAllBtn.addEventListener(
				'click',
				function ( e ) {
					e.preventDefault();
					allCheckboxes.forEach(
						function ( cb ) {
							cb.checked = false;
						}
					);
					updateSelectionCount();
				}
			);
		}

		// Select Core Tables Only handler.
		if ( selectCoreBtn ) {
			selectCoreBtn.addEventListener(
				'click',
				function ( e ) {
					e.preventDefault();
					allRows.forEach(
						function ( row ) {
							var cb     = row.querySelector( 'input[type="checkbox"]' );
							var isCore = row.getAttribute( 'data-core' ) === '1';
							if ( cb ) {
								cb.checked = isCore;
							}
						}
					);
					updateSelectionCount();
				}
			);
		}

		// Table real-time filter handler.
		if ( tableFilter ) {
			tableFilter.addEventListener(
				'input',
				function () {
					var query = tableFilter.value.trim().toLowerCase();
					var visibleCount = 0;

					allRows.forEach(
						function ( row ) {
							var tableName = row.getAttribute( 'data-table' ) || '';
							if ( '' === query || tableName.indexOf( query ) !== -1 ) {
								row.style.display = 'flex';
								visibleCount++;
							} else {
								row.style.display = 'none';
							}
						}
					);

					if ( noTablesMsg ) {
						noTablesMsg.style.display = ( visibleCount === 0 ) ? 'block' : 'none';
					}
				}
			);
		}

		// Live Run safety confirmation.
		if ( liveRunBtn ) {
			liveRunBtn.addEventListener(
				'click',
				function ( e ) {
					var checkedCount = document.querySelectorAll( '#qsrdb-tables-list input[type="checkbox"]:checked' ).length;
					if ( checkedCount === 0 ) {
						alert( 'Please select at least one database table.' );
						e.preventDefault();
						return false;
					}

					var confirmMsg = ( window.qsrdbL10n && window.qsrdbL10n.confirmLiveRun )
						? window.qsrdbL10n.confirmLiveRun
						: 'WARNING: You are about to run a LIVE search/replace on your database.\n\nThis action cannot be undone. Are you sure you have backed up your database and want to proceed?';

					if ( ! confirm( confirmMsg ) ) {
						e.preventDefault();
						return false;
					}

					// If confirmed, uncheck dry run toggle so live run is executed.
					if ( dryRunToggle ) {
						dryRunToggle.checked = false;
					}
				}
			);
		}

		// Dry Run button validation.
		if ( dryRunBtn ) {
			dryRunBtn.addEventListener(
				'click',
				function ( e ) {
					var checkedCount = document.querySelectorAll( '#qsrdb-tables-list input[type="checkbox"]:checked' ).length;
					if ( checkedCount === 0 ) {
						alert( 'Please select at least one database table.' );
						e.preventDefault();
						return false;
					}
					if ( dryRunToggle ) {
						dryRunToggle.checked = true;
					}
				}
			);
		}
	}
);
