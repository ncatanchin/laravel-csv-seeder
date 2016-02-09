<?php namespace Crockett\CsvSeeder;

use Crockett\CsvSeeder\Exception\CsvSeederException;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Database\Schema;

class CsvSeeder extends Seeder
{

    /**
     * Class name of an Eloquent model
     *
     * @var string \Illuminate\Database\Eloquent\Model
     */
    public $model;

    /**
     * DB table name
     *
     * @var string
     */
    public $table;

    /**
     * CSV filename
     *
     * @var string
     */
    public $filename;

    /**
     * Specifies DB fields that should be hashed prior to insertion.
     * Override this as needed.
     *
     * @var array|string|false
     */

    public $hashable = 'password';

    /**
     * A SQL INSERT query will execute every time this number of rows are
     * read from the CSV. Without this, large INSERTs will fail silently
     *
     * @var int
     */
    public $insert_chunk_size = 50;

    /**
     * A closure that takes an array of records and inserts them into the DB.
     *
     * Use it to override ride the default insertion behavior.
     *
     * @var object|closure
     */
    public $insert_callback = null;

    /**
     * CSV delimiter (defaults to ,)
     *
     * @var string
     */
    public $csv_delimiter = ',';

    /**
     * Number of rows to skip at the start of the CSV.
     * When resolving column names, the very first row is assumed to be the header row
     *
     * @var int
     */
    public $offset_rows = 0;

    /**
     * The mapping of CSV to DB columns. If not specified manually,
     * the very first row of your CSV will be read as your DB columns,
     * if they weren't already resolved from an Eloquent model.
     *
     * To read the first, third and fourth columns of your CSV only, use:
     * [
     *     0 => 'column1_name',
     *     2 => 'column3_name',
     *     3 => 'column4_name',
     * ];
     *
     * @var array
     */
    public $mapping = [];

    /**
     * Alias your CSV column names to different DB column names
     *
     * After $this->mapping has been applied to the CSV row, you can use this
     * to specify which columns should be aliased and what their alias names should be.
     *
     * To alias a CSV column name of "email_address" to a DB column name "email", use:
     * [
     *    'email_address' => 'email'
     * ];
     *
     * @var array
     */
    public $aliases = [];

    /**
     * Enables or disables query logging. False is recommended for large CSVs.
     *
     * @var bool
     */
    public $log_queries = false;

    /**
     * The level of log messages that will be written to the logs
     *
     * @var false|string ('off', 'critical', 'error', 'warning', 'notice', 'log', 'debug', 'info')
     */
    public $log_level = 'off';

    /**
     * The prefix for log messages
     *
     * @var string
     */
    public $log_prefix = 'CsvSeeder: ';

    /**
     * Model attributes which are only read when a model is passed to the seeder
     *
     * @var array
     */
    private $attributes;


    /**
     * Run DB seed
     */
    public function run()
    {
        //DB::table($this->table)->truncate();

        // Squawk about missing filename
        if (empty( $this->filename )) {
            throw new CsvSeederException(
                'No CSV filename specified.'
            );
        }

        // Squawk about missing table and model
        if (empty( $this->table ) && empty( $this->model )) {
            throw new CsvSeederException(
                'No table or model name specified.'
            );
        }

        // check if the passed model exists and check if it
        // can be parsed for a column mapping and table name
        $this->readModel($this->model);

        // convert hashable to array if a string was passed
        $this->hashable = is_string($this->hashable) ? [$this->hashable] : $this->hashable;

        // disable query log
        if ($this->log_queries == false) {
            $this->disableQueryLog();
        }

        // read the CSV and seed the database
        $this->seedFromCSV($this->filename, $this->csv_delimiter);
    }

    public function resetCSVSeeder()
    {
        $this->model             = null;
        $this->table             = null;
        $this->filename          = null;
        $this->insert_callback   = null;
        $this->insert_chunk_size = 50;
        $this->mapping           = [];
        $this->aliases           = [];
        $this->hashable          = [];
        $this->csv_delimiter     = ',';
        $this->offset_rows       = 0;
    }

    /**
     * Opens a CSV file and returns it as a resource
     *
     * @param $filename
     *
     * @return FALSE|resource
     */
    public function openCSV($filename)
    {
        if (!file_exists($filename) || !is_readable($filename)) {
            throw new CsvSeederException(
                "CSV file could not be opened. " . $filename . " does not exist or is not readable."
            );
        }

        // check if file is gzipped
        $file_info      = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime_type = finfo_file($file_info, $filename);
        finfo_close($file_info);
        $gzipped = strcmp($file_mime_type, "application/x-gzip") == 0;

        $handle = $gzipped ? gzopen($filename, 'r') : fopen($filename, 'r');

        return $handle;
    }

    /**
     * Collect data from a given CSV file and return as array
     *
     * @param string $filename
     * @param string $deliminator
     *
     * @return array|bool
     */
    public function seedFromCSV($filename, $deliminator = ",")
    {
        $handle = $this->openCSV($filename);

        // CSV doesn't exist or couldn't be read from.
        if ($handle === false) {
            throw new CsvSeederException(
                "CSV file could not be opened. " . $filename . " does not exist or is not readable."
            );
        }

        $chunk     = [];
        $mapping   = $this->mapping ?: [];
        $offset    = $this->offset_rows;
        $row_count = 0;
        $skipped   = 0;

        // Mapping was specified, do one final check to ensure the columns actually exist
        if (!empty( $mapping )) {
            $mapping = $this->checkMappingColumns($mapping);
        }

        while (( $row = fgetcsv($handle, 0, $deliminator) ) !== false) {

            // If no mapping was specified
            // grab the first CSV row and use it as the mapping
            // unless attributes are defined, then use those
            // and then skip to the next row
            if (empty( $mapping )) {
                if (!empty( $this->model ) && !empty( $this->attributes )) {
                    $columns = $this->attributes;
                } else {
                    $columns    = $row;
                    $columns[0] = $this->stripUtf8Bom($columns[0]);
                }

                $mapping = $this->checkMappingColumns($columns);

                if ($offset == 0) $offset ++;
            }

            // check if the first row is a header row by comparing
            // the mapping names to the row values, then skip it
            if ($row_count == 0) {
                $is_header_row = false;
                foreach ($mapping as $index => $column) {
                    if (array_key_exists($index, $row)) {
                        if ($row[$index] == $column) {
                            $is_header_row = true;
                        }
                    }
                }
                if ($is_header_row && $offset == 0) {
                    $offset ++;
                }
            }

            // Skip rows by the specified offset
            while ($offset > 0) {
                $offset --;
                continue 2;
            }

            $row = $this->readRow($row, $mapping);

            // Insert only non-empty rows from the csv file
            if (empty( $row )) {
                $skipped ++;
                continue;
            }

            $chunk[] = $row;

            // Chunk size reached, insert and clear the chunk
            if (count($chunk) >= $this->insert_chunk_size) {
                $this->insert($chunk);
                $chunk = [];
            }

            $row_count ++;
        }

        // Insert any leftover rows from the last chunk
        if (count($chunk) > 0) {
            $this->insert($chunk);
        }

        fclose($handle);

        // log any skipped rows
        if ($skipped > 0) {
            $this->log($skipped . ' row(s) were skipped because they were empty.', 'debug');
        }
    }

    /**
     * Read a CSV row into a DB insertable array
     *
     * @param array $row     List of CSV columns
     * @param array $mapping Array of csvCol => dbCol
     *
     * @return array
     */
    public function readRow(array $row, array $mapping)
    {
        $columns = [];

        // apply column mapping
        foreach ($mapping as $csvColumn => $dbColumn) {
            if (!array_key_exists($csvColumn, $row) || empty( $row[$csvColumn] )) {
                $columns[$dbColumn] = null;
            } else {
                $columns[$dbColumn] = $row[$csvColumn];
            }
        }

        // apply mapping aliases
        if (!empty( $this->aliases ) && is_array($this->aliases)) {
            foreach ($this->aliases as $column => $alias) {
                if (array_key_exists($column, $columns)) {
                    // store the column value, remove the old column name, add the new alias name
                    $value = $columns[$column];
                    array_pull($columns, $column);
                    $columns[$alias] = $value;
                }
            }
        }

        // hash any hashable columns
        if (is_array($this->hashable)) {
            foreach ($this->hashable as $hashable) {
                if (array_key_exists($hashable, $columns)) {
                    $columns[$hashable] = $this->hash($columns[$hashable]);
                }
            }
        }

        return $columns;
    }

    /**
     * Insert a chunk of rows into the DB
     *
     * @param array $chunk
     *
     * @return bool   TRUE on success else FALSE
     */
    public function insert(array $chunk)
    {
        // check for a custom insert callback
        $callback = is_object($this->insert_callback)
            ? $this->insert_callback
            : $this->insertChunk($chunk);

        try {
            call_user_func($callback, $chunk);
        } catch (\Exception $e) {
            $this->log("Insert failed: " . $e->getMessage(), 'error');

            return false;
        }

        return true;
    }

    /**
     * Strip UTF-8 BOM characters from the start of a string
     *
     * @param  string $text
     *
     * @return string       String with BOM stripped
     */
    public function stripUtf8Bom($text)
    {
        $bom  = pack('H*', 'EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);

        return $text;
    }

    // hash a value using laravel's hash helper
    public function hash($value)
    {
        return bcrypt($value);
    }

    // the default chunk insertion behavior
    protected function insertChunk(array $chunk)
    {
        // use the table or model if its available to insert the chunk
        if (empty( $this->model )) {
            DB::table($this->table)->insert($chunk);
        } else {
            $model = $this->resolveModel($this->model);
            $model->insert($chunk);
        }
    }

    // parse the details of a model passed to the seeder
    protected function readModel($model_name)
    {
        if (empty( $model_name )) {
            return false;
        }

        // Model name was specified - check if it can be resolved
        // and check if we can resolve any variables that are needed later
        $model = false;

        // resolve an instance of the model
        try {
            $model = $this->resolveModel($model_name);
        } catch (\Exception $e) {
            $this->log('Exception occurred while resolving ' . $model_name, 'critical');
        }

        if (!$model instanceof Model) {
            throw new CsvSeederException(
                '"' . $model_name . '" could not be resolved.'
            );
        }

        // update the log prefix to reflect the active model
        $this->log_prefix = $this->log_prefix . get_class($this) . ': ' . get_class($model) . ': ';

        // resolve the table name from the model
        $this->table = $this->resolveModelTableName($model);
        $this->log('Table name resolved from the model.', 'error');

        // if not specified, try to resolve the column mapping from the model
        if (empty( $this->mapping )) {
            $mapping = $this->resolveModelColumnMap($model);
            if ($mapping !== false) {
                $this->attributes = $mapping;
                $this->log('Column mapping resolved from the model.', 'notice');
            }
        }
    }

    // resolve a model out of laravel's IOC container
    protected function resolveModel($model, $parameters = [])
    {
        return app($model, $parameters);
    }

    // get the table name from an Eloquent model
    protected function resolveModelTableName(Model $model)
    {
        return $model->getTable();
    }

    protected function resolveModelColumnMap(Model $model)
    {
        if (!property_exists($model, 'fillable')) {
            return false;
        }

        return $model->getFillable();
    }

    // log a message using laravel's log helper
    protected function log($message, $level = 'info')
    {
        if ($this->log_level === false) {
            return false;
        }

        $levels    = ['off', 'critical', 'error', 'warning', 'notice', 'log', 'debug', 'info'];
        $max_level = array_search($this->log_level, $levels);
        $log_level = array_search($level, $levels);

        if ($log_level == 0 || $log_level > $max_level) {
            return false;
        }

        return logger()->log($level, $this->log_prefix . $message);
    }

    /**
     * Removes columns from a mapping that don't exist in the database
     *
     * @param $mapping array
     *
     * @return array
     */
    private function checkMappingColumns(array $mapping)
    {
        foreach ($mapping as $index => $column) {
            if (!$this->checkColumnExists($this->table, $column)) {
                array_pull($mapping, $index);
            }
        }

        // Squawk if all mappings were removed by the existence check
        if (empty( $mapping )) {
            throw new CsvSeederException(
                "All the specified column mappings do not exist in the database."
            );
        }

        return $mapping;
    }

    private function checkColumnExists($table, $column)
    {
        // apply mapping aliases if defined
        if (!empty( $this->aliases )) {
            if (array_key_exists($column, $this->aliases)) {
                $column = $this->aliases[$column];
            }
        }

        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    private function disableQueryLog()
    {
        DB::disableQueryLog();
    }

    private function enableQueryLog()
    {
        DB::enableQueryLog();
    }
}
