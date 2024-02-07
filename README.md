# DB implementation of Persist
This implementation of the abstract [`Persist/Base`](https://github.com/theking2/kingsoft-persist) uses a `Db` connection to make tables in the database available as persistent PHP objects. For each table an PHP object is availabl implementing keys and values.

## Setup
To create the PHP proxies to the tables and views use
```
https://example.com/vendor/kingsoft/persist-db/discover.php
```
which only works if the proper global SETTINGS array is available. (See [`Kingsoft\Utils`](https://github.com/theking2/kingsoft-utils)

This will create a folder `discovered` in the root and responds with a page listing what is available. To have the proxies autoloaded add the psr-4 section to your `composer.json`and run 
```
composer dump-autoload
```

The proxy objects can now be 
 * Created in the database with freeze()
 * Read from the databae with thaw(id)
 * Updated by change the properties and freeze()
 * Deleted by `thaw`ing and  `delete`ing the php object

We have a CRUD object in this way.

# Searching
Searching ais also possible be setting a where and order with a static `findall` which gets a `Generator` interface to use with foreach. A `findFirst`, `fineNext`, can be used in an `Iterator` context using the `Persist\IteratorTrait`. 

# Services
 * `Persist\Base::createFromArray()` creates a record from an array representation
 * `Persist\Base::getJson()` retrieves a json representation fo the object
