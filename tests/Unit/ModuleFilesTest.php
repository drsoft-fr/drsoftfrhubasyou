<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The rules of PrestaShop's module validator, checked on every file of the module, so that
 * a new file or folder cannot forget them.
 */
final class ModuleFilesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Not part of the module's own files (hidden folders are skipped too: .git, .idea…) */
    private const SKIPPED_FOLDERS = ['vendor', 'dist'];

    /** API Platform runs every file of this folder: an index.php there would break the API */
    private const NO_INDEX = ['src/ApiPlatform/Resources'];

    /** Run before PrestaShop (tests) or without it (PHP CS Fixer) */
    private const NO_GUARD = ['.php-cs-fixer.dist.php', 'tests/Unit/bootstrap.php', 'tests/Integration/bootstrap.php'];

    private const GUARD = "if (!defined('_PS_VERSION_')) {\n    exit;\n}";

    public function testTheWebServerServesNothingButTheImages(): void
    {
        $htaccess = (string) @file_get_contents(self::ROOT . '/.htaccess');

        self::assertStringContainsString('Require all denied', $htaccess);
        self::assertStringContainsString('Deny from all', $htaccess);
        self::assertMatchesRegularExpression('/<FilesMatch "[^"]*png[^"]*">\s*Require all granted/', $htaccess, 'The logo of the module stays visible in the back office');
    }

    public function testEveryFolderHasAnIndexFile(): void
    {
        $missing = [];

        foreach ($this->folders() as $folder) {
            if (!in_array($folder, self::NO_INDEX, true) && !is_file(self::ROOT . '/' . ($folder === '' ? '' : $folder . '/') . 'index.php')) {
                $missing[] = $folder === '' ? '/' : $folder;
            }
        }

        self::assertSame([], $missing);

        foreach (self::NO_INDEX as $folder) {
            self::assertFileDoesNotExist(self::ROOT . '/' . $folder . '/index.php');
        }
    }

    public function testEveryPhpFileStopsOutsidePrestaShop(): void
    {
        $missing = [];

        foreach ($this->files('php') as $path => $content) {
            if (basename($path) === 'index.php' || in_array($path, self::NO_GUARD, true)) {
                continue;
            }

            $guard = strpos($content, self::GUARD);
            preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) /m', $content, $declaration, PREG_OFFSET_CAPTURE);

            // Before the class: loading the file outside PrestaShop runs nothing
            if ($guard === false || ($declaration && $declaration[0][1] < $guard)) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryFileHasTheLicenseHeader(): void
    {
        $missing = [];

        foreach ($this->files('php') as $path => $content) {
            // Right after the opening tag, no blank line: the validator's license check refuses one
            if (!preg_match('~\A<\?php\n/\*\*\n(?: \*.*\n)*? \* @license   https://opensource\.org/licenses/MIT MIT License\n~', $content)) {
                $missing[] = $path;
            }
        }

        foreach ($this->files('tpl') as $path => $content) {
            if (!preg_match('~\A\{\*\*\n(?: \*.*\n)*? \* @license   https://opensource\.org/licenses/MIT MIT License\n~', $content)) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * @return string[] Paths relative to the module, '' for its root
     */
    private function folders(): array
    {
        $folders = [''];

        foreach ($this->iterator() as $item) {
            if ($item->isDir()) {
                $folders[] = $this->relative($item);
            }
        }

        sort($folders);

        return $folders;
    }

    /**
     * @return array<string, string> Path relative to the module => content
     */
    private function files(string $extension): array
    {
        $files = [];

        foreach ($this->iterator() as $item) {
            if ($item->isFile() && $item->getExtension() === $extension) {
                $files[$this->relative($item)] = (string) file_get_contents($item->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function iterator(): iterable
    {
        $directory = new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            fn (\SplFileInfo $item): bool => !($item->isDir() && (str_starts_with($item->getFilename(), '.') || in_array($this->relative($item), self::SKIPPED_FOLDERS, true)))
        );

        return new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
    }

    private function relative(\SplFileInfo $item): string
    {
        return ltrim(substr($item->getPathname(), strlen(self::ROOT)), '/');
    }
}
