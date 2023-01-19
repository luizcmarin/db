<?php

declare(strict_types=1);

namespace Yiisoft\Db\Schema;

use PDO;
use Throwable;
use Yiisoft\Cache\Dependency\TagDependency;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Constraint\Constraint;
use Yiisoft\Db\Exception\NotSupportedException;

use function array_change_key_case;
use function array_map;
use function gettype;
use function is_array;
use function preg_match;

/**
 * The AbstractSchema class provides a set of methods for working with database schemas such as creating, modifying,
 * and inspecting tables, columns, and other database objects.
 *
 * It is a very powerful and flexible tool that allows you to perform a wide range of database operations in a
 * database-agnostic way.
 */
abstract class AbstractSchema implements SchemaInterface
{
    /**
     * Schema cache version, to detect incompatibilities in cached values when the data format of the cache changes.
     */
    protected const SCHEMA_CACHE_VERSION = 1;
    public const CACHE_VERSION = 'cacheVersion';

    /**
     * @var string|null $defaultSchema The default schema name used for the current session.
     */
    protected string|null $defaultSchema = null;
    private array $schemaNames = [];
    private array $tableNames = [];
    protected array $viewNames = [];
    private array $tableMetadata = [];

    public function __construct(protected ConnectionInterface $db, private SchemaCache $schemaCache)
    {
    }

    /**
     * @param string $name the table name.
     *
     * @return array The cache key for the specified table name.
     */
    abstract protected function getCacheKey(string $name): array;

    /**
     * @return string The cache tag name.
     *
     * This allows {@see refresh()} to invalidate all cached table schemas.
     */
    abstract protected function getCacheTag(): string;

    /**
     * Loads all check constraints for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return array The check constraints for the given table.
     */
    abstract protected function loadTableChecks(string $tableName): array;

    /**
     * Loads all default value constraints for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return array The default value constraints for the given table.
     */
    abstract protected function loadTableDefaultValues(string $tableName): array;

    /**
     * Loads all foreign keys for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return array The foreign keys for the given table.
     */
    abstract protected function loadTableForeignKeys(string $tableName): array;

    /**
     * Loads all indexes for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return array The indexes for the given table.
     */
    abstract protected function loadTableIndexes(string $tableName): array;

    /**
     * Loads a primary key for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return Constraint|null The primary key for the given table. `null` if the table has no primary key.
     */
    abstract protected function loadTablePrimaryKey(string $tableName): Constraint|null;

    /**
     * Loads all unique constraints for the given table.
     *
     * @param string $tableName The table name.
     *
     * @return array The unique constraints for the given table.
     */
    abstract protected function loadTableUniques(string $tableName): array;

    /**
     * Loads the metadata for the specified table.
     *
     * @param string $name The table name.
     *
     * @return TableSchemaInterface|null DBMS-dependent table metadata, `null` if the table does not exist.
     */
    abstract protected function loadTableSchema(string $name): TableSchemaInterface|null;

    public function getDefaultSchema(): string|null
    {
        return $this->defaultSchema;
    }

    public function getPdoType(mixed $data): int
    {
        /** @psalm-var array<string, int> $typeMap */
        $typeMap = [
            // php type => PDO type
            SchemaInterface::PHP_TYPE_BOOLEAN => PDO::PARAM_BOOL,
            SchemaInterface::PHP_TYPE_INTEGER => PDO::PARAM_INT,
            SchemaInterface::PHP_TYPE_STRING => PDO::PARAM_STR,
            SchemaInterface::PHP_TYPE_RESOURCE => PDO::PARAM_LOB,
            SchemaInterface::PHP_TYPE_NULL => PDO::PARAM_NULL,
        ];

        $type = gettype($data);

        return $typeMap[$type] ?? PDO::PARAM_STR;
    }

    public function getRawTableName(string $name): string
    {
        if (str_contains($name, '{{')) {
            $name = preg_replace('/{{(.*?)}}/', '\1', $name);

            return str_replace('%', $this->db->getTablePrefix(), $name);
        }

        return $name;
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaChecks(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::CHECKS, $refresh);
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaDefaultValues(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::DEFAULT_VALUES, $refresh);
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaForeignKeys(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::FOREIGN_KEYS, $refresh);
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaIndexes(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::INDEXES, $refresh);
    }

    public function getSchemaNames(bool $refresh = false): array
    {
        if (empty($this->schemaNames) || $refresh) {
            $this->schemaNames = $this->findSchemaNames();
        }

        return $this->schemaNames;
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaPrimaryKeys(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::PRIMARY_KEY, $refresh);
    }

    /**
     * @throws NotSupportedException
     */
    public function getSchemaUniques(string $schema = '', bool $refresh = false): array
    {
        return $this->getSchemaMetadata($schema, SchemaInterface::UNIQUES, $refresh);
    }

    public function getTableChecks(string $name, bool $refresh = false): array
    {
        /** @psalm-var mixed $tableChecks */
        $tableChecks = $this->getTableMetadata($name, SchemaInterface::CHECKS, $refresh);
        return is_array($tableChecks) ? $tableChecks : [];
    }

    public function getTableDefaultValues(string $name, bool $refresh = false): array
    {
        /** @psalm-var mixed $tableDefaultValues */
        $tableDefaultValues = $this->getTableMetadata($name, SchemaInterface::DEFAULT_VALUES, $refresh);
        return is_array($tableDefaultValues) ? $tableDefaultValues : [];
    }

    public function getTableForeignKeys(string $name, bool $refresh = false): array
    {
        /** @psalm-var mixed $tableForeignKeys */
        $tableForeignKeys = $this->getTableMetadata($name, SchemaInterface::FOREIGN_KEYS, $refresh);
        return is_array($tableForeignKeys) ? $tableForeignKeys : [];
    }

    public function getTableIndexes(string $name, bool $refresh = false): array
    {
        /** @psalm-var mixed $tableIndexes */
        $tableIndexes = $this->getTableMetadata($name, SchemaInterface::INDEXES, $refresh);
        return is_array($tableIndexes) ? $tableIndexes : [];
    }

    public function getTableNames(string $schema = '', bool $refresh = false): array
    {
        if (!isset($this->tableNames[$schema]) || $refresh) {
            /** @psalm-var string[] */
            $this->tableNames[$schema] = $this->findTableNames($schema);
        }

        return is_array($this->tableNames[$schema]) ? $this->tableNames[$schema] : [];
    }

    public function getTablePrimaryKey(string $name, bool $refresh = false): Constraint|null
    {
        /** @psalm-var mixed $tablePrimaryKey */
        $tablePrimaryKey = $this->getTableMetadata($name, SchemaInterface::PRIMARY_KEY, $refresh);
        return $tablePrimaryKey instanceof Constraint ? $tablePrimaryKey : null;
    }

    public function getTableSchema(string $name, bool $refresh = false): TableSchemaInterface|null
    {
        /** @psalm-var mixed $tableSchema */
        $tableSchema = $this->getTableMetadata($name, SchemaInterface::SCHEMA, $refresh);
        return $tableSchema instanceof TableSchemaInterface ? $tableSchema : null;
    }

    public function getTableSchemas(string $schema = '', bool $refresh = false): array
    {
        /** @psalm-var mixed $tableSchemas */
        $tableSchemas = $this->getSchemaMetadata($schema, SchemaInterface::SCHEMA, $refresh);

        return is_array($tableSchemas) ? $tableSchemas : [];
    }

    public function getTableUniques(string $name, bool $refresh = false): array
    {
        /** @psalm-var mixed $tableUniques */
        $tableUniques = $this->getTableMetadata($name, SchemaInterface::UNIQUES, $refresh);
        return is_array($tableUniques) ? $tableUniques : [];
    }

    public function isReadQuery(string $sql): bool
    {
        $pattern = '/^\s*(SELECT|SHOW|DESCRIBE)\b/i';

        return preg_match($pattern, $sql) > 0;
    }

    public function refresh(): void
    {
        if ($this->schemaCache->isEnabled()) {
            $this->schemaCache->invalidate($this->getCacheTag());
        }

        $this->tableNames = [];
        $this->tableMetadata = [];
    }

    public function refreshTableSchema(string $name): void
    {
        $rawName = $this->getRawTableName($name);

        unset($this->tableMetadata[$rawName]);

        $this->tableNames = [];

        if ($this->schemaCache->isEnabled()) {
            $this->schemaCache->remove($this->getCacheKey($rawName));
        }
    }

    public function schemaCacheEnable(bool $value): void
    {
        $this->schemaCache->setEnable($value);
    }

    /**
     * Returns all schema names in the database, including the default one but not system schemas.
     *
     * This method should be overridden by child classes in order to support this feature because the default
     * implementation simply throws an exception.
     *
     * @throws NotSupportedException If this method is not supported by the DBMS.
     *
     * @return array All schema names in the database, except system schemas.
     */
    protected function findSchemaNames(): array
    {
        throw new NotSupportedException(static::class . ' does not support fetching all schema names.');
    }

    /**
     * Returns all table names in the database.
     *
     * This method should be overridden by child classes in order to support this feature because the default
     * implementation simply throws an exception.
     *
     * @param string $schema The schema of the tables. Defaults to empty string, meaning the current or default schema.
     *
     * @throws NotSupportedException If this method is not supported by the DBMS.
     *
     * @return array All table names in the database. The names have NO schema name prefix.
     */
    protected function findTableNames(string $schema): array
    {
        throw new NotSupportedException(static::class . ' does not support fetching all table names.');
    }

    /**
     * Extracts the PHP type from abstract DB type.
     *
     * @param ColumnSchemaInterface $column The column schema information.
     *
     * @return string The PHP type name.
     */
    protected function getColumnPhpType(ColumnSchemaInterface $column): string
    {
        /** @psalm-var string[] $typeMap */
        $typeMap = [
            // abstract type => php type
            SchemaInterface::TYPE_TINYINT => SchemaInterface::PHP_TYPE_INTEGER,
            SchemaInterface::TYPE_SMALLINT => SchemaInterface::PHP_TYPE_INTEGER,
            SchemaInterface::TYPE_INTEGER => SchemaInterface::PHP_TYPE_INTEGER,
            SchemaInterface::TYPE_BIGINT => SchemaInterface::PHP_TYPE_INTEGER,
            SchemaInterface::TYPE_BOOLEAN => SchemaInterface::PHP_TYPE_BOOLEAN,
            SchemaInterface::TYPE_FLOAT => SchemaInterface::PHP_TYPE_DOUBLE,
            SchemaInterface::TYPE_DOUBLE => SchemaInterface::PHP_TYPE_DOUBLE,
            SchemaInterface::TYPE_BINARY => SchemaInterface::PHP_TYPE_RESOURCE,
            SchemaInterface::TYPE_JSON => SchemaInterface::PHP_TYPE_ARRAY,
        ];

        if (isset($typeMap[$column->getType()])) {
            if ($column->getType() === SchemaInterface::TYPE_BIGINT) {
                return PHP_INT_SIZE === 8 && !$column->isUnsigned()
                    ? SchemaInterface::PHP_TYPE_INTEGER : SchemaInterface::PHP_TYPE_STRING;
            }

            if ($column->getType() === SchemaInterface::TYPE_INTEGER) {
                return PHP_INT_SIZE === 4 && $column->isUnsigned()
                    ? SchemaInterface::PHP_TYPE_STRING : SchemaInterface::PHP_TYPE_INTEGER;
            }

            return $typeMap[$column->getType()];
        }

        return SchemaInterface::PHP_TYPE_STRING;
    }

    /**
     * Returns the metadata of the given type for all tables in the given schema.
     *
     * @param string $schema The schema of the metadata. Defaults to empty string, meaning the current or default schema
     * name.
     * @param string $type The metadata type.
     * @param bool $refresh Whether to fetch the latest available table metadata. If this is `false`, cached data may be
     * returned if available.
     *
     * @throws NotSupportedException
     *
     * @return array The metadata of the given type for all tables in the given schema.
     *
     * @psalm-return list<Constraint|TableSchemaInterface|array>
     */
    protected function getSchemaMetadata(string $schema, string $type, bool $refresh): array
    {
        $metadata = [];
        /** @psalm-var string[] $tableNames */
        $tableNames = $this->getTableNames($schema, $refresh);

        foreach ($tableNames as $name) {
            if ($schema !== '') {
                $name = $schema . '.' . $name;
            }

            $tableMetadata = $this->getTableTypeMetadata($type, $name, $refresh);

            if ($tableMetadata !== null) {
                $metadata[] = $tableMetadata;
            }
        }

        return $metadata;
    }

    /**
     * Returns the metadata of the given type for the given table.
     *
     * @param string $name The table name. The table name may contain schema name if any. Do not quote the table name.
     * @param string $type The metadata type.
     * @param bool $refresh whether to reload the table metadata even if it is found in the cache.
     *
     * @return mixed metadata.
     *
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedArrayAssignment
     */
    protected function getTableMetadata(string $name, string $type, bool $refresh = false): mixed
    {
        $rawName = $this->getRawTableName($name);

        if (!isset($this->tableMetadata[$rawName])) {
            $this->loadTableMetadataFromCache($rawName);
        }

        if ($refresh || !isset($this->tableMetadata[$rawName][$type])) {
            $this->tableMetadata[$rawName][$type] = $this->loadTableTypeMetadata($type, $rawName);
            $this->saveTableMetadataToCache($rawName);
        }

        return $this->tableMetadata[$rawName][$type];
    }

    /**
     * This method returns the desired metadata type for the table name.
     */
    protected function loadTableTypeMetadata(string $type, string $name): Constraint|array|TableSchemaInterface|null
    {
        return match ($type) {
            SchemaInterface::SCHEMA => $this->loadTableSchema($name),
            SchemaInterface::PRIMARY_KEY => $this->loadTablePrimaryKey($name),
            SchemaInterface::UNIQUES => $this->loadTableUniques($name),
            SchemaInterface::FOREIGN_KEYS => $this->loadTableForeignKeys($name),
            SchemaInterface::INDEXES => $this->loadTableIndexes($name),
            SchemaInterface::DEFAULT_VALUES => $this->loadTableDefaultValues($name),
            SchemaInterface::CHECKS => $this->loadTableChecks($name),
            default => null,
        };
    }

    /**
     * This method returns the desired metadata type for table name (with refresh if needed)
     */
    protected function getTableTypeMetadata(
        string $type,
        string $name,
        bool $refresh = false
    ): Constraint|array|null|TableSchemaInterface {
        return match ($type) {
            SchemaInterface::SCHEMA => $this->getTableSchema($name, $refresh),
            SchemaInterface::PRIMARY_KEY => $this->getTablePrimaryKey($name, $refresh),
            SchemaInterface::UNIQUES => $this->getTableUniques($name, $refresh),
            SchemaInterface::FOREIGN_KEYS => $this->getTableForeignKeys($name, $refresh),
            SchemaInterface::INDEXES => $this->getTableIndexes($name, $refresh),
            SchemaInterface::DEFAULT_VALUES => $this->getTableDefaultValues($name, $refresh),
            SchemaInterface::CHECKS => $this->getTableChecks($name, $refresh),
            default => null,
        };
    }

    /**
     * Changes row's array key case to lower.
     *
     * @param array $row Thew row's array or an array of row's arrays.
     * @param bool $multiple Whether multiple rows or a single row passed.
     *
     * @return array The normalized row or rows.
     */
    protected function normalizeRowKeyCase(array $row, bool $multiple): array
    {
        if ($multiple) {
            return array_map(static fn (array $row) => array_change_key_case($row), $row);
        }

        return array_change_key_case($row);
    }

    /**
     * Resolves the table name and schema name (if any).
     *
     * @param string $name The table name.
     *
     * @throws NotSupportedException If this method is not supported by the DBMS.
     *
     * @return TableSchemaInterface The with resolved table, schema, etc. names.
     *
     * {@see TableSchemaInterface}
     */
    protected function resolveTableName(string $name): TableSchemaInterface
    {
        throw new NotSupportedException(static::class . ' does not support resolving table names.');
    }

    /**
     * Sets the metadata of the given type for the given table.
     *
     * @param string $name The table name.
     * @param string $type The metadata type.
     * @param mixed $data The metadata to be set.
     *
     * @psalm-suppress MixedArrayAssignment
     */
    protected function setTableMetadata(string $name, string $type, mixed $data): void
    {
        $this->tableMetadata[$this->getRawTableName($name)][$type] = $data;
    }

    /**
     * Tries to load and populate table metadata from cache.
     */
    private function loadTableMetadataFromCache(string $rawName): void
    {
        if (!$this->schemaCache->isEnabled() || $this->schemaCache->isExcluded($rawName)) {
            $this->tableMetadata[$rawName] = [];
            return;
        }

        $metadata = $this->schemaCache->getOrSet(
            $this->getCacheKey($rawName),
            null,
            $this->schemaCache->getDuration(),
            new TagDependency($this->getCacheTag()),
        );

        if (
            !is_array($metadata) ||
            !isset($metadata[self::CACHE_VERSION]) ||
            $metadata[self::CACHE_VERSION] !== static::SCHEMA_CACHE_VERSION
        ) {
            $this->tableMetadata[$rawName] = [];
            return;
        }

        unset($metadata[self::CACHE_VERSION]);
        $this->tableMetadata[$rawName] = $metadata;
    }

    /**
     * Saves table metadata to cache.
     */
    private function saveTableMetadataToCache(string $rawName): void
    {
        if ($this->schemaCache->isEnabled() === false || $this->schemaCache->isExcluded($rawName) === true) {
            return;
        }

        /** @psalm-var array<string, array<TableSchemaInterface|int>> $metadata */
        $metadata = $this->tableMetadata[$rawName];
        /** @var int */
        $metadata[self::CACHE_VERSION] = static::SCHEMA_CACHE_VERSION;

        $this->schemaCache->set(
            $this->getCacheKey($rawName),
            $metadata,
            $this->schemaCache->getDuration(),
            new TagDependency($this->getCacheTag()),
        );
    }

    /**
     * Find the view names for the database.
     *
     * @param string $schema the schema of the views. Defaults to empty string, meaning the current or default schema.
     *
     * @return array The names of all views in the database.
     */
    protected function findViewNames(string $schema = ''): array
    {
        return [];
    }

    /**
     * @throws Throwable
     *
     * @return array The view names for the database.
     */
    public function getViewNames(string $schema = '', bool $refresh = false): array
    {
        if (!isset($this->viewNames[$schema]) || $refresh) {
            $this->viewNames[$schema] = $this->findViewNames($schema);
        }

        return (array) $this->viewNames[$schema];
    }
}