# JUnit XML Report Split By Timing
JUnit XML Report Split By Timing is a tool to split JUnit XML report by timing.

## Usage
```bash
find {Project Directory}/tests/ -name *Test.php \
  | xargs php src/JUnitXMLReportSplitByTiming.php
```

### Options
- --output-log: Specifies the prefix of the output log file. The name of the output file will be `{output-log} split-number.xml`. The default is `junit-xml-report-split-by-timing`.
- --input-log: Specifies the name of the log file to be input. The default is `junit-xml-report-split-by-timing`.
- --parallelism: Specifies the number of parallels. Default is `1`.
