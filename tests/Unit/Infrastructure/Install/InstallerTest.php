<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Infrastructure\Install;

use DrSoftFr\Module\HubAsYou\Infrastructure\Install\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Only the splitting of the SQL files: the rest of the class needs PrestaShop.
 */
final class InstallerTest extends TestCase
{
    private const SQL_DIR = __DIR__ . '/../../../../src/Infrastructure/Install/Resources/sql/';

    public function testEachStatementEndsWithASemicolonAtTheEndOfALine(): void
    {
        self::assertSame(
            ["CREATE TABLE a\n(\n    id INT\n)", 'DROP TABLE b'],
            Installer::splitStatements("CREATE TABLE a\n(\n    id INT\n);\n\nDROP TABLE b;\n")
        );
    }

    public function testCommentLinesAreRemoved(): void
    {
        self::assertSame(
            ['DROP TABLE a', 'DROP TABLE b'],
            Installer::splitStatements("-- first; with a semicolon\nDROP TABLE a;\n    -- second;\nDROP TABLE b;\n")
        );
    }

    public function testSemicolonInsideAValueDoesNotSplit(): void
    {
        self::assertSame(
            ["INSERT INTO a VALUES ('x;y')"],
            Installer::splitStatements("INSERT INTO a VALUES ('x;y');\n")
        );
    }

    public function testWindowsLineEndings(): void
    {
        self::assertSame(
            ['DROP TABLE a', 'DROP TABLE b'],
            Installer::splitStatements("DROP TABLE a;\r\nDROP TABLE b;\r\n")
        );
    }

    public function testLastStatementWithoutSemicolonIsKept(): void
    {
        self::assertSame(['DROP TABLE a', 'DROP TABLE b'], Installer::splitStatements("DROP TABLE a;\nDROP TABLE b"));
    }

    public function testNothingToRun(): void
    {
        self::assertSame([], Installer::splitStatements(''));
        self::assertSame([], Installer::splitStatements("-- only a comment\n\n"));
    }

    public function testInstallFileOfTheModule(): void
    {
        $statements = Installer::splitStatements((string) file_get_contents(self::SQL_DIR . 'install.sql'));

        self::assertCount(4, $statements);

        foreach ($statements as $statement) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $statement);
            self::assertStringEndsWith('DEFAULT CHARSET = utf8mb4', $statement);
        }
    }

    public function testUninstallFileOfTheModule(): void
    {
        $statements = Installer::splitStatements((string) file_get_contents(self::SQL_DIR . 'uninstall.sql'));

        self::assertCount(4, $statements);

        foreach ($statements as $statement) {
            self::assertStringStartsWith('DROP TABLE IF EXISTS', $statement);
        }
    }
}
