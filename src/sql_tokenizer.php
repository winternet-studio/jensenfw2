<?php
namespace winternet\jensenfw2;

/**
 * Quick and dirty SQL tokenizer / parser
 *
 * Originally ported by Justin Carlson from SQL::Tokenizer's Tokenizer.pm by Igor Sutton Lopes.
 * Then tweaked and cleaned up by Allan Jensen (also added untokenize()).
 *
 * @author Justin Carlson <justin.carlson@gmail.com>
 * @author Allan Jensen, winternet.no
 * @license MIT
 * @version 0.0.2
 */
class sql_tokenizer {

	public static $querysections = ['alter', 'create', 'drop', 'select', 'delete', 'insert', 'update', 'from', 'where', 'limit', 'order'];
	public static $operators = ['=', '<>', '<', '<=', '>', '>=', 'like', 'clike', 'slike', 'not', 'is', 'in', 'between'];
	public static $types = ['character', 'char', 'varchar', 'nchar', 'bit', 'numeric', 'decimal', 'dec', 'integer', 'int', 'smallint', 'float', 'real', 'double', 'date', 'datetime', 'time', 'timestamp', 'interval', 'bool', 'boolean', 'set', 'enum', 'text'];
	public static $conjunctions = ['by', 'as', 'on', 'into', 'from', 'where', 'with'];
	public static $functions = ['avg', 'count', 'max', 'min', 'sum', 'nextval', 'currval', 'concat'];
	public static $reserved = ['absolute', 'action', 'add', 'all', 'allocate', 'and', 'any', 'are', 'asc', 'ascending', 'assertion', 'at', 'authorization', 'begin', 'bit_length', 'both', 'cascade', 'cascaded', 'case', 'cast', 'catalog', 'char_length', 'character_length', 'check', 'close', 'coalesce', 'collate', 'collation', 'column', 'commit', 'connect', 'connection', 'constraint', 'constraints', 'continue', 'convert', 'corresponding', 'cross', 'current', 'current_date', 'current_time', 'current_timestamp', 'current_user', 'cursor', 'day', 'deallocate', 'declare', 'default', 'deferrable', 'deferred', 'desc', 'descending', 'describe', 'descriptor', 'diagnostics', 'disconnect', 'distinct', 'domain', 'else', 'end', 'end-exec', 'escape', 'except', 'exception', 'exec', 'execute', 'exists', 'external', 'extract', 'false', 'fetch', 'first', 'for', 'foreign', 'found', 'full', 'get', 'global', 'go', 'goto', 'grant', 'group', 'having', 'hour', 'identity', 'immediate', 'indicator', 'initially', 'inner', 'input', 'insensitive', 'intersect', 'isolation', 'join', 'key', 'language', 'last', 'leading', 'left', 'level', 'limit', 'local', 'lower', 'match', 'minute', 'module', 'month', 'names', 'national', 'natural', 'next', 'no', 'null', 'nullif', 'octet_length', 'of', 'only', 'open', 'option', 'or', 'order', 'outer', 'output', 'overlaps', 'pad', 'partial', 'position', 'precision', 'prepare', 'preserve', 'primary', 'prior', 'privileges', 'procedure', 'public', 'read', 'references', 'relative', 'restrict', 'revoke', 'right', 'rollback', 'rows', 'schema', 'scroll', 'second', 'section', 'session', 'session_user', 'size', 'some', 'space', 'sql', 'sqlcode', 'sqlerror', 'sqlstate', 'substring', 'system_user', 'table', 'temporary', 'then', 'timezone_hour', 'timezone_minute', 'to', 'trailing', 'transaction', 'translate', 'translation', 'trim', 'true', 'union', 'unique', 'unknown', 'upper', 'usage', 'user', 'using', 'value', 'values', 'varying', 'view', 'when', 'whenever', 'work', 'write', 'year', 'zone', 'eoc'];
	public static $startenclosure = ['{', '('];
	public static $endenclosure = ['}', ')'];
	public static $tokens = [',', ' '];

	private $query = [];

	public function __construct() {
	}

	/**
	 * Simple SQL Tokenizer
	 *
	 * @author Justin Carlson <justin.carlson@gmail.com>
	 * @license GPL
	 * @param string $sqlQuery
	 * @return token array
	 */
	public static function tokenize($sqlQuery, $cleanWhitespace = true) {
		/**
		 * Strip extra whitespace from the query
		 */
		if ($cleanWhitespace) {
			$sqlQuery = ltrim(preg_replace('/[\\s]{2,}/', ' ', $sqlQuery));
		}

		/**
		 * Regular expression based on SQL::Tokenizer's Tokenizer.pm by Igor Sutton Lopes
		 **/
		$regex = '('; # begin group

		$regex .= '(?:--|\\#)[\\ \\t\\S]*'; # inline comments
		$regex .= '|(?:<>|<=>|>=|<=|==|=|!=|!|<<|>>|<|>|\\|\\||\\||&&|&|-|\\+|\\*(?!\/)|\/(?!\\*)|\\%|~|\\^|\\?)'; # logical operators
		$regex .= '|[\\[\\]\\(\\),;`]|\\\'\\\'(?!\\\')|\\"\\"(?!\\"")'; # empty single/double quotes
		$regex .= '|".*?(?:(?:""){1,}"|(?<!["\\\\])"(?!")|\\\\"{2})|\'.*?(?:(?:\'\'){1,}\'|(?<![\'\\\\])\'(?!\')|\\\\\'{2})'; # quoted strings
		$regex .= '|\/\\*[\\ \\t\\n\\S]*?\\*\/'; # c style comments
		$regex .= '|(?:[\\w:@]+(?:\\.(?:\\w+|\\*)?)*)'; # words, placeholders, database.table.column strings
		$regex .= '|[\t\ ]+';
		$regex .= '|[\.]'; #period

		$regex .= ')'; # end group

		// get global match
		preg_match_all('/' . $regex . '/smx', $sqlQuery, $result);

		// return tokens
		return $result[0];
	}

	/**
	 * @param array $tokens : Output from [[tokenize()]]
	 * @return string : SQL query
	 */
	public static function untokenize($tokens) {
		return implode('', $tokens);
	}

	/**
	 * Parse a string into query sections and return an array.
	 * @param string $sqlQuery
	 * @return sql_parser instance
	 */
	public static function parse_string($sqlQuery) {
		// returns a sql_parser object
		if (! isset($this)) {
			$handle = new static();
		} else {
			$handle = $this;
		}

		// tokenize the query
		$tokens = static::tokenize($sqlQuery);
		$tokenCount = count($tokens);
		$queryParts = [];
		$section = $tokens[0];

		// parse the tokens
		for ($t = 0; $t < $tokenCount; $t ++) {

			if (in_array($tokens[$t], static::$startenclosure)) {

				$sub = $handle->readsub($tokens, $t);
				$handle->query[$section] .= $sub;

			} else {

				if (in_array(strtolower($tokens[$t]), static::$querysections) && ! isset($handle->query[$tokens[$t]])) {
					$section = strtolower($tokens[$t]);
				}

				// rebuild the query in sections
				if (! isset($handle->query[$section])) $handle->query[$section] = '';
				$handle->query[$section] .= $tokens[$t];
			}
		}

		return $handle;
	}

	/**
	 * Parses a section of a query
	 *
	 * Usually a sub-query or where clause.
	 *
	 * @param array $tokens
	 * @param int $position
	 * @return string section
	 */
	private function readsub($tokens, &$position) {
		$sub = $tokens[$position];
		$tokenCount = count($tokens);
		$position ++;
		while ( ! in_array($tokens[$position], static::$endenclosure) && $position < $tokenCount ) {
			if (in_array($tokens[$position], static::$startenclosure)) {
				$sub .= $this->readsub($tokens, $position);
			} else {
				$sub .= $tokens[$position];
			}
			$position ++;
		}
		$sub .= $tokens[$position];
		return $sub;
	}

	/**
	 * Returns manipulated sql to get the number of rows in the query.
	 * Doesn't work on grouped and other fun stuff like that.
	 * @return string sql
	 */
	public function get_count_query() {
		$this->query['select'] = 'SELECT COUNT(1) AS `count` ';
		unset($this->query['limit']);
		return implode('', $this->query);
	}

	/**
	 * Returns manipulated sql to get the unlimited number of rows in the query.
	 *
	 * @return string sql
	 */
	public function get_limited_count_query() {
		$this->query['select'] = 'SELECT COUNT(1) AS `count` ';
		return implode('', $this->query);
	}

	/**
	 * Returns the select section of the query.
	 *
	 * @return string sql
	 */
	public function get_select_statement() {
		return $this->query['select'];
	}

	/**
	 * Returns the from section of the query.
	 *
	 * @return string sql
	 */
	public function get_from_statement() {
		return $this->query['from'];
	}

	/**
	 * Returns the where section of the query.
	 *
	 * @return string sql
	 */
	public function get_where_statement() {
		return $this->query['where'];
	}

	/**
	 * Returns the limit section of the query.
	 *
	 * @return string sql
	 */
	public function get_limit_statement() {
		return $this->query['limit'];
	}

	/**
	 * Returns the where section of the query.
	 *
	 * @return string sql
	 */
	public function get($which) {
		if (! isset($this->query[$which])) return false;
		return $this->query[$which];
	}

	/**
	 * Returns the where section of the query.
	 *
	 * @return string sql
	 */
	public function get_array() {
		return $this->query;
	}

}
