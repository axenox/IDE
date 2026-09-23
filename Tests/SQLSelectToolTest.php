<?php
require_once __DIR__ . '/../AI/Common/SqlSelectQueryValidator.php';

use axenox\IDE\AI\Common\SqlSelectQueryValidator;

$cases = [
    ['SELECT * FROM users', false, false],
    ['WITH results AS (SELECT 1) SELECT * FROM results;', false, false],
    ["SELECT 'DELETE; UPDATE', \"INTO\" FROM users", false, false],
    ['SELECT $$DELETE; UPDATE$$', false, false],
    ["SELECT ';' AS separator; SELECT 2", true, false],
    ['SELECT 1; SELECT 2', false, true],
    ['SELECT 1; SELECT 2', true, false],
    ['SELECT 1; DELETE FROM users', true, true],
    ['WITH deleted AS (DELETE FROM users RETURNING *) SELECT * FROM deleted', false, true],
    ['SELECT * INTO users_backup FROM users', false, true],
    ['SELECT * FROM users FOR UPDATE', false, true],
    ["SELECT 1 /*! INTO OUTFILE '/tmp/result' */", false, true],
    ['UPDATE users SET active = 0', false, true],
    ['/* unterminated comment', false, true],
];

foreach ($cases as [$sql, $allowMultipleQueries, $expectsError]) {
    $error = SqlSelectQueryValidator::validate($sql, $allowMultipleQueries);
    if (($error !== null) !== $expectsError) {
        fwrite(STDERR, 'Unexpected validation result for: ' . $sql . PHP_EOL);
        fwrite(STDERR, 'Error: ' . ($error ?? 'none') . PHP_EOL);
        exit(1);
    }
}

$splitStatements = SqlSelectQueryValidator::splitStatements("SELECT ';' AS separator; SELECT 2;");
if ($splitStatements !== ["SELECT ';' AS separator", 'SELECT 2']) {
    fwrite(STDERR, 'SQL statements were not split at the expected separators.' . PHP_EOL);
    exit(1);
}

echo 'SQLSelectTool validation tests passed.' . PHP_EOL;