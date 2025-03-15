<?php

class MyZipArchive
{
    protected $_type;
    protected $_encoding;
    protected $_files;
    protected $_file;
    public function open($file)
    {
        $this->_file = $file;
        $mime = mime_content_type($file);
        if ($mime === 'application/zip') {
            // check encoding
            foreach (['UTF-8', 'Big5'] as $encoding) {
                $cmd = sprintf("zipinfo -1 -O %s %s", 
                    escapeshellarg($encoding), escapeshellarg($file));
                $output = shell_exec($cmd);
                // check encoding
                if ($output == @iconv('Big5', 'UTF-8', @iconv('UTF-8', 'Big5', $output))) {
                    $this->_encoding = $encoding;
                    $this->_files = explode("\n", $output);
                    $this->numFiles = count($this->_files);
                    break;
                }
            }
            $this->_type = 'zip';
            if (!$this->_encoding) {
                throw new Exception('Unknown encoding');
            }
        } elseif ('application/x-rar' === $mime) {
            $this->_type = 'rar';
            $cmd = sprintf("unrar lb -O %s", escapeshellarg($file));
            $output = trim(shell_exec($cmd));
            $this->_files = explode("\n", $output);
            $this->numFiles = count($this->_files);
        } elseif ('application/x-7z-compressed' == $mime) {
            $this->_type = '7z';
            $cmd = sprintf("7z l -ba -slt -r -so %s", escapeshellarg($file));
            $output = shell_exec($cmd);
            $this->_files = [];
            $lines = explode("\n", $output);
            $name = '';
            foreach ($lines as $line) {
                if (preg_match('/^Path = (.+)$/', $line, $matches)) {
                    $name = $matches[1];
                } elseif (preg_match('/^Size = (\d+)$/', $line, $matches)) {
                    $this->_files[] = $name;
                }
            }
            $this->numFiles = count($this->_files);
        } else {
            throw new Exception('TODO: ' . $mime);
        }
        return true;
    }

    public function locateName($name)
    {
        return in_array($name, $this->_files);
    }

    public function getStreamName($name)
    {
        if ($this->_type == 'zip') {
            $cmd = sprintf("unzip -p -O %s %s %s", 
                escapeshellarg($this->_encoding), escapeshellarg($this->_file), escapeshellarg($name));
            return popen($cmd, 'r');
        } elseif ($this->_type == 'rar') {
            $cmd = sprintf("unrar p -inul -O %s %s %s", 
                escapeshellarg($this->_encoding), escapeshellarg($this->_file), escapeshellarg($name));
            return popen($cmd, 'r');
        } elseif ($this->_type == '7z') {
            $cmd = sprintf("7z e -so %s %s", escapeshellarg($this->_file), escapeshellarg($name));
            return popen($cmd, 'r');
        }
    }

    public function getNameIndex($index)
    {
        return $this->_files[$index];
    }

    public function getFromIndex($index)
    {
        $fp = $this->getStreamName($this->getNameIndex($index));
        $data = stream_get_contents($fp);
        fclose($fp);
        return $data;
    }
}

