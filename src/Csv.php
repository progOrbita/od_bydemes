<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use OrbitaDigital\OdBydemes\ReadFiles;

class Csv extends ReadFiles
{
    private $csv_header = [];
    /**
     * Constructor
     * @param array $csv_header Header expected in the csv files
     */
    function __construct(array $csv_header)
    {
        $this->csv_header = $csv_header;
    }
    /**
     * Read a file and returns the array with the data.
     * Open the file, r -> read mode only.
     * fgetcsv requires the file, line length to read and separator. Will return false at the end of the file
     * if everything is right, save the header and the rows into an array which is returned if there's no errors
     * @param string $file csv to be readed
     * @return bool|array array with the file data or false if there's an error
     */
    public function read(string $file)
    {
        if (!$this->checkFile($file, 'csv')) {
            return false;
        }
        $data = [];
        
        $fileOpen = fopen($file, 'r');

        // BOM as a string for comparison.
        $bom = "\xef\xbb\xbf";
        
        // Progress file pointer and get first 3 characters to compare to the BOM string.
        if (fgets($fileOpen, 4) !== $bom) {
            // BOM not found - rewind pointer to start of file.
            rewind($fileOpen);
        }

        $header = fgetcsv($fileOpen, 0, ",");

        if (!$this->checkHeader($header)) {
            $this->lastError = 'header of <b>' . $file . '</b> is not fine';
            return false;
        }
        while (($row = fgetcsv($fileOpen, 0, ",")) !== false) {
            //if the first value of the row is empty, skip it
            if (empty($row[0])) {
                continue;
            }
            array_push($data, array_combine($header, $row));
            var_dump($data);
            return false; //Not processing all the data
        }
        //if header is right but content in totally empty
        if (empty($data)) {
            $this->lastError = 'File data is empty';
            return false;
        }
        return $data;
    }
    /**
     * Check if the header is the expected
     * @param array $header header of the file
     * @return bool false if headers dont match, true otherwise
     */
    private function checkHeader(array $header): bool
    {
        return $header === $this->csv_header;
    }
    /**
     * Read and extract the data from the array of files into an joined array.
     * @param array $filesData array to extract information
     * @return bool|array false if there's an error, otherwise an array with the joined information
     */
    public function process(array $filesData)
    {
        $csv_data = [];
        //If there is no files at all
        if (empty($filesData)) {
            $this->lastError = 'csv information not found';
            return false;
        }
        //reading each file
        foreach ($filesData as $id_lang =>  $file) {
            $data_file = $this->read($file);
            if (!$data_file) {
                return false;
            }
            $csv_data[$id_lang] = $data_file;
        }
        $joinedData = [];
        //each id_lang key contains the csv with that language csv
        foreach ($csv_data as $id_lang => $lang_csv) {
            foreach ($lang_csv as $csv_values) {
                $joinedData[$csv_values['Id']]['Titulo'][$id_lang] = $csv_values['Titulo'];
                $joinedData[$csv_values['Id']]['Description'][$id_lang] = $csv_values['Description'];
            }
        }
        return $joinedData;
    }
}
