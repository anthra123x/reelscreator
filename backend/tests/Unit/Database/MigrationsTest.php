<?php

namespace Tests\Unit\Database;

use Tests\TestCase;

class MigrationsTest extends TestCase
{
    public function test_migration_files_exist_and_define_proper_tables(): void
    {
        $migrationFiles = glob(database_path('migrations/*.php'));
        $this->assertNotEmpty($migrationFiles);

        $expectedKeywords = [
            'users',
            'videos',
            'clips',
            'social_accounts',
            'publications',
            'publishing_logs',
        ];

        $allContent = '';
        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            $allContent .= "\n".$content;
            $migration = require $file;
            $this->assertTrue(is_object($migration) || is_string($migration));
        }

        foreach ($expectedKeywords as $keyword) {
            $this->assertStringContainsString("Schema::create('{$keyword}'", $allContent);
        }

        // Verify UUID and JSONB usages
        $this->assertStringContainsString("uuid('id')->primary()", $allContent);
        $this->assertStringContainsString("jsonb('metadata')", $allContent);
    }
}
