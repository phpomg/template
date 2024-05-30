<?php

declare(strict_types=1);

namespace PHPOMG\Template;

class Stream
{
    private $position;
    private $varname;
    private static $datas = [];

    public function stream_open($path)
    {
        $this->varname = $path;
        if (!isset(self::$datas[$this->varname])) {
            self::$datas[$this->varname] = '';
        }
        $this->position = 0;
        return true;
    }

    public function stream_read($count)
    {
        $p = &$this->position;
        $ret = substr(self::$datas[$this->varname], $p, $count);
        $p += strlen($ret);
        return $ret;
    }

    public function stream_write($data)
    {
        $v = &self::$datas[$this->varname];
        $l = strlen($data);
        $p = &$this->position;
        $v = substr($v, 0, $p) . $data . substr($v, $p += $l);
        return $l;
    }

    public function stream_tell()
    {
        return $this->position;
    }

    public function stream_eof()
    {
        return $this->position >= strlen(self::$datas[$this->varname]);
    }

    public function stream_seek($offset, $whence)
    {
        $l = strlen(self::$datas[$this->varname]);
        $p = &$this->position;
        switch ($whence) {
            case SEEK_SET:
                $newPos = $offset;
                break;
            case SEEK_CUR:
                $newPos = $p + $offset;
                break;
            case SEEK_END:
                $newPos = $l + $offset;
                break;
            default:
                return false;
        }
        $ret = ($newPos >= 0 && $newPos <= $l);
        if ($ret) {
            $p = $newPos;
        }

        return $ret;
    }

    public function stream_stat()
    {
    }

    public function url_stat()
    {
    }

    public function stream_set_option()
    {
    }
}
