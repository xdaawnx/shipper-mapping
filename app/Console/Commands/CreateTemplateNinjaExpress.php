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
use App\Helper\Mapping;

class CreateTemplateNinjaExpress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-template-ninja-express';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    
    const HEADERS = [
        'No', 
        'Logistic Name', 
        'Rate Name', 
        'Origin Province Name', 
        'Origin City Name', 
        'Origin Suburb Name', 
        'Destination Province Name', 
        'Destination City Name', 
        'Destination Suburb Name', 
        'Destination Area Name', 
        'Pricing', 
        'Min Kg', 
        'Max Kg', 
        'Min Day', 
        'Max Day'
    ];
    
    public function handle()
    {
        $lock = Cache::lock('create-template', 60); // 5 minutes lock
        $startTime = microtime(true);

        if ($lock->get()) {
            try {
                // Task logic here
                $this->info('Task is running.');
                $this->createTemplate();
                

            } catch (\Throwable $th) {
                $this->error($th->getMessage().". line : ".$th->getLine());
            }
            finally {
                // Release the lock
                $lock->release();
            }
        } else {
            $this->info('Previous process is still running. Skipping this iteration.');
        }

        $endTime = microtime(true);
        $executionTime = number_format((float) ($endTime - $startTime) / 60, 2, '.', '');

        $this->info('Execution time '.$executionTime." Minutes");


    }
    public function createTemplate() : void {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $filePath = '/docs/source/Ninja-2024-Rate-Card.xlsx';

        // Check if the file exists
        if (!Storage::disk('local')->exists($filePath)) {
            $this->info('File not found.');
            return;
        }

        $coloumnStartPrice = "H";
        $coloumnEndPrice = "TA";
        $startRow = 2;
        $data = [];
        
        // Get the full path to the file
        $fullPath = Storage::path($filePath);

        // Define the sheets to read by name or index (0-based)
        $sheetNames = ['Regular Published Rate Card', 'SLA'];

        // Cache::forget('ninja:price1');

        $data = Cache::get("ninja:price");
        $lastCityColoumn = Cache::get("ninja:priceConf");

        if (!$data) {
            // Load the spreadsheet
            $this->info("start load file");
            $spreadsheet = IOFactory::load($fullPath);
            $this->info("end load file");

            
            // Extract data
            $data = [];
            $this->info("start read data and save to cache");
            foreach ($sheetNames as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
    
                if (!$sheet) {
                    $this->info("Sheet '{$sheetName}' not found.");
                    return;
                }
    
                foreach ($sheet->getRowIterator() as $row) {
                    $rowData = [];
                    foreach ($row->getCellIterator() as $cell) {
                        if (Date::isDateTime($cell)) {
                            $minDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('m');
                            $maxDate = (int) Date::excelToDateTimeObject($cell->getValue())->format('d');
                            $rowData[] = $minDate."-".$maxDate;
                            continue;
                        }
                        if ($cell->isFormula()) {
                            $rowData[] = $cell->getCalculatedValue();
                            continue;
                        }
                        $rowData[] = $cell->getValue();
                    }
                    $data[$sheetName][] = $rowData;
                }
            }


            $lastCityColoumn["lastRecord"] = [
                "StartColoumnOrigin" => 7,
            ];
            $lastCity = $lastCityColoumn["lastRecord"]["StartColoumnOrigin"];
            
            Cache::add("ninja:priceConf", $lastCityColoumn, now()->addHours(24)); // Cache for 24 hour
            Cache::add("ninja:price", $data, now()->addHours(24)); // Cache for 24 hour
            $this->info("success read data and save to cache");

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles(); // Force garbage collection

        }
        $lastCity = $lastCityColoumn["lastRecord"]["StartColoumnOrigin"];

        $origin = $data[$sheetNames[0]][$startRow][$lastCity];
        if (!empty(Mapping::SKIPCITYORIGIN[$origin])) {
            $this->warn("Skip City {$origin}");
            $lastCityColoumn["lastRecord"]["StartColoumnOrigin"]++;
            Cache::put("ninja:priceConf", $lastCityColoumn, now()->addHours(24)); // Cache for 24 hour
            return;
        }

        $city = $this->findCity($origin);
        
        if (empty($city)) {
            $this->warn("City {$origin} not found");
            return;
        }
        $this->info($coloumnStartPrice."3 :".$origin." - ".$city->city_name);

        $dataCSV = [];
        $suburbNotFound = [];
        $no = [];

        $this->info("start mapping suburb");
        foreach ($data[$sheetNames[0]] as $key => $value) {
            if ($key <= 2) {
                continue;
            }
            $priceSheet = $data[$sheetNames[0]];
            $slaSheet = $data[$sheetNames[1]];
            
            $cityName = $priceSheet[$key][3];
            if (!empty(Mapping::SKIPCITYORIGIN[$cityName])) {
                continue;
            }

            $cityDestination = $this->findCity($cityName, $key);
            
            if (empty($cityDestination)) {
                $this->warn("Suburb city {$cityName} not found");
                return;
            }
            
            $suburbName = $priceSheet[$key][4];
            if (!empty(Mapping::SUBURBSKIPDESTINATION[$suburbName]) || $cityDestination->city_id == 70 && $suburbName == "Sukaraja") {
                $this->warn("Suburb {$suburbName} not found, skip suburb");
                continue;
            }
            
            $suburbName = !empty(Mapping::SUBURBEXCEPTION[$suburbName]) ? Mapping::SUBURBEXCEPTION[$suburbName] : $suburbName;
            $suburbNameModified = str_replace("-"," ",$suburbName);

            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
                ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('city.city_id',$cityDestination->city_id)
            ->where(function ($query) use($suburbName, $suburbNameModified) {
                $query->where('suburb_name',$suburbName)
                    ->orWhere("suburb_name",$suburbNameModified);
            });
        
            $suburb = $query->first();
            if (empty($suburb->suburb_id)) {
                
                $query = Suburb::select([
                    "suburb_id",
                    "suburb_name",
                    "city_name",
                    "city.city_id",
                    "province_name",
                    DB::raw("REGEXP_REPLACE(suburb_name, '\\\\(.*\\\\)', '') as outside"),
                    DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(suburb_name, '(', -1), ')', 1) AS inside")
                ])
                ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
                ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
                ->where('city.city_id',$cityDestination->city_id)
                ->having(function ($query) use($suburbName) {
                    $query->having('outside',$suburbName)
                    ->orHaving('inside',$suburbName);
                });

                // exception for panukal
                $query = $this->exceptionSuburb($query,$suburbName,$cityDestination);
                $suburb = $query->first();

                if (empty($suburb->suburb_id)) {

                    // $sql = $query->toSql();
                    // $bindings = $query->getBindings();
                    // $fullQuery = vsprintf(str_replace(['%', '?'], ['%%', '%s'], $sql), $bindings);

                    // $this->info($suburbName);
                    // $this->warn('Executing Query: ' . $fullQuery);

                    // $suburbNotFound[] = ["suburbName" => "{$suburbName}", "city_id"=> $cityDestination->city_id];
                    // LOG::info($priceSheet[$key]);
                    // var_dump($priceSheet[$key][1]);
                    $this->error("city {$cityName}, suburb {$suburbName} not found | DB -> city_id : {$cityDestination->city_id}, city_name :{$cityDestination->city_name}");
                    continue;
                }
            }
            $price = $priceSheet[$key][$lastCity];
            $sla = explode("-",$slaSheet[$key][($lastCity-1)]) ;
            $minDate = $sla[0];
            $maxDate = $sla[1];

            $dataCSV[] = [
                $key - 2,
                "Ninja Xpress",
                "Standard",
                $city->province_name,
                $city->city_name,
                null,
                $suburb->province_name,
                $suburb->city_name,
                $suburb->suburb_name,
                null,
                $price,
                1,
                "0",
                $minDate,
                $maxDate
            ];
        }
        $this->info("end mapping suburb");

        // LOG::info($suburbNotFound);
        // LOG::info($no);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(SELF::HEADERS, NULL, 'A1');

        $sheet->fromArray($dataCSV, NULL, 'A2');

        // Write the file as CSV
        $writer = new Csv($spreadsheet);

        // Set CSV delimiter (optional, default is comma)
        $writer->setDelimiter(',');
        $now = date('Ymd');
        // Save the file to a specified location
        $outputPath = "/docs/output/{$now}/";
        $fullPath = Storage::path($outputPath);

        // Check if the directory already exists
        if (!file_exists($fullPath)) {
            // Create the directory
            if (mkdir($fullPath, 0777, true)) { // 0777 is the permission, 'true' allows recursive creation
                $this->info("Directory created successfully.");

            } else {
                $this->warn("Failed to create directory.");
            }
        } 

        $originCity = !empty(Mapping::CITYEXCEPTION[$origin]) ? Mapping::CITYEXCEPTION[$origin] : $origin;
        $filePrefix = ($lastCityColoumn["lastRecord"]["StartColoumnOrigin"] - 6)."_".date('Ymd')."_";
        $fileName = "{$filePrefix}".str_replace('/', '_', $originCity).".csv";
        $writer->save($fullPath.$fileName);
        $this->info("CSV file created successfully: {$fullPath}{$fileName}");

        $lastCityColoumn["lastRecord"]["StartColoumnOrigin"]++;
        // if next coloumn is empty then delete cache and rename file
        if (empty($data[$sheetNames[0]][$lastCityColoumn["lastRecord"]["StartColoumnOrigin"]])) {
            $from = $filePath;
            $to = '/docs/source/DONE_Ninja-2024-Rate-Card.xlsx';
            if (Storage::move($from, $to)) {
                $this->info("File renamed successfully.");
            } else {
                $this->warn("Failed to rename file.");
            }

            Cache::forget('ninja:price');
            Cache::forget('ninja:priceConf');
            $this->info("templating is complete :D");
        }

        $this->info("update cache for next coloumn");
        Cache::put("ninja:priceConf", $lastCityColoumn, now()->addHours(24)); // Cache for 24 hour
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

    public function getNextExcelAlphabet($current) {
        $length = strlen($current);
        $next = $current;
        $i = $length - 1;

        while ($i >= 0) {
            // Increment the last character
            if ($next[$i] < 'Z') {
                $next[$i] = chr(ord($next[$i]) + 1);
                return $next;
            }

            // If character is 'Z', reset it to 'A' and move to the previous character
            $next[$i] = 'A';
            $i--;
        }

        // If all characters were 'Z', prepend 'A' (e.g., 'Z' → 'AA')
        return 'A' . $next;
    }
    
    public function findCity(string $cityName) {
        $originCity = !empty(Mapping::CITYEXCEPTION[$cityName]) ? Mapping::CITYEXCEPTION[$cityName] : $cityName;
        $cityNameModified = $this->processString($originCity);
        $query = City::join('province', 'city.province_id', '=', 'province.province_id')
            ->select('city.city_id', 'city.city_name', 'province.province_name');
        switch ($cityName) {
            case 'Kota Banjar':
                $query->where("city.province_id","=",9);
                break;
            
            case 'Kab. Banjar':
                $query->where("city.province_id","=",13);
                break;
        }
        if ($cityNameModified[0] == $cityNameModified[1]) {
            $query->where('city.city_name', '=', $cityNameModified[1]);
        }elseif ($cityNameModified[0] != $cityNameModified[1]) {
            $query->where('city.city_name', '=', $cityNameModified[0]);
        }

        $city = $query->first();

        if (empty($city)) {
            switch ($cityNameModified[1]) {
                case 'Baru':
                    $cityNameModified[1] = "KotaBaru";
                    break;
                case "Mobagu":
                    $cityNameModified[1] = "Kotamobagu";
                    break;
                case "Lima Puluh Koto/":
                    $cityNameModified[1] = "Lima Puluh Koto/Kota";
            }

            $query = City::join('province', 'city.province_id', '=', 'province.province_id')
            ->select('city.city_id', 'city.city_name', 'province.province_name')
            ->where('city.city_name', '=', $cityNameModified[1]);

            $city = $query->first();
        }
        // if ($i == 3080) {
        //     return $query;
        // }
        return $city;
    }
    function exceptionSuburb($query,$suburbName,$cityDestination) {
        if ($suburbName == "Penukal") {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"like","%{$suburbName}%");
        }
        
        if ($suburbName == "Sukamakmur" && $cityDestination->city_id == 253) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Suka makmur")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Sei/Sungai Raya" && $cityDestination->city_id == 246){
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Sungai Raya")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }

        if ($suburbName == "Kebon Agung" && $cityDestination->city_id == 81) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Kebonagung")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Karang Rejo" && $cityDestination->city_id == 132) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Karangrejo")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Sei/Sungai Raya" && ($cityDestination->city_id == 147 || $cityDestination->city_id == 162)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Sungai Raya")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Pallangga" && ($cityDestination->city_id == 385)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Palangga")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Wapoga Inggerus" && ($cityDestination->city_id == 323)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Wapoga")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Kota Baru" && ($cityDestination->city_id == 281)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Kotabaru")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Sei/Sungai Pinang" && ($cityDestination->city_id == 439)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Sungai Pinang")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Muara Dua" && ($cityDestination->city_id == 429)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Muaradua")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        if ($suburbName == "Lima Puluh" && ($cityDestination->city_id == 467)) {
            $query = Suburb::select([
                "suburb_id",
                "suburb_name",
                "city_name",
                "city.city_id",
                "province_name",
            ])
            ->join('city', 'city.city_id', '=', 'suburb.city_id')  // Joining city table
            ->join('province', 'province.province_id', '=', 'city.province_id')  // Joining province table
            ->where('suburb_name',"=","Limapuluh")
            ->where('city.city_id',"=",$cityDestination->city_id);
        }
        return $query;
    }
}
