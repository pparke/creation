<?php

/**
 * Parses an SELECT statement
 *
 * @package   Creation
 * @copyright 2007 silverorange
 */
class CreationSelect extends CreationObject
{
	// {{{ protected function parseName()

	protected function parseName()
	{
		return md5($this->sql);
	}

	// }}}
	// {{{ protected function parseDeps()

	protected function parseDeps()
	{
		$regexp = '/select.*from\s+([a-zA-Z0-9_,\w]+)/ui';
		$matches = array();

		preg_match($regexp, $this->sql, $matches);

		$deps = array();
		$tables = explode(',', $matches[1]);
		foreach ($tables as $table) {
			$regexp = '/^([a-zA-Z0-9_]+)/ui';
			$matches = array();
			if (preg_match($regexp, $table, $matches) === 1) {
				$deps[] = $matches[1];
			}
		}

		return $deps;
	}

	// }}}
}

?>
