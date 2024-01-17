<?php declare(strict_types=1);
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/kingsoft/utils/session.inc.php';
require ROOT . 'vendor/autoload.php';

if( !defined( '_NAMESPACE' ) ) {
  define( '_NAMESPACE', ucfirst( SETTINGS['api']['namespace'] ) );
}

use \Kingsoft\Utils\Html as Html;
use \Kingsoft\Utils\Format as Format;

/**
 * Map SQL domains to php types
 */
$type_list = [ 
  'int' => [ 'int', 'integer', 'smallint', 'tinyint', 'bigint' ],
  'float' => [ 'float', 'double', 'real' ],
  'string' => [ 'char', 'varchar', 'text' ],
  'bool' => [ 'bool', 'boolean' ],
  'Date' => [ 'date' ],
  '\DateTime' => [ 'datetime' ],
];

$db = \Kingsoft\Db\Database::getConnection();

$sql        = "show tables";
$table_stat = $db->prepare( $sql );
$table_stat->execute();

$table_stat->bindColumn( 1, $table_name );

$all_tables = [];
while( $table_stat->fetch() ) {
  //Make filename PSR-4 compliant
  $class_name   = Format::snakeToPascal( $table_name );
  $file_name    = "./src/" . $class_name . ".php";
  $all_tables[] = $class_name;

  echo Html::wrap_tag( 'h1', $class_name );

  $sql       = "show columns from `$table_name`";
  $cols_stat = $db->prepare( $sql );
  $cols_stat->execute();

  $cols_stat->bindColumn( 1, $fieldName );
  $cols_stat->bindColumn( 2, $fieldType );
  $cols_stat->bindColumn( 4, $fieldKey );

  $cols = [];

  $type_pattern = '/(\w*)(\((\d*)\))?(\s(\w*))?/';
  while( $cols_stat->fetch() ) {
    if( $fieldKey === 'PRI' ) {
      $keyname = $fieldName;
    }
    preg_match( $type_pattern, $fieldType, $desc );
    foreach( $type_list as $php_type => $db_types ) {
      if( in_array( $desc[1], $db_types ) ) {
        $cols[ $fieldName ] = [ $php_type, $desc[3] ?? 0, $desc[5] ?? '' ];
        break;
      }
    }
  }
  echo Html::wrap_tag( 'p', "key: $keyname" );
  echo '<ul>';
  foreach( $cols as $fieldName => $fieldDescription ) {
    echo Html::wrap_tag( 'li', $fieldName );
  }
  echo '</ul>';
  $fh = fopen( $file_name, 'w' );
  //$cols = "'" . implode( "',\n\t\t\t'", $cols ) . "'";
  fwrite( $fh, "<?php declare(strict_types=1);\n" );
  fprintf( $fh, "namespace %s;\n\n", _NAMESPACE );
  fprintf( $fh, "/**\n * %s – Persistant DB object\n", $table_name );

  // Set the datatype for Date and DateTime to PHP \DateTime
  foreach( $cols as $fieldName => $fieldDescription ) {
    fprintf( $fh, " * %-10s\$%s;\n",
      $fieldDescription[0] === 'Date'
      ? '\DateTime'
      : $fieldDescription[0],
      $fieldName
    );
  }
  ;

  fwrite( $fh, " */\n" );

  fprintf( $fh, "final class %s\n\textends \\Kingsoft\\Persist\\Base\n", $class_name );
  fwrite( $fh, "\timplements \\Kingsoft\\Persist\\IPersist\n{\n", );
  fwrite( $fh, "\tuse \\Kingsoft\Persist\Db\\DBPersistTrait;\n\n" );

  // Set the datatype for Date and DateTime to PHP \DateTime
  foreach( $cols as $fieldName => $fieldDescription ) {
    fprintf( $fh, "\tprotected ?%-10s\$%s;\n",
      $fieldDescription[0] === 'Date'
      ? '\DateTime'
      : $fieldDescription[0],
      $fieldName
    );
  }
  ;

  fwrite( $fh, "\n\t// Persist functions\n" );
  fprintf( $fh, "\tstatic public function getPrimaryKey():string { return '%s'; }\n", $keyname );
  fprintf( $fh, "\tstatic public function getTableName():string { return '`%s`'; }\n", $table_name );
  fwrite( $fh, "\tstatic public function getFields():array {\n" );
  fwrite( $fh, "\t\treturn [\n" );
  foreach( $cols as $fieldName => $fieldDescription ) {
    fprintf( $fh, "\t\t\t%-20s => ['%s', %d ],\n", "'$fieldName'", $fieldDescription[0], $fieldDescription[1] );
  }
  ;
  fwrite( $fh, "\t\t];\n\t}\n}" );
  fclose( $fh );

}

echo '<hr>';
echo '<h2>Settings [api]</h2>';
echo '<pre>';
array_walk( $all_tables, function ($table_name) {
  echo "allowedendpoints[] = " . $table_name . PHP_EOL;
} );
echo '</pre>';
echo '<hr>';
echo '<h2>composer.json</h2>';
echo '<pre>';
echo '
  "autoload": {
    "psr-4": {
      ' . _NAMESPACE . '\\\\: "vendor/kingsoft/persist-db/src/discover/src"
    }
  });
';
echo '</pre>';


