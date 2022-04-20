<?php

namespace ObjQL;

abstract class Model {

    public static string $schema;
    public static string $table_name;
    public static \PDO $dbh;
    public mixed $_id;

    public function __construct(...$properties) {
        $pk = static::getPrimaryKey();
        static::_createRecord(...$properties);
        $this->_id = static::$dbh->lastInsertId();
    }

    public function __destruct() {}

    public function __get(string $name):mixed {
        $relations = static::_getRelations();
        if (($i = array_search($name, array_map(fn($r) => $r->COLUMN_NAME, $relations->up))) !== false) {
            $sth = static::$dbh->prepare(sprintf(
                    <<<SQL
                    SELECT a.`%s` 
                    FROM `%s` AS a INNER JOIN `%s` AS b ON a.`%s` = b.`%s` 
                    WHERE b.`%s` = :id
                    SQL,
                    (static::$schema.'\\'.$relations->up[$i]->REFERENCED_TABLE_NAME)::getPrimaryKey(),
                    $relations->up[$i]->REFERENCED_TABLE_NAME,
                    static::$table_name,
                    $relations->up[$i]->REFERENCED_COLUMN_NAME,
                    $relations->up[$i]->COLUMN_NAME,
                    static::getPrimaryKey()
                ));
            $sth->execute(['id' => $this->_id]);
            return (static::$schema.'\\'.$relations->up[$i]->REFERENCED_TABLE_NAME)::get($sth->fetchColumn());
        } else {
            $sth = static::$dbh->prepare(
                sprintf(
                    'SELECT `%s` FROM `%s` WHERE `%s` = :id',
                    $name,
                    static::$table_name,
                    static::getPrimaryKey()
                )
            );
            $sth->execute(['id' => $this->_id]);
            return $sth->fetchColumn();
        }
    }

    public function __set(string $name, mixed $value):void {
        if (is_object($value) && isset($value->_id)) $value = $value->_id;
        if (isset($this->_id)) {
            $sth = static::$dbh->prepare(sprintf(
                    'UPDATE `%s` SET `%s` = :value WHERE `%s` = :id',
                    static::$table_name,
                    $name,
                    static::getPrimaryKey()
                ));
            $sth->execute([
                'value' => $value,
                'id' => $this->_id
            ]);
        }
        if ($name == static::getPrimaryKey()) $this->_id = $value;
    }

    public function __unset(string $name):void {
        if (isset($this->_id)) {
            $sth = static::$dbh->prepare(sprintf(
                'UPDATE `%s` SET `%s` = NULL WHERE `%s` = :id',
                static::$table_name,
                $name,
                static::getPrimaryKey()
            ));
            $sth->execute(['id' => $this->_id]);
        }
    }

    public static function get(mixed $id):Model {
        $obj = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $obj->_id = $id;
        return $obj;
    }

    public function getRelations():object {
        $relations = static::_getRelations();
        $return = (object)[
            'parents' => (object)[],
            'children' => (object)[]
        ];
        foreach ($relations->up as $relation) {
            $return->parents->{$relation->REFERENCED_COLUMN_NAME} = (static::$schema.'\\'.$relation->REFERENCED_TABLE_NAME)::select(...[
                    $relation->REFERENCED_COLUMN_NAME => $this->_id
                ]);
        }
        foreach ($relations->down as $relation) {
            $return->children->{$relation->TABLE_NAME} ??= (object)[];
            $return->children->{$relation->TABLE_NAME}->{$relation->COLUMN_NAME} = (static::$schema.'\\'.$relation->TABLE_NAME)::select(...[
                    $relation->COLUMN_NAME => $this->_id
                ]);
        }
        return $return;
    }

    public static function select(...$args):array {
        $sql = sprintf(
            'SELECT `%s` FROM `%s`',
            static::getPrimaryKey(),
            static::$table_name
        );
        if (!empty($args)) {
            if (array_key_first($args)) {
                $sql .= sprintf(' WHERE %s', implode(
                    ' AND ',
                    array_map(fn($a) => "`$a` = :$a", array_keys($args))
                ));
            } else {
                $sql .= " WHERE $args[0]";
                array_shift($args);
            }
        }
        $sth = static::$dbh->prepare($sql);
        $sth->execute($args);
        return array_map(
            fn($id) => static::get($id),
            $sth->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function update(...$properties):bool {
        $sth = static::$dbh->prepare(sprintf(
                'UPDATE `%s` SET %s WHERE `%s` = :id',
                static::$table_name,
                implode(', ', array_map(
                    fn($p) => "`$p` = :$p",
                    array_keys($properties)
                )),
                static::getPrimaryKey()
            ));
        return $sth->execute(['id' => $this->_id]);
    }

    public function delete():bool {
        $sth = static::$dbh->prepare(sprintf(
                'DELETE FROM `%s` WHERE `%s` = :id',
                static::$table_name,
                static::getPrimaryKey()
            ));
        return $sth->execute(['id' => $this->_id]);
    }

    public static function getPrimaryKey():string {
        $sth = static::$dbh->prepare(<<<SQL
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                CONSTRAINT_NAME = 'PRIMARY' AND
                TABLE_SCHEMA = :schema AND
                TABLE_NAME = :table_name 
            SQL);
        $sth->execute([
            'schema' =>  static::$schema,
            'table_name' => static::$table_name
        ]);
        return $sth->fetchColumn();
    }

    protected static function _getRelations():object {
        $sth_down = static::$dbh->prepare(<<<SQL
            SELECT 
                   COLUMN_NAME,
                   TABLE_NAME,
                   REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                REFERENCED_TABLE_SCHEMA = :schema AND
                REFERENCED_TABLE_NAME = :table_name 
            SQL);
        $sth_up = static::$dbh->prepare(<<<SQL
            SELECT 
                   COLUMN_NAME,
                   REFERENCED_TABLE_NAME,
                   REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                REFERENCED_TABLE_NAME IS NOT NULL AND
                TABLE_SCHEMA = :schema AND
                TABLE_NAME = :table_name
            SQL);
        $sth_down->execute([
            'schema' =>  static::$schema,
            'table_name' => static::$table_name
        ]);
        $sth_up->execute([
            'schema' =>  static::$schema,
            'table_name' => static::$table_name
        ]);
        return (object)[
            'down' => $sth_down->fetchAll(\PDO::FETCH_OBJ),
            'up' => $sth_up->fetchAll(\PDO::FETCH_OBJ)
        ];
    }

    protected static function _createRecord(...$properties):bool {
        $sth = static::$dbh->prepare(sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                static::$table_name,
                implode(', ', array_map(fn($p) => "`$p`", array_keys($properties))),
                implode(', ', array_map(fn($p) => ":$p", array_keys($properties)))
            ));
        return $sth->execute($properties);
    }

}