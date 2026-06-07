<?php

namespace Tests\Unit\Helpers;

class PhpStreamWrapperMock
{
    public $context;
    public static $buffer = '';
    private $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->position = 0;
        return true;
    }

    public function stream_read($count)
    {
        $ret = substr(self::$buffer, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_write($data)
    {
        self::$buffer .= $data;
        return strlen($data);
    }

    public function stream_eof()
    {
        return $this->position >= strlen(self::$buffer);
    }

    public function stream_stat()
    {
        return ['size' => strlen(self::$buffer)];
    }
    
    public function url_stat($path, $flags)
    {
        return ['size' => strlen(self::$buffer)];
    }
}
