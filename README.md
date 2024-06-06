# DB implementation of Persist
This implementation of the abstract [`Persist/Base`](https://github.com/theking2/kingsoft-persist) uses a [`Db`](https://github.com/theking2/kingsoft-db) connection to make tables in the database available as persistent PHP objects. This generates a class for every table or view in the database extracting PK, possibly with auto increment from tables. With Mariadb it is not possible to extract PK from a view so manual setting the getPrimaryKey() is needed.

## Setup
To create the PHP proxies to the tables and views use
```
https://example.com/vendor/kingsoft/persist-db/discover.php
```
which only works if the proper global SETTINGS array is available. (See [`Kingsoft\Utils`](https://github.com/theking2/kingsoft-utils). Specifically it requires the proper DB settings and a `namespace=` setting under `[api]` to generate the classses in the required PHP namespace. The location can later be added to the `composer.json` file for autoload.

This will create a folder `discovered` in the root with subfolders based on namespace in the settings file. It also responds with a page listing what is available. To have the proxies autoloaded add the psr-4 section to your `composer.json`and run 
```
composer dump-autoload
```
The `allowedEndPoints` array can be used for a [`persist-rest`](https://github.com/theking2/kingsoft-persist-rest) settings section. 

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
