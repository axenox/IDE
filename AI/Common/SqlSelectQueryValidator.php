<?php
namespace axenox\IDE\AI\Common;

/**
 * Conservatively validates that SQL text contains read-oriented SELECT queries.
 */
class SqlSelectQueryValidator
{
    private const FORBIDDEN_KEYWORDS = [
        'ALTER', 'ANALYZE', 'ATTACH', 'CALL', 'COMMENT', 'COMMIT', 'COPY', 'CREATE',
        'DEALLOCATE', 'DELETE', 'DETACH', 'DISCARD', 'DO', 'DROP', 'EXEC', 'EXECUTE',
        'GRANT', 'INSERT', 'INTO', 'LOAD', 'LOCK', 'MERGE', 'PRAGMA', 'PREPARE',
        'REINDEX', 'RELEASE', 'RENAME', 'REPLACE', 'RESET', 'REVOKE', 'ROLLBACK',
        'SAVEPOINT', 'SET', 'TRUNCATE', 'UNLOCK', 'UPDATE', 'UPSERT', 'VACUUM'
    ];

    /**
     * Returns an error if the SQL does not contain the allowed number of read-oriented queries.
     *
     * @param string $sql
     * @param bool $allowMultipleQueries
     * @return string|null
     */
    public static function validate(string $sql, bool $allowMultipleQueries = false) : ?string
    {
        $statements = self::splitStatements($sql);
        if ($statements === null) {
            return 'The SQL query contains an unterminated string, quoted identifier or comment.';
        }
        if ($statements === []) {
            return 'The SQL query must not be empty.';
        }
        if (! $allowMultipleQueries && count($statements) > 1) {
            return 'Only one SQL query is allowed.';
        }

        foreach ($statements as $statement) {
            $validationError = self::validateStatement(self::stripLiteralsAndComments($statement));
            if ($validationError !== null) {
                return $validationError;
            }
        }

        return null;
    }

    /**
     * Splits SQL at statement separators outside literals, quoted identifiers and comments.
     *
     * @param string $sql
     * @return string[]|null
     */
    public static function splitStatements(string $sql) : ?array
    {
        $sqlWithoutLiterals = self::stripLiteralsAndComments($sql);
        if ($sqlWithoutLiterals === null) {
            return null;
        }

        $statements = [];
        $statementStart = 0;
        $length = strlen($sqlWithoutLiterals);
        for ($position = 0; $position < $length; $position++) {
            if ($sqlWithoutLiterals[$position] !== ';') {
                continue;
            }
            if (trim(substr($sqlWithoutLiterals, $statementStart, $position - $statementStart)) !== '') {
                $statements[] = trim(substr($sql, $statementStart, $position - $statementStart));
            }
            $statementStart = $position + 1;
        }
        if (trim(substr($sqlWithoutLiterals, $statementStart)) !== '') {
            $statements[] = trim(substr($sql, $statementStart));
        }

        return $statements;
    }

    /**
     * Returns an error if a single SQL statement is not read-oriented.
     *
     * @param string $statement
     * @return string|null
     */
    private static function validateStatement(string $statement) : ?string
    {
        preg_match_all('/[A-Z_][A-Z0-9_$]*/i', $statement, $matches);
        $keywords = array_map('strtoupper', $matches[0]);
        $firstKeyword = $keywords[0] ?? null;
        if ($firstKeyword !== 'SELECT' && $firstKeyword !== 'WITH') {
            return 'Only SELECT queries and SELECT queries beginning with WITH are allowed.';
        }

        $forbiddenKeywords = array_intersect($keywords, self::FORBIDDEN_KEYWORDS);
        if ($forbiddenKeywords !== []) {
            return 'The SQL query contains a keyword that is not allowed in read-only queries: ' . reset($forbiddenKeywords) . '.';
        }

        if (! in_array('SELECT', $keywords, true)) {
            return 'A WITH query must contain a SELECT statement.';
        }
        if (preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $statement)) {
            return 'Row-locking SELECT queries are not allowed.';
        }

        return null;
    }

    /**
     * Replaces SQL literals, quoted identifiers and comments while preserving string length.
     *
     * @param string $sql
     * @return string|null
     */
    private static function stripLiteralsAndComments(string $sql) : ?string
    {
        $result = '';
        $length = strlen($sql);
        for ($position = 0; $position < $length;) {
            $tokenStart = $position;
            $character = $sql[$position];
            $nextCharacter = $sql[$position + 1] ?? '';

            if (($character === '-' && $nextCharacter === '-') || $character === '#') {
                $lineEnd = strpos($sql, "\n", $position + 1);
                $position = $lineEnd === false ? $length : $lineEnd;
                $result .= str_repeat(' ', $position - $tokenStart);
                continue;
            }
            if ($character === '/' && $nextCharacter === '*') {
                $commentEnd = strpos($sql, '*/', $position + 2);
                if ($commentEnd === false) {
                    return null;
                }
                $isExecutableComment = ($sql[$position + 2] ?? '') === '!';
                $position = $commentEnd + 2;
                if ($isExecutableComment) {
                    $result .= 'EXEC' . str_repeat(' ', $position - $tokenStart - 4);
                } else {
                    $result .= str_repeat(' ', $position - $tokenStart);
                }
                continue;
            }
            if ($character === '$' && preg_match('/\G(\$[A-Z_][A-Z0-9_]*\$|\$\$)/i', $sql, $tagMatch, 0, $position)) {
                $tag = $tagMatch[0];
                $literalEnd = strpos($sql, $tag, $position + strlen($tag));
                if ($literalEnd === false) {
                    return null;
                }
                $position = $literalEnd + strlen($tag);
                $result .= str_repeat(' ', $position - $tokenStart);
                continue;
            }
            if ($character === '[') {
                $identifierEnd = strpos($sql, ']', $position + 1);
                if ($identifierEnd === false) {
                    return null;
                }
                $position = $identifierEnd + 1;
                $result .= str_repeat(' ', $position - $tokenStart);
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $position++;
                $closed = false;
                while ($position < $length) {
                    if ($sql[$position] === '\\' && $position + 1 < $length) {
                        $position += 2;
                        continue;
                    }
                    if ($sql[$position] === $quote) {
                        if (($sql[$position + 1] ?? '') === $quote) {
                            $position += 2;
                            continue;
                        }
                        $position++;
                        $closed = true;
                        break;
                    }
                    $position++;
                }
                if (! $closed) {
                    return null;
                }
                $result .= str_repeat(' ', $position - $tokenStart);
                continue;
            }

            $result .= $character;
            $position++;
        }

        return $result;
    }
}