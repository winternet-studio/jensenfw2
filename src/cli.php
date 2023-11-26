<?php
/**
 * Functions related to CLI (Command Line Interface/terminal)
 */

namespace winternet\jensenfw2;

class cli {

	/**
	 * Get arguments (named and non-named) from the command line
	 *
	 * Example command line: `php generate_languages.phpcli "GDPR Compliance Process.md" --type=markdown --debug`
	 */
	public static function arguments() {
		global $argc, $argv;
		$output = [];
		if ($argc < 1) {
			return $output;
		}

		// Parse command-line arguments
		for ($i = 1; $i < $argc; $i++) {
			$arg = $argv[$i];

			// Check if the argument starts with '--'
			if (strpos($arg, '--') === 0) {
				// Split the argument into name and value
				@list($name, $value) = explode('=', substr($arg, 2), 2);

				if (isset($value)) {
					$output[$name] = $value;
				} else {
					$output[$name] = true;
				}
			} else {
				$output[$i] = $arg;
			}
		}
		return $output;
	}

}
