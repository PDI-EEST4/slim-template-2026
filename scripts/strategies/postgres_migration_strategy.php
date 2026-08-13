<?php

declare(strict_types=1);

class PostgresMigrationStrategy extends MigrationStrategy {
    public function ensureMigrationTable(): void {
        $this->pdo->exec(sprintf(
          'CREATE TABLE IF NOT EXISTS %s (
                      filename VARCHAR(255) PRIMARY KEY,
                      hash VARCHAR(64) NOT NULL,
                      applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                  )',
          $this->migrationTable
        ));
    }

    public function applyMigration(string $filename, string $sql, string $hash): void {
        $this->pdo->beginTransaction();

        try {
          $this->pdo->exec($sql);
          $this->markMigrationAsApplied($filename, $hash);
          $this->pdo->commit();
        } catch (Throwable $throwable) {
          if ($this->pdo->inTransaction()) {
              $this->pdo->rollBack();
          }

          throw $throwable;
        }
    }
}
