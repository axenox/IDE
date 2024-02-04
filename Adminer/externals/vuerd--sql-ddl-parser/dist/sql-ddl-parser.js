/*!
 * @vuerd/sql-ddl-parser
 * @version 0.2.2 | Wed Sep 08 2021
 * @author dineug <dineug2@gmail.com>
 * @license MIT
 */
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
    typeof define === 'function' && define.amd ? define(['exports'], factory) :
    (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global['@vuerd/sql-ddl-parser'] = {}));
}(this, (function (exports) { 'use strict';

    /*! *****************************************************************************
    Copyright (c) Microsoft Corporation.

    Permission to use, copy, modify, and/or distribute this software for any
    purpose with or without fee is hereby granted.

    THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
    REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
    INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM
    LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR
    OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
    PERFORMANCE OF THIS SOFTWARE.
    ***************************************************************************** */

    function __spreadArray(to, from, pack) {
        if (pack || arguments.length === 2) for (var i = 0, l = from.length, ar; i < l; i++) {
            if (ar || !(i in from)) {
                if (!ar) ar = Array.prototype.slice.call(from, 0, i);
                ar[i] = from[i];
            }
        }
        return to.concat(ar || Array.prototype.slice.call(from));
    }

    /**
     * https://mariadb.com/kb/en/data-types/
     */
    var MariaDBTypes = [
        'BIGINT',
        'BINARY',
        'BIT',
        'BLOB',
        'BOOL',
        'BOOLEAN',
        'CHAR',
        'DATE',
        'DATETIME',
        'DEC',
        'DECIMAL',
        'DOUBLE PRECISION',
        'DOUBLE',
        'ENUM',
        'FIXED',
        'FLOAT',
        'GEOMETRY',
        'GEOMETRYCOLLECTION',
        'INT',
        'INTEGER',
        'JSON',
        'LINESTRING',
        'LONGBLOB',
        'LONGTEXT',
        'MEDIUMBLOB',
        'MEDIUMINT',
        'MEDIUMTEXT',
        'MULTILINESTRING',
        'MULTIPOINT',
        'MULTIPOLYGON',
        'NUMERIC',
        'POINT',
        'POLYGON',
        'REAL',
        'SET',
        'SMALLINT',
        'TEXT',
        'TIME',
        'TIMESTAMP',
        'TINYBLOB',
        'TINYINT',
        'TINYTEXT',
        'VARBINARY',
        'VARCHAR',
        'YEAR',
    ];

    /**
     * https://docs.microsoft.com/ko-kr/sql/t-sql/data-types/data-types-transact-sql?view=sql-server-ver15
     */
    var MSSQLTypes = [
        'BIGINT',
        'BINARY',
        'BIT',
        'CHAR',
        'DATE',
        'DATETIME',
        'DATETIME2',
        'DATETIMEOFFSET',
        'DECIMAL',
        'FLOAT',
        'GEOGRAPHY',
        'GEOMETRY',
        'IMAGE',
        'INT',
        'MONEY',
        'NCHAR',
        'NTEXT',
        'NUMERIC',
        'NVARCHAR',
        'REAL',
        'SMALLDATETIME',
        'SMALLINT',
        'SMALLMONEY',
        'SQL_VARIANT',
        'TEXT',
        'TIME',
        'TINYINT',
        'UNIQUEIDENTIFIER',
        'VARBINARY',
        'VARCHAR',
        'XML',
    ];

    /**
     * https://dev.mysql.com/doc/refman/8.0/en/data-types.html
     */
    var MySQLTypes = [
        'BIGINT',
        'BINARY',
        'BIT',
        'BLOB',
        'BOOL',
        'BOOLEAN',
        'CHAR',
        'DATE',
        'DATETIME',
        'DEC',
        'DECIMAL',
        'DOUBLE PRECISION',
        'DOUBLE',
        'ENUM',
        'FLOAT',
        'GEOMETRY',
        'GEOMETRYCOLLECTION',
        'INT',
        'INTEGER',
        'JSON',
        'LINESTRING',
        'LONGBLOB',
        'LONGTEXT',
        'MEDIUMBLOB',
        'MEDIUMINT',
        'MEDIUMTEXT',
        'MULTILINESTRING',
        'MULTIPOINT',
        'MULTIPOLYGON',
        'NUMERIC',
        'POINT',
        'POLYGON',
        'SET',
        'SMALLINT',
        'TEXT',
        'TIME',
        'TIMESTAMP',
        'TINYBLOB',
        'TINYINT',
        'TINYTEXT',
        'VARBINARY',
        'VARCHAR',
        'YEAR',
    ];

    /**
     * https://docs.oracle.com/cd/B28359_01/server.111/b28318/datatype.htm#CNCPT012
     */
    var OracleTypes = [
        'BFILE',
        'BINARY_DOUBLE',
        'BINARY_FLOAT',
        'BLOB',
        'CHAR',
        'CLOB',
        'DATE',
        'DATETIME',
        'LONG RAW',
        'LONG',
        'NCHAR',
        'NCLOB',
        'NUMBER',
        'NVARCHAR2',
        'RAW',
        'TIMESTAMP WITH LOCAL TIME ZONE',
        'TIMESTAMP WITH TIME ZONE',
        'TIMESTAMP',
        'UriType',
        'VARCHAR',
        'VARCHAR2',
        'XMLType',
    ];

    /**
     * https://www.postgresql.org/docs/current/datatype.html
     */
    var PostgreSQLTypes = [
        'BIGINT',
        'BIGSERIAL',
        'BIT VARYING',
        'BIT',
        'BOOL',
        'BOOLEAN',
        'BOX',
        'BYTEA',
        'CHAR',
        'CHARACTER VARYING',
        'CHARACTER',
        'CIDR',
        'CIRCLE',
        'DATE',
        'DECIMAL',
        'DOUBLE PRECISION',
        'FLOAT4',
        'FLOAT8',
        'INET',
        'INT',
        'INT2',
        'INT4',
        'INT8',
        'INTEGER',
        'INTERVAL',
        'JSON',
        'JSONB',
        'LINE',
        'LSEG',
        'MACADDR',
        'MACADDR8',
        'MONEY',
        'NUMERIC',
        'PATH',
        'PG_LSN',
        'POINT',
        'POLYGON',
        'REAL',
        'SERIAL',
        'SERIAL2',
        'SERIAL4',
        'SERIAL8',
        'SMALLINT',
        'SMALLSERIAL',
        'TEXT',
        'TIME WITH',
        'TIME',
        'TIMESTAMP WITH',
        'TIMESTAMP',
        'TIMESTAMPTZ',
        'TIMETZ',
        'TSQUERY',
        'TSVECTOR',
        'TXID_SNAPSHOT',
        'UUID',
        'VARBIT',
        'VARCHAR',
        'XML',
    ];

    /**
     * https://www.sqlite.org/datatype3.html
     */
    var SQLiteTypes = [
        'BLOB',
        'INTEGER',
        'NUMERIC',
        'REAL',
        'TEXT',
    ];

    var MariaDBKeywords = [];

    var MSSQLKeywords = [];

    var MySQLKeywords = [
        'ADD',
        'ALTER',
        'AND',
        'AS',
        'ASC',
        'AUTO_INCREMENT',
        'BY',
        'CASCADE',
        'COLUMN',
        'COMMENT',
        'CONSTRAINT',
        'CREATE',
        'DATABASE',
        'DEFAULT',
        'DELETE',
        'DESC',
        'DROP',
        'EXISTS',
        'FOREIGN',
        'IF',
        'INDEX',
        'KEY',
        'LIKE',
        'NOT',
        'NULL',
        'ON',
        'OR',
        'PRIMARY',
        'REFERENCES',
        'RENAME',
        'SCHEMA',
        'SELECT',
        'SET',
        'TABLE',
        'UNION',
        'UNIQUE',
        'USE',
    ];

    var OracleKeywords = [];

    var PostgreSQLKeywords = [];

    var SQLiteKeywords = ['AUTOINCREMENT'];

    var tokenMatch = {
        whiteSpace: /(?:\s+|#.*|-- +.*|\/\*(?:[\s\S])*?\*\/)+/,
        leftParen: '(',
        rightParen: ')',
        comma: ',',
        period: '.',
        equal: '=',
        semicolon: ';',
        doubleQuote: "\"",
        singleQuote: "'",
        backtick: '`',
        keywords: getKeywords(),
        // number, english, korean, chinese, japanese
        string: /[a-z0-9_\u3131-\u314E\u314F-\u3163\uAC00-\uD7A3\u3040-\u309F\u30A0-\u30FF\u3400-\u4DB5\u4E00-\u9FCC]/i,
        unknown: /.+/,
        dataTypes: getDataTypes(),
    };
    function getDataTypes() {
        var keywords = __spreadArray(__spreadArray(__spreadArray(__spreadArray(__spreadArray(__spreadArray([], MariaDBTypes), MSSQLTypes), MySQLTypes), OracleTypes), PostgreSQLTypes), SQLiteTypes);
        return Array.from(new Set(keywords.map(function (keyword) { return keyword.toUpperCase(); })));
    }
    function getKeywords() {
        var keywords = __spreadArray(__spreadArray(__spreadArray(__spreadArray(__spreadArray(__spreadArray(__spreadArray([], MariaDBKeywords), MSSQLKeywords), MySQLKeywords), OracleKeywords), PostgreSQLKeywords), SQLiteKeywords), getDataTypes());
        return Array.from(new Set(keywords.map(function (keyword) { return keyword.toUpperCase(); })));
    }
    function keywordEqual(token, value) {
        return (token.type === 'keyword' &&
            token.value.toUpperCase() === value.toUpperCase());
    }
    function isExtraString(token) {
        if (!token)
            return false;
        return (token.type === 'doubleQuoteString' ||
            token.type === 'singleQuoteString' ||
            token.type === 'backtickString');
    }
    function isStringKeyword(token) {
        if (!token)
            return false;
        var value = token.value.toUpperCase();
        return token.type === 'string' && tokenMatch.keywords.includes(value);
    }
    function isKeyword(token) {
        if (!token)
            return false;
        return token.type === 'keyword';
    }
    function isString(token) {
        if (!token)
            return false;
        return token.type === 'string';
    }
    function isPeriod(token) {
        if (!token)
            return false;
        return token.type === 'period';
    }
    function isLeftParen(token) {
        if (!token)
            return false;
        return token.type === 'leftParen';
    }
    function isRightParen(token) {
        if (!token)
            return false;
        return token.type === 'rightParen';
    }
    function isSemicolon(token) {
        if (!token)
            return false;
        return token.type === 'semicolon';
    }
    function isComma(token) {
        if (!token)
            return false;
        return token.type === 'comma';
    }
    function isCurrent(list, current) {
        return list.length > current;
    }
    function isNewStatement(token) {
        if (!token)
            return false;
        return (keywordEqual(token, 'CREATE') ||
            keywordEqual(token, 'ALTER') ||
            keywordEqual(token, 'DROP') ||
            keywordEqual(token, 'USE') ||
            keywordEqual(token, 'RENAME') ||
            keywordEqual(token, 'DELETE') ||
            keywordEqual(token, 'SELECT'));
    }
    function isCreateTable(tokens) {
        return (tokens.length > 2 &&
            keywordEqual(tokens[0], 'CREATE') &&
            keywordEqual(tokens[1], 'TABLE'));
    }
    function isCreateIndex(tokens) {
        return (tokens.length > 2 &&
            keywordEqual(tokens[0], 'CREATE') &&
            keywordEqual(tokens[1], 'INDEX'));
    }
    function isCreateUniqueIndex(tokens) {
        return (tokens.length > 3 &&
            keywordEqual(tokens[0], 'CREATE') &&
            keywordEqual(tokens[1], 'UNIQUE') &&
            keywordEqual(tokens[2], 'INDEX'));
    }
    function isAlterTableAddPrimaryKey(tokens) {
        return ((tokens.length > 6 &&
            keywordEqual(tokens[0], 'ALTER') &&
            keywordEqual(tokens[1], 'TABLE') &&
            keywordEqual(tokens[3], 'ADD') &&
            keywordEqual(tokens[4], 'PRIMARY') &&
            keywordEqual(tokens[5], 'KEY')) ||
            (tokens.length > 8 &&
                keywordEqual(tokens[0], 'ALTER') &&
                keywordEqual(tokens[1], 'TABLE') &&
                keywordEqual(tokens[3], 'ADD') &&
                keywordEqual(tokens[4], 'CONSTRAINT') &&
                keywordEqual(tokens[6], 'PRIMARY') &&
                keywordEqual(tokens[7], 'KEY')));
    }
    function isAlterTableAddForeignKey(tokens) {
        return ((tokens.length > 6 &&
            keywordEqual(tokens[0], 'ALTER') &&
            keywordEqual(tokens[1], 'TABLE') &&
            keywordEqual(tokens[3], 'ADD') &&
            keywordEqual(tokens[4], 'FOREIGN') &&
            keywordEqual(tokens[5], 'KEY')) ||
            (tokens.length > 8 &&
                keywordEqual(tokens[0], 'ALTER') &&
                keywordEqual(tokens[1], 'TABLE') &&
                keywordEqual(tokens[3], 'ADD') &&
                keywordEqual(tokens[4], 'CONSTRAINT') &&
                keywordEqual(tokens[6], 'FOREIGN') &&
                keywordEqual(tokens[7], 'KEY')));
    }
    function isAlterTableAddUnique(tokens) {
        return ((tokens.length > 5 &&
            keywordEqual(tokens[0], 'ALTER') &&
            keywordEqual(tokens[1], 'TABLE') &&
            keywordEqual(tokens[3], 'ADD') &&
            keywordEqual(tokens[4], 'UNIQUE')) ||
            (tokens.length > 7 &&
                keywordEqual(tokens[0], 'ALTER') &&
                keywordEqual(tokens[1], 'TABLE') &&
                keywordEqual(tokens[3], 'ADD') &&
                keywordEqual(tokens[4], 'CONSTRAINT') &&
                keywordEqual(tokens[6], 'UNIQUE')));
    }
    function isDataType(token) {
        if (!token)
            return false;
        var value = token.value.toUpperCase();
        return token.type === 'keyword' && tokenMatch.dataTypes.includes(value);
    }
    function isNot(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'NOT');
    }
    function isNull(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'NULL');
    }
    function isDefault(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'DEFAULT');
    }
    function isComment(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'COMMENT');
    }
    function isAutoIncrement(token) {
        if (!token)
            return false;
        return (keywordEqual(token, 'AUTO_INCREMENT') ||
            keywordEqual(token, 'AUTOINCREMENT'));
    }
    function isPrimary(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'PRIMARY');
    }
    function isKey(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'KEY');
    }
    function isUnique(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'UNIQUE');
    }
    function isConstraint(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'CONSTRAINT');
    }
    function isIndex(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'INDEX');
    }
    function isForeign(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'FOREIGN');
    }
    function isReferences(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'REFERENCES');
    }
    function isDESC(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'DESC');
    }
    function isOn(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'ON');
    }
    function isTable(token) {
        if (!token)
            return false;
        return keywordEqual(token, 'TABLE');
    }

    function createTable(tokens) {
        var current = { value: 0 };
        var ast = {
            type: 'create.table',
            name: '',
            comment: '',
            columns: [],
            indexes: [],
            foreignKeys: [],
        };
        while (isCurrent(tokens, current.value)) {
            var token = tokens[current.value];
            if (isLeftParen(token)) {
                current.value++;
                var _a = createTableColumns(tokens, current), columns = _a.columns, indexes = _a.indexes, foreignKeys = _a.foreignKeys;
                ast.columns = columns;
                ast.indexes = indexes;
                ast.foreignKeys = foreignKeys;
                continue;
            }
            if (isString(token) && !ast.name) {
                ast.name = token.value;
                token = tokens[++current.value];
                if (isPeriod(token)) {
                    token = tokens[++current.value];
                    if (isString(token)) {
                        ast.name = token.value;
                        current.value++;
                    }
                }
                continue;
            }
            if (isComment(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    ast.comment = token.value;
                    current.value++;
                }
                continue;
            }
            current.value++;
        }
        return ast;
    }
    function createTableColumns(tokens, current) {
        var columns = [];
        var indexes = [];
        var foreignKeys = [];
        var primaryKeyColumnNames = [];
        var uniqueColumnNames = [];
        var column = {
            name: '',
            dataType: '',
            default: '',
            comment: '',
            primaryKey: false,
            autoIncrement: false,
            unique: false,
            nullable: true,
        };
        while (isCurrent(tokens, current.value)) {
            var token = tokens[current.value];
            if (isString(token) && !column.name) {
                column.name = token.value;
                current.value++;
                continue;
            }
            if (isLeftParen(token)) {
                token = tokens[++current.value];
                while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                    token = tokens[++current.value];
                }
                current.value++;
                continue;
            }
            if (isConstraint(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    current.value++;
                }
                continue;
            }
            if (isPrimary(token)) {
                token = tokens[++current.value];
                if (isKey(token)) {
                    token = tokens[++current.value];
                    if (isLeftParen(token)) {
                        token = tokens[++current.value];
                        while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                            if (isString(token)) {
                                primaryKeyColumnNames.push(token.value.toUpperCase());
                            }
                            token = tokens[++current.value];
                        }
                        current.value++;
                    }
                    else {
                        column.primaryKey = true;
                    }
                }
                continue;
            }
            if (isForeign(token)) {
                var foreignKey = parserForeignKey(tokens, current);
                if (foreignKey) {
                    foreignKeys.push(foreignKey);
                }
                continue;
            }
            if (isIndex(token) || isKey(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    var name_1 = token.value;
                    var indexColumns = [];
                    token = tokens[++current.value];
                    if (isLeftParen(token)) {
                        token = tokens[++current.value];
                        var indexColumn = {
                            name: '',
                            sort: 'ASC',
                        };
                        while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                            if (isString(token)) {
                                indexColumn.name = token.value;
                            }
                            if (isDESC(token)) {
                                indexColumn.sort = 'DESC';
                            }
                            if (isComma(token)) {
                                indexColumns.push(indexColumn);
                                indexColumn = {
                                    name: '',
                                    sort: 'ASC',
                                };
                            }
                            token = tokens[++current.value];
                        }
                        if (!indexColumns.includes(indexColumn) && indexColumn.name !== '') {
                            indexColumns.push(indexColumn);
                        }
                        if (indexColumns.length) {
                            indexes.push({
                                name: name_1,
                                unique: false,
                                columns: indexColumns,
                            });
                        }
                        current.value++;
                    }
                }
                continue;
            }
            if (isUnique(token)) {
                token = tokens[++current.value];
                if (isKey(token)) {
                    token = tokens[++current.value];
                }
                if (isString(token)) {
                    token = tokens[++current.value];
                }
                if (isLeftParen(token)) {
                    token = tokens[++current.value];
                    while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                        if (isString(token)) {
                            uniqueColumnNames.push(token.value.toUpperCase());
                        }
                        token = tokens[++current.value];
                    }
                    current.value++;
                }
                else {
                    column.unique = true;
                }
                continue;
            }
            if (isNot(token)) {
                token = tokens[++current.value];
                if (isNull(token)) {
                    column.nullable = false;
                    current.value++;
                }
                continue;
            }
            if (isDefault(token)) {
                token = tokens[++current.value];
                if (isString(token) || isKeyword(token)) {
                    column.default = token.value;
                    current.value++;
                }
                continue;
            }
            if (isComment(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    column.comment = token.value;
                    current.value++;
                }
                continue;
            }
            if (isAutoIncrement(token)) {
                column.autoIncrement = true;
                current.value++;
                continue;
            }
            if (isDataType(token)) {
                var value = token.value;
                token = tokens[++current.value];
                if (isLeftParen(token)) {
                    value += '(';
                    token = tokens[++current.value];
                    while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                        value += token.value;
                        token = tokens[++current.value];
                    }
                    value += ')';
                    current.value++;
                }
                column.dataType = value;
                continue;
            }
            if (isComma(token)) {
                if (column.name || column.dataType) {
                    columns.push(column);
                }
                column = {
                    name: '',
                    dataType: '',
                    default: '',
                    comment: '',
                    primaryKey: false,
                    autoIncrement: false,
                    unique: false,
                    nullable: true,
                };
                current.value++;
                continue;
            }
            if (isRightParen(token)) {
                current.value++;
                break;
            }
            current.value++;
        }
        if (!columns.includes(column) && (column.name || column.dataType)) {
            columns.push(column);
        }
        columns.forEach(function (column) {
            if (primaryKeyColumnNames.includes(column.name.toUpperCase())) {
                column.primaryKey = true;
            }
            if (uniqueColumnNames.includes(column.name.toUpperCase())) {
                column.unique = true;
            }
        });
        return {
            columns: columns,
            indexes: indexes,
            foreignKeys: foreignKeys,
        };
    }
    function parserForeignKey(tokens, current) {
        var foreignKey = {
            columnNames: [],
            refTableName: '',
            refColumnNames: [],
        };
        var token = tokens[++current.value];
        if (isKey(token)) {
            token = tokens[++current.value];
            if (isLeftParen(token)) {
                token = tokens[++current.value];
                while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                    if (isString(token)) {
                        foreignKey.columnNames.push(token.value);
                    }
                    token = tokens[++current.value];
                }
                token = tokens[++current.value];
            }
            if (isReferences(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    foreignKey.refTableName = token.value;
                    token = tokens[++current.value];
                    if (isPeriod(token)) {
                        token = tokens[++current.value];
                        if (isString(token)) {
                            foreignKey.refTableName = token.value;
                            token = tokens[++current.value];
                        }
                    }
                    if (isLeftParen(token)) {
                        token = tokens[++current.value];
                        while (isCurrent(tokens, current.value) && !isRightParen(token)) {
                            if (isString(token)) {
                                foreignKey.refColumnNames.push(token.value);
                            }
                            token = tokens[++current.value];
                        }
                        token = tokens[++current.value];
                    }
                }
            }
            if (foreignKey.columnNames.length &&
                foreignKey.columnNames.length === foreignKey.refColumnNames.length) {
                return foreignKey;
            }
        }
        return null;
    }

    function alterTableAddForeignKey(tokens) {
        var current = { value: 0 };
        var ast = {
            type: 'alter.table.add.foreignKey',
            name: '',
            columnNames: [],
            refTableName: '',
            refColumnNames: [],
        };
        while (isCurrent(tokens, current.value)) {
            var token = tokens[current.value];
            if (isTable(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    ast.name = token.value;
                    token = tokens[++current.value];
                    if (isPeriod(token)) {
                        token = tokens[++current.value];
                        if (isString(token)) {
                            ast.name = token.value;
                            current.value++;
                        }
                    }
                }
                continue;
            }
            if (isConstraint(token)) {
                token = tokens[++current.value];
                if (isString(token)) {
                    current.value++;
                }
                continue;
            }
            if (isForeign(token)) {
                var foreignKey = parserForeignKey(tokens, current);
                if (foreignKey) {
                    ast.columnNames = foreignKey.columnNames;
                    ast.refTableName = foreignKey.refTableName;
                    ast.refColumnNames = foreignKey.refColumnNames;
                }
                continue;
            }
            current.value++;
        }
        return ast;
    }

    function alterTableAddPrimaryKey(tokens) {
        var current = 0;
        var ast = {
            type: 'alter.table.add.primaryKey',
            name: '',
            columnNames: [],
        };
        while (isCurrent(tokens, current)) {
            var token = tokens[current];
            if (isTable(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    ast.name = token.value;
                    token = tokens[++current];
                    if (isPeriod(token)) {
                        token = tokens[++current];
                        if (isString(token)) {
                            ast.name = token.value;
                            current++;
                        }
                    }
                }
                continue;
            }
            if (isConstraint(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    current++;
                }
                continue;
            }
            if (isPrimary(token)) {
                token = tokens[++current];
                if (isKey(token)) {
                    token = tokens[++current];
                    if (isLeftParen(token)) {
                        token = tokens[++current];
                        while (isCurrent(tokens, current) && !isRightParen(token)) {
                            if (isString(token)) {
                                ast.columnNames.push(token.value);
                            }
                            token = tokens[++current];
                        }
                        token = tokens[++current];
                    }
                }
                continue;
            }
            current++;
        }
        return ast;
    }

    function alterTableAddUnique(tokens) {
        var current = 0;
        var ast = {
            type: 'alter.table.add.unique',
            name: '',
            columnNames: [],
        };
        while (isCurrent(tokens, current)) {
            var token = tokens[current];
            if (isTable(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    ast.name = token.value;
                    token = tokens[++current];
                    if (isPeriod(token)) {
                        token = tokens[++current];
                        if (isString(token)) {
                            ast.name = token.value;
                            current++;
                        }
                    }
                }
                continue;
            }
            if (isConstraint(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    current++;
                }
                continue;
            }
            if (isUnique(token)) {
                token = tokens[++current];
                if (isLeftParen(token)) {
                    token = tokens[++current];
                    while (isCurrent(tokens, current) && !isRightParen(token)) {
                        if (isString(token)) {
                            ast.columnNames.push(token.value);
                        }
                        token = tokens[++current];
                    }
                    current++;
                }
                continue;
            }
            current++;
        }
        return ast;
    }

    function createIndex(tokens, unique) {
        if (unique === void 0) { unique = false; }
        var current = 0;
        var ast = {
            type: 'create.index',
            name: '',
            unique: unique,
            tableName: '',
            columns: [],
        };
        while (isCurrent(tokens, current)) {
            var token = tokens[current];
            if (isIndex(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    ast.name = token.value;
                }
                continue;
            }
            if (isOn(token)) {
                token = tokens[++current];
                if (isString(token)) {
                    ast.tableName = token.value;
                    token = tokens[++current];
                    if (isLeftParen(token)) {
                        token = tokens[++current];
                        var indexColumn = {
                            name: '',
                            sort: 'ASC',
                        };
                        while (isCurrent(tokens, current) && !isRightParen(token)) {
                            if (isString(token)) {
                                indexColumn.name = token.value;
                            }
                            if (isDESC(token)) {
                                indexColumn.sort = 'DESC';
                            }
                            if (isComma(token)) {
                                ast.columns.push(indexColumn);
                                indexColumn = {
                                    name: '',
                                    sort: 'ASC',
                                };
                            }
                            token = tokens[++current];
                        }
                        if (!ast.columns.includes(indexColumn) && indexColumn.name !== '') {
                            ast.columns.push(indexColumn);
                        }
                        current++;
                    }
                }
                continue;
            }
            current++;
        }
        return ast;
    }

    function createUniqueIndex(tokens) {
        return createIndex(tokens, true);
    }

    /**
     * https://github.com/jamiebuilds/the-super-tiny-compiler
     */
    function tokenizer(input) {
        var current = 0;
        var tokens = [];
        while (current < input.length) {
            var char = input[current];
            if (tokenMatch.whiteSpace.test(char)) {
                current++;
                continue;
            }
            if (char === tokenMatch.leftParen) {
                tokens.push({
                    type: 'leftParen',
                    value: '(',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.rightParen) {
                tokens.push({
                    type: 'rightParen',
                    value: ')',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.comma) {
                tokens.push({
                    type: 'comma',
                    value: ',',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.period) {
                tokens.push({
                    type: 'period',
                    value: '.',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.equal) {
                tokens.push({
                    type: 'equal',
                    value: '=',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.semicolon) {
                tokens.push({
                    type: 'semicolon',
                    value: ';',
                });
                current++;
                continue;
            }
            if (char === tokenMatch.doubleQuote) {
                var value = '';
                char = input[++current];
                while (char !== tokenMatch.doubleQuote) {
                    value += char;
                    char = input[++current];
                }
                char = input[++current];
                tokens.push({ type: 'doubleQuoteString', value: value });
                continue;
            }
            if (char === tokenMatch.singleQuote) {
                var value = '';
                char = input[++current];
                while (char !== tokenMatch.singleQuote) {
                    value += char;
                    char = input[++current];
                }
                char = input[++current];
                tokens.push({ type: 'singleQuoteString', value: value });
                continue;
            }
            if (char === tokenMatch.backtick) {
                var value = '';
                char = input[++current];
                while (char !== tokenMatch.backtick) {
                    value += char;
                    char = input[++current];
                }
                char = input[++current];
                tokens.push({ type: 'backtickString', value: value });
                continue;
            }
            if (tokenMatch.string.test(char)) {
                var value = '';
                while (tokenMatch.string.test(char)) {
                    value += char;
                    char = input[++current];
                }
                tokens.push({ type: 'string', value: value });
                continue;
            }
            if (tokenMatch.unknown.test(char)) {
                var value = '';
                while (tokenMatch.unknown.test(char)) {
                    value += char;
                    char = input[++current];
                }
                tokens.push({ type: 'unknown', value: value });
                continue;
            }
            current++;
        }
        tokens.forEach(function (token) {
            if (isExtraString(token)) {
                token.type = 'string';
            }
            else if (isStringKeyword(token)) {
                token.type = 'keyword';
            }
        });
        return tokens;
    }
    function parser(tokens) {
        var current = 0;
        var tokenStatements = [];
        var statements = [];
        while (current < tokens.length) {
            var token = tokens[current];
            if (isNewStatement(token)) {
                var statement = [];
                statement.push(token);
                token = tokens[++current];
                while (current < tokens.length &&
                    !isNewStatement(token) &&
                    !isSemicolon(token)) {
                    statement.push(token);
                    token = tokens[++current];
                }
                tokenStatements.push(statement);
            }
            if (token && isNewStatement(token)) {
                continue;
            }
            current++;
        }
        tokenStatements.forEach(function (tokenStatement) {
            if (isCreateTable(tokenStatement)) {
                statements.push(createTable(tokenStatement));
            }
            else if (isCreateIndex(tokenStatement)) {
                statements.push(createIndex(tokenStatement));
            }
            else if (isCreateUniqueIndex(tokenStatement)) {
                statements.push(createUniqueIndex(tokenStatement));
            }
            else if (isAlterTableAddPrimaryKey(tokenStatement)) {
                statements.push(alterTableAddPrimaryKey(tokenStatement));
            }
            else if (isAlterTableAddForeignKey(tokenStatement)) {
                statements.push(alterTableAddForeignKey(tokenStatement));
            }
            else if (isAlterTableAddUnique(tokenStatement)) {
                statements.push(alterTableAddUnique(tokenStatement));
            }
        });
        return statements;
    }
    function DDLParser(input) {
        var tokens = tokenizer(input);
        return parser(tokens);
    }

    exports.DDLParser = DDLParser;
    exports.parser = parser;
    exports.tokenizer = tokenizer;

    Object.defineProperty(exports, '__esModule', { value: true });

})));
