# OOSQL

A PHP library for reading and updating MySQL databases in a more object-oriented way

## Usage
Database information can be passed to the constructor *or* stored in config.yml (example included). 

## Examples
```php
use OOSQL\OOSQL;

# use credentials from config.yml
$database = new OOSQL;

# retrieve the row in the `Hero` table with ID (primary key) 10, as an object
$my_hero = $database->get('Hero', 10);

# increment the hero's level and update the database
$my_hero->level++;
$my_hero->write();

# create a new hero and insert into the database
$johnny = $database->new('Hero');
$johnny->name = 'Johnny';
$johnny->level = 1;
$johnny->write();

# alternative insert syntax with properties passed to OOSQL::new()
$database->new('Hero', [
    'name' => 'Johnny',
    'level' => 1
])->write();
```

## License
[MIT](https://choosealicense.com/licenses/mit/)