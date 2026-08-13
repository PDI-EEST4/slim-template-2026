<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

Dotenv::createImmutable($projectRoot)->safeLoad();

require_once $projectRoot . '/src/database/database.php';

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Este script solo puede ejecutarse desde CLI.\n");
  exit(1);
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
  fwrite(STDOUT, "Uso: php scripts/migrate.php\n");
  fwrite(STDOUT, "Aplica los archivos .sql pendientes de la carpeta de migraciones.\n");
  exit(0);
}

final class SqlMigrationRunner
{
  private const MIGRATION_TABLE = 'schema_migrations';
  private MigrationStrategy $strategy;

  public function __construct(
    private readonly Database $database,
    private readonly string $projectRoot,
  ) {
    $mappings = [
      "pgsql" => PostgresMigrationStrategy::class,
      "mysql" => MySqlMigrationStrategy::class,
    ];
    $driver = $database->getDriver();
    $pdo = $database->getConnection();

    $this->strategy = new ($mappings[$driver])($pdo, self::MIGRATION_TABLE);
  }

  public function run(): int
  {
    $migrationDirectory = $this->resolveMigrationDirectory();

    if ($migrationDirectory === null) {
      fwrite(STDERR, "No se encontró un directorio de migraciones.\n");
      return 1;
    }

    $pdo = $this->database->getConnection();
    $this->strategy->ensureMigrationTable();

    $migrations = $this->loadMigrations($migrationDirectory);

    if ($migrations === []) {
      fwrite(STDOUT, "No se encontraron archivos .sql para aplicar.\n");
      return 0;
    }

    $appliedMigrations = $this->getAppliedMigrations($pdo);

    foreach ($migrations as $migration) {
        if (!isset($appliedMigrations[$migration['filename']])) {
            continue;
        }

        if ($migration['hash'] !== $appliedMigrations[$migration['filename']]) {
            fwrite(STDERR, "⚠️  La migración {$migration['filename']} fue modificada después de aplicarse.\n");
            return 1;
        }
    }

    $pendingMigrations = array_values(array_filter(
        $migrations,
        static fn(array $migration): bool => !isset($appliedMigrations[$migration['filename']])
    ));

    if ($pendingMigrations === []) {
      fwrite(STDOUT, "No hay migraciones pendientes.\n");
      return 0;
    }

    fwrite(STDOUT, "Aplicando migraciones desde: {$migrationDirectory}\n");

    foreach ($pendingMigrations as $migration) {
      $this->applyMigration($migration);
    }

    fwrite(STDOUT, "Migraciones completadas correctamente.\n");
    return 0;
  }

  private function resolveMigrationDirectory(): ?string
  {
    $candidates = [
      $this->projectRoot . '/database/migrations',
      $this->projectRoot . '/src/database/migrations',
    ];

    $firstExistingDirectory = null;

    foreach ($candidates as $candidate) {
      if (!is_dir($candidate)) {
        continue;
      }

      if ($firstExistingDirectory === null) {
        $firstExistingDirectory = $candidate;
      }

      $sqlFiles = glob($candidate . '/*.sql');

      if ($sqlFiles !== false && $sqlFiles !== []) {
        return $candidate;
      }
    }

    return $firstExistingDirectory;
  }

  /**
   * @return array<int, array{filename: string, path: string, sql: string, hash: string}>
   */
  private function loadMigrations(string $migrationDirectory): array
  {
    $files = glob($migrationDirectory . '/*.sql');

    if ($files === false) {
      return [];
    }

    natsort($files);

    $migrations = [];

    foreach ($files as $filePath) {
      if (!is_file($filePath)) {
        continue;
      }

      $sql = file_get_contents($filePath);

      if ($sql === false || trim($sql) === "") {
        throw new RuntimeException(
          "La migración " . basename($filePath) . " está vacía o no se pudo leer."
        );
      }

      $migrations[] = [
        "filename" => basename($filePath),
        "path" => $filePath,
        "sql" => $sql,
        "hash" => hash("sha256", $sql),
      ];
    }

    return $migrations;
  }

  /**
   * @return array<int, string>
   */
  private function getAppliedMigrations(PDO $pdo): array
  {
    $stmt = $pdo->query(
      sprintf("SELECT filename, hash FROM %s", self::MIGRATION_TABLE),
    );

    if ($stmt === false) {
      return [];
    }

    $applied = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $applied[$row["filename"]] = $row["hash"];
    }

    return $applied;
  }

  /**
   * @param array{filename: string, path: string, sql: string, hash: string} $migration
   */
  private function applyMigration(array $migration): void
  {
    fwrite(STDOUT, 'Aplicando ' . $migration['filename'] . "...\n");

    try {
      $this->strategy->applyMigration($migration["filename"], $migration["sql"], $migration["hash"]);

      fwrite(STDOUT, 'Aplicada ' . $migration['filename'] . "\n");
    } catch (Throwable $throwable) {
      throw new RuntimeException(
        'Error al aplicar ' . $migration['filename'] . ': ' . $throwable->getMessage(),
        0,
        $throwable
      );
    }
  }
}

try {
  $runner = new SqlMigrationRunner(new Database(), $projectRoot);
  exit($runner->run());
} catch (Throwable $throwable) {
  fwrite(STDERR, $throwable->getMessage() . "\n");
  exit(1);
}
