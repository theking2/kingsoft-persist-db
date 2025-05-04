<?php declare(strict_types=1);
namespace Kingsoft\Persist\Db;

use Kingsoft\Db\Database;
use Kingsoft\Db\DatabaseException;

trait DBPersistTrait
{
	// MARK: helpers

	/** _q - wrap fields in backticks */
	private static function _q( string $field ): string
	{
		return "`$field`";
	}

	// MARK: Field conversion
	/** 
	 * wrapFieldArray - wrap field names in backticks and precede with table name
	 *
	 * @param  array $fields
	 * @return string
	 */
	static private function wrapFieldArray( array $fields ): string
	{
		return static::getTableName() . '.`' . implode( '`, ' . self::getTableName() . '.`', $fields ) . '`';
	}
	/**
	 * getFieldNames - get field names from the parts, great for Iterators
	 *
	 * @param  mixed $withID - include the key
	 * @return array
	 */
	static private function getFieldNames( ?bool $withID = true ): array
	{
		if( $withID )
			return array_keys( static::getFields() );

		$result = [];
		foreach( array_keys( static::getFields() ) as $field ) {
			if( $field != static::getPrimaryKey() ) {
				$result[] = $field;
			}
		}
		return $result;
	}
	/**
	 * getFieldPlaceholders - get placeholders for fields prefixed with :
	 * @param bool $withID - include ID field
	 * @return string - placeholders
	 */
	static private function getFieldPlaceHolders( ?bool $withID = true ): string
	{
		if( $withID )
			return ":" . implode( ',:', self::getFieldNames() );

		$result = [];
		foreach( self::getFieldNames( $withID ) as $fieldname ) {
			if( $fieldname !== static::getPrimaryKey() ) {
				$result[] = ':' . $fieldname;
			}
		}
		return implode( ',', $result );
	}
	/**
	 * getFieldList
	 *
	 * @param  mixed $ignore_dirty - if true, only dirty fields are returned
	 * @return string
	 */
	private function getUpdateFieldList( ?bool $ignore_dirty = false ): string
	{
		$result = [];
		foreach( static::getFields() as $field => $description ) {
			// don't update primary key
			if( $field === static::getPrimaryKey() )
				continue;
			if( $ignore_dirty or in_array( $field, $this->_dirty ) ) {
				$result[] = "`$field` = :$field";
			}
		}
		return implode( ',', $result );
	}
	/**
	 * getFieldList return the list with or without PK column
	 * @param bool $withID - include ID field
	 */
	static protected function getSelectFields( ?bool $withID = false ): string
	{
		return static::wrapFieldArray( static::getFieldNames( $withID ) );
	}
	/**
	 * bindFieldList
	 *
	 * @param  mixed $stmt
	 * @param  mixed $ignore_dirty - if true, only dirty fields are bound
	 * @return void
	 */
	private function bindFieldList( \PDOStatement $stmt, ?bool $ignore_dirty = false ): bool
	{
		$result = true;
		foreach( static::getFields() as $field => $description ) {
			// don't update primary key
			if( $field === static::getPrimaryKey() )
				continue;
			if( $ignore_dirty or in_array( $field, $this->_dirty ) ) {
				$result = $result && $stmt->bindParam( ":$field", $this->$field );
			}
		}
		return $result;
	}
	/**
	 * Create insert buffer as string[]
	 *
	 * @param  mixed $stmt
	 * @param  mixed $ignore_dirty - if true, only dirty fields are bound
	 * @return void
	 */
	private function bindValueList( \PDOStatement $stmt, ?bool $ignore_dirty = false ): bool
	{
		$result               = true;
		$this->_insert_buffer = [];
		foreach( static::getFields() as $field => $description ) {
			// don't update primary key
			if( $this->isPrimaryKeyAutoIncrement() and $field === static::getPrimaryKey() )
				continue;
			if( $ignore_dirty or in_array( $field, $this->_dirty ) ) {
				if( null === $this->$field ) {
					$result = $result && $stmt->bindValue( ":$field", null, \PDO::PARAM_NULL );
				} else {
					// we assume a string here
					$result = $result && $stmt->bindValue( ":$field", $this->_insert_buffer[] = $this->getFieldString( $field ) );
				}
			}
		}
		return $result;
	}

	// MARK: getters and setters
	/**
	 * __setter for all fields
	 *
	 * @param  string $field
	 * @param  mixed $value
	 * @return void
	 * @throws \InvalidArgumentException
	 * @throws \DateMalformedStringException
	 */
	public function __set( string $field, mixed $value ): void
	{
		/** check if field exists */
		if( !$this->_isField( $field ) ) {
			throw new \InvalidArgumentException( sprintf( 'Field %s does not exist in %s', $field, $this->getTableName() ) );
		}
		$convert_boolean = fn( null|bool|string $value ): ?bool =>
			( null === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) ?
			throw new \InvalidArgumentException( "Invalid boolean value $value" ) :
			(bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );

		/** convert to DateTime type */
		$convert_date = fn( null|string|\DateTimeInterface $value ): ?\DateTime =>
			match ( true ) {
				default                                                  => throw new \DateMalformedStringException( "Invalid date value $value" ),
				null === $value                                          => null,
				$value instanceof \DateTime                              => $value,
				$value instanceof \DateTimeImmutable                     => \DateTime::createFromImmutable( $value ),
				is_string( $value ) and $value === '0000-00-00 00:00:00' => null,
				is_string( $value )                                      => new \DateTime( $value ),
			};

		$this->$field   = match ( $this->getFields()[$field][ 0 ] ) {
			default    => $value, // Default case
			'\DateTime',
			'DateTime',
			'Date'     => $convert_date( $value ),
			'int',
			'unsigned' => is_int( $value ) ? (int) $value : throw new \InvalidArgumentException( "int value expected $value" ), // Handle both int and unsigned as integers
			'float'    => is_float( $value ) ? (float) $value : throw new \InvalidArgumentException( "float value expected $value" ), // Handle both float and double as floats
			'boolean',
			'bool'     => $convert_boolean( $value ), // Convert to boolean
		};
		$this->_dirty[] = $field;

	}

	// MARK: construction
	/**
	 * Constructor calls the parent constructor to initialize the field values
	 * @param mixed $param The primary key value or an array of field values
	 * @param array $where An array of where clauses (see getWhere())
	 * @param array $order An array of order clauses (see getOrder())
	 */
	public function __construct( mixed $param = null, array $where = [], array $order = [] )
	{
		$this->_where = $where;
		$this->_order = $order;
		parent::__construct( $param );
	}

	// MARK: CRUD insert/find

	/**
	 * create – create a new record in the \Kingsoft\Db\Database or update an existing one
	 * @return bool
	 */
	public function freeze(): bool
	{
		if( $this->isRecord() ) {
			return $this->update();
		}
		return $this->insert();
	}

	/**
	 * thaw – fetch a record from the \Kingsoft\Db\Database by key
	 *
	 * @param  $id key value to use
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	public function thaw( mixed $id ): null|\Kingsoft\Persist\IPersist
	{
		$query = sprintf
		( 'select %s from %s where `%s` = :ID'
			, static::getSelectFields( false )
			, static::getTableName()
			, static::getPrimaryKey()
		);

		try {
			$stmt = Database::getConnection()->prepare( $query );
			if( !$stmt ) {
				throw DatabaseException::createStatementException( Database::getConnection(), "Could not prepare for {$this->getTableName()}:%s)" );
			}

			if( !$stmt->execute( [':ID' => $id] ) ) {
				throw DatabaseException::createExecutionException( $stmt, "Could not execute for {$this->getTableName()}:%s)" );
			}

			$stmt->setFetchMode( \PDO::FETCH_INTO | \PDO::FETCH_PROPS_LATE, $this );
			if( $stmt->fetch() ) {
				switch( $this->getFields()[$this->getPrimaryKey()][ 0 ] ) {
					case 'int':
						$this->{$this->getPrimaryKey()} = (int) $id;
						break;
					case 'string':
						$this->{$this->getPrimaryKey()} = (string) $id;
						break;
					default:
						throw new \Exception( "Unknown type for primary key" );
				}
				$this->_dirty = [];
				return $this;
			} else {
				$this->{$this->getPrimaryKey()} = null;
				$this->_dirty                   = [];
				return null;
			}
		} catch ( \PDOException $e ) {
			$errorInfo = $stmt->errorInfo();
			$message   = sprintf(
				'Error finding %s, (%s)',
				$this->getTableName(),
				$errorInfo[ 2 ]
			);
			throw new DatabaseException( DatabaseException::ERR_STATEMENT, $e, $message );
		}
	}

	/**
	 * Insert a new record in the \Kingsoft\Db\Database
	 * NOTE: This is not thread save as between the execute and lastInsertId another
	 * Apart from that the ID might be not an autoincrement field but string or a UUID
	 * sql statement could occur yielding the wrong ID to be set.
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	protected function insert(): bool
	{
		try {
			if( !$this->isPrimaryKeyAutoIncrement() ) {
				$this->{$this->getPrimaryKey()} = $this->nextPrimaryKey();
			}

			if( method_exists( $this, 'initialize' ) ) {
				$this->initialize();
			}

			if( $this->getInsertStatement()->execute() ) {
				if( $this->isPrimaryKeyAutoIncrement() ) {
					$this->{$this->getPrimaryKey()} = (int) Database::getConnection()->lastInsertId();
				}
				$this->_dirty = [];
				return true;
			} else {
				throw DatabaseException::createExecutionException(
					$this->insert_statement, "Could not insert in {$this->getTableName()}:%s" );
			}

		} catch ( \PDOException $e ) {
			throw DatabaseException::createExecutionException(
				$this->insert_statement, "Could not insert in {$this->getTableName()}:%s)"
			);
		}
	}

	// MARK: Update
	/**
	 * Synchronize changes in \Kingsoft\Db\Database
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	protected function update(): bool
	{
		try {

			if( $this->getUpdateStatement()->execute() ) {
				$this->_dirty = [];
				return true;
			}

			throw DatabaseException::createExecutionException(
				$this->update_statement, "Could not update {$this->getTableName()}:%s"
			);

		} catch ( \PDOException $e ) {
			throw DatabaseException::createExecutionException(
				$this->update_statement, "Could not update {$this->getTableName()}:%s"
			);
		}
	}

	// MARK: Delete
	/**
	 * Datensatz $this->ID aus der Tabelle entfernen
	 * If $constraint is set than use this to select the records to delete
	 * If $constraint is not set than delete thre record by ID
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	public function delete(): bool
	{
		try {

			if( $this->getDeleteStatement()->execute() ) {
				$this->_dirty                   = [];
				$this->{$this->getPrimaryKey()} = 0;
				return true;
			}
			throw DatabaseException::createExecutionException(
				$this->delete_statement, "Could not delete {$this->getTableName()}:%s"
			);

		} catch ( \PDOException $e ) {
			throw DatabaseException::createExecutionException(
				$this->delete_statement, "Could not delete from {self->getTableName()}:%s"
			);
		}
	}

	// MARK: Traversal

	/**
	 * Find records in the \Kingsoft\Db\Database
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	public static function find( ?array $where = [], ?array $order = [] ): null|static
	{
		$obj = new static( where: $where, order: $order );
		$obj->findFirst();
		if( $obj->_valid ) {
			return $obj;
		}
		return null;
	}

	/** array $_where assoc array of fieldnames and operators */
	private array $_where = [];
	/** array $_order assoc array of fieldnames and sort direction */
	private array $_order = [];
	/**	array $_insert_buffer assoc array of fieldsnames and values */
	private array $_insert_buffer = [];

	/** @var \PDOStatement $current_statement contains the statement for traversal */
	private ?\PDOStatement $current_statement = null;

	/**
	 * Find the first record in the \Kingsoft\Db\Database
	 * @throws \Kingsoft\Db\DatabaseException
	 */
	function findFirst()
	{
		$query = sprintf(
			'select %s from %s',
			self::getSelectFields( true ),
			$this->getTableName()
		);
		$query .= $this->getWhere();
		$query .= $this->getOrderBy();
		try {
			if( !$stmt = Database::getConnection()->prepare( $query ) ) {
				throw DatabaseException::createStatementException(
					Database::getConnection(), "Could not prepare statement for {$this->getTableName()}:%s"
				);
			}
			if( !$this->bindWhere( $stmt ) ) {
				throw DatabaseException::createExecutionException(
					$stmt, "Could not bind where in {$this->getTableName()}:%s"
				);
			}


			if( !$stmt->execute() ) {
				throw DatabaseException::createExecutionException( $stmt, "Could not execute statement for {$this->getTableName()}:%s" );
			}

			$stmt->setFetchMode( \PDO::FETCH_INTO | \PDO::FETCH_PROPS_LATE, $this );
			if( $stmt->fetch() ) {
				$this->current_statement = $stmt;
				$this->_valid            = true;
				$this->_dirty            = [];
			} else {
				$this->_valid = false;
			}
		} catch ( \PDOException $e ) {
			$errorInfo = $stmt->errorInfo();
			$message   = sprintf(
				'Error finding %s, (%s)',
				$this->getTableName(),
				$errorInfo[ 2 ]
			);
			throw new DatabaseException( DatabaseException::ERR_STATEMENT, $e, $message );
		}
	}
	/**
	 * Navigate to next record if available, return false if no more records are available
	 */
	public function findNext(): bool
	{
		try {
			if( $this->current_statement->fetch() ) {
				$this->_valid = true;
				$this->_dirty = [];
				return true;
			} else {
				$this->_valid = false;
				return false;
			}
		} catch ( \PDOException $e ) {
			throw DatabaseException::createExecutionException(
				$this->current_statement, "Could not find next in {$this->getTableName()}:%s"
			);
		}
	}

	/**
	 * Find all records in the \Kingsoft\Db\Database and return a generator
	 * @param $where array of where clauses, see getWhere()
	 * @param $order array of order clauses, see getOrder()
	 * @throws \Kingsoft\Db\DatabaseException 
	 */
	public static function findAll( ?array $where = [], ?array $order = [] ): \Generator
	{
		$obj = ( (object) ( new static ) )->setWhere( $where )->setOrder( $order );
		for(
			$obj->findFirst();
			$obj->_valid;
			$obj->findNext()
		) {
			yield $obj->{$obj->getPrimaryKey()} => $obj;
		}
	}

	// MARK: Sorting

	/**
	 * Specify the order of the records and set $this->_order
	 * @param $order contains the order by clause for example ['id' => 'asc', 'name' => 'desc']
	 * @return object
	 */
	public function setOrder( array $order ): object
	{
		$this->_order = $order;
		return $this;
	}
	/**
	 * Set Sort order
	 * @return string
	 */
	private function getOrderBy(): string
	{
		$order = [];
		foreach( $this->_order as $fieldname => $direction ) {
			if( $this->_isField( $fieldname ) ) {
				$order[] = "`$fieldname` $direction";
			} else
				throw new \InvalidArgumentException( sprintf( 'Field %s does not exist in %s', $fieldname, $this->getTableName() ) );
		}
		if( count( $order ) > 0 ) {
			return ' order by ' . implode( ', ', $order );
		}
		return '';
	}

	// MARK: Where
	/**
	 * Set the where clause for the select statement and set $this->_where
	 * @param  $where array of fields/values to select by, the operator is the first character of the value
	 * * \=	equal
	 * * !	not equal
	 * * \*	like
	 * * <	smaller
	 * * \>	greater
	 * * &	bitwise and
	 * * |	bitwise or
	 * * ^	bitwise xor
	 * * ~	IN value array (comma separated)
	 * @return \Kingsoft\Persist\Base
	 */
	public function setWhere( array $where ): \Kingsoft\Persist\Base
	{
		array_walk( $where, function ($value, $key) {
			if( !$this->_isField( $key ) ) {
				throw new \InvalidArgumentException( sprintf( 'Field %s does not exist in %s', $key, $this->getTableName() ) );
			}
		} );
		$this->_where = $where;
		return $this;
	}
	/**
	 * Construct sql where clause by example using the _where array set by setWhere
	 *
	 * @return string SQL where clause string
	 */
	private function getWhere(): string
	{
		$where = ['0=0']; // do nothing
		foreach( $this->_where as $fieldname => $filter ) {
			if( strstr( '=!*<>&|^~', substr( $filter, 0, 1 ) ) ) {
				$operator = substr( $filter, 0, 1 );

				// Pop off the first character
				$this->__set( $fieldname, substr( $filter, 1 ) );

				// Special case of the SQL 'IN' operator
				if( $operator === '~' ) {

					// We store the comma seperated operand list as array of values
					// which will be bound later
					$in_values = explode( ',', $filter );

					// create a comma seperated list of numbered placeholders
					// "IN (:name_1,:name_2,....)"
					$in_section = [];
					for( $i = 0; $i < count( $in_values ); $i++ ) {
						$in_section[] = ":{$fieldname}_{$i}";
					}
					$in_section = implode( ',', $in_section );

					$where[] = "`$fieldname` IN ($in_section)";
				} else {

					switch( $operator ) {
						case '!':
							$operator = '<>';
							break;
						case '*':
							$operator = 'like';
							break; // the reason why the operands are swapped
						case '<':
							$operator = '>';
							break; // the operands are swapped!
						case '>':
							$operator = '<';
							break; // the operands are swapped!
						default:
							break; // we take the operator as it is for all other cases
					}

					$where[] = "`$fieldname` $operator :$fieldname";
				}
			} else {
				// no operator, we assume '='
				$this->__set( $fieldname, $filter );
				$where[] = "`$fieldname` = :$fieldname";
			}
		}
		$where = implode( ' and ', $where );
		return ' where ' . $where;
	}
	/**
	 * Bind the set values to the statement for the where clause tthe fields set by setWhere()
	 * 
	 * @param \PDOStatement $stmt statement to bind to
	 * @return bool
	 */
	private function bindWhere( \PDOStatement $stmt ): bool
	{
		$result = true;
		foreach( $this->_where as $fieldname => $filter ) {
			if( substr( $filter, 0, 1 ) === '~' ) {
				$in_values = explode( ',', substr( $filter, 1 ) );
				for( $i = 0; $i < count( $in_values ); $i++ ) {
					$result = $result && $stmt->bindValue( ":{$fieldname}_{$i}", $in_values[$i] );
				}
			} else {
				$result = $result && $stmt->bindValue( ":$fieldname", $this->getFieldString( $fieldname ) );
			}
		}
		return $result;
	}

	// MARK: Prepared Statements

	/** @var \PDOStatement $insert_statement bind fields to values */
	private $insert_statement = null;
	/**
	 * Create insert statement
	 * Fields are bound to the object members, only dirty fields are bound
	 */
	protected function getInsertStatement(): \PDOStatement
	{
		$isPrimaryKeyAutoIncrement = !( method_exists( __CLASS__, 'isPrimaryKeyAutoIncrement' ) && self::isPrimaryKeyAutoIncrement() );
		// if not an autoincrement field, it has to be in the dirty list
		// otherwise use lastInsertId
		if( !$isPrimaryKeyAutoIncrement && !array_key_exists( $this->getPrimaryKey(), $this->_dirty ) ) {
			$this->_dirty = array_keys( static::getFields() );
		}
		if( is_null( $this->insert_statement ) ) {
			$query = sprintf(
				'insert into %s(%s) values(%s)',
				static::getTableName(),
				static::getSelectFields( $isPrimaryKeyAutoIncrement ),
				static::getFieldPlaceholders( $isPrimaryKeyAutoIncrement )
			);

			$this->insert_statement = Database::getConnection()->prepare( $query );
			if( !$this->insert_statement ) {
				throw DatabaseException::createStatementException(
					Database::getConnection(), "Could not prepare insert statement for {$this->getTableName()}:%s" );
			}
			if( !$this->bindValueList( $this->insert_statement, true ) ) {
				throw DatabaseException::createStatementException(
					Database::getConnection(), "Could not bind insert statement for {$this->getTableName()}:%s" );
			}
		}
		return $this->insert_statement;
	}
	/**
	 * Create Statement
	 * Result columns are bind to the fields
	 * 
	 * @return \PDOStatement 
	 */
	private function getUpdateStatement(): \PDOStatement
	{
		$query = sprintf(
			'update %s set %s where %s = :ID',
			static::getTableName(),
			$this->getUpdateFieldList( false ),
			static::getPrimaryKey()
		);

		$result = Database::getConnection()->prepare( $query );
		if( !$result ) {
			throw DatabaseException::createStatementException(
				Database::getConnection(), "Could not prepare update statement for {$this->getTableName()}:%s"
			);
		}
		if( !$result->bindParam( ':ID', $this->{$this->getPrimaryKey()} ) ) {
			throw DatabaseException::createStatementException(
				Database::getConnection(), "Could not bind ID to update statement for {$this->getTableName()}:%s" );
		}
		if( !$this->bindValueList( $result, false ) ) {
			throw DatabaseException::createStatementException(
				Database::getConnection(), "Could not bind update statement for {$this->getTableName()}:%s"
			);
		}

		return $result;
	}
	/** @var \PDOStatement $delete_statement bind ID param to PK */
	private ?\PDOStatement $delete_statement = null;
	/**
	 * Create Statement, bind to object members and save
	 * return cached select statement
	 * Result columns are bind to the fields
	 * 
	 * @return \PDOStatement 
	 */
	protected function getDeleteStatement(): \PDOStatement
	{
		if( is_null( $this->delete_statement ) ) {
			$query                  = sprintf( 'delete from %s where `%s` = :ID',
				static::getTableName(),
				static::getPrimaryKey()
			);
			$this->delete_statement = Database::getConnection()->prepare( $query );
			if( !$this->delete_statement ) {
				throw DatabaseException::createStatementException(
					Database::getConnection(), "Could not prepare delete statement {$this->getTableName()}:%s"
				);
			}
			if( !$this->delete_statement->bindParam( ':ID', $this->{$this->getPrimaryKey()} ) ) {
				throw DatabaseException::createStatementException(
					Database::getConnection(), "Could not bind ID to delete statement {$this->getTableName()}:%s"
				);
			}
		}
		return $this->delete_statement;
	}
}