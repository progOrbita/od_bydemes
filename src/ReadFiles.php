<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

class ReadFiles
{
    protected $lastError = '';
    private $message = '';

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
    /**
     * Get message, information about saving a json file
     * @return string message
     */
    public function getMessage(): string
    {
        return $this->message;
    }
    /**
     * Compare the json file with the array of csv files to find distinct values beetwen both
     * @param array $jsonDecoded json which have right values
     * @param array $csvFiles csv array with the files
     * @return bool|array Array with the errors located beetwen both files. True if no errors were found, false if keys differs.
     */
    public function findErrors(array $jsonDecoded, array $csvFiles)
    {

        $dataError = [];
        foreach ($jsonDecoded as $id => $value) {
            if (array_keys($jsonDecoded[$id]) !== array_keys($csvFiles[$id])) {
                return false;
            }
            for ($id_lang = 1; $id_lang <= 4; $id_lang++) {
                if ($jsonDecoded[$id]['Titulo'][$id_lang] !== $csvFiles[$id]['Titulo'][$id_lang]) {
                    $dataError[$id]['Titulo'][$id_lang] = $csvFiles[$id]['Titulo'][$id_lang];
                }
                if ($jsonDecoded[$id]['Description'][$id_lang] !== $csvFiles[$id]['Description'][$id_lang]) {
                    $dataError[$id]['Description'][$id_lang] = $csvFiles[$id]['Description'][$id_lang];
                }
            }
        }
        return empty($dataError) ? true : $dataError;
    }
    /**
     * Attempts to writes data into a json file
     * @param array $data array containing all the information
     * @param string $prefix optional, add a prefix to the file
     * @return string A message showing the result
     */
    public function saveJson(array $data, string $prefix = ''): bool
    {
        $this->message = '';
        $csvData = json_encode($data, JSON_PRETTY_PRINT, JSON_FORCE_OBJECT);
        $currentDate = date('d_M_Y'); //day, short month and year 4 digits
        $dir = getcwd() . '/rates_processed'; //takes current script directory
        $file = $dir . '/' . $prefix . '_' . $currentDate . '.json';
        //Check and attempt to create the directory (directory can be nested)
        if (!is_dir($dir)) {
            $this->message .= 'Directory <b>' . $dir . '</b> not found, creating...';
            if (!mkdir($dir, 0777, true)) {
                $this->lastError = '<br/><b>' . $dir . '</b> cannot be created, verify the permissions';
                return false;
            }
            $this->message .= '<br/>Directory created';
        }
        //If directory exist but dont have write permissions for files
        if (!is_writable($dir)) {
            $this->lastError = '<br/>Error, verify your write permissions in <b>' . $dir . '</b> folder';
            return false;
        }
        if (!is_file($file)) {
            $this->message .= '<br/>File dont exist, creating <b>' . $file . '</b> ...';
            $createdFile = fopen($file, 'w');
            if ($createdFile === false) {
                $this->lastError = 'File couldnt be created, verify your permissions';
                return false;
            }
            fclose($createdFile);
        }
        //If file exist but write is forbidden
        if (!is_writable($file)) {
            $this->lastError = '<br/>Check your write permissions, information couldnt be written on <b>' . $file . '</b>';
            return false;
        }
        file_put_contents($file, $csvData);
        $this->message .= '<br/>data inserted in the file: <b>' . $file . '</b>';
        return true;
    }
}
