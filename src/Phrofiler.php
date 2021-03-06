<?php
namespace Sharils;

use ErrorException;

class Phrofiler
{
    const TIME_FILENAME_PREFIX = 'php-phrofiler-time-';

    const TIME_TEMPLATE_IIFE = <<<'PHP'
#!/usr/bin/env php
<?php
set_error_handler(function () {
    fwrite(STDERR, implode(PHP_EOL, array_slice(func_get_args(), 0, 4)));
    exit(func_get_arg(0));
});

call_user_func(function () {
    ob_start();

    %s;

    $_ = microtime(true);
    for ($__ = %d; --$__; ) {
        %s;
    }
    $_ = microtime(true) - $_;

    %s;

    ob_end_clean();

    fwrite(STDERR, $_);
});

PHP;

    const TIME_TEMPLATE_NO_IIFE = <<<'PHP'
#!/usr/bin/env php
<?php
set_error_handler(function () {
    fwrite(STDERR, implode(PHP_EOL, array_slice(func_get_args(), 0, 4)));
    exit(func_get_arg(0));
});

ob_start();

%s;

$_ = microtime(true);
for ($__ = %d; --$__; ) {
    %s;
}
$_ = microtime(true) - $_;

%s;

ob_end_clean();

fwrite(STDERR, $_);

PHP;

    const WHOLE_FILENAME_PREFIX = 'php-phrofiler-whole-';

    const WHOLE_TEMPLATE = <<<'PHP'
#!/usr/bin/env php
<?php
%s;

%s;

%s;

PHP;

    private $iife = false;

    private $loopCount = 100000;

    private $setUp = null;

    private $tearDown = null;

    private $timeCount = 10;

    public function iife($iife = null)
    {
        if (func_num_args() === 0) {
            return $this->iife;
        } else {
            assert('is_bool($iife)');

            $this->iife = $iife;
        }
    }

    public function loopCount($loopCount = null)
    {
        if (func_num_args() === 0) {
            return $this->loopCount;
        } else {
            assert('is_int($loopCount) && 0 < $loopCount && $loopCount <= 1000000');

            $this->loopCount = $loopCount;
        }
    }

    public function profile(array $snippets)
    {
        $timeFilenames = array_map([$this, 'toTimeFilename'], $snippets);

        $wholeFilenames = array_map([$this, 'toWholeFilename'], $snippets);

        foreach ($timeFilenames as $timeFilename) {
            $times[] = $this->toTime($timeFilename);
        }

        $minTime = min($times);
        $ratios = array_map(function ($time) use ($minTime) {
            return round($minTime / $time * 100) / 100;
        }, $times);

        $data = array_map(
            [$this, 'toObject'],
            $snippets,
            $timeFilenames,
            $wholeFilenames,
            $times,
            $ratios
        );

        usort($data, [$this, 'lowToHigh']);

        return $data;
    }

    public function setUp($setUp = null)
    {
        if (func_num_args() === 0) {
            return $this->setUp;
        } else {
            assert('is_string($setUp)');

            $this->setUp = $setUp;
        }
    }

    public function tearDown($tearDown = null)
    {
        if (func_num_args() === 0) {
            return $this->tearDown;
        } else {
            assert('is_string($tearDown)');

            $this->tearDown = $tearDown;
        }
    }

    private function lowToHigh($low, $high)
    {
        return $low->time < $high->time ? -1 : 1;
    }

    private function timeTemplate()
    {
        return $this->iife ?
            self::TIME_TEMPLATE_IIFE :
            self::TIME_TEMPLATE_NO_IIFE;
    }

    private function toObject(
        $snippet,
        $timeFilename,
        $wholeFilename,
        $time,
        $ratio
    ) {
        return (object) get_defined_vars();
    }

    private function toFilename($prefix, $snippet)
    {
        $filename = sys_get_temp_dir() .
            DIRECTORY_SEPARATOR .
            $prefix .
            md5($this->setUp . $snippet . $this->tearDown . $this->iife);

        return $filename;
    }

    private function toTime($filename)
    {
        assert('is_readable($filename)');

        for ($timeCount = 0; $timeCount < $this->timeCount; $timeCount++) {
            $proc = proc_open($filename, [
                2 => ['pipe', 'w'],
            ], $pipes);
            assert('is_resource($proc)');

            $info = stream_get_contents($pipes[2]);
            assert('$info');

            if (is_numeric($info)) {
                $time = $info;
            } else {
                $info = explode(PHP_EOL, $info);
                assert('count($info) === 4');

                list($severity, $message, $file, $line) = $info;
                throw new ErrorException(
                    $message,
                    0,
                    $severity,
                    $file,
                    $line
                );
            }

            $times[] = $time;
        }

        $time = array_sum($times) / $this->timeCount;

        return $time;
    }

    private function toTimeFilename($snippet)
    {
        assert('is_string($snippet)');

        $timeFilename = $this->toFilename(self::TIME_FILENAME_PREFIX, $snippet);
        assert('$timeFilename !== false');

        if (!is_readable($timeFilename)) {
            $content = $this->toTimePhp($snippet);

            $success = file_put_contents($timeFilename, $content);
            assert('$success !== false');

            $success = chmod($timeFilename, 0777);
            assert('$success !== false');
        }

        return $timeFilename;
    }

    private function toTimePhp($snippet)
    {
        assert('is_string($snippet)');

        return sprintf(
            $this->timeTemplate(),
            $this->setUp(),
            $this->loopCount(),
            $snippet,
            $this->tearDown()
        );
    }

    private function toWholeFilename($snippet)
    {
        assert('is_string($snippet)');

        $wholeFilename = $this->toFilename(self::WHOLE_FILENAME_PREFIX, $snippet);
        assert('$wholeFilename !== false');

        if (!is_readable($wholeFilename)) {
            $content = $this->toWholePhp($snippet);

            $success = file_put_contents($wholeFilename, $content);
            assert('$success !== false');

            $success = chmod($wholeFilename, 0777);
            assert('$success !== false');
        }

        return $wholeFilename;
    }

    private function toWholePhp($snippet)
    {
        assert('is_string($snippet)');

        return sprintf(
            self::WHOLE_TEMPLATE,
            $this->setUp(),
            $snippet,
            $this->tearDown()
        );
    }
}
