<?php

define("ObjQL\conf",
    json_decode(
        file_get_contents(__DIR__ .'/objql.conf')
    )
);

require_once __DIR__.'/Model.php';

spl_autoload_register(function($class_name) {
    $class_components = explode('\\', $class_name);
    if (count($class_components) > 2) return;
    try {
        $table_name = end($class_components);
        $schema = ObjQL\conf->{$class_components[0]}->dbname ?? ObjQL\conf->dbname ?? $class_components[0];
        $dbh = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s',
                ObjQL\conf->{$class_components[0]}->host ?? ObjQL\conf->host,
                $schema
            ),
            ObjQL\conf->{$class_components[0]}->user ?? ObjQL\conf->user,
            ObjQL\conf->{$class_components[0]}->pass ?? ObjQL\conf->pass
        );
        $sth = $dbh->prepare(<<<SQL
            SELECT *
            FROM INFORMATION_SCHEMA.TABLES
            WHERE
                TABLE_SCHEMA = :schema AND
                TABLE_NAME = :table_name
            SQL);
        $sth->execute([
            'schema' => $schema,
            'table_name' => $table_name
        ]);
        if ($sth->fetchAll()) {
            eval(sprintf(
                <<<PHP
                namespace %s {
                    final class %s extends \ObjQL\Model {
                        public static string \$schema;
                        public static string \$table_name;
                        public static \PDO \$dbh;
                    }
                }
                PHP,
                count($class_components) == 2 ? "$class_components[0]" : '',
                $table_name
            ));
            $class_name::$schema = $schema;
            $class_name::$table_name = $table_name;
            $class_name::$dbh = $dbh;
        }
    } catch (Exception) {
        return;
    }
});