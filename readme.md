# CSV Seeder

This package is intended to make seeding your database with CSV files a breeze.


## Installation

Require this package in your composer.json and run `composer update`

    "crockett/csv-seeder": "1.1.*"

Or just require it directly:

`composer require crockett/csv-seeder`


## Basic Usage

All you need is a valid CSV file:

    first_name,last_name,email
    Foo,Bar,foo@example.com
    Bar,Foo,bar@example.com

A similar database table:

| id  | first_name | last_name | email |
| --- | ---        | ---       | ---   |

And either extend `Crockett\CsvSeeder\CsvSeeder` in your seeder:

    use Crockett\CsvSeeder\CsvSeeder;

    class UsersTableSeeder extends CsvSeeder {

        public function __construct()
        {
            $this->table = 'users';
            $this->filename = base_path('/path/to/csv/users.csv');
        }

        public function run()
        {
            // run the CSV seeder
            parent::run();
        }
    }

Or create a new instance and run it:

    $csvSeeder = new Crockett\CsvSeeder\CsvSeeder();
    $csvSeeder->table = 'users';
    $csvSeeder->filename = base_path('/path/to/csv/users.csv');
    $csvSeeder->runSeeder();

Drop your CSV into `/database/seeds/csvs/your_csv.csv` or whatever path you specify in your constructor above.


## Configuration

 - `table` (string) Database table to insert into.
 - `model` (string) Instead of a table name, you can pass an Eloquent model class name.
 - `filename` (string) The path to the CSV file.
 - `csv_delimiter` (string ,) The CSV field delimiter.
 - `offset_rows` (int 0) How many rows at the start of the CSV to skip.
 - `skip_header_row` (bool true) Automatically skip the first row if it's determined to be the header. Setting `offset_rows` higher than 0 bypasses this.
 - `mapping` (array) Associative array of csvColumnIndex => csvColumnName. See examples for details. If not specified, the first row (after offset) of the CSV will be used as the mapping.
 - `aliases` (array) Associative array of csvColumnName => aliasColumnName. See examples for details. Allows for flexible CSV column names.
 - `hashable` (string|array 'password') Hashes the specified field(s) using `bcrypt`. Useful if you are importing users and need their passwords hashed. Note: This is EXTREMELY SLOW, large CSVs will take time to import.
 - `insert_chunk_size` (int 50) An insert callback will trigger every `insert_chunk_size` rows while reading the CSV.
 - `insert_callback` (closure|callable null) - Override the default insert callback with your own. Callback must accept an array of rows ($chunk). See examples for details.
 - `truncate_before_insert` (bool false) - Truncate the table prior to insertion
 - `ignore_foreign_keys` (bool false) - Disable foreign key checks when truncating the table.
 - `disable_query_log` (bool true) - Disable the query log. Recommended true for large CSVs.
 - `guard_model` (bool true) - Respect model attributes such as $fillable and $guarded when resolving table columns with a model.
 - `write_logs` (bool false) - Write messages to logs. Recommended false for large CSVs.
 - `console_logs` (bool true) - Show messages in the console.
 - `log_prefix` (string) - Customize the log messages


## Examples

Setting the model instead of a table (table can still be set):

    public function __construct()
    {
        $this->model = \App\User::class;
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
    }

CSV with pipe delimited values:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
        $this->csv_delimiter = '|';
    }

Specifying which CSV columns to import:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            5 => 'age',
        ];
    }

Skipping the first row in your CSV (Note: If the first row after the offset isn't the header row, a mapping must be defined):

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
        $this->offset_rows = 1;
        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            2 => 'password',
        ];
    }

Aliasing a CSV column to a different name with a mapping:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            5 => 'age',
        ];
        $this->aliases = [
            'age' => 'date_of_birth',
        ];
        // result: first_name,last_name,date_of_birth
    }

Aliasing a CSV column to a different name without a mapping:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv'); // CSV headers: first_name,last_name,age
        $this->aliases = [
            'age' => 'date_of_birth',
        ];
        // result: first_name,last_name,date_of_birth
    }


## Advanced Usage

Defining your own insert method

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/your_csv.csv');
        $this->insert_callback = function ($chunk) {
            // your custom insert code here.
            // examples:

            // mass insert the whole chunk:
            \DB::table('users')->insert($chunk);

            // insert rows from the chunk individually:
            foreach ($chunk as $row) {
                \App\User::create($row);
            }
        }
    }

Seeding multiple CSVs in one seeder

Manually:

    public function run()
    {
        $this->table = 'users';
        $this->filename = base_path('/database/seeds/csvs/users.csv')
        parent::run();

        // ensures a clean slate for the next CSV
        $this->resetSeeder();

        $this->table = 'posts';
        $this->filename = base_path('/database/seeds/csvs/posts.csv')
        parent::run();
    }

Via helper functions:

    public function run()
    {
        $this->seedModelWithCSV(User::class, base_path('/database/seeds/csvs/users.csv'));

        // resetSeeder() is called automatically

        $this->seedTableWithCSV('posts', base_path('/database/seeds/csvs/posts.csv'));
    }


## License

CsvSeeder is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)