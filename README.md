# DB implementation of Persist

This implementation of the abstract [`Persist/Base`](https://github.com/theking2/kingsoft-persist) uses a [`Db`](https://github.com/theking2/kingsoft-db) connection to make tables in the database available as persistent PHP objects. This generates a class for every table or view in the database extracting PK, possibly with auto increment from tables. With Mariadb it is not possible to extract PK from a view so manual setting the getPrimaryKey() is needed.

# Discover

To create the PHP proxies to the tables and views create a `discover.php` in the root like:

```php
<?php declare(strict_types=1);

require_once 'config.php';
require 'vendor/autoload.php';

use \Kingsoft\Persist\Db\Bootstrap;
$bootstrap = new Bootstrap( 'Realm\Namespace', ROOT );
$bootstrap->discover();
```

Or if you don't pass `classFolderRoot`, it will use the `ROOT` constant if defined:

```php
$bootstrap = new Bootstrap( 'Realm\Namespace' );
$bootstrap->discover();
```

Currently it expects the static class providing the proper global `SETTINGS['db']` for `\Kingsoft\Db\Database\` in either a `config.php` or `settings.ini` file.

## Naming

Table and view names in `SHOUTING_SNAKE_CASE` are converted to `camelCase` the field names are currently left untouched as [mapping of field names](https://github.com/theking2/kingsoft-persist-db/issues/62) to properties is currently not implemented.

### config.php in root

Sample `config.php` file
```php
// Database Configuration
$db = [
    'hostname' => 'localhost',
    'database' => 'db',
    'username' => 'user',
    'password' => 'pass'
];
// Output the configuration as an array (if needed for debugging)
define( 'SETTINGS', [
    'db' => $db,
]);
unset($db);
```

 Opening the file will generate the class files for all tables and views the user has access rights to. The page itself contains clues about these and and the end sections to use for `\Kingsoft\PersistRest\`. 

```url
https://example.com/discover.php
```

If a PK is not auto increment, a string is generated with bin2hex(random_bytes(12)) with the tablename- as prefix. The atrribute length should be larger than `CHAR(str_len(tablename) + 1 + 24)`.

This will create a folder `discovered/Realm/Namespace` in the root with subfolders based on namespace in the settings file. It also responds with a page listing what is available. The contents of the folder may be moved to another but make sure to adapt the `composer.json` - section conformly.

## Views

Views are created but not updatable. Also as PK cannot be established these are commented out. Make sure to copy and adapt the create classfiles.

## Handling Missing Primary Keys

### Tables and Views Without Primary Keys

When working with tables or views that don't have a primary key defined, the `discover()` process will generate classes with the following default implementations:

```php
public static function getPrimaryKey():string { return ''; }
public static function isPrimaryKeyAutoIncrement():bool { return false; }
```

### Limitations

Objects generated from tables/views without primary keys have **significant limitations**:

1. **No `thaw()` operation**: You cannot load individual records by ID since there's no primary key to identify them
2. **No `update()` operation**: Updates require a primary key to identify which record to modify
3. **No `delete()` operation**: Deletions require a primary key to identify which record to remove
4. **No `freeze()` for existing records**: Only inserting new records may work if the table structure allows it

### What Still Works

Even without a primary key, you can still use:

- **`findAll()`**: Retrieve all records matching specific criteria using `where` clauses
- **`find()`**: Find the first record matching specific criteria
- **Iterator operations**: Use `findFirst()` and `findNext()` to traverse result sets

### Recommended Approach

For **views** without primary keys:
1. Use the generated class as-is for **read-only operations** (`findAll()`, `find()`)
2. Do not attempt CRUD operations that require a primary key

For **tables** without primary keys:
1. **Best practice**: Add a primary key to your table schema
2. If you cannot modify the schema, manually edit the generated class to define a unique field combination as the primary key:
   ```php
   public static function getPrimaryKey():string { return 'your_unique_field'; }
   ```
3. Ensure the field you choose uniquely identifies each record

### Example: Working with Views

```php
// This works - reading all records from a view
foreach( ViewName::findAll() as $record ) {
    echo $record->field_name;
}

// This works - finding specific records
$record = ViewName::find(['field' => '=value']);

// This will NOT work - views don't support updates
// $record->freeze(); // Don't do this!

// This will NOT work - no primary key to identify records
// $record = new ViewName(123); // Don't do this!
```

## Composer file section

To have the proxies autoloaded add the psr-4 section to your `composer.json`and run 

```sh
composer dump-autoload
```

Than the classes will autoload when `new \Realm\Namespace\TableName()` is called.

# Document
To create a API documentation page

```php
<?php declare(strict_types=1);

require_once 'config.php';
require 'vendor/autoload.php';

use \Kingsoft\Persist\Db\Bootstrap;
$bootstrap = new Bootstrap( 'Kingsoft\LinkQr', ROOT );
$bootstrap->document( 
    ROOT.'doc-header.html', 
    ROOT.'doc-footer.html'
);
```

Copy the template files to the root.

## Configuration

The `allowedEndPoints` array created by a discover can be used for a [`persist-rest`](https://github.com/theking2/kingsoft-persist-rest) settings section. 

The proxy objects work as a facade for database tables and can now used to (CRUD)

 * Created a record, set the attributes and call `Persist::freeze()` to store it in the database
 * Read by constructor(  ) using the record's id as the single parameter
 * Update by reading, changing the properties and IPersist::freeze()
 * Delete by `thaw`ing and  `IPersist::delete`ing the php object

We have turened a normal PHP object in a CRUD object in this way. Yaj!

# Searching

Searching is also possible be setting a where and order with a static `findall` which gets a `Generator` interface to use with foreach. A `findFirst`, `fineNext`, can be used in an `Iterator` context using the `Persist\IteratorTrait`. The object itself is a generater and can be used in a `yield` loop. 

# Services

 * `Persist\Base::createFromArray()` creates a record from an array representation
 * `Persist\Base::getJson()` creates a json representation of the object
 * `Persist\Base::createFromJson()` does the reverse
