<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

class ReadFiles
{
    protected $lastError = '';

    /**
     * Check if file exist, can be readed and extension is right
     * @param string $file file to be checked
     * @param string $extension extension of the file
     * @return bool true if there's no error. False otherwise
     */
    protected function checkFile(string $file, string $extension): bool
    {
        if (!file_exists($file)) {
            $this->lastError = "<b>" . $file . "</b> doesn't exist<br/>";
            return false;
        }
        if (!is_readable($file)) {
            $this->lastError = "<b>" . $file . "</b> couldn't be read<br/>";
            return false;
        }
        if (preg_match('/^.+\.' . $extension . '/i', $file) <= 0) {
            $this->lastError = "<b>" . $file . "</b> isn't a " . $extension . " file<br/>";
            return false;
        }
        if (filesize($file) === 0) {
            $this->lastError = '<b>' . $file . ' file is empty</b>, verify the content again';
            return false;
        }
        return true;
    }
    /**
     * Get lastError, contains the information of the last error from the file checks.
     * @return string lastError
     */

    public function getLastError(): string
    {
        return $this->lastError;
    }
}
