<?php

namespace Javansolo\HttpSendFile;

use Exception;

class SendFile
{
    /**
     * if false we set content disposition from file that will be sent.
     *
     * @var mixed
     */
    protected $disposition = false;

    /**
     * throttle speed in second.
     *
     * @var float
     */
    protected $sec = 0.1;

    /**
     * bytes per $sec.
     *
     * @var int
     */
    protected $bytes = 40960;

    /**
     * if contentType is false we try to guess it.
     *
     * @var mixed
     */
    protected $type = false;

    /**
     * set content disposition.
     *
     * @param mixed $file_name
     */
    public function contentDisposition($file_name = false)
    {
        $this->disposition = $file_name;
    }

    /**
     * set throttle speed.
     *
     * @param float $sec
     * @param int   $bytes
     */
    public function throttle(float $sec = 0.1, int $bytes = 40960)
    {
        $this->sec = $sec;
        $this->bytes = $bytes;
    }

    /**
     * set content mime type if false we try to guess it.
     *
     * @param string|bool $content_type
     */
    public function contentType($content_type = null)
    {
        $this->type = $content_type;
    }

    /**
     * Sets-up headers and starts transfer bytes.
     *
     * @param string                   $file_path
     * @param bool                     $withDisposition
     * @param callable|callable-string $callBackEndOfFile
     *
     * @throws Exception
     */
    public function send(string $file_path, bool $withDisposition = true, $callBackEndOfFile = null)
    {
        if (!is_readable($file_path)) {
            throw new Exception('File not found or inaccessible!');
        }

        $size = filesize($file_path);
        if (!$this->disposition) {
            $this->disposition = $this->name($file_path);
        }

        if (!$this->type) {
            $this->type = $this->getContentType($file_path);
        }

        //turn off output buffering to decrease cpu usage
        $this->cleanAll();

        // required for IE, otherwise Content-Disposition may be ignored
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }

        header('Content-Type: '.$this->type);
        if ($withDisposition) {
            header('Content-Disposition: attachment; filename="'.$this->disposition.'"');
        }
        header('Accept-Ranges: bytes');

        // The three lines below basically make the
        // download non-cacheable
        header('Cache-control: private');
        header('Pragma: private');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        // multipart-download and download resuming support
        if (isset($_SERVER['HTTP_RANGE'])) {
            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            list($range) = explode(',', $range, 2);
            list($range, $range_end) = explode('-', $range);
            $range = intval($range);
            if (!$range_end) {
                $range_end = $size - 1;
            } else {
                $range_end = intval($range_end);
            }

            $new_length = $range_end - $range + 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Length: $new_length");
            header("Content-Range: bytes $range-$range_end/$size");
        } else {
            $new_length = $size;
            header('Content-Length: '.$size);
        }

        /* output the file itself */
        $chunkSize = $this->bytes;
        $bytes_send = 0;

        $file = @fopen($file_path, 'rb');
        if ($file) {
            if (isset($range) and $range > 0) {
                fseek($file, $range);
            }
            while (!feof($file) && (!connection_aborted()) && ($bytes_send < $new_length)) {
                $buffer = fread($file, $chunkSize);
                echo $buffer;
                flush();
                usleep($this->sec * 1000000);
                $bytes_send += strlen($buffer);
            }
            if (feof($file) and is_callable($callBackEndOfFile)) {
                call_user_func($callBackEndOfFile);
            }
            fclose($file);
        } else {
            throw new Exception('Error - can not open file.');
        }
    }

    /**
     * get name from path info.
     *
     * @param mixed $file
     *
     * @return string
     */
    protected function name($file): string
    {
        $info = pathinfo($file);

        return $info['basename'];
    }

    /**
     * method for getting mime type of file.
     *
     * @param string $path
     *
     * @return string $mime_type
     */
    protected function getContentType(string $path)
    {
        $result = false;
        if (is_file($path) === true) {
            if (function_exists('finfo_open') === true) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if (is_resource($finfo) === true) {
                    $result = finfo_file($finfo, $path);
                }
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type') === true) {
                $result = preg_replace('~^(.+);.*$~', '$1', mime_content_type($path));
            } elseif (function_exists('exif_imagetype') === true) {
                $result = image_type_to_mime_type(exif_imagetype($path));
            }
        }

        return $result;
    }

    /**
     * clean all buffers.
     */
    protected function cleanAll()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
}
