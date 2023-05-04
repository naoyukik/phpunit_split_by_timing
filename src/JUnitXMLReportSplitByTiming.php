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
 * JUnit XML Reports split by timing
 */
class JUnitXMLReportSplitByTiming
{
    public const PARALLELISM = '1';
    public const OUTPUT = 'phpunit';
    public const INPUT = 'phpunit-results.xml';

    /**
     * @return array{"--input-log": string, "--output-log": string, "--parallelism": string}
     */
    private static function initOptions(): array
    {
        return [
            '--input-log' => self::INPUT,
            '--output-log' => self::OUTPUT,
            '--parallelism' => self::PARALLELISM,
        ];
    }

    /**
     * @param string[] $argv
     * @return void
     */
    public static function main(array $argv): void
    {
        $files = [];

        $argvs = self::getTestsFileByArgv($argv);

        $extractOptions = self::extractOptions($argvs);
        /**
         * @var array{
         *     "--input-log": string,
         *     "--output-log": string,
         *     "--parallelism": string,
         * } $options
         */
        $options = array_merge(self::initOptions(), $extractOptions);
        $argvFiles = self::deleteOptions($argvs, $options);

        $xmlFile = $options['--input-log'];
        $xmlData = simplexml_load_string(file_get_contents($xmlFile));
        if ($xmlData !== false) {
            $xmlArr = self::searchFilePath($xmlData);
            $files = self::flatten($xmlArr);
        }

        $addFiles = self::addMissingFiles($files, $argvFiles);
        $useList = self::removeMissingFiles($addFiles, $argvFiles);

        $parallelism = $options['--parallelism'];
        $groups = self::greedy($useList, (int)$parallelism);

        /**
         * @var int $i
         * @var array $iValue
         */
        foreach ($groups as $i => $iValue) {
            $groupNumber = $i + 1;
            self::createPhpUnitXml($iValue, $groupNumber, $options['--output-log']);
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
     * @param string[]|null $argvFiles
     * @return array
     */
    public static function addMissingFiles(array $files, ?array $argvFiles): array
    {
        $filesKeys = array_keys($files);

        if (empty($argvFiles) === true) {
            return [];
        }

        foreach ($argvFiles as $argvFile) {
            if (in_array($argvFile, $filesKeys, true) === false) {
                $files[$argvFile] = 0;
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
     * @param string $outputFileNamePrefix
     * @return void
     */
    public static function createPhpUnitXml(array $files, int $groupNumber, string $outputFileNamePrefix): void
    {
        if (empty($files) === true) {
            return;
        }

        $baseDir = realpath('./');
        $xmlFileStringData = [];
        /** @var string $file */
        foreach ($files as $file) {
            $xmlFileStringData[] = "<file>{$baseDir}/{$file}</file>";
        }
        $testFileString = implode("\n", $xmlFileStringData);
        $template = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Test Case">
            {$testFileString}
        </testsuite>
    </testsuites>
</phpunit>
XML;

        file_put_contents("./{$outputFileNamePrefix}{$groupNumber}.xml", $template);
    }

    /**
     * @param string[] $argvs
     * @return string[]
     */
    public static function extractOptions(array $argvs): array
    {
        $options = array_filter($argvs, static function ($argv) {
            return str_contains($argv, '--');
        });
        $options = array_map(static function ($argv) {
            return explode('=', $argv);
        }, $options);

        /** @var string[] $options */
        $options = array_reduce(
            $options,
            /**
             * @param array $carry
             * @param string[] $argv
             * @return array
             */
            static function (array $carry, array $argv): array {
                $carry[$argv[0]] = $argv[1];
                return $carry;
            },
            []
        );

        return $options;
    }

    /**
     * @param array $argvs
     * @param array<array-key, string> $options
     * @return string[]
     */
    public static function deleteOptions(array $argvs, array $options): array
    {
        /** @var array<array-key, string> $stringOptions */
        $stringOptions = [];

        foreach ($options as $index => $option) {
            $stringOptions[] = $index . '=' . $option;
        }

        /** @var string[] $remainingData */
        $remainingData = array_filter($argvs, static function ($argv) use ($stringOptions) {
            return in_array($argv, $stringOptions, true) === false;
        });

        return $remainingData;
    }
}

if (count(debug_backtrace()) === 0) {
    JUnitXMLReportSplitByTiming::main($argv);
}
