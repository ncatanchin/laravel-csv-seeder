## CSV Seeder

This package is intended to minimize the time and hassle spent importing CSV-based data. By making a few assumptions about your average CSV file, most users won't need any configuration to start seeding. For those that do, the available configuration options offer plenty of control over your CSV and how the data is inserted into the database.


### Overview
- [Key Features](#key-features)
- [Installation](#installation)
- [Setup](#setup)
- [Common Usage](#common-usage)
    - [Mismatched Columns](#mismatched-columns)
    - [Insert Callbacks](#insert-callbacks)
- [Configuration](#configuration)
- [More Examples](#more-examples)
- [License](#license)


### Key Features

- Automatic mapping of CSV headers to the columns in your DB table.
- Aliases allow you to easily adjust a CSV column's name before it's inserted.
- Insert callbacks can be used to directly manipulate the CSV data before it's inserted.
- ORM support - when using a model, common attributes such as `$guarded` and `$fillable` are applied to the CSV.


## Installation

Require this package in your composer.json and run `composer update`

    "crockett/csv-seeder": "1.1.*"

Or just require it directly:

`composer require crockett/csv-seeder`

## Setup

Here is a typical, single CSV seeder setup:

    use Crockett\CsvSeeder\CsvSeeder;

    class UsersTableSeeder extends CsvSeeder {

        public function __construct()
        {
            $this->filename = base_path('path/to/csv/users.csv');
            $this->table = 'users';
        }

        public function run()
        {
            parent::run();
        }
    }

If you want to seed multiple CSVs in the same seeder, you could do something like this:

    use Crockett\CsvSeeder\CsvSeeder;

    class UsersTableSeeder extends CsvSeeder {

        public function run()
        {
            // seed the users table
            $this->filename = base_path('path/to/csv/users.csv');
            $this->table = 'users';
            parent::run();

            // seed the posts table
            $this->filename = base_path('path/to/csv/posts.csv');
            $this->table = 'posts';
            parent::run();
        }
    }

That can get messy, fast. Instead, you could use the helper method `seedFromCSV()`, which is just a cleaner, condensed way to define your parameters and call `parent::run()`. Example:

    use Crockett\CsvSeeder\CsvSeeder;

    class UsersTableSeeder extends CsvSeeder {

        public function run()
        {
            // seed the users table
            $this->seedFromCSV(base_path('path/to/users.csv'), 'users');

            // seed the posts table
            $this->seedFromCSV(base_path('path/to/posts.csv'), 'posts');
        }
    }


## Common Usage

Given the following CSV file and database table:

    // users.csv
    first_name,last_name,birth_date,password,favorite_color
    Joe,Bar,2000-02-10,joePassword,red
    Jim,Foo,1990-02-10,jimPassword,blue
    Foo,Bar,1980-02-10,fooPassword,green

    // users DB table
    id, first_name, last_name, birth_date, password, favorite_color

You can just run the seeder with no further setup:

    $this->seedFromCSV(base_path('path/to/users.csv'), 'users');

When all the column names match, this is all that's required to run the seeder. You could even go a step further and omit the table name, because the CSV filename is the same as the table.

### Mismatched columns

Unless you have complete control over you CSVs, the headers won't always match up with your DB columns. For example:

    // users.csv
    first_name, last_name, birth_date, password, favorite_color

    // users DB table
    id, first_name, last_name, age, password

In this case, you can define `$aliases` to rename the `birth_date` column to `age` before it's inserted:

    $this->aliases = [
        'birth_date' => 'age'
    ];

    $this->seedFromCSV(base_path('path/to/users.csv'), 'users');

Alternatively, you can manually define a `$mapping` for your CSV. A mapping allows you to explicitly choose and rename CSV columns. For example:

    // users.csv
    first_name, last_name, birth_date, password, favorite_color

    // users DB table
    id, first_name, last_name, color, password

    // users seeder
    $this->mapping = [
        0 => 'first_name',
        1 => 'last_name',
        3 => 'password',
        4 => 'color', // renamed from favorite_color
    ];

    $this->seedFromCSV(base_path('path/to/users.csv'), 'users');

When you define a `$mapping`, a header row on your CSV is *not* required. In all other cases, `CsvSeeder` will assume your header row is the first row after `$offset_rows`.

### Insert Callbacks

In some cases you'll need to manipulate the CSV data before it's inserted into the database. Using an `$insert_callback`, it couldn't be easier! When a `$chunk` of rows is read from the CSV, they're passed to the `$insert_callback`. All you need to do is define your own callback.

Here we'll iterate over individual rows in the chunk and insert them using `Model::create()`:

    $this->insert_callback = function ($chunk) {
        foreach($chunk as $row) {
            \App\User::create($row->toArray());
        }
    };

    $this->seedFromCSV(base_path('path/to/users.csv'), 'users');

Note, `$chunk` and `$row` are instances of `\Illuminate\Support\Collection` so you can easily manipulate and filter the rows and columns:

    $this->insert_callback = function ($chunk) {
        foreach($chunk as $row) {
            $user_data = $row->only('first_name', 'last_name', 'password')->toArray();
            \App\User::create($user_data);
        }
    };

    $this->seedFromCSV(base_path('path/to/users.csv'), 'users');


## Configuration

 - `table` (string) Database table to insert into.
 - `model` (string) Instead of a table name, you can pass an ORM model name.
 - `model_guard` (bool true) - Respect model attributes such as $fillable and $guarded when resolving table columns with a model.
 - `filename` (string) The path to the CSV file.
 - `delimiter` (string ,) The CSV field delimiter.
 - `offset_rows` (int 0) How many rows at the start of the CSV to skip.
 - `skip_header_row` (bool true) Automatically skip the first row if it's determined to be the header. Setting `offset_rows` higher than 0 bypasses this.
 - `mapping` (array) Associative array of csvColumnIndex => csvColumnName. See examples for details. If not specified, the first row (after offset) of the CSV will be used as the mapping.
 - `aliases` (array) Associative array of csvColumnName => aliasColumnName. See examples for details. Allows for flexible CSV column names.
 - `hashable` (string|array 'password') Hashes the specified field(s) using `bcrypt`. Useful if you are importing users and need their passwords hashed. Note: This is EXTREMELY SLOW, large CSVs will take time to import.
 - `insert_chunk_size` (int 50) An insert callback will trigger every `insert_chunk_size` rows while reading the CSV.
 - `insert_callback` (callable) - Override the default insert callback with your own. Callback must accept a `Collection` of rows ($chunk).
 - `console_logs` (bool true) - Show messages in the console. (neglible performance impact)
 - `write_logs` (bool false) - Write messages to logs. (recommended off for large CSVs)
 - `disable_query_log` (bool true) - Disable the query log. (recommended on for large CSVs)
 - `log_prefix` (string) - Customize the log messages


## More Examples

CSV with pipe delimited values:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('database/seeds/csvs/your_csv.csv');
        $this->csv_delimiter = '|';
    }

Specifying which CSV columns to import:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('database/seeds/csvs/your_csv.csv');
        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            5 => 'age',
        ];
    }

Using a model instead of a table:

    public function __construct()
    {
        $this->model = \App\User::model;
        $this->filename = base_path('database/seeds/csvs/your_csv.csv');
    }

Skipping the first row of your CSV (Note: If the first row after the offset isn't the header row, a mapping must be defined):

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('database/seeds/csvs/your_csv.csv');
        $this->offset_rows = 1;
        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            2 => 'password',
        ];
    }

Aliasing a CSV column:

    public function __construct()
    {
        $this->table = 'users';
        $this->filename = base_path('database/seeds/csvs/your_csv.csv');

        // aliasing without a mapping - here, 'age' is a named column in your CSV
        $this->aliases = [
            'age' => 'date_of_birth',
        ];

        $this->mapping = [
            0 => 'first_name',
            1 => 'last_name',
            5 => 'age',
        ];
        // aliasing with a mapping - here, 'age' is a column you mapped to index 5 of your CSV
        $this->aliases = [
            'age' => 'date_of_birth',
        ];
    }

## License

CsvSeeder is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)