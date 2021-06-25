<?php

namespace OOSQL;

include_once __DIR__.'/OOSQL.php';

class Doppel {
            
    public function __construct(
        private OOSQL $__conn,
        public string $__class,
        public ?string $__id = null,
        bool $autoload = true
    ) {
        $schema = $__conn->parseSchema($__class);
        if ($autoload) {
            foreach (get_object_vars($schema) as $property=>$default) {
                $this->{$property} = $default;
            }
        }
        $__id && $this->load($autoload);
    }
    
    public function load(bool $continue = false):bool {
        $keychain = $this->__conn->getKeys();
        $primary_key = $keychain->{$this->__class}->primary;
        try {
            $stmt = $this->__conn->prepare(sprintf(
                    'SELECT * FROM %s WHERE %s = ?',
                    $this->__class,
                    $primary_key
                ));
            $stmt->execute([$this->__id]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return false;
        }
        foreach ($result[0] as $property=>$value) {
            if ($property == $keychain->{$this->__class}->primary) {
                continue;
            }
            foreach ($keychain->{$this->__class}->upstream as $upstream) {
                if ($property == $upstream->key) {
                    $this->{$upstream->class} = new Doppel($this->__conn, $upstream->class, $value, false);
                    $continue && $this->{$upstream->class}->load();
                    continue 2;
                }
            }
            $this->{$property} = $value;
        }
        if ($continue) {
            foreach ($keychain->{$this->__class}->downstream as $downstream) {
                $stmt = $this->__conn->prepare(sprintf(
                        'SELECT * FROM %s WHERE %s = ?',
                        $downstream->class,
                        $downstream->property
                    ));
                $stmt->execute([$result[0][$downstream->key]]);
                $downstream_objects = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if ($keychain->{$downstream->class}->primary) {
                    $this->{$downstream->class} = $downstream_objects;
                    array_walk($this->{$downstream->class}, function(&$downstream_object) use ($downstream, $keychain) {
                        $downstream_object = new Doppel(
                                $this->__conn,
                                $downstream->class,
                                $downstream_object[$keychain->{$downstream->class}->primary],
                                false
                            );
                    });
                } else {
                    $teething_ring = $keychain->{$downstream->class}->upstream;
                    $invert_downstream = (object)[
                        'key' => $downstream->property,
                        'property' => $downstream->key,
                        'class' => $this->__class
                    ];
                    unset($teething_ring[array_search($invert_downstream, $teething_ring)]);
                    $teething_ring = array_values($teething_ring);
                    $this->{$downstream->class} = $downstream_objects;
                    foreach ($this->{$downstream->class} as &$downstream_object) {
                        $coven = new \stdClass;
                        foreach ($teething_ring as $sister) {
                            $coven->{$sister->class} = new Doppel(
                                    $this->__conn,
                                    $sister->class,
                                    $downstream_object[$sister->key],
                                    false
                                );
                        }
                        $downstream_object = $coven;
                    }
                }
            }
        }
        return true;
    }
    
    public function clone():Doppel {
        $clone = clone $this;
        unset($clone->__id);
        return $clone;
    }
    
    public function write():bool {
        $keychain = $this->__conn->getKeys();
        $schema = $this->__conn->parseSchema($this->__class);
        $shallow_fields = array_keys(get_object_vars($schema));
        $upstream_keys = $keychain->{$this->__class}->upstream;
        $updates = [];
        $values = [];
        foreach ($upstream_keys as $upstream) {
            unset($shallow_fields[array_search($upstream->class, $shallow_fields)]);
            array_push($updates, sprintf('`%s` = ?', $upstream->key));
            array_push($values, $this->{$upstream->class}->__id);
        }
        foreach ($shallow_fields as $shallow_field) {
            array_push($updates, "`$shallow_field` = ?");
            array_push($values, $this->{$shallow_field});
        }
        if ($this->__id ?? false) {
            array_push($values, $this->__id);
            $stmt = $this->__conn->prepare(sprintf(
                    'UPDATE %s SET %s WHERE %s = ?',
                    $this->__class,
                    implode(', ', $updates),
                    $keychain->{$this->__class}->primary
                ));
            $stmt->execute($values);
        } else {
            $stmt = $this->__conn->prepare(sprintf(
                    'INSERT INTO %s (`%s`) VALUES (%s)',
                    $this->__class,
                    implode('`, `', $shallow_fields),
                    implode(', ', array_fill(0, count($shallow_fields), '?'))
                ));
            $stmt->execute($values) && $this->__id = $this->__conn->lastInsertId();
        }
        foreach($keychain->{$this->__class}->downstream ?? [] as $downstream) {
            $stmt = $this->__conn->prepare(sprintf(
                    'DELETE FROM %s WHERE %s = ?',
                    $downstream->class,
                    $downstream->property
                ));
            $stmt->execute([$this->__id]);
            $property = $downstream->class;
            $update = [];
            $values = [];
            foreach ($keychain->{$downstream->class}->upstream as $sister) {
                array_push($update, $sister->key);
            }
            foreach ($this->{$property} as $relation) {
                foreach ($keychain->{$downstream->class}->upstream as $sister) {
                    array_push(
                            $values,
                            $sister->class == $this->__class ?
                                $this->__id :
                                $relation->__id ?? $relation->{$sister->class}->__id
                    );
                }
            }
            $stmt = $this->__conn->prepare(sprintf(
                    'INSERT INTO %s (`%s`) VALUES (%s)',
                    $downstream->class,
                    implode('`, `', $update),
                    implode('), (', array_fill(0, count($this->{$property}), implode(', ', array_fill(0, count($update), '?'))))
                ));
            $stmt->execute($values);
        }
        return true;
    }
    
    public function delete():bool {
        $keychain = $this->__conn->getKeys();
        $stmt = $this->__conn->prepare(sprintf(
                'DELETE FROM %s WHERE %s = ?',
                $this->__class,
                $keychain->{$this->__class}->primary
            ));
        return $stmt->execute([$this->__id]);
    }
    
    public function __toString():string {
        $obj = clone $this;
        unset($obj->__conn);
        return json_encode(get_object_vars($obj), JSON_PRETTY_PRINT);
    }
    
}
