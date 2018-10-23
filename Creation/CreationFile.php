<?php

/**
 * Parses creation statements from an SQL file
 *
 * @package   Creation
 * @copyright 2006-2015 silverorange
 */
class CreationFile
{
	// {{{ private properties

	private $objects = array();

	// }}}
	// {{{ public function __construct()

	public function __construct($filename)
	{
		$sql = file_get_contents($filename);
		$sql = self::cleanSql($sql);
		$statements = $this->parseStatements($sql);

		foreach ($statements as $statement) {
			$object = $this->parseObject($statement);
			$object->filename = $filename;

			if ($object !== null)
				$this->objects[$object->name] = $object;
		}
	}

	// }}}
	// {{{ public function getObjects()

	public function getObjects()
	{
		return $this->objects;
	}

	// }}}
	// {{{ public static function cleanSql()

	public static function cleanSql($sql)
	{
		$regexp = '/--.*/';
		$sql = preg_replace($regexp, '', $sql);

		$regexp = '|/\*.*\*/|uUs';
		$sql = preg_replace($regexp, '', $sql);

		return $sql;
	}

	// }}}
	// {{{ private function parseStatements()

	/**
	 * Parses statements out of a block of SQL
	 *
	 * @param string $sql the SQL to parse.
	 *
	 * @return array the array of statements parsed from the given SQL.
	 */
	private function parseStatements($sql)
	{
		$lines = explode("\n", $sql);

		$in_function_string = false;
		$in_function_definition = false;

		$in_string = false;
		$string_type = null;

		$in_comment = false;
		$in_inline_comment = false;

		$statement = '';
		$statements = array();

		$compound_depth = 0;

		$token_expression = '/(\/\*|\*\/|--|\$\$|;|\'|"|BEGIN|END;)/ui';

		foreach ($lines as $line) {
			// new line always ends an inline comment
			$in_inline_comment = false;

			$tokens = preg_split($token_expression, $line, -1,
				PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

			foreach ($tokens as $token) {
				switch (mb_strtoupper($token)) {
				case '/*':
					if (!$in_comment) {
						if (!$in_string) {
							$in_comment = true;
						} else {
							if (!$in_inline_comment) {
								$statement.= $token;
							}
						}
					}
					break;

				case '*/':
					if (!$in_string) {
						if ($in_comment) {
							$in_comment = false;
						} else {
							echo 'Unopened closing comment detected: ',
								$line, "\n";

							exit(1);
						}
					} else {
						if (!$in_inline_comment) {
							$statement.= $token;
						}
					}

					break;

				case '--':
					if (!$in_string) {
						$in_inline_comment = true;
					} else {
						if (!$in_comment) {
							$statement.= $token;
						}
					}
					break;

				case '$$':
					if (!$in_comment && !$in_inline_comment) {
						if (!$in_string) {
							$in_function_string = !$in_function_string;
						}
						$statement.= $token;
					}
					break;

				case 'BEGIN':
					if (!$in_comment && !$in_inline_comment) {
						$statement.= $token;
						if (!$in_string && !$in_function_string) {
							$compound_depth++;
							$in_function_definition = true;
						}
					}
					break;

				case 'END;':
					if (!$in_comment && !$in_inline_comment) {
						$statement.= $token;
						if (!$in_string && $compound_depth == 1 &&
							!$in_function_string) {
							$compound_depth--;
							$in_function_definition = false;
							$statements[] = trim($statement);
							$statement = '';
						} else {
							$compound_depth--;
						}
					}
					break;

				case ';':
					if (!$in_comment && !$in_inline_comment) {
						$statement.= $token;
						if (!$in_string && !$in_function_string &&
							!$in_function_definition) {
							$statements[] = trim($statement);
							$statement = '';
						}
					}
					break;

				case '"':
					// TODO: this will catch \"
					if (!$in_comment && !$in_inline_comment) {
						if ($in_string) {
							if ($string_type == '"') {
								$in_string = false;
							}
						} else {
							$in_string = true;
							$string_type = '"';
						}
						$statement.= $token;
					}
					break;

				case "'":
					// TODO: this will catch \'
					if (!$in_comment && !$in_inline_comment) {
						if ($in_string) {
							if ($string_type == "'") {
								$in_string = false;
							}
						} else {
							$in_string = true;
							$string_type = "'";
						}
						$statement.= $token;
					}
					break;

				default:
					if (!$in_comment && !$in_inline_comment) {
						$statement.= $token;
					}
					break;
				}
			}

			if (!$in_comment) {
				$statement.= "\n";
			}
		}

		// last statement does not need a semicolon terminator
		if (!$in_comment && !$in_inline_comment) {
			$statements[] = trim($statement);
		}

		// remove empty statements
		$statements = array_filter(
			$statements,
			function ($statement) {
				return $statement != '';
			}
		);

		return $statements;
	}

	// }}}
	// {{{ private function parseObject()

	private function parseObject($sql)
	{
		$types = array(
			'table',
			'view',
			'function',
			'trigger',
			'index',
			'type',
			'procedure',
			'aggregate',
		);

		$types = implode('|', $types);
		$regexp = '/create( or replace| unique)? ('.$types.')/ui';
		$matches = array();

		if (preg_match($regexp, $sql, $matches)) {
			$type = mb_strtolower($matches[2]);

			switch ($type) {
			case 'table':
				$object = new CreationTable($sql);
				break;
			case 'view':
				$object = new CreationView($sql);
				break;
			case 'function':
				$object = new CreationFunction($sql);
				break;
			case 'trigger':
				$object = new CreationTrigger($sql);
				break;
			case 'index':
				$object = new CreationIndex($sql);
				break;
			case 'type':
				$object = new CreationType($sql);
				break;
			case 'procedure':
				$object = new CreationProcedure($sql);
				break;
			case 'aggregate':
				$object = new CreationAggregate($sql);
				break;
			default:
				print_r($matches);
				exit;
			}
		} elseif (preg_match('/insert into/ui', $sql, $matches)) {
			$object = new CreationInsert($sql);
		} elseif (preg_match('/select /ui', $sql, $matches)) {
			$object = new CreationSelect($sql);
		} elseif (preg_match('/alter table/ui', $sql, $matches)) {
			$object = new CreationAlter($sql);
		} else {
			echo "Could not create an object for:\n", $sql;
			exit;
		}

		return $object;
	}

	// }}}
}

?>
