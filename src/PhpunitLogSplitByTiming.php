<?php

/**
 * This file is part of the phpunit-log-split-by-timing package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace App;

use SimpleXMLElement;

/**
 * PHPUnit log split by timing
 */
class PhpunitLogSplitByTiming
{
    /**
     * @param array $argv
     * @return void
     */
    public static function main(array $argv): void
    {
        $files = [];

        $argvFiles = self::getTestsFileByArgv($argv);
        $xmlFile = "phpunit-results.xml";
        $xmlData = simplexml_load_string(file_get_contents($xmlFile));
        if ($xmlData !== false) {
            $xmlArr = self::searchFilePath($xmlData);
            $files = self::flatten($xmlArr);
        }

        $addFiles = self::addMissingFiles($files, $argvFiles);
        $useList = self::removeMissingFiles($addFiles, $argvFiles);
        $groups = self::greedy($useList, 3);

        /**
         * @var int $i
         * @var array $iValue
         */
        foreach ($groups as $i => $iValue) {
            $groupNumber = $i + 1;
            self::createPhpUnitXml($iValue, $groupNumber);
        }
    }

    /**
     * @param \SimpleXMLElement $xmlData
     * @return array
     */
    public static function searchFilePath(SimpleXMLElement $xmlData): array
    {
        $files = [];

        foreach ($xmlData as $xmlDatum) {
            $attrs = $xmlDatum->attributes();

            if ($attrs === null) {
                return [];
            }

            if (
                empty($attrs->file) === false
            ) {
                $file = (string)$attrs->file;
                $time = (string)$attrs->time;
                $files[$file] = $time;
                continue;
            }

            $files[] = self::searchFilePath($xmlDatum);
        }
        return $files;
    }

    /**
     * Divide N time into K groups using the greedy method
     *
     * @param array{string: int} $files[file name:string => time:int]
     * @param int $numberSplit number to be split
     * @return array<int<-1, max>, list<string>>
     */
    public static function greedy(array $files, int $numberSplit): array
    {
        $groups = [];
        $groupTotalTime = [];

        arsort($files);

        // Initialize array for number of groups
        for ($i = 0; $i < $numberSplit; $i++) {
            $groups[$i] = [];
            $groupTotalTime[$i] = 0;
        }

        /**
         * @var string $file
         * @var float $time
         */
        foreach ($files as $file => $time) {
            // Find the group with the shortest time
            $minTime = 999999999;
            $minGroup = -1;
            for ($i = 0; $i < $numberSplit; $i++) {
                if ($groupTotalTime[$i] < $minTime) {
                    $minTime = $groupTotalTime[$i];
                    $minGroup = $i;
                }
            }

            // Add to the group with the shortest time
            $groups[$minGroup][] = $file;
            $groupTotalTime[$minGroup] += $time;
        }

        return $groups;
    }

    /**
     * @param array $array
     * @return array<string, float>
     */
    public static function flatten(array $array): array
    {
        $return = [];
        array_walk_recursive($array, callback: static function (string $value, string $key) use (&$return) {
            /** @var array<string, float> $return */
            $return[$key] = (float)$value;
        });
        /** @var array<string, float> $return */
        return $return;
    }

    /**
     * @param array $argv
     * @return string[]
     */
    public static function getTestsFileByArgv(array $argv): array
    {
        /** @var array<string> $argv */
        array_shift($argv);
        if (empty($argv) === false) {
            return $argv;
        }
        return [];
    }

    /**
     * Add files not included in previous runs
     *
     * @param array $files
     * @param string[] $argvFiles
     * @return array
     */
    public static function addMissingFiles(array $files, array $argvFiles): array
    {
        $filesKeys = array_keys($files);

        if (empty($argvFiles) === false) {
            foreach ($argvFiles as $argvFile) {
                if (in_array($argvFile, $filesKeys, true) === false) {
                    $files[$argvFile] = 0;
                }
            }
        }
        return $files;
    }

    /**
     * Delete files that exist only in past runs
     *
     * @param array $files
     * @param array $argvFiles
     * @return array
     */
    public static function removeMissingFiles(array $files, array $argvFiles): array
    {
        $filesKeys = array_keys($files);

        if (empty($argvFiles) === false) {
            foreach ($filesKeys as $filesKey) {
                if (in_array($filesKey, $argvFiles, true) === false) {
                    unset($files[$filesKey]);
                }
            }
        }
        return $files;
    }

    /**
     * Create phpunit.xml
     *
     * @param array $files
     * @param int $groupNumber
     * @return void
     */
    public static function createPhpUnitXml(array $files, int $groupNumber): void
    {
        $baseDir = realpath('./');
        $xmlFileStringData = [];
        /** @var string $file */
        foreach ($files as $file) {
            $xmlFileStringData[] = "<file>{$baseDir}/{$file}</file>";
        }
        $testFileString = implode("\n", $xmlFileStringData);
        $template = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit >
    <testsuites>
        <testsuite name="Test Case">
            {$testFileString}
        </testsuite>
    </testsuites>
</phpunit>
XML;

        file_put_contents("./ci_phpunit_{$groupNumber}.xml", $template);
    }
}

//PhpunitLogSplitByTiming::main($argv);
