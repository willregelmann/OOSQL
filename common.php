<?php
namespace OOSQL;

define('CONFIG', yaml_parse_file(__DIR__.'/config.yml'));

spl_autoload_register(function (string $class_name) {

    if (preg_match('/OOSQL\\\\(.+)$/', $class_name, $matches)) {
        try {
            include_once sprintf('%s/%s.php', __DIR__, $matches[1]);
        } catch (\Exception) {}
    }
    
});