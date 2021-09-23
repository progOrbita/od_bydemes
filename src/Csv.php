<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use OrbitaDigital\OdBydemes\ReadFiles;

class Csv extends ReadFiles
{

    private $url = '';
    private $csv_header = [];
    private $parse_header = [];
    private $delimiter = '';
    private $length = 0;
    //file which is going to be readed
    private $fopened;

    /**
     * Constructor
     * @param array $csv_header Header expected in the csv files
     */
    function __construct(string $url, $parse_header, string $delimiter, int $length)
    {
        $this->url = $url;
        if (!$this->checkFile($this->url, 'csv')) {
            die($this->getLastError());
        }
        //open archive only once
        $this->fopened = fopen($url, 'r');

        //removing the BOM of csv
        $bom = "\xef\xbb\xbf";
        // Progress file pointer and get first 3 characters to compare to the BOM string.
        if (fgets($this->fopened, 4) !== $bom) {
            // BOM not found - rewind pointer to start of file.
            rewind($this->fopened);
        }

        //csv_header = first csv row
        $this->setHeader(fgetcsv($this->fopened, 0, ","));
        $this->parse_header = $parse_header;
        $this->delimiter = $delimiter;
        $this->length = $length;
    }
    /**
     * Set csv_header value
     * @param $header array to be inserted
     */
    public function setHeader(array $header)
    {
        $this->csv_header = $header;
    }

    /**
     * Read a file and returns the array with the data.
     * Open the file, r -> read mode only.
     * fgetcsv requires the file, line length to read and separator. Will return false at the end of the file
     * if everything is right, save the header and the rows into an array which is returned if there's no errors
     * @param string $file csv to be readed
     * @return bool|array array with the file data or false if there's an error
     */
    public function read()
    {

        $data = [];
        //Set the header the one I wanted (all fields)
        $this->setHeader($this->parse_header);
        $i = 0;
        while ($i !== 30) {
            $row = fgetcsv($this->fopened, 0, ",");
            //if the first value of the row is empty, skip it
            if (empty($row[0])) {
                continue;
            }
            $i++;
            array_push($data, array_combine($this->csv_header, $row));
        }
        return $data; //to process only some rows instead of all of them
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
    public function checkHeader(array $header): bool
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
        //If there is no files at all
        if (empty($filesData)) {
            $this->lastError = 'csv information not found';
            return false;
        }

        //reading each file
        $joinedData = [];

        //each id_lang key contains the csv with that language csv
        foreach ($filesData as $id_lang => $lang_csv) {
            foreach ($lang_csv as $csv_values) {
                foreach ($csv_values as $header_value => $row_value) {
                    $joinedData[$csv_values[$id_lang]][$header_value] = $row_value;
                }
            }
        }
        return $joinedData;
    }
}
