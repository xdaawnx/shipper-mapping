<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\City;
use App\Models\Suburb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use App\Helper\MappingLionCoverage;
use Illuminate\Support\Carbon;
use App\Models\LogisticRateCity;

class LogisticCoverage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:logistic-coverage {rate : rate name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    private $rateID = [
        "REG" => 44, 
        "BOSS" => 648,
        "JAGO" => 647,
        "BIG" => 649
    ];

    // Define the sheets to read by name or index (0-based)
    private $sheetNames = [
        "REG" => 'REGPACK CITY TO CITY', 
        "BOSS" => 'BOSSPACK CITY TO CITY',
        "JAGO" => "JAGOPACK CITY TO CITY",
        "BIG" => "BIGPACK CITY TO CITY"
    ];

    private $logisticID = 16;

    public function handle()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);
        $filePath = '/docs/source/Service Rates Sept 2024.xlsx';

        $rate = strtoupper($this->argument('rate'));

        $fullPath = Storage::path($filePath);

        // Check if the file exists
        if (!Storage::disk('local')->exists($filePath)) {
            $this->info('File not found.');
            return;
        }

        // Validate the argument
        if (empty($this->sheetNames[$rate])) {
            $this->error("Invalid action. Allowed rate are: " . implode(', ', array_keys($this->sheetNames)));
            return 1; // Exit with a non-zero status code
        }

        $coverage = Cache::get("lion:coverage:{$rate}");

        if (!$coverage) {
            // Load the spreadsheet
            $this->info("start load file");
            $spreadsheet = IOFactory::load($fullPath);
            $this->info("end load file");
            
            // Extract data
            $coverage = [];
            $this->info("start read data and save to cache");

            $sheet = $spreadsheet->getSheetByName($this->sheetNames[$rate]);

            if (!$sheet) {
                $this->info("Sheet '{$rate}' not found.");
                return;
            }
            $highestRow = $sheet->getHighestRow(); // Get the total number of rows in the sheet

            for ($row = 3; $row <= $highestRow; $row++) {
                $rowData = [];
            
                // Read column "A"
                $cell = $sheet->getCell("D{$row}");
                $valueA = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
                if (Date::isDateTime($cell)) {
                    $minDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('m');
                    $maxDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('d');
                    $valueA = $minDate . "-" . $maxDate;
                }
                $rowData['origin'] = $valueA;
            
                // Read column "D"
                $cell = $sheet->getCell("F{$row}");
                $valueD = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
                if (Date::isDateTime($cell)) {
                    $minDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('m');
                    $maxDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('d');
                    $valueD = $minDate . "-" . $maxDate;
                }
                $rowData['destination'] = $valueD;
            
                $coverage[] = $rowData;
            }


            Cache::forever("lion:coverage:{$rate}", $coverage); // Cache forereverrrrrrr
            $this->info("success read data and save to cache");

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles(); // Force garbage collection
        }
        $cities = [];
        $notfound = $skip = [];

        foreach ($coverage as $k => $v) {
            if (is_null($v["origin"]) || is_null($v["destination"])) {
                break;
            }

            if (!empty($skip[$v["origin"]]) || !empty($skip[$v["destination"]])) {
                continue;
            }

            // Skip if the city is in the skip list
            if (!empty(MappingLionCoverage::SKIPCITY[$v["origin"]])) {
                $this->warn("skip origin {$v['origin']}");
                $skip[$v["origin"]] = 404;
                continue;
            }
            if (!empty(MappingLionCoverage::SKIPCITY[$v["destination"]])) {
                $this->warn("skip destination {$v['destination']}");
                $skip[$v["destination"]] = 404;
                continue;
            }
            
            // Handle origin cities
            $originResults = $this->getCityFromCacheOrFind($v["origin"]);
            if (is_null($originResults)) {
                $notfound[$v["origin"]] = null;
                $this->warn("origin {$v['origin']} not found");
                continue;
            } 
            foreach ($originResults as $origin) {
                $cityId = $origin['city_id'] ?? null;
                if ($cityId && !isset($cities[$cityId])) {
                    $cities[$cityId] = $origin;
                }

                $cities[$cityId]["lrc"]["origin"] = 1;
                $cities[$cityId]["sheet"]["origin"] = $v["origin"];
            }

            // Handle destination cities
            $destinationResults = $this->getCityFromCacheOrFind($v["destination"]);
            if (is_null($destinationResults)) {
                $notfound[$v["destination"]] = null;
                $this->warn("destination {$v['destination']} not found");
                continue;
            } 
            foreach ($destinationResults as $destination) {
                $cityId = $destination['city_id'] ?? null;
                if ($cityId && !isset($cities[$cityId])) {
                    $cities[$cityId] = $destination;
                }
                $cities[$cityId]["lrc"]["destination"] = 1;
                $cities[$cityId]["sheet"]["destination"] = $v["destination"];

            }
        }

        // Convert associative array to an indexed array
        $cities = array_values($cities);

        foreach ($cities as $k => $city) {
            $cityId = $city['city_id'];
            $orderEnabled = $city['lrc']['origin'] ?? 0;
            $destinationEnabled = $city['lrc']['destination'] ?? 0;

            // Check if the record exists and update or insert
            LogisticRateCity::updateOrCreate(
                [
                    'rate_id' => $this->rateID[$rate], // Replace with your actual rate_id
                    'city_id' => $cityId,
                    'logistic_id' => $this->logisticID, // Replace with your actual logistic_id
                ],
                [
                    'rate_enabled' => 1, // Default or required value
                    'order_enabled' => $orderEnabled,
                    'destination_enabled' => $destinationEnabled,
                    'dropoff_enabled' => 0, // Default value
                    'cod_origin_enabled' => 0, // Default value
                    'cod_destination_enabled' => 0, // Default value
                    'hubless_enabled' => 0, // Default value
                    'implant_enabled' => 0, // Default value
                    'cashless' => 0, // Default value
                    'multikoli_enabled' => 0, // Default value
                    'created_date' => Carbon::now(), // Will only be used on insert
                    'created_by' => "admin", // Will only be used on insert
                ]
            );
        }
        $this->info("task completed");
    }

    
    public function processString($input)
    {
        // Initialize output array
        $outputs = [];
    
        // Check if the input contains "Kab."
        if (strpos($input, 'Kab.') !== false) {
            // Remove "Kab." and trim spaces
            $province = str_replace('Kab.', '', $input);
            $province = trim($province);
    
            // Format the first output: "Aceh Barat, Kab."
            $outputs[] = $province . ', Kab.';
    
            // Format the second output: "Aceh Barat"
            $outputs[] = $province;
    
        }
        // Check if the input contains "Kota"
        elseif (strpos($input, 'Kota') !== false) {
            // Remove "Kota" and trim spaces
            $city = str_replace('Kota', '', $input);
            $city = trim($city);
    
            // Format the first output: "Tangerang, Kota"
            $outputs[] = $city . ', Kota';
    
            // Format the second output: "Tangerang" (with first letter capitalized)
            $outputs[] = ucfirst($city);
        }
        else {
            // If neither "Kab." nor "Kota" is found, return the original string as is
            $outputs[] = $input;
            $outputs[] = $input;
        }
    
        return $outputs;
    }

    private function getCityFromCacheOrFind($city)
    {
        $cacheKey = "lion:coverage:city-{$city}";
        $cachedCity = Cache::get($cacheKey);

        if (!empty($cachedCity)) {
            return $cachedCity;
        }

        $foundCity = $this->findCity($city);
        if (!empty($foundCity)) {
            Cache::add($cacheKey, $foundCity, now()->addHours(24)); // Cache for 24 hours
        }

        return $foundCity; // Will return null if not found
    }

    public function findCity(string $cityName) {
        DB::enableQueryLog();
        $originCity = !empty(MappingLionCoverage::CITYEXCEPTION[$cityName]) ? MappingLionCoverage::CITYEXCEPTION[$cityName] : $cityName;

        $cityNameModified = $this->processString($originCity);
        $query = City::join('province', 'city.province_id', '=', 'province.province_id')
            ->select('city.city_id', 'city.city_name', 'province.province_name');

        if ($cityNameModified[0] == $cityNameModified[1]) {
            $query->where('city.city_name', 'like', "%$cityNameModified[1]%");
        }elseif ($cityNameModified[0] != $cityNameModified[1]) {
            $query->where('city.city_name', 'like', "%$cityNameModified[0]%");
        }
        // Get the results from the query
        return $query->get()->toArray();
        
        // Now, map over the cities to extract only the attributes
        $formattedCities = $cities->map(function ($city) {
            return $city->attributesToArray();
        });
        
        return $formattedCities->toArray();
    }
}
