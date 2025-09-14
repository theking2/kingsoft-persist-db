# DB implementation of Persist

This implementation of the abstract [`Persist/Base`](https://github.com/theking2/kingsoft-persist) uses a [`Db`](https://github.com/theking2/kingsoft-db) connection to make tables in the database available as persistent PHP objects. This generates a class for every table or view in the database extracting PK, possibly with auto increment from tables. With Mariadb it is not possible to extract PK from a view so manual setting the getPrimaryKey() is needed.

# Discover

To create the PHP proxies to the tables and views create a `discover.php` in the root like:

```php
<?php declare(strict_types=1);

require_once 'config.php';
require 'vendor/autoload.php';

use \Kingsoft\Persist\Db\Bootstrap;
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
$bootstrap = new Bootstrap( 'Kingsoft\LinkQr' );
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
