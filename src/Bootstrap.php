<?php declare(strict_types=1);

namespace Kingsoft\Persist\Db;
use \Kingsoft\Utils\Html as Html;
use \Kingsoft\Utils\Format as Format;


class Bootstrap
{
	/**
	 * Map SQL domains to php types
	 */
	const
		TYPELIST = [ 
			'int'       => [ 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'bigint' ],
			'float'     => [ 'float', 'double', 'real' ],
			'string'    => [ 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'decimal', 'binary', 'varbinary', 'enum' ],
			'bool'      => [ 'bool', 'boolean' ],
			'Date'      => [ 'date' ],
			'\DateTime' => [ 'datetime' ],
			'set'       => [ 'set' ]
		];
	readonly private string $phpNamespace;
	readonly private string $classFolder;
	private \PDO            $db;
	protected array         $all_tables;
	public function __construct(
		readonly string $namespace,
	) {
		$configuredNamespace = str_replace( '\\', '/', $this->namespace );
		$parts               = explode( '/', $configuredNamespace );
		$parts               = array_map( 'ucfirst', $parts );
		$this->phpNamespace  = implode( '\\', $parts );
	}
	public function discover()
	{
		$this->classFolder = str_replace( '\\', '/', ROOT . 'discovered/' . $this->phpNamespace . '/' );
		if( !is_dir( $this->classFolder ) )
			mkdir( $this->classFolder, 0755, true );

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
			foreach( Bootstrap::TYPELIST as $php_type => $db_types ) {
				if( in_array( $desc[ 1 ], $db_types ) ) {
					$cols[ $fieldName ] = [ $php_type, $desc[ 3 ] ?? 0, $desc[ 5 ] ?? '' ];
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
		//$cols = "'" . implode( "',\n\t\t\t'", $cols ) . "'";
		fwrite( $fh, "<?php declare(strict_types=1);\n" );
		fprintf( $fh, "namespace %s;\n\n", $this->phpNamespace );
		fprintf( $fh, "/**\n * Persistant DB object for table – %s\n", $table_name );

		fwrite( $fh, " */\n" );

		fwrite( $fh, "\timplements \\Kingsoft\\Persist\\IPersist\n{\n" );
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
			fprintf( $fh, "\tstatic public function getPrimaryKey():string { return '%s'; }\n", $keyname );
			fprintf( $fh, "\tstatic public function isPrimaryKeyAutoIncrement():bool { return %s; }\n", $hasAutoIncrement ? 'true' : 'false' );
			if( !$hasAutoIncrement ) {
				switch( $cols[ $keyname ][ 0 ] ) {
					case 'int':
						fprintf( $fh, "\tstatic public function nextPrimaryKey():int { return 0; }\n" );
						break;
					case 'string':
						fprintf( $fh, "\tstatic public function nextPrimaryKey():string { return \"%s-\" . bin2hex(random_bytes(12)); }\n", $table_name );
						break;
					default:
						fprintf( $fh, "\t//static public function nextPrimaryKey():string { return ''; }\n" );
						break;
				}
			}
		} else {
			// No primary key, so we need to override the default
			fprintf( $fh, "\t//static public function getPrimaryKey():string { return ''; }\n" );
			fprintf( $fh, "\t//static public function isPrimaryKeyAutoIncrement():bool { return false; }\n" );
		}
		fprintf( $fh, "\tstatic public function getTableName():string { return '`%s`'; }\n", $table_name );
		fwrite( $fh, "\tstatic public function getFields():array {\n" );
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
      "' . addslashes( $this->phpNamespace . "\\" ) . '": "' . $this->classFolder . '"
    }
  }
';
		echo '</pre>';
	}
}