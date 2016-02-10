<?php

namespace Crockett\CsvSeeder;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class CsvSeeder extends Seeder
{

    /**
     * DB table name
     *
     * @var string
     */
    public $table;

    /**
     * Model class name
     *
     * @var string
     */
    public $model;

    /**
     * CSV filename
     *
     * @var string
     */
    public $filename;

    /**
     * CSV delimiter
     *
     * @var string
     */
    public $csv_delimiter = ',';

    /**
     * Number of rows to skip at the start of the CSV.
     *
     * @var int
     */
    public $offset_rows = 0;

    /**
     * Automatically skips the first row of the CSV -
     * Only when the first row's column values are equal to the column names resolved (or defined) in $mapping.
     *
     * Setting $offset_rows to anything higher than 0 bypasses this.
     *
     * If you know your CSV doesn't have headers it's somewhat safer
     * to disable this, but generally it shouldn't affect anything.
     *
     * @var bool
     */
    public $skip_header_row = true;

    /**
     * CSV header name mapping -
     * An array that uses the CSV column index as the array key and a column name as the array value.
     * Column names do not have to match the CSV headers.
     *
     * Using the indexes, you can specify which columns to read from the CSV and which to ignore.
     * For example, to only read the first, third, and fourth columns from your CSV:
     * [
     *     0 => 'first_column_name',
     *     2 => 'third_column_name',
     *     3 => 'fourth_column_name',
     * ];
     *
     * @var array
     */
    public $mapping = [];

    /**
     * Aliases CSV headers to differently named DB columns.
     *
     * Useful when $mapping is being resolved automatically and you're
     * reading a CSV with headers named differently from your DB columns.
     *
     * For example, to alias a CSV column named "email_address" to a DB column named "email":
     * [
     *    'email_address' => 'email'
     *    // 'csv_header' => 'alias_name'
     * ];
     *
     * @var array
     */
    public $aliases = [];

    /**
     * Specifies DB columns that should have their values hashed prior to insertion.
     * Override this as needed.
     *
     * If you set any $aliases, be sure to use the aliased DB column name.
     *
     * @var array|string
     */

    public $hashable = 'password';

    /**
     * A SQL INSERT query will execute every time this number of rows are read from the CSV.
     * Without this, large INSERTS will fail silently.
     *
     * @var int
     */
    public $insert_chunk_size = 50;

    /**
     * A closure that takes an array of CSV rows ($chunk) and inserts them into the DB.
     * Use to override the default insertion behavior.
     *
     * Example:
     *     function ($chunk) {
     *         // insert $rows individually with model::create()
     *         foreach($chunk as $row) {
     *             YourModel::create($row);
     *         }
     *     }
     *
     * @var closure|callable|null
     */
    public $insert_callback = null;

    /**
     * Truncate the table prior to insertion
     *
     * @var bool
     */
    public $truncate_before_insert = false;

    /**
     * Disables foreign key checks when truncating a table.
     *
     * @var bool
     */
    public $ignore_foreign_keys = false;

    /**
     * Enables or disables query logging. Recommended for large CSVs.
     *
     * @var bool
     */
    public $disable_query_log = true;

    /**
     * Limit mapping columns to what is specified by the $fillable/$guarded model attributes
     *
     * @var bool
     */
    public $guard_model = true;

    /**
     * Show messages in the console
     *
     * @var bool
     */
    public $console_logs = true;

    /**
     * Write messages to laravel.log
     *
     * @var bool
     */
    public $write_logs = true;

    /**
     * The prefix for log messages
     *
     * @var string
     */
    public $log_prefix = '';

    /**
     * Holder for columns read from the DB table
     *
     * @var array
     */
    private $table_columns;

    public function __construct(
        $filename = null,
        $table = null,
        $model = null,
        $delimiter = ',',
        $mapping = null,
        $aliases = null,
        $insert_callback = null
    ) {
        if (!is_null($filename)) {
            $this->table = $table;
            $this->model = $model;
            $this->runOnce($filename, $delimiter, $mapping, $aliases, $insert_callback);
        }
    }

    /**
     * Run DB seed
     */
    public function run()
    {
        $this->runSeeder();
    }

    public function runSeeder()
    {
        // parse the model
        if (!empty( $this->model )) {
            $this->parseModel($this->model);
        }

        // abort for missing filename
        if (empty( $this->filename )) {
            $this->console('CSV filename was not specified.', 'error');

            return;
        }

        // abort for missing table
        if (empty( $this->table )) {
            $this->console('DB table could not be resolved. Try setting it manually.', 'error');

            return;
        }

        // disable query log
        if ($this->disable_query_log) $this->disableQueryLog();

        // truncate the table before insertion
        if ($this->truncate_before_insert) $this->truncateTable();

        // update the log_prefix
        $this->log_prefix = $this->log_prefix . $this->table . ': ';

        // convert hashable to array if a string was passed
        $this->hashable = is_string($this->hashable) ? [$this->hashable] : $this->hashable;

        // load the acceptable table columns
        $this->resolveTableColumns();

        // finally, read and parse the CSV, seeding the database
        $this->parseCSV();
    }

    /**
     * Reset the seeder for another use
     */
    public function resetSeeder()
    {
        $this->model         = null;
        $this->table         = null;
        $this->filename      = null;
        $this->mapping       = [];
        $this->aliases       = [];
        $this->hashable      = [];
        $this->csv_delimiter = ',';
        $this->offset_rows   = 0;
        $this->log_prefix    = '';

        $this->insert_chunk_size = 50;
        $this->insert_callback   = null;
    }

    public function seedModelWithCSV(
        $model,
        $filename,
        $delimiter = ',',
        $mapping = null,
        $aliases = null,
        $insert_callback = null
    ) {
        $this->model = $model;
        $this->runOnce($filename, $delimiter, $mapping, $aliases, $insert_callback);
    }

    public function seedTableWithCSV(
        $table,
        $filename,
        $delimiter = ',',
        $mapping = null,
        $aliases = null,
        $insert_callback = null
    ) {
        $this->table = $table;
        $this->runOnce($filename, $delimiter, $mapping, $aliases, $insert_callback);
    }

    public function runOnce(
        $filename,
        $delimiter = ',',
        $mapping = null,
        $aliases = null,
        $insert_callback = null
    ) {
        $this->filename      = $filename;
        $this->csv_delimiter = $delimiter;
        $this->mapping       = $mapping;
        $this->aliases       = $aliases;

        $this->insert_callback = $insert_callback;

        $this->runSeeder();
        $this->resetSeeder();
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
            return false;
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
     * Parse rows from the CSV and pass chunks of rows to the insert function
     */
    public function parseCSV()
    {
        $handle = $this->openCSV($this->filename);

        // abort for bad CSV
        if ($handle === false) {
            $this->console(
                "CSV file could not be opened.\n {$this->filename} does not exist or is not readable.", 'error');

            return;
        }

        $row_count = 0;
        $skipped   = 0; // rows that were skipped
        $failed    = 0; // chunk inserts that failed
        $chunk     = []; // accumulator for rows until the chunk_limit is reached
        $mapping   = empty( $this->mapping ) ? [] : $this->cleanMapping($this->mapping);
        $offset    = $this->offset_rows;

        while (( $row = fgetcsv($handle, 0, $this->csv_delimiter) ) !== false) {

            if ($row_count == 0 && $offset == 0) {
                // Resolve mapping from the first row
                if (empty( $mapping )) {
                    $mapping = $this->cleanMapping($row);
                }

                // Automagically skip the header row
                if (!empty( $mapping ) && $this->skip_header_row) {
                    if ($this->isHeaderRow($row, $mapping)) {
                        $offset ++;
                    }
                }
            }

            // Skip the offset rows
            while ($offset > 0) {
                $offset --;
                continue 2;
            }

            // Resolve mapping using the first row after offset
            if (empty( $mapping )) {
                $mapping = $this->cleanMapping($row);
                // abort if mapping empty
                if (empty( $mapping )) {
                    $this->console("The mapping columns do not exist on the DB table.", 'error');

                    return;
                }
            }

            $row = $this->parseRow($row, $mapping);

            // Insert only non-empty rows from the csv file
            if (empty( $row )) {
                $skipped ++;
                continue;
            }

            $chunk[] = $row;

            // Chunk size reached, insert and clear the chunk
            if (count($chunk) >= $this->insert_chunk_size) {
                if (!$this->insert($chunk)) $failed ++;
                $chunk = [];
            }

            $row_count ++;
        }

        // convert failed chunks to failed rows
        $failed = $failed * $this->insert_chunk_size;

        // Insert any leftover rows from the last chunk
        if (count($chunk) > 0) {
            if (!$this->insert($chunk)) $failed += count($chunk);
        }

        fclose($handle);

        // log results to console
        $log = 'Imported ' . ( $row_count - $skipped - $failed ) . ' of ' . $row_count . ' rows. ';
        if ($skipped > 0) $log .= $skipped . " empty rows. ";
        if ($failed > 0) $log .= "<error>" . $failed . " failed rows.</error>";

        $this->console($log);
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
        $callback = $this->getInsertCallback();

        try {
            call_user_func($callback, $chunk);
        } catch (\Exception $e) {
            $this->log("Chunk insert failed:\n" . $e->getMessage(), 'critical');

            return false;
        }

        return true;
    }

    /**
     * Resolve the function that inserts chunks into the database or returns the default behavior.
     *
     * @returns closure|callable
     */
    public function getInsertCallback()
    {
        return is_object($this->insert_callback)
            ? $this->insert_callback
            : function (array $chunk) {
                if (empty( $this->model )) {
                    // use DB table insert method
                    DB::table($this->table)->insert($chunk);
                } else {
                    // use model insert method
                    $model = $this->resolveModel($this->model);
                    $model->insert($chunk);
                }
            };
    }

    /**
     * Strips UTF-8 BOM characters from a string
     *
     * @param $string
     *
     * @return string
     */
    public function stripUtf8Bom($string)
    {
        $bom    = pack('H*', 'EFBBBF');
        $string = preg_replace("/^$bom/", '', $string);

        return $string;
    }

    /**
     * Check if the column values in $row are the same as the column names in $mapping.
     */
    protected function isHeaderRow(array $row, array $mapping)
    {
        $is_header_row = true;

        foreach ($mapping as $index => $column) {
            if (array_key_exists($index, $row)) {
                if ($row[$index] != $column) {
                    $is_header_row = false;
                }
            }
        }

        return $is_header_row;
    }

    /**
     * Parse a CSV row into a DB insertable array
     *
     * @param array $row     List of CSV columns
     * @param array $mapping Array of csvCol => dbCol
     *
     * @return array
     */
    protected function parseRow(array $row, array $mapping)
    {
        $columns = [];

        // apply mapping to row columns - ['column_name' => 'column_value']
        foreach ($mapping as $csv_index => $column_name) {
            $columns[$column_name] = array_key_exists($csv_index, $row) && !empty( $row[$csv_index] )
                ? $columns[$column_name] = $row[$csv_index]
                : null;
        }

        $columns = $this->aliasColumns($columns);

        $columns = $this->hashColumns($columns);

        return $columns;
    }

    /**
     * Remove columns in the mapping that don't exist in the DB table
     *
     * @param array $mapping
     *
     * @return array
     */
    protected function cleanMapping(array $mapping)
    {
        $columns    = $mapping;
        $columns[0] = $this->stripUtf8Bom($columns[0]);

        // Cull columns that don't exist in the database
        foreach ($columns as $index => $column) {
            // apply column alias
            $column = $this->aliasColumn($column);
            if (array_search($column, $this->table_columns) === false) {
                array_pull($columns, $index);
            }
        }

        return $columns;
    }

    /**
     * Apply aliases to a group of columns
     */
    protected function aliasColumns(array $columns)
    {
        if (is_array($this->aliases) && !empty( $this->aliases )) {
            foreach ($this->aliases as $csv_column => $alias_column) {
                if (array_key_exists($csv_column, $columns)) {
                    // store the value, remove the old column, add the new aliased column
                    $value = $columns[$csv_column];
                    array_pull($columns, $csv_column);
                    $columns[$alias_column] = $value;
                }
            }
        }

        return $columns;
    }

    /**
     * Apply alias to a single column
     */
    protected function aliasColumn($column)
    {
        return is_array($this->aliases) && array_key_exists($column, $this->aliases)
            ? $this->aliases[$column]
            : $column;
    }

    /**
     * Hash any hashable columns
     */
    protected function hashColumns(array $columns)
    {
        if (is_array($this->hashable) && !empty( $this->hashable )) {
            foreach ($this->hashable as $hashable) {
                if (array_key_exists($hashable, $columns)) {
                    $columns[$hashable] = bcrypt($columns[$hashable]);
                }
            }
        }

        return $columns;
    }

    /**
     * Resolves allowed columns for the table. Applies model guard if available.
     */
    protected function resolveTableColumns()
    {
        // get every column that exists on the table
        $columns = $this->getTableColumns();

        if (empty( $columns )) {
            $this->log('Unable to resolve table columns', 'critical');
            $this->console('Unable to resolve table columns', 'error');

            return;
        }

        // Run the model guard on the columns
        if (!empty( $this->model ) && $this->guard_model) {
            $columns = $this->guardModelColumns($this->resolveModel($this->model), $columns);
        }

        $this->log('Table columns resolved.');

        $this->table_columns = $columns;
    }

    /**
     * Apply model attributes like $fillable and $guarded to an array of columns
     *
     * @param Model $model
     * @param array $columns
     *
     * @return array
     */
    protected function guardModelColumns($model, $columns)
    {
        // filter out columns not allowed by the $fillable attribute
        if (method_exists($model, 'getFillable')) {
            if (!empty( $fillable = $model->getFillable() )) {
                foreach ($columns as $index => $column) {
                    if (array_search($column, $fillable) === false) {
                        array_pull($columns, $index);
                    }
                }
            };
        }

        return $columns;
    }

    /**
     * Verify the model exists and resolve its' table name, if not already defined
     *
     * @param $class - model class that implements the ORM (Eloquent)
     */
    protected function parseModel($class)
    {
        try {
            $model = $this->resolveModel($class);
        } catch (\Exception $e) {
            $this->log("$class could not be resolved.", 'warning');
            $this->console("$class could not be resolved.", 'error');

            return;
        }

        // resolve the table name from the model
        if (empty( $this->table )) {
            $table = method_exists($model, 'getTable') ? $model->getTable() : false;
            if ($table !== false) {
                $this->table = $table;
            } else {
                $this->log("Table could not be resolved from $class.", 'warning');
                $this->console("Table could not be resolved from $class.", 'error');
            }
        }
    }

    /**
     * Returns a new model instance
     */
    protected function resolveModel($class, $parameters = [])
    {
        return app($class, $parameters);
    }

    /**
     * Get all columns for the DB table
     */
    protected function getTableColumns()
    {
        return DB::getSchemaBuilder()->getColumnListing($this->table);
    }

    /**
     * Truncate a table (optionally ignore foreign keys)
     */
    protected function truncateTable()
    {
        if ($this->ignore_foreign_keys) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        DB::table($this->table)->truncate();

        if ($this->ignore_foreign_keys) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Disable the query log
     */
    protected function disableQueryLog()
    {
        DB::disableQueryLog();
    }

    /**
     * Show a message in the console
     */
    protected function console($message, $style = null)
    {
        if ($this->console_logs === false) return;

        $message = $style ? "<$style>$message</$style>" : $message;

        $this->command->line('<info>CSVSeeder: </info>' . $this->log_prefix . $message);
    }

    /**
     * Write a message to the logs using Laravel's Log helper
     */
    protected function log($message, $level = 'info')
    {
        if ($this->write_logs === false) return;

        logger()->log($level, 'CSVSeeder: ' . $this->log_prefix . $message);
    }
}