<?php declare(strict_types=1);

namespace Kingsoft\Persist\Db;
use \Kingsoft\Utils\Html as Html;
use \Kingsoft\Utils\Format as Format;


final class Bootstrap
{
	// MARK: - Constants
	/**
	 * Map SQL domains to php types
	 */
	private const
		TYPELIST = [ 
			'int'       => [ 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'bigint' ],
			'float'     => [ 'float', 'double', 'real' ],
			'string'    => [ 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'decimal', 'binary', 'varbinary', 'enum' ],
			'bool'      => [ 'bool', 'boolean' ],
			'Date'      => [ 'date' ],
			'\DateTime' => [ 'datetime', 'timestamp' ],
		//	'set'       => [ 'set' ]
		];
	// MARK: - Properties
	private readonly string $phpNamespace;
	private readonly string $classFolder;
	private \PDO            $db;
	protected array         $all_tables;
	private int             $inheritedPermissions;
	// MARK: - Initializer
	public function __construct(
		readonly string $namespace,
		readonly string $classFolderRoot = '',
	) {
		$configuredNamespace = str_replace( '\\', '/', $this->namespace );
		$parts               = explode( '/', $configuredNamespace );
		$parts               = array_map( 'ucfirst', $parts );
		$this->phpNamespace  = implode( '\\', $parts );
		
		// Set classFolder using classFolderRoot parameter or ROOT constant
		$root = $this->classFolderRoot;
		$this->classFolder = str_replace( '\\', '/',
			$this->classFolderRoot . '/' . $this->phpNamespace . '/'
		);
	}
	// MARK: - Discovery
	public function discover()
	{
		// Get permissions from the root folder to inherit
		$this->inheritedPermissions = 0755; // Default fallback
		if( is_dir( $this->classFolderRoot ) ) {
			$this->inheritedPermissions = fileperms( $this->classFolderRoot ) & 0777;
		}
		
		if( !is_dir( $this->classFolder ) )
			mkdir( $this->classFolder, $this->inheritedPermissions, true );

		$this->db = \Kingsoft\Db\Database::getConnection();

		$sql        = "show tables";
		$table_stat = $this->db->prepare( $sql );
		$table_stat->execute();

		$table_stat->bindColumn( 1, $table_name );

		$all_tables = [];
		while( $table_stat->fetch() ) {
			$this->doTable( $table_name );
		}
		$this->writeHtml();
	}

	// #MARK: - private functions
	private function doTable( string $table_name )
	{
		//Make filename PSR-4 compliant
		$class_name         = str_replace( '-', '_', $table_name );
		$class_name         = Format::snakeToPascal( $class_name );
		$file_name          = $this->classFolder . $class_name . ".php";
		$this->all_tables[] = $class_name;

		echo Html::wrap_tag( 'h1', $class_name );

		$sql       = "show columns from `$table_name`";
		$cols_stat = $this->db->prepare( $sql );
		$cols_stat->execute();

		$cols_stat->bindColumn( 1, $fieldName );
		$cols_stat->bindColumn( 2, $fieldType );
		$cols_stat->bindColumn( 4, $fieldKey );
		$cols_stat->bindColumn( 6, $fieldExtra );
		$cols = [];

		$type_pattern = '/(\w*)(\((\d*)\))?(\s(\w*))?/';
		$hasSet       = false;
		$keyname      = null;
		while( $cols_stat->fetch() ) {
			if( $fieldKey === 'PRI' ) {
				$keyname          = $fieldName;
				$hasAutoIncrement = $fieldExtra === 'auto_increment';
			}
			preg_match( $type_pattern, $fieldType, $desc );

			$baseType = $desc[1] ?? '';
			$length   = $desc[3] ?? 0;
			$extra    = $desc[5] ?? '';

    		// default: unknown types -> string, keep raw type info
    		$cols[$fieldName] = [ 'string', $length, $extra, $fieldType ];
	
			foreach( Bootstrap::TYPELIST as $php_type => $db_types ) {
				if( in_array( $baseType, $db_types, true ) ) {
					$cols[ $fieldName ][0] = $php_type;

					if( $php_type === 'set' ) {
						$hasSet                  = true;
						$cols[ $fieldName ][ 2 ] = explode( ",", mb_substr( $fieldType, 4, -1 ) ); // remove 'set(' and ')'
					}

					break;
				}
			}
			$cols[ $fieldName ][ 3 ] = $fieldType;
		}

		echo Html::wrap_tag( 'p', "key: " . ( $keyname ?? 'none' ) );
		echo '<ul>';
		foreach( $cols as $fieldName => $fieldDescription ) {
			echo Html::wrap_tag( 'li', $fieldName );
		}
		echo '</ul>';
		$fh = fopen( $file_name, 'w' );
		// Set file permissions to match the parent folder
		chmod( $file_name, $this->inheritedPermissions );
		//$cols = "'" . implode( "',\n\t\t\t'", $cols ) . "'";
		fwrite( $fh, "<?php declare(strict_types=1);\n" );
		fprintf( $fh, "namespace %s;\n\n", $this->phpNamespace );
		fprintf( $fh, "/**\n * Persistant DB object for table – %s\n", $table_name );
		if( !isset( $keyname ) ) {
			fwrite( $fh, " *\n * WARNING: This table/view has no primary key defined.\n" );
			fwrite( $fh, " * Limited functionality: Cannot use thaw(), update(), delete(), or freeze() for existing records.\n" );
			fwrite( $fh, " * Only read operations (findAll, find, findFirst, findNext) are supported.\n" );
			fwrite( $fh, " * For tables: Consider adding a primary key to the schema.\n" );
			fwrite( $fh, " * For views: Use only for read-only operations.\n" );
		}
		fwrite( $fh, " */\n" );

		fprintf( $fh, "final class %s\n", $class_name );
		fwrite( $fh, "\textends \\Kingsoft\\Persist\\Base\n" );
		fwrite( $fh, "\timplements \\Kingsoft\\Persist\\IPersist\n{\n", );

		fwrite( $fh, "\tuse \\Kingsoft\Persist\Db\\DBPersistTrait;\n\n" );

		// create the set constants
		if( $hasSet ) {
			foreach( $cols as $fieldName => $fieldDescription ) {
				if( $fieldDescription[ 0 ] === 'set' ) {
					$bit = 1;
					foreach( $fieldDescription[ 2 ] as $set_value ) {
						fprintf( $fh, "\tconst %s_%s = 0x%x;\n", $fieldName, str_replace( [ "'", " " ], [ "", "_" ], $set_value ), $bit );
						$bit <<= 1;
					}
				}
			}
		}
		// Set the datatype for Date and DateTime to PHP \DateTime
		foreach( $cols as $fieldName => $fieldDescription ) {
			fprintf( $fh, "\tprotected ?%-10s\$%s;\n",
				match ( $fieldDescription[ 0 ] ) {
					'Date'  => '\DateTime',
					'set'   => 'int',
					default => $fieldDescription[ 0 ],
				},
				$fieldName
			);
		}

		fwrite( $fh, "\n\t// Persist functions\n" );
		if( isset( $keyname ) ) {
			fprintf( $fh, "\tpublic static function getPrimaryKey():string { return '%s'; }\n", $keyname );
			fprintf( $fh, "\tpublic static function isPrimaryKeyAutoIncrement():bool { return %s; }\n", $hasAutoIncrement ? 'true' : 'false' );
			if( !$hasAutoIncrement ) {
				switch( $cols[ $keyname ][ 0 ] ) {
					case 'int':
						fprintf( $fh, "\tpublic static function nextPrimaryKey():int { return 0; }\n" );
						break;
					case 'string':
						fprintf( $fh, "\tpublic static function nextPrimaryKey():string { return \"%s-\" . bin2hex(random_bytes(12)); }\n", $table_name );
						break;
					default:
						fprintf( $fh, "\tpublic static function nextPrimaryKey():string { return ''; }\n" );
						break;
				}
			}
		} else {
			// No primary key found in table/view
			// Objects without primary keys have limited functionality:
			// - Cannot use thaw() to load by ID
			// - Cannot use update() to modify records
			// - Cannot use delete() to remove records
			// - Can only use findAll(), find(), findFirst(), findNext() for read operations
			// For tables: Consider adding a primary key to the schema
			// For views: Use only for read-only operations
			fprintf( $fh, "\tpublic static function getPrimaryKey():string { return ''; }\n" );
			fprintf( $fh, "\tpublic static function isPrimaryKeyAutoIncrement():bool { return false; }\n" );
		}
		fprintf( $fh, "\tpublic static function getTableName():string { return '`%s`'; }\n", $table_name );
		fwrite( $fh, "\tpublic static function getFields():array {\n" );
		fwrite( $fh, "\t\treturn [\n" );
		foreach( $cols as $fieldName => $fieldDescription ) {
			fprintf( $fh, "\t\t\t%-20s => ['%s', %d ], \t\t//\t%s\n", "'$fieldName'", $fieldDescription[ 0 ], $fieldDescription[ 1 ], $fieldDescription[ 3 ] );
		}
		fwrite( $fh, "\t\t];\n\t}\n}" );
		fclose( $fh );

	}
	private function writeHtml()
	{
		echo '<hr>';
		echo '<h2>Settings [api]</h2>';
		echo '<pre>';
		array_walk( $this->all_tables, function ($table_name) {
			echo "allowedendpoints[] = " . $table_name . PHP_EOL;
		} );
		echo '</pre>';
		echo '<hr>';
		echo '<h2>config.php</h2>';
		echo '<pre>';
		echo '$api = [' . PHP_EOL;
		echo '    \'namespace\' => \'' . $this->phpNamespace . '\',' . PHP_EOL;
		echo '    \'allowedendpoints\' => [' . PHP_EOL;
		array_walk( $this->all_tables, function ($table_name) {
			//echo "	'" . $table_name . "' => [ 'class' => '" . $this->phpNamespace . "\\" . $table_name . "' ]," . PHP_EOL;
			echo "        '" . $table_name . "'," . PHP_EOL;
		} );
		echo '    ],' . PHP_EOL;
		echo '    \'allowedmethods\' => [ \'GET\', \'POST\', \'PUT\', \'DELETE\' ]' . PHP_EOL;
		echo '];' . PHP_EOL;
		echo '<h2>composer.json</h2>';
		echo '<pre>';
		echo ',
  "autoload": {
    "psr-4": {
      "' . addslashes( $this->phpNamespace . "\\" ) . '": "' . str_replace( '\\', '/', './discovered/' . $this->phpNamespace . '/' ) . '"
    }
  }
';
		echo '</pre>';
	}
	// MARK: - Documentation
	public function document(string $headerTemplateFilename, string $footerTemplateFilename) 
	{
		$this->db = \Kingsoft\Db\Database::getConnection();

		$sql        = "show tables";
		$table_stat = $this->db->prepare( $sql );
		$table_stat->execute();

		$table_stat->bindColumn( 1, $table_name );

		$all_tables = [];
		$scheme     = $_SERVER[ 'REQUEST_SCHEME' ] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
		$url        = $scheme . "://" . $_SERVER[ 'HTTP_HOST' ];

		echo Format::load_parse_file( $headerTemplateFilename, [ 'apiUrl' => $url ] );
		echo Html::wrap_tag( 'h1', "Endpoints" );
		echo '<dl>';

		while( $table_stat->fetch() ) {
			$this->doTableForDocumentation( $table_name, $url );
		}
		echo '</dl>';
		echo Format::load_parse_file( $footerTemplateFilename, [ 'apiUrl' => $url ] );
	}

	private function doTableForDocumentation( string $table_name, string $url )
	{
		$fieldName  = "";
		$fieldType  = "";
		$fieldKey   = "";
		$fieldExtra = "";

		//Make filename PSR-4 compliant
		$class_name = str_replace( '-', '_', $table_name );
		$class_name = Format::snakeToPascal( $class_name );

		//check if included in the allowed endpoints
		if( !in_array( $class_name, SETTINGS[ 'api' ][ 'allowedendpoints' ] ) )
			return;

		$this->all_tables[] = $class_name;
		$url .= "/" . $class_name;

		echo Html::wrap_tag( 'dt', $class_name );
		echo sprintf( "<p>Retrieve: <a target=\"_blank\" href=\"%1\$s\">const url = \"%1\$s\"</a></p>", $url );
		echo sprintf( "<p>Update: const url = \"%1\$s/{\$%2\$sId}\"</p>", $url, Format::snakeToCamel( $class_name ) );
		echo "<p>Note: views are not updatable</p>";

		$sql       = "show columns from `$table_name`";
		$cols_stat = $this->db->prepare( $sql );
		$cols_stat->execute();

		$cols_stat->bindColumn( 1, $fieldName );
		$cols_stat->bindColumn( 2, $fieldType );
		$cols_stat->bindColumn( 4, $fieldKey );
		$cols_stat->bindColumn( 6, $fieldExtra );

		$cols = [];

		$type_pattern     = '/(\w*)(\((\d*)\))?(\s(\w*))?/';
		$hasAutoIncrement = false;
		while( $cols_stat->fetch() ) {
			if( $fieldKey === 'PRI' ) {
				$keyname          = $fieldName;
				$hasAutoIncrement = $fieldExtra === 'auto_increment';
			}
			preg_match( $type_pattern, $fieldType, $desc );
			foreach( Bootstrap::TYPELIST as $php_type => $db_types ) {
				if( in_array( $desc[ 1 ], $db_types ) ) {
					$cols[ $fieldName ] = [ $php_type, $desc[ 3 ] ?? 0, $desc[ 5 ] ?? '' ];
					break;
				}
			}
		}
		echo '<dd>';
		echo Html::wrap_tag( 'p', "key: " . ( $keyname ?? 'none' ) . " is auto increment: " . ( $hasAutoIncrement ? 'ja' : 'nein' ) );
		echo Html::wrap_tag( 'p', "Collection response (GET " . $url . "):" );
		echo '<pre>';
		printf( "%s: {\n", Format::snakeToCamel( $table_name ) );
		foreach( $cols as $fieldName => $fieldDescription ) {
			$width = 20 - mb_strlen( $fieldName );
			if( ( $fieldName === $keyname ) && $hasAutoIncrement )
				continue;
			printf( "\t%s: %-{$width}s // type: %s\n", $fieldName, $fieldDescription[ 0 ] == 'int' ? "0," : '"",', $fieldDescription[ 0 ] );
		}
		echo '}</pre>';
		echo Html::wrap_tag( 'p', "Single resource response (GET " . $url . "/{id}):" );
		echo '<pre>';
		echo "{\n";
		foreach( $cols as $fieldName => $fieldDescription ) {
			$width = 20 - mb_strlen( $fieldName );
			if( ( $fieldName === $keyname ) && $hasAutoIncrement )
				continue;
			printf( "\t%s: %-{$width}s // type: %s\n", $fieldName, $fieldDescription[ 0 ] == 'int' ? "0," : '"",', $fieldDescription[ 0 ] );
		}
		echo '}</pre></dd>';
	}
}
