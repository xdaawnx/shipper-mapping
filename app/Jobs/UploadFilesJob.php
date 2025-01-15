<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\RequestException;

class UploadFilesJob implements ShouldQueue
{
    use Queueable;

    protected $files;
    /**
     * Create a new job instance.
     */
    public function __construct(array $files)
    {
        $this->files = $files;
    }

    /**
     * Execute the job.
     */
    private $dir = "Sicepat2025";

    public function handle()
    {
        $effective_date = date('Y-m-d H:i:s', strtotime(date("Y-m-d H:i:s"). ' +7 hours'));
        $mediaSVCToken = Cache::get("mediasvc:token");
        $pricingSVCToken = Cache::get("pricingsvc:token");

        if (!$mediaSVCToken || !$pricingSVCToken) {
            $username = env("MEDIASVC_CLIENT_ID");
            $password = env("MEDIASVC_CLIENT_SECRET");
            $respMediaSVC = $this->getCred($username, $password);
            $data = $respMediaSVC['data'];
            Cache::add("mediasvc:token", $data["access_token"], now()->addHours(1)); // Cache for 24 hour
            $mediaSVCToken = $data["access_token"];

            $username = env("PRICINGSVC_CLIENT_ID");
            $password = env("PRICINGSVC_CLIENT_SECRET");
            $respPricingSVC = $this->getCred($username, $password);   
            $data = $respPricingSVC['data'];
            Cache::add("mediasvc:token", $data["access_token"], now()->addHours(1)); // Cache for 24 hour
            $pricingSVCToken = $data["access_token"];
        }

        $httpClient = new HTTP();
        $multipartData = [
            [
                'name'     => 'account_id',
                'contents' => 1,
            ],
            [
                'name'     => 'account_type',
                'contents' => 'ADMIN',
            ],
            [
                'name'     => 'is_public',
                'contents' => 'true',
            ],
        ];
        $i = 0;
        foreach ($this->files as $key => $file) {
            if (!file_exists(Storage::path("/docs/output/{$this->dir}/{$file}"))) {
                throw new \Exception("One or more files do not exist.");
            }
            $no = $i == 0 ? "" : $i+1;
            $multipartData[] = [
                'name'     => 'file'.$no,  // The field name in the API (e.g., file, documents, etc.)
                'contents' => fopen(Storage::path("/docs/output/{$this->dir}/{$file}"), 'r'),
                'filename' => $file // Optionally add a custom file name
            ];
            $i++;
        }

        // Simulate HTTP upload
        $response = Http::withToken($mediaSVCToken)
            ->asMultipart()
            ->post(
                env("MEDIASVC_HOST").'/v1/media/files',
                $multipartData
            );

        if ($response->failed()) {
            // Log dan lempar exception jika gagal
            Log::error("Failed to upload files: " . implode(', ', $this->files), [
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);

            // Lempar exception HTTP untuk kegagalan
            throw new RequestException($response);
        }
        $response = json_decode($response->getBody());
        $dataMediaSVC = $response->data->media;
        $jobIds = [];

        foreach ($dataMediaSVC as $k => $v) {
            $jobIds[] = $v->media_key.'/'.$v->uuid;
        }

        $afterUploadFiles = $this->afterUploadFilePut($mediaSVCToken, $jobIds, "pricingsvc", "BulkUpdate", []);
        foreach ($afterUploadFiles as $key => $value) {
            // dd($value->file_name);
            $bulkUpdate = $this->bulkUpdate($pricingSVCToken, $value->url, $effective_date);
            DB::table('uploaded_files')
                ->where('file_name', $value->file_name) // Add conditions
                ->update([
                    'status' => 'success',
                    'job_id' => $bulkUpdate->data["job_id"]
                ]); 
        }
    }
    public static function getCred(string $username, string $password) {
        try {
            $url = env("ACCOUNTSVC_HOST")."/v1/oauth2/token";
            $response = Http::withHeaders([
                    "x-app-debug" => "true",
                ])
                ->withBasicAuth(
                    $username,
                    $password
                )
                ->asForm()
                ->post($url, [
                    'grant_type' => "client_credentials",
                    'username' => $username,
                    'password' => $password,
                ]);

            // Cek status response
            if ($response->successful()) {
                // Respons status 200
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            } else {
                // Respons status 400-500
                return [
                    'success' => false,
                    'error' => $response->json(),
                ];
            }
        } catch (\Exception $e) {
            // Tangani jika terjadi kesalahan di luar respons HTTP
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ],
            ];
        }
    }

    public function afterUploadFilePut($token = "",$jobIds = "", $destinationBucket = "", $prefixFilename = "", $opt = [])
    {
        $data = [
            'data' => [
                'media' => [
                    'account_id' => 1,
                    'account_type' => "ADMIN",
                    'destination_bucket' => $destinationBucket,
                    'id' => [],
                    'prefix_filename' => $prefixFilename,
                ],
            ],
        ];
        foreach ($jobIds as $k => $v) {
            $data["data"]["media"]["id"][] = $v;
        }

        try {
            $response = Http::withHeaders(array_key_exists("headers", $opt) ? $opt['headers'] : [])
            ->withToken($token)
            ->put(env('MEDIASVC_HOST') . '/v1/media/files', $data);
            if ($response->successful()) {
                $res = json_decode($response->getBody());
                return $res->data->media;
            }
            $response->throw();
        } catch (RequestException $e) {
            Log::error($e);
            throw $e;
        }

        return [];
    }

    public function bulkUpdate($token = '', $url = "", $effective_date = "")
    {
        // $data = [
        //     "entity_id" => 1,
        //     "entity_type" => "ADMIN",
        //     "upload_file_url" => $url,
        //     "effective_date" => $effective_date,   
        // ];
        // // var_dump(http_build_query($data));
        // try {
        //     $pricingReq = HTTP::
        //     withOptions([
        //         'debug' => true,
        //     ])->withToken($token)
        //     ->post(
        //         env('PRICINGSVC_HOST') . '/v1/rate/job/bulk-update',
        //         [
        //             'form_params' => $data, // Use form_params for x-www-form-urlencoded
        //         ]
        //     );    
        //     if ($pricingReq->failed()) {
        //         Log::error($pricingReq->body());
        //         throw new \Exception("HTTP Request failed with status code: " . $pricingReq->status());
        //     }
        //     $result = json_decode($pricingReq->getBody());
        //     return $result->data;
        // } catch (RequestException $ex) {
        //     return json_decode($ex->getBody());
        // }


        // Data to be sent in the request
        $data = [
            "entity_id" => 1,
            "entity_type" => "ADMIN",
            "upload_file_url" => $url,
            "effective_date" => "",
        ];

        // Build the query string from the data array
        $data = json_encode($data);

        // The URL for the API endpoint
        $url = env('PRICINGSVC_HOST') . '/v1/rate/job/bulk-update';

        // cURL initialization
        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Return the response as a string
        curl_setopt($ch, CURLOPT_POST, true);            // Specify that it's a POST request
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  // Set the form data as POST fields
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',   // Accept header
            'Authorization: Bearer ' . $token,   // Authorization header
            'Content-Type: application/json'   // Content type header
        ]);

        // Execute the cURL request
        $response = curl_exec($ch);

        // Check for errors
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::error("cURL Error: " . $error);
            throw new \Exception("cURL Error: " . $error);
        }

        // Check the HTTP status code of the response
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Close the cURL session
        curl_close($ch);

        // If the request failed, log the error and throw an exception
        if ($statusCode >= 300) {
            Log::error("HTTP Request failed with status code: " . $statusCode . " and response: " . $response);
            throw new \Exception("HTTP Request failed with status code: " . $statusCode);
        }

        // Log the response
        // Log::info("HTTP Request succeeded with response: " . $response);

        // Return or process the response as needed
        return (object) json_decode($response,true);

    }
}
