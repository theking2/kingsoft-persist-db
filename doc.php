<?php declare(strict_types=1);
require_once '../config.php';

// construct the namespace and class folder
if( !defined( '_NAMESPACE' ) ) {
  $configuredNamespace = str_replace( '\\', '/', SETTINGS[ 'api' ][ 'namespace' ] );
  $parts               = explode( '/', $configuredNamespace );
  $parts               = array_map( 'ucfirst', $parts );
  $namespace           = implode( '\\', $parts );
  define( '_NAMESPACE', $namespace );
}

define( 'DISCOVERED_CLASSFOLDER', str_replace( '\\', '/', ROOT . 'discovered/' . _NAMESPACE . '/' ) );
if( !is_dir( DISCOVERED_CLASSFOLDER ) )
  mkdir( DISCOVERED_CLASSFOLDER, 0755, true );

use \Kingsoft\Utils\Html as Html;
use \Kingsoft\Utils\Format as Format;

/**
 * Map SQL domains to php types
 * Be aware that this is a very simple mapping and does not cover all possible types
 * PHP does not support unsigned integers, so they are all treated as signed
 * PHP does not support decimals, so they are all treated as string use bc math functions for calculations
 */
$type_list = [ 
  'int'       => [ 'int', 'integer', 'smallint', 'tinyint', 'bigint' ],
  'float'     => [ 'float', 'double', 'real' ],
  'string'    => [ 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'decimal', 'binary', 'varbinary', 'enum', 'set' ],
  'bool'      => [ 'bool', 'boolean' ],
  'Date'      => [ 'date' ],
  '\DateTime' => [ 'datetime' ],
];

$db = \Kingsoft\Db\Database::getConnection();

$sql        = "show tables";
$table_stat = $db->prepare( $sql );
$table_stat->execute();

$table_stat->bindColumn( 1, $table_name );

$all_tables = [];
$url        = "http://" . $_SERVER[ 'HTTP_HOST' ];

echo Format::load_parse_file( 'doc-header.html', [ 'apiUrl' => $url ] );
echo Html::wrap_tag( 'h1', "Endpoints" );
echo '<dl>';


while( $table_stat->fetch() ) {
  doTable( $table_name, $url );
}
echo '</dl>';
echo Format::load_parse_file( 'doc-footer.html', [ 'apiUrl' => $url ] );


function doTable( string $table_name, string $url )
{
  global $db, $type_list, $all_tables;

  $fieldName  = "";
  $fieldType  = "";
  $fieldKey   = "";
  $fieldExtra = "";

  //Make filename PSR-4 compliant
  $class_name = str_replace( '-', '_', $table_name );
  $class_name = Format::snakeToPascal( $class_name );

  //check if included in the allwoed endpoints
  if( !in_array( $class_name, SETTINGS[ 'api' ][ 'allowedendpoints' ] ) )
    return;

  $all_tables[] = $class_name;
  $url .= "/" . $class_name;

  echo Html::wrap_tag( 'dt', $class_name );
  echo sprintf( "<p>Retrieve: <a target=\"_blank\" href=\"%1\$s\">const url = \"%1\$s\"</a></p>", $url );
  echo sprintf( "<p>Update: const url = \"%1\$s/{\$%2\$sId}\"</p>", $url, Format::snakeToCamel( $class_name ) );
  echo "<p>Note: views are not updatable</p>";

  $sql       = "show columns from `$table_name`";
  $cols_stat = $db->prepare( $sql );
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
    foreach( $type_list as $php_type => $db_types ) {
      if( in_array( $desc[ 1 ], $db_types ) ) {
        $cols[ $fieldName ] = [ $php_type, $desc[ 3 ] ?? 0, $desc[ 5 ] ?? '' ];
        break;
      }
    }
  }
  echo '<dd>';
  echo Html::wrap_tag( 'p', "key: " . ( $keyname ?? 'none' ) . " is auto increment: " . ( $hasAutoIncrement ? 'ja' : 'nein' ) );
  echo Html::wrap_tag( 'p', "Fields:" );
  echo '<pre>';
  printf( "%s: {\n", Format::snakeToCamel( $table_name ) );
  foreach( $cols as $fieldName => $fieldDescription ) {
    $width = 20 - mb_strlen( $fieldName );
    if( ( $fieldName === $keyname ) && $hasAutoIncrement )
      continue;
    printf( "\t%s: %-{$width}s // type: %s\n", $fieldName, $fieldDescription[ 0 ] == 'int' ? "0," : '"",', $fieldDescription[ 0 ] );
  }
  echo '}</pre></dd>';
}
