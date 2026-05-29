<?php
/**
 * Tenant-scoping database decorator
 *
 * Wraps OpenCart's DB object and rewrites SQL so that every statement against
 * a tenant-scoped table is constrained to the current tenant:
 *
 *   INSERT / REPLACE  -> a `tenant_id` value is added.
 *   UPDATE / DELETE   -> a `tenant_id = X` predicate is added to the WHERE.
 *   SELECT            -> the primary FROM table is filtered by `tenant_id`.
 *
 * The rewriter uses a small SQL-aware scanner (paren/quote/backtick aware)
 * rather than naive regex so that trailing GROUP BY / ORDER BY / LIMIT clauses
 * and sub-queries are not corrupted.
 *
 * IMPORTANT (read isolation): write isolation (INSERT/UPDATE/DELETE) and
 * single-table SELECTs are handled reliably. SELECTs with multiple joined
 * tenant tables are scoped on their PRIMARY table only; such queries should
 * be verified during the read-isolation hardening phase. Anything the scanner
 * cannot confidently rewrite is passed through unchanged and logged.
 */
class TenantDB {
	private $db;
	private $tenant_id;
	private $prefix;
	private $tables = array();
	private $core = array();
	private $auto = false;
	private $registry_table = '';
	private $log;

	/**
	 * @param object $db            underlying DB instance to delegate to
	 * @param int    $tenant_id     current tenant id (>0)
	 * @param array  $tenant_tables scoped table names WITHOUT prefix
	 * @param string $prefix        DB prefix (e.g. "oc_")
	 * @param object $log           optional Log instance for warnings
	 * @param array  $options       auto-isolation options:
	 *                                 'core_tables'    => string[] (no prefix)
	 *                                 'auto'           => bool
	 *                                 'registry_table' => string (prefixed)
	 */
	public function __construct($db, $tenant_id, array $tenant_tables, $prefix, $log = null, array $options = array()) {
		$this->db = $db;
		$this->tenant_id = (int)$tenant_id;
		$this->prefix = $prefix;
		$this->log = $log;

		foreach ($tenant_tables as $table) {
			$this->tables[strtolower($prefix . $table)] = true;
		}

		if (!empty($options['core_tables'])) {
			foreach ($options['core_tables'] as $table) {
				$this->core[strtolower($prefix . $table)] = true;
			}
		}

		$this->auto = !empty($options['auto']);
		$this->registry_table = isset($options['registry_table']) ? $options['registry_table'] : '';
	}

	/* ------------------------------------------------------------------ */
	/* DB interface (delegated)                                           */
	/* ------------------------------------------------------------------ */

	public function query($sql) {
		return $this->db->query($this->rewrite($sql));
	}

	public function escape($value) {
		return $this->db->escape($value);
	}

	public function countAffected() {
		return $this->db->countAffected();
	}

	public function getLastId() {
		return $this->db->getLastId();
	}

	public function connected() {
		return $this->db->connected();
	}

	/* ------------------------------------------------------------------ */
	/* Rewriting                                                          */
	/* ------------------------------------------------------------------ */

	private function isScoped($table) {
		return isset($this->tables[strtolower($table)]);
	}

	private function isCore($table) {
		return isset($this->core[strtolower($table)]);
	}

	/**
	 * Mark a table as tenant-scoped for the rest of this request and persist it
	 * to the registry table so future requests scope it too. The registry table
	 * itself is never scoped, so this is written via the underlying connection.
	 */
	private function registerTable($prefixed_table) {
		$this->tables[strtolower($prefixed_table)] = true;

		if (!$this->registry_table) {
			return;
		}

		$bare = $prefixed_table;
		if ($this->prefix !== '' && stripos($prefixed_table, $this->prefix) === 0) {
			$bare = substr($prefixed_table, strlen($this->prefix));
		}

		try {
			$this->db->query(
				"INSERT IGNORE INTO `" . $this->registry_table . "` SET `name` = '" . $this->db->escape($bare) . "'"
			);
		} catch (\Exception $e) {
			// Registry unavailable: isolation still applies for this request.
			$this->warn('could not persist auto-scoped table ' . $bare, $e->getMessage());
		}
	}

	private function warn($message, $sql) {
		if ($this->log) {
			$this->log->write('Multi-Tenant: ' . $message . ' | ' . $sql);
		}
	}

	private function rewrite($sql) {
		// Platform context (no tenant): never rewrite.
		if ($this->tenant_id <= 0) {
			return $sql;
		}

		$head = ltrim($sql);

		if (preg_match('/^(INSERT(?:\s+IGNORE)?|REPLACE)\b/i', $head)) {
			return $this->rewriteInsert($sql);
		}
		if (preg_match('/^UPDATE\b/i', $head)) {
			return $this->rewriteUpdate($sql);
		}
		if (preg_match('/^DELETE\b/i', $head)) {
			return $this->rewriteDelete($sql);
		}
		if (preg_match('/^\(*\s*SELECT\b/i', $head)) {
			return $this->rewriteSelect($sql);
		}
		if (preg_match('/^CREATE\s+TABLE\b/i', $head)) {
			return $this->rewriteCreate($sql);
		}

		return $sql;
	}

	/**
	 * CREATE TABLE [IF NOT EXISTS] `table` ( ... )
	 *
	 * Injects a `tenant_id` column as the first column for scoped tables so
	 * that extension tables created on demand are tenant-isolated from birth.
	 */
	private function rewriteCreate($sql) {
		if (!preg_match('/^(\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?\s*\()/i', $sql, $m)) {
			return $sql;
		}
		$table = $m[2];

		$scoped = $this->isScoped($table);

		// Auto-isolation: a brand-new, non-core table belongs to whatever
		// extension is creating it -> scope it and remember it. ($table here is
		// already the full, prefixed name as written in the SQL.)
		if (!$scoped && $this->auto && !$this->isCore($table)) {
			$this->registerTable($table);
			$scoped = true;
		}

		if (!$scoped) {
			return $sql;
		}
		if (preg_match('/`tenant_id`/i', $sql)) {
			return $sql;
		}
		return $m[1] . '`tenant_id` int(11) NOT NULL DEFAULT ' . $this->tenant_id . ', ' . substr($sql, strlen($m[1]));
	}

	/**
	 * INSERT ... SET ... | INSERT ... (cols) VALUES (vals) | REPLACE ...
	 */
	private function rewriteInsert($sql) {
		// SET form (OpenCart's dominant style).
		if (preg_match('/^(\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?([a-z0-9_]+)`?\s+SET\s+)/i', $sql, $m)) {
			$table = $m[2];
			if (!$this->isScoped($table)) {
				return $sql;
			}
			if (preg_match('/`?tenant_id`?\s*=/i', $sql)) {
				return $sql; // already set explicitly
			}
			return $m[1] . '`tenant_id` = ' . $this->tenant_id . ', ' . substr($sql, strlen($m[1]));
		}

		// (cols) VALUES (vals) form.
		if (preg_match('/^(\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?([a-z0-9_]+)`?\s*)\((.*?)\)\s*VALUES\s*\((.*)\)\s*;?\s*$/is', $sql, $m)) {
			$table = $m[2];
			if (!$this->isScoped($table)) {
				return $sql;
			}
			$columns = $m[3];
			$values = $m[4];

			if (preg_match('/`?tenant_id`?/i', $columns)) {
				return $sql;
			}
			// Multiple value tuples cannot be safely split here.
			if (strpos($values, '),(') !== false || preg_match('/\)\s*,\s*\(/', $values)) {
				$this->warn('multi-row INSERT into scoped table not rewritten', $sql);
				return $sql;
			}

			return $m[1] . '(`tenant_id`, ' . $columns . ') VALUES (' . $this->tenant_id . ', ' . $values . ')';
		}

		$this->warn('unrecognised INSERT not rewritten', $sql);
		return $sql;
	}

	/**
	 * UPDATE `table` SET ... [WHERE ...]
	 */
	private function rewriteUpdate($sql) {
		if (!preg_match('/^\s*UPDATE\s+`?([a-z0-9_]+)`?\s+SET\b/i', $sql, $m)) {
			return $sql;
		}
		$table = $m[1];
		if (!$this->isScoped($table)) {
			return $sql;
		}
		return $this->injectWhere($sql, '`' . $table . '`.`tenant_id` = ' . $this->tenant_id, false);
	}

	/**
	 * DELETE FROM `table` [WHERE ...]
	 */
	private function rewriteDelete($sql) {
		if (!preg_match('/^\s*DELETE\s+FROM\s+`?([a-z0-9_]+)`?/i', $sql, $m)) {
			return $sql;
		}
		$table = $m[1];
		if (!$this->isScoped($table)) {
			return $sql;
		}
		// DELETE can carry an alias only in multi-table form; for the common
		// single-table form a bare column reference is correct.
		return $this->injectWhere($sql, '`tenant_id` = ' . $this->tenant_id, false);
	}

	/**
	 * SELECT ... FROM `table` [alias] [JOIN ...] ...
	 *
	 * Scopes every tenant-scoped table in the query:
	 *   - each scoped table joined with `JOIN ... ON` gets the tenant predicate
	 *     appended to its ON condition (correct for LEFT/INNER join semantics);
	 *   - the primary FROM table gets the predicate added to the WHERE clause.
	 */
	private function rewriteSelect($sql) {
		// 1. Scope joined tenant tables inside their ON clauses first.
		$sql = $this->scopeJoins($sql);

		// 2. Scope the primary FROM table via the WHERE clause.
		$from = $this->scanKeyword($sql, array('FROM'), 0);
		if ($from === false) {
			return $sql;
		}

		$after = substr($sql, $from + 4);
		if (!preg_match('/^\s+`?([a-z0-9_]+)`?(?:\s+(?:AS\s+)?(?!WHERE|JOIN|INNER|LEFT|RIGHT|CROSS|STRAIGHT_JOIN|ON|USING|GROUP|ORDER|LIMIT|HAVING|UNION)`?([a-z0-9_]+)`?)?/i', $after, $m)) {
			return $sql;
		}

		$table = $m[1];
		if (!$this->isScoped($table)) {
			// Primary table is global; nothing to scope on the WHERE.
			return $sql;
		}

		$ref = !empty($m[2]) ? '`' . $m[2] . '`' : '`' . $table . '`';

		return $this->injectWhere($sql, $ref . '.`tenant_id` = ' . $this->tenant_id, true);
	}

	/**
	 * Append a tenant predicate to the ON condition of every JOIN whose table
	 * is tenant-scoped. Edits are collected and applied right-to-left so byte
	 * offsets stay valid.
	 */
	private function scopeJoins($sql) {
		$boundaries = array('JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'STRAIGHT_JOIN', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT', 'UNION');

		$edits = array();

		foreach ($this->scanAllKeywords($sql, array('JOIN')) as $jpos) {
			$tableOffset = $jpos + 4; // past "JOIN"
			$segment = substr($sql, $tableOffset);

			if (!preg_match('/^\s+`?([a-z0-9_]+)`?(?:\s+(?:AS\s+)?(?!ON|USING|JOIN|INNER|LEFT|RIGHT|CROSS|STRAIGHT_JOIN|WHERE)`?([a-z0-9_]+)`?)?/i', $segment, $m)) {
				continue;
			}

			$table = $m[1];
			if (!$this->isScoped($table)) {
				continue;
			}

			// Find this join's ON clause.
			$onPos = $this->scanKeyword($sql, array('ON'), $tableOffset + strlen($m[0]));
			if ($onPos === false) {
				// USING(...) or comma join: cannot safely place the predicate.
				$this->warn('scoped JOIN without ON not rewritten', $sql);
				continue;
			}
			// The ON must belong to THIS join (no other boundary in between).
			$nextBoundary = $this->scanKeyword($sql, $boundaries, $tableOffset + strlen($m[0]));
			if ($nextBoundary !== false && $nextBoundary < $onPos) {
				continue;
			}

			$condStart = $onPos + 2;
			$condEnd = $this->scanKeyword($sql, $boundaries, $condStart);
			if ($condEnd === false) {
				$condEnd = strlen(rtrim(rtrim($sql), ';'));
			}

			$ref = !empty($m[2]) ? '`' . $m[2] . '`' : '`' . $table . '`';
			$condition = substr($sql, $condStart, $condEnd - $condStart);

			$replacement = ' (' . trim($condition) . ') AND ' . $ref . '.`tenant_id` = ' . $this->tenant_id . ' ';
			$edits[] = array($condStart, $condEnd, $replacement);
		}

		// Apply right-to-left.
		usort($edits, function ($a, $b) { return $b[0] - $a[0]; });
		foreach ($edits as $e) {
			$sql = substr($sql, 0, $e[0]) . $e[2] . substr($sql, $e[1]);
		}

		return $sql;
	}

	/**
	 * Add a predicate to a statement's WHERE clause (creating one if needed),
	 * keeping any trailing GROUP BY / HAVING / ORDER BY / LIMIT intact and the
	 * original condition wrapped in parentheses.
	 *
	 * @param bool $select whether this is a SELECT (affects where a missing
	 *                     WHERE is inserted relative to GROUP/ORDER/LIMIT)
	 */
	private function injectWhere($sql, $predicate, $select) {
		$where = $this->scanKeyword($sql, array('WHERE'), 0);

		$tailKeywords = array('GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT', 'PROCEDURE', 'FOR UPDATE', 'LOCK IN');

		if ($where === false) {
			// No WHERE: insert one before the first trailing clause (or at end).
			$tail = $this->scanKeyword($sql, $tailKeywords, 0);
			if ($tail === false) {
				return rtrim(rtrim($sql), ';') . ' WHERE ' . $predicate;
			}
			return substr($sql, 0, $tail) . 'WHERE ' . $predicate . ' ';
				// note: leaves the trailing clause that begins at $tail intact
		}

		// Existing WHERE: wrap the original condition and prepend the predicate.
		$condStart = $where + 5; // past "WHERE"
		$tail = $this->scanKeyword($sql, $tailKeywords, $condStart);
		if ($tail === false) {
			$tail = strlen(rtrim(rtrim($sql), ';'));
		}

		$before = substr($sql, 0, $condStart);
		$condition = substr($sql, $condStart, $tail - $condStart);
		$rest = substr($sql, $tail);

		return $before . ' ' . $predicate . ' AND (' . trim($condition) . ') ' . $rest;
	}

	/**
	 * Find the byte offset of the first of the given keywords that appears at
	 * parenthesis depth 0 and outside of string/identifier quoting, searching
	 * from $from. Keywords are matched on word boundaries, case-insensitively.
	 * Multi-word keywords (e.g. "ORDER BY") allow variable internal whitespace.
	 *
	 * @return int|false byte offset, or false if not found
	 */
	private function scanKeyword($sql, array $keywords, $from) {
		$len = strlen($sql);
		$depth = 0;
		$quote = '';

		// Pre-split multi-word keywords for matching.
		$specs = array();
		foreach ($keywords as $kw) {
			$specs[] = preg_split('/\s+/', strtoupper($kw));
		}

		for ($i = $from; $i < $len; $i++) {
			$ch = $sql[$i];

			if ($quote !== '') {
				if ($ch === '\\' && $quote !== '`') {
					$i++; // skip escaped char inside '...' / "..."
					continue;
				}
				if ($ch === $quote) {
					$quote = '';
				}
				continue;
			}

			if ($ch === '\'' || $ch === '"' || $ch === '`') {
				$quote = $ch;
				continue;
			}
			if ($ch === '(') { $depth++; continue; }
			if ($ch === ')') { if ($depth > 0) $depth--; continue; }

			if ($depth !== 0) {
				continue;
			}

			// Only attempt a keyword match at a word boundary.
			if ($i > 0) {
				$prev = $sql[$i - 1];
				if (ctype_alnum($prev) || $prev === '_') {
					continue;
				}
			}

			foreach ($specs as $words) {
				$pos = $this->matchKeywordSequence($sql, $i, $words);
				if ($pos !== false) {
					return $i;
				}
			}
		}

		return false;
	}

	/**
	 * Like scanKeyword(), but returns every matching top-level offset.
	 *
	 * @return int[] list of byte offsets (possibly empty)
	 */
	private function scanAllKeywords($sql, array $keywords) {
		$positions = array();
		$from = 0;
		while (($pos = $this->scanKeyword($sql, $keywords, $from)) !== false) {
			$positions[] = $pos;
			$from = $pos + 1;
		}
		return $positions;
	}

	/**
	 * Try to match a sequence of words (with arbitrary whitespace between them)
	 * starting at $i. Returns the end offset on success or false.
	 */
	private function matchKeywordSequence($sql, $i, array $words) {
		$len = strlen($sql);
		$p = $i;

		foreach ($words as $idx => $word) {
			$wlen = strlen($word);
			if ($p + $wlen > $len) {
				return false;
			}
			if (strcasecmp(substr($sql, $p, $wlen), $word) !== 0) {
				return false;
			}
			$p += $wlen;

			$last = ($idx === count($words) - 1);
			if ($last) {
				// Must end on a word boundary.
				if ($p < $len) {
					$nx = $sql[$p];
					if (ctype_alnum($nx) || $nx === '_') {
						return false;
					}
				}
				return $p;
			}

			// Require whitespace before the next word.
			$ws = 0;
			while ($p < $len && ctype_space($sql[$p])) { $p++; $ws++; }
			if ($ws === 0) {
				return false;
			}
		}

		return false;
	}
}
