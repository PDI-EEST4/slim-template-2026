<?php

declare(strict_types=1);

class MySqlMigrationStrategy extends MigrationStrategy {
    public function ensureMigrationTable(): void {
        $this->pdo->exec(sprintf(
          'CREATE TABLE IF NOT EXISTS %s (
                    filename VARCHAR(255) NOT NULL PRIMARY KEY,
                    hash VARCHAR(64) NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
          $this->migrationTable
        ));
    }

    public function applyMigration(string $filename, string $sql, string $hash): void {
        $this->pdo->exec($sql);
        $this->markMigrationAsApplied($filename, $hash);
    }
}
