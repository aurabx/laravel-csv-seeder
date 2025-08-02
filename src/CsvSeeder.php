<?php

namespace Aurabx\CsvSeeder;

use Aurabx\LaravelShim\Str;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Taken from http://laravelsnippets.com/snippets/seeding-database-with-csv-files-cleanly
 * and modified to include insert chunking
 */
abstract class CsvSeeder extends Seeder
{
    /**
     * DB table name
     *
     * @var string
     */
    public string $table = '';

    /**
     * CSV filename
     *
     * @var string
     */
    public string $filename = '';

    /**
     * DB connection to use. Leave empty for the default connection
     */
    public string $connection = '';

    /**
     * DB fields to be hashed before import, For example, a password field.
     */
    public array $hashable = ['password'];

    /**
     * An SQL INSERT query will execute every time this number of rows
     * is read from the CSV. Without this, large INSERTS will silently
     * fail.
     */
    public int $insert_chunk_size = 50;

    /**
     * CSV delimiter (defaults to ,)
     */
    public string $csv_delimiter = ',';

    /**
     * Number of rows to skip at the start of the CSV
     */
    public int $offset_rows = 0;

    /**
     * Can be used to tell the import to trim any leading or trailing white space from the column;
     */
    public bool $should_trim = false;

    /**
     * Add created_at and updated_at to rows
     */
    public bool $timestamps = false;
    /**
     * created_at and updated_at values to be added to each row. Only used if
     * $this->timestamps is true
     */
    public string $created_at = '';
    public string $updated_at = '';

    /**
     * The mapping of CSV to DB column. If not specified manually, the first
     * row (after offset_rows) of your CSV will be read as your DB columns.
     *
     * Mappings take the form of csvColNumber => dbColName.
     *
     * IE to read the first, third and fourth columns of your CSV only, use:
     * array (
     *   0 => id,
     *   2 => name,
     *   3 => description,
     * )
     */
    public array $csvMapping = [];

    protected array $reverseMapping = [];

    /**
     * @var array
     */
    public array $dbToCsvMapping = [];

    /**
     * The internal prefix that is used to denote columns that are in the csv.
     *
     * If you happened to have a column called 'csv' you'd want to rename this.
     *
     * @var string
     */
    protected string $internal_mapping_prefix = 'csv';


    /**
     * Run DB seed
     * @throws Exception
     */
    public function run(): void
    {
        $this->configure();

        // Cache created_at and updated_at if we need to
        if ($this->timestamps) {
            if (!$this->created_at) {
                $this->created_at = Carbon::now()->toDateTimeString();
            }
            if (!$this->updated_at) {
                $this->updated_at = Carbon::now()->toDateTimeString();
            }
        }

        $this->seedFromCSV($this->filename, $this->csv_delimiter);
    }


    /**
     * @return void
     */
    public function configure(): void
    {
        $this->insert_chunk_size = 1;

        // Disable query log to optimize large CSV imports.
        DB::disableQueryLog();
    }

    /**
     * Strip UTF-8 BOM characters from the start of a string
     *
     * @param  string $text
     * @return string       String with BOM stripped
     */
    public function stripUtf8Bom(string $text): string
    {
        $bom = pack('H*', 'EFBBBF');
        return preg_replace("/^$bom/", '', $text);
    }

    /**
     * Opens a CSV file and returns it as a resource
     *
     * @param string $filename
     * @return FALSE|resource
     */
    public function openCSV(string $filename): mixed
    {
        if (!file_exists($filename) || !is_readable($filename)) {
            Log::error("CSV insert failed: CSV " . $filename . " does not exist or is not readable.");
            return false;
        }

        // check if the file is gzipped
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime_type = finfo_file($finfo, $filename);
        finfo_close($finfo);
        $gzipped = strcmp($file_mime_type, "application/x-gzip") === 0;

        return $gzipped ? gzopen($filename, 'r') : fopen($filename, 'rb');
    }

    /**
     * Reads all rows of a given CSV and imports the data.
     *
     * @param string $filename
     * @param string $deliminator
     * @return bool  Whether the import completed successfully.
     * @throws Exception
     */
    public function seedFromCSV(string $filename, string $deliminator = ","): bool
    {
        $handle = $this->openCSV($filename);

        // CSV doesn't exist or couldn't be read from.
        if ($handle === false) {
            throw new \RuntimeException("CSV insert failed: CSV " . $filename . " does not exist or is not readable.");
        }

        $success = true;
        $data = [];
        $rowCount = 0;
        $offset = $this->offset_rows;

        // If no mapping provided, read the first row as the header mapping.
        if (!$this->csvMapping) {
            $this->csvMapping = $this->getCsvMappingFromFirstRow($handle, $deliminator, $filename);
        }

        $this->reverseMapping = array_flip($this->csvMapping);

        $this->dbToCsvMapping = $this->getDbColumns();

        // Initialize hashable columns based on mapping.
        $this->hashable = $this->removeUnusedHashColumns($this->csvMapping);

        // Skip offset rows if any.
        for ($i = 0; $i < $offset; $i++) {
            if ($this->getRow($handle, $deliminator) === false) {
                break;
            }
        }

        // Process each row.
        while (($row = $this->getRow($handle, $deliminator)) !== false) {
            $row = $this->readRow($row, $this->csvMapping);

            // Skip empty rows.
            if (empty($row)) {
                continue;
            }

            $data[$rowCount] = $row;
            $rowCount++;

            // If chunk size reached, perform insert.
            if ($rowCount === $this->insert_chunk_size) {
                $success = $success && $this->insert($data);
                $rowCount = 0;
                $data = []; // Reset data after insert.
            }
        }

        // Insert any leftover rows.
        if (count($data)) {
            $success = $success && $this->insert($data);
        }

        fclose($handle);

        return $success;
    }

    /**
     * Creates a CSV->DB column mapping from the given CSV row.
     *
     * @param array $row  List of DB columns to insert into
     * @return array  List of DB fields to insert into
     */
    public function createMappingFromRow(array $row): array
    {
        $mapping = [];
        $mapping[0] = $this->stripUtf8Bom($row[0]);

        // skip csv columns that don't exist in the database
        foreach ($row as $index => $column_name) {
            if ($this->databaseHasColumn($column_name)) {
                $mapping[$index] = $column_name;
            }
        }

        return $mapping;
    }

    /**
     * Returns the column name.
     *
     * @param callable|string $column The column name or a callable that returns the column name.
     *
     * @return string
     */
    public function getCsvColumn(callable|string $column): string
    {
        if (is_callable($column)) {
            return (string)$column();
        }

        return implode('.', [
            $this->internal_mapping_prefix,
            $column
        ]);
    }

    /**
     * Removes fields from the hashable array that don't exist in our mapping.
     *
     * This function acts as a performance enhancement - we don't want
     * to search for hashable columns on every row imported when we already
     * know they don't exist.
     *
     * @param array $mapping
     * @return array
     */
    public function removeUnusedHashColumns(array $mapping): array
    {
        $hashables = $this->hashable;

        foreach ($hashables as $key => $field) {
            if (!in_array($field, $mapping, true)) {
                unset($hashables[$key]);
            }
        }

        return $hashables;
    }

    /**
     * @return array
     */
    abstract public function getDbColumns(): array;

    /**
     * Read a CSV row into a DB insertable array
     *
     * @param array $csv_row A row of data to read
     * @param array $mapping    Array of csvCol => dbCol
     *
     * @return array
     */
    public function readRow(array $csv_row, array $mapping): array
    {
        $row_values = [];

        foreach ($this->dbToCsvMapping as $db_column => $csv_mapping) {
            // Process callable mappings.
            if (is_callable($csv_mapping)) {
                $value = $csv_mapping($csv_row);
                $row_values[$db_column] = $this->should_trim && is_string($value) ? trim($value) : $value;
                continue;
            }

            // Process mappings specified as a string with a dot.
            if (is_string($csv_mapping) && str_contains($csv_mapping, '.')) {
                // Explode into two parts only.
                [$prefix, $csv_column_name] = explode('.', $csv_mapping, 2);

                // Check for internal mapping.
                if ($prefix === $this->internal_mapping_prefix && isset($csv_column_name) && $csv_column_name !== '') {
                    if (isset($this->reverseMapping[$csv_column_name])) {
                        $csvIndex = $this->reverseMapping[$csv_column_name];
                        $csvData = data_get($csv_row, $csvIndex);
                        $row_values[$db_column] = $this->should_trim && is_string($csvData) ? trim($csvData) : $csvData;
                    }
                    continue;
                }
            }

            // If the CSV mapping is numeric (or any other type) treat it as an index.
            if (is_numeric($csv_mapping)) {
                $csvData = data_get($csv_row, $csv_mapping);
                $row_values[$db_column] = $this->should_trim && is_string($csvData) ? trim($csvData) : $csvData;
                continue;
            }

            // Otherwise, assign the mapping value directly.
            $row_values[$db_column] = $csv_mapping;
        }

        // Convert any column that has a boolean value or a boolean string to an integer:
        // - Actual booleans are converted to 1 or 0.
        // - Strings "true"/"false" (case‑insensitive) are converted to 1 or 0.
        foreach ($row_values as $column => $value) {
            if (is_bool($value)) {
                $row_values[$column] = $value ? 1 : 0;
            } elseif (is_string($value)) {
                $lower = strtolower($value);
                if ($lower === 'true') {
                    $row_values[$column] = 1;
                } elseif ($lower === 'false') {
                    $row_values[$column] = 0;
                }
            }
        }

        // Process hashing if needed.
        if (!empty($this->hashable)) {
            foreach ($this->hashable as $columnToHash) {
                if (isset($row_values[$columnToHash])) {
                    $row_values[$columnToHash] = Hash::make($row_values[$columnToHash]);
                }
            }
        }

        // Assign timestamps if enabled.
        if ($this->timestamps) {
            $row_values['created_at'] = $this->created_at;
            $row_values['updated_at'] = $this->updated_at;
        }

        return $row_values;
    }

    /**
     * Seed a given set of data to the DB
     *
     * @param array $seedData
     * @return bool   TRUE on success else FALSE
     */
    public function insert(array $seedData): bool
    {
        try {
            DB::connection($this->connection)->table($this->table)->insert($seedData);
        } catch (\Exception $e) {
            Log::error("CSV insert failed: " . $e->getMessage() . " - CSV " . $this->filename);
            return false;
        }

        return true;
    }

    /**
     * @param mixed $handle
     * @param string $deliminator
     *
     * @return array|false
     */
    public function getRow(mixed $handle, string $deliminator): array|false
    {
        return fgetcsv($handle, 0, $deliminator);
    }

    /**
     * @param mixed $handle
     * @param string $deliminator
     * @param string $filename
     *
     * @return array
     */
    protected function getCsvMappingFromFirstRow(mixed $handle, string $deliminator, string $filename): array
    {
        $headerRow = $this->getRow($handle, $deliminator);
        if ($headerRow === false) {
            fclose($handle);
            throw new \RuntimeException("CSV insert failed: Unable to read header row from CSV " . $filename);
        }

        return $this->createMappingFromRow($headerRow);
    }

    /**
     * @param string $col
     *
     * @return bool
     */
    protected function databaseHasColumn(string $col): bool
    {
        return DB::connection($this->connection)
            ->getSchemaBuilder()
            ->hasColumn($this->table, $col);
    }
}
