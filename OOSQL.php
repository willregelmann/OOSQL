<?php

namespace OOSQL;

include_once __DIR__.'/common.php';

class OOSQL extends \PDO {
            
    private array $schemas = [];
    
    public function __construct(
        string $host = CONFIG['database']['host'],
        int $port = CONFIG['database']['port'] ?? 3306,
        ?string $user = CONFIG['database']['user'],
        ?string $pass = CONFIG['database']['pass'],
        public ?string $dbname = CONFIG['database']['dbname']
    ) {
        $dsn = "mysql:server=$host";
        $port && $dsn .= ",$port";
        $dbname && $dsn .= ";dbname=$dbname";
        parent::__construct($dsn, $user, $pass);
        $dbname && $this->parseSchemas();
    }
    
    public function use(string $dbname):void {
        $this->query("use $dbname");
        $this->dbname = $dbname;
        $this->parseSchemas();
    }
    
    public function list(string $class):array {
        $stmt = $this->query("SELECT * FROM $class");
        $keychain = $this->getKeys();
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $list = [];
        foreach ($items as $item) {
            $key = $item[$keychain->{$class}->primary];
            $list[$key] = new Doppel($this, $class, $key, true, false);
        }
        return $list;
    }
    
    public function new(string $class, array $properties = []):Doppel {
        $obj = new Doppel($this, $class);
        foreach ($properties as $property=>$value) {
            $obj->{$property} = $value;
        }
        return $obj;
    }
    
    public function get(string $class, $id):Doppel {
        return new Doppel($this, $class, $id, true, true);
    }
    
    private function mapValuesToSchema(array $values, string $class) {
        $schema = clone $this->schemas[$class];
        foreach (get_object_vars($schema) as $field=>$default) {
            $schema->{$field} = $values[$field];
        }
        return $schema;
    }
    
    private function parseSchemas() {
        $stmt = $this->prepare('SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $stmt->execute([$this->dbname]);
        $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            @class_alias('OOSQL\Doppel', sprintf('OOSQL\\%s\\%s', $this->dbname, $table['TABLE_NAME']));
            $this->parseSchema($table['TABLE_NAME']);
        }
    }
    
    public function parseSchema(string $class) {
        $stmt = $this->prepare('SELECT * FROM information_schema.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?');
        $stmt->execute([$class, $this->dbname]);
        $keychain = $this->getKeys();
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $schema = new \stdClass;
        foreach ($columns as $column) {
            foreach (($keychain->{$class}->upstream ?? []) as $upstream) {
                if ($column['COLUMN_NAME'] == $upstream->key) {
                    $schema->{$upstream->class} = new \stdClass;
                    continue 2;
                }
            }
            if ($column['COLUMN_NAME'] == ($keychain->{$class}->primary ?? null)) {
                continue;
            }
            $schema->{$column['COLUMN_NAME']} = $column['COLUMN_DEFAULT'];
        }
        return $schema;
    }
    
    public function getKeys(?string $class = null) {
        $query = 'SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ?';
        $class && $query .= " AND '$class' IN (TABLE_NAME, REFERENCED_TABLE_NAME)";
        $stmt = $this->prepare($query);
        $stmt->execute([$this->dbname]);
        $keys = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $keychain = new \stdClass;
        foreach ($keys as $key) {
            $keychain->{$key['TABLE_NAME']} ??= (object)[
                'primary' => null,
                'upstream' => [],
                'downstream' => []
            ];
            if ($key['CONSTRAINT_NAME'] == 'PRIMARY') {
                $keychain->{$key['TABLE_NAME']}->primary = $key['COLUMN_NAME'];
            } else {
                $keychain->{$key['REFERENCED_TABLE_NAME']} ??= (object)[
                    'primary' => null,
                    'upstream' => [],
                    'downstream' => []
                ];
                array_push($keychain->{$key['TABLE_NAME']}->upstream, (object)[
                    'key' => $key['COLUMN_NAME'],
                    'property' => $key['REFERENCED_COLUMN_NAME'],
                    'class' => $key['REFERENCED_TABLE_NAME']
                ]);
                array_push($keychain->{$key['REFERENCED_TABLE_NAME']}->downstream, (object)[
                    'key' => $key['REFERENCED_COLUMN_NAME'],
                    'property' => $key['COLUMN_NAME'],
                    'class' => $key['TABLE_NAME']
                ]);
            }
        }
        return $keychain;
    }
    
}

print_r((new OOSQL)->get('hero', 4));