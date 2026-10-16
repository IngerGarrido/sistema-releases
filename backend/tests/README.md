# Backend tests (PHPUnit)

## Requisitos

- PHP >= 8.1
- [Composer](https://getcomposer.org/) instalado en el sistema

## Instalación

```bash
cd backend
composer install
```

Esto instalará PHPUnit 10 en `vendor/`.

## Ejecutar tests

```bash
cd backend
vendor/bin/phpunit
```

O usando el script de composer (si se agrega) o directamente con la config:

```bash
vendor/bin/phpunit --configuration phpunit.xml
```

## Notas

- El bootstrap (`tests/bootstrap.php`) carga `config.php` sin abrir conexión a la
  base de datos. La conexión PDO solo se crea cuando se invoca `getDB()`, y los
  tests actuales no la usan.
- Si agregás tests que requieran BD, montá una base de datos de prueba y exponé
  sus credenciales por variables de entorno antes de correr `phpunit`.
