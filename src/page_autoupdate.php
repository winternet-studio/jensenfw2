<?php
namespace winternet\jensenfw2;

/**
 * Automatically update certain parts of a web page
 *
 * Purpose: easily keep information up-to-date without requiring complex API/AJAX etc
 */
class page_autoupdate {

	/**
	 * Initialize the auto-update
	 *
	 * Should be called within the <head> or whereever you want Javascript written.
	 *
	 * @param array $elementIDs : Available options:
	 * @param string $options : Available options:
	 *   - `interval` : seconds between updates
	 */
	public function __construct($elementIDs, $options = []) {
?>
<script type="text/javascript">
var JfwPageAutoUpdate = {
	elementIDs: <?= json_encode($elementIDs) ?>,
	options: <?= json_encode(array_merge(['interval' => 60], $options)) ?>,

	pageReady: function() {
		console.log('Page ready');
		setTimeout(JfwPageAutoUpdate.fetchUpdate, JfwPageAutoUpdate.options.interval * 1000);
	},

	fetchUpdate: function() {
		fetch('<?= core::page_url(['jfw2autoupdate' => 1]) ?>', {cache: 'no-store'})
			.then(response => {
				if (!response.ok) {
					return Promise.reject(response);
				}
				return response.text();
			})
			.then(html => {
				var currDocument = document;

				var parser = new DOMParser();
				var newDocument = parser.parseFromString(html, 'text/html');
				Object.values(JfwPageAutoUpdate.elementIDs).forEach(function(elementID) {
					console.log('#'+ elementID +' updated');
					currDocument.getElementById(elementID).innerHTML = newDocument.getElementById(elementID).innerHTML;
				});

				setTimeout(JfwPageAutoUpdate.fetchUpdate, JfwPageAutoUpdate.options.interval * 1000);
			})
			.catch(error => {
				if (typeof error.text === 'function') {   //this example is expecting a JSON response
					error.text().then(responseData => {
						console.log('HTTP error code when auto-updating page: '+ error.status +' '+ error.statusText +': '+ responseData);
					});
				} else {
					console.log('Network error when auto-updating page.');
					console.log(error);
				}
				setTimeout(JfwPageAutoUpdate.fetchUpdate, JfwPageAutoUpdate.options.interval * 1000);
			});
	},
}
</script>
<?php
		$this->documentReadyJs();
	}

	public function documentReadyJs() {
		// Source: https://stackoverflow.com/a/1795167/2404541
?>
<script type="text/javascript">
// Mozilla, Opera, Webkit
if ( document.addEventListener ) {
	document.addEventListener( "DOMContentLoaded", function(){
		document.removeEventListener( "DOMContentLoaded", arguments.callee, false);
		JfwPageAutoUpdate.pageReady();
	}, false );

// If IE event model is used
} else if ( document.attachEvent ) {
	// ensure firing before onload
	document.attachEvent("onreadystatechange", function(){
		if ( document.readyState === "complete" ) {
			document.detachEvent( "onreadystatechange", arguments.callee );
			JfwPageAutoUpdate.pageReady();
		}
	});
}
</script>
<?php
	}

}
