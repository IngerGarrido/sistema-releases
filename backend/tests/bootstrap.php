<?php
/**
 * Test bootstrap: carga config.php sin abrir conexión a BD.
 *
 * config.php solo declara funciones y define constantes desde .env. La conexión PDO
 * se crea dentro de getDB(), por lo que es seguro hacer require aquí mientras los
 * tests no llamen a esa función.
 */

// Asegurar constantes mínimas si .env no existe (no se conecta a BD igualmente).
if (!getenv('DB_NAME')) {
    putenv('DB_NAME=test_db_unused');
}

require_once __DIR__ . '/../config.php';
