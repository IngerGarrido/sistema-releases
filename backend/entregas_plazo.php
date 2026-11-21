#!/usr/bin/env php
<?php
/**
 * Compatibilidad: el cron viejo de Entregas ahora corre TODOS los procesos
 * diarios. Usar backend/procesos_diarios.php en crons nuevos.
 */
require __DIR__ . '/procesos_diarios.php';
