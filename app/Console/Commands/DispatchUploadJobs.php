<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Jobs\UploadFilesJob as Job;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\Http;

class DispatchUploadJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:upload-files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {   
        $files = collect(scandir(Storage::path('/docs/output/20241126/')))
            ->filter(fn ($file) => str_ends_with($file, '.csv'))
            ->values();

        $uploadedFiles = DB::table('uploaded_files')->pluck('file_name')->toArray();

        $remainingFiles = $files->diff($uploadedFiles);

        // if ($remainingFiles->count() < 10) {
        //     $this->error('Not enough files to dispatch 5 jobs.');
        //     return;
        // }

        $batches = $remainingFiles->chunk(2);

        for ($i = 0; $i < 1; $i++) {
            $batch = $batches->shift();
            if (!$batch) break;

            // Record the files as "processing"
            foreach ($batch as $file) {
                DB::table('uploaded_files')->insert(['file_name' => $file,"status"=> "on progress"]);
            }

            Job::dispatch($batch->toArray());
        }
        $this->info('Dispatched upload jobs successfully.');
    }
   

}
