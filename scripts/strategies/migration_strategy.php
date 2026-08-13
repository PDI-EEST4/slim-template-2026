<?php

declare(strict_types=1);

abstract class MigrationStrategy {
  public function __construct(protected readonly PDO $pdo, protected readonly string $migrationTable) {}

  abstract public function ensureMigrationTable(): void;
  abstract public function applyMigration(string $filename, string $sql, string $hash): void;

  protected function markMigrationAsApplied(string $filename, string $hash): void
  {
    $stmt = $this->pdo->prepare(sprintf(
      'INSERT INTO %s (filename, hash) VALUES (:filename, :hash)',
      $this->migrationTable
    ));

    $stmt->execute([
      'filename' => $filename,
      'hash' => $hash
    ]);
  }
}
