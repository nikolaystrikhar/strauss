<?php

namespace BrianHenryIE\Strauss\Tests\Unit;

use BrianHenryIE\Strauss\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\DiscoveredFiles;
use Mockery;

/**
 * Class DiscoveredFilesTest.
 *
 * @coversDefaultClass \BrianHenryIE\Strauss\DiscoveredFiles
 *
 * @package BrianHenryIE\Strauss\Tests\Unit
 */
class DiscoveredFilesTest extends TestCase
{
    /**
     * Tests that a file can be added and gotten.
     *
     * @return void
     *
     * @author NikolayStrikhar
     */
    public function testFileCanBeAddedAndGotten()
    {
        // Arrange.

        $discovered_files = new DiscoveredFiles();

        $file = Mockery::mock(File::class);
        $file->shouldReceive('getTargetRelativePath')->andReturn('path/to/file1.php');

        // Act.

        $discovered_files->add($file);

        // Assert.

        $this->assertEquals(
            ['path/to/file1.php' => $file],
            $discovered_files->getFiles()
        );
    }

    /**
     * Tests that multiple files with different paths can be added and gotten.
     *
     * @return void
     *
     * @author NikolayStrikhar
     */
    public function testFileMultipleFilesWithDifferentPathsCanBeAddedAndGotten()
    {
        // Arrange.

        $discovered_files = new DiscoveredFiles();

        $file1 = Mockery::mock(File::class);
        $file1->shouldReceive('getTargetRelativePath')->andReturn('path/to/file1.php');

        $file2 = Mockery::mock(File::class);
        $file2->shouldReceive('getTargetRelativePath')->andReturn('path/to/file2.php');

        // Act.

        $discovered_files->add($file1);
        $discovered_files->add($file2);

        // Assert.

        $this->assertEquals(
            [
                'path/to/file1.php' => $file1,
                'path/to/file2.php' => $file2,
            ],
            $discovered_files->getFiles()
        );
    }

    /**
     * Tests that files are overwritten when they have the same path.
     *
     * @return void
     *
     * @author NikolayStrikhar
     */
    public function testFilesWithSamePathsAreOverwritten()
    {
        // Arrange.

        $discovered_files = new DiscoveredFiles();

        $file1 = Mockery::mock(File::class);
        $file1->shouldReceive('getTargetRelativePath')->andReturn('path/to/file1.php');

        $file2 = Mockery::mock(File::class);
        $file2->shouldReceive('getTargetRelativePath')->andReturn('path/to/file1.php');

        // Act.

        $discovered_files->add($file1);
        $discovered_files->add($file2); // This should overwrite file 1.

        // Assert.

        $this->assertEquals(
            ['path/to/file1.php' => $file2],
            $discovered_files->getFiles()
        );
    }
}
