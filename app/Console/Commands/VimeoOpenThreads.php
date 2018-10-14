<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Vimeo\Laravel\Facades\Vimeo;
use Illuminate\Support\Facades\Log;

class VimeoOpenThreads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vimeo:threads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    private function findSourceVideo($video_id)
    {
        $latestRequest = Vimeo::request('/me/videos/' . $video_id, ['per_page' => 10], 'GET');
//        dd($latestRequest);
        if(isset($latestRequest['body']['files'][0]['link'])){
            $dataV['video_main_url'] = isset($latestRequest['body']['files'][0]['link']) ? $latestRequest['body']['download'][0]['link'] : $latestRequest['files']['download'][0][0]['link'];
            $dataV['size'] = isset($latestRequest['body']['files'][0]['size']) ? $latestRequest['body']['files'][0]['size'] : $latestRequest['body']['files'][0][0]['size'];
            $dataV['md5'] = isset($latestRequest['body']['files'][0]['md5']) ? $latestRequest['body']['files'][0]['md5'] : $latestRequest['body']['files'][0][0]['md5'];
            $dataV['type'] = isset($latestRequest['body']['files'][0]['type']) ? $latestRequest['body']['files'][0]['type'] : $latestRequest['body']['files'][0][0]['type'];
        }else{
            $dataV['video_main_url'] = isset($latestRequest['body']['download'][0]['link']) ? $latestRequest['body']['download'][0]['link'] : $latestRequest['body']['download'][0][0]['link'];
            $dataV['size'] = isset($latestRequest['body']['download'][0]['size']) ? $latestRequest['body']['download'][0]['size'] : $latestRequest['body']['download'][0][0]['size'];
            $dataV['md5'] = isset($latestRequest['body']['download'][0]['md5']) ? $latestRequest['body']['download'][0]['md5'] : $latestRequest['body']['download'][0][0]['md5'];
            $dataV['type'] = isset($latestRequest['body']['download'][0]['type']) ? $latestRequest['body']['download'][0]['type'] : $latestRequest['body']['download'][0][0]['type'];
        }

        $video_extension = explode("/",$dataV['type']);
        $dataV['video_extension'] = $video_extension[1];
        $dataV['video_uri'] = $latestRequest['body']['uri'];
        $dataV['video_id'] = $video_id;
        $dataV['name'] = $latestRequest['body']['name'];
        $dataV['status'] = 2;
        return $dataV;
    }

    private function getFromJson(){
        $path = storage_path() . "/json/video_feed.json"; // ie: /var/www/laravel/app/storage/json/filename.json
        $json = json_decode(file_get_contents($path), true);
        return $json;
    }

    public function handle()
    {
        $gDisk = Storage::disk('gcs');
        $localDisk = Storage::disk('public');
        $video_ids = $this->getFromJson();
        foreach ($video_ids as $video) {
            $video_id = $video['VimeoID'];
            $client_id = $video['ClientID'];
            $targetUrl = $this->findSourceVideo($video_id);
            $jsonArray = ($targetUrl);
            $jsonArray['client_id'] = $client_id;
            $jsonArray['time_started'] = Carbon::now();
            print_r($jsonArray);
            $fromUrl = $targetUrl['video_main_url'];
            try {
                // lets make a directory.
                try {
                    $bucket = $value = config('app.gcs_bucket');
                    $gcs_base_url = config('app.gcs_base_url');
                    $localDisk->makeDirectory($bucket . $client_id);
                } catch (\Exception $ex) {
                    //if already created
                    Log::info($ex->getMessage());
                }
                $local_base_url = $gcs_base_url . $bucket . $client_id;
//                echo "Base URL:".$local_base_url . "\n";
                // start downloading the exact video.
                if (!$gDisk->has($bucket . $client_id . "/" . $video_id . "." .$jsonArray['video_extension'])) {

                    echo "starting";
                    $output = shell_exec('wget "' . $fromUrl . '" -O ' . $local_base_url . "/" . $video_id . "." .$jsonArray['video_extension']);
                    Log::info($output);
                    Log::debug("Downloading video".$fromUrl);

                    //now time to put in gcloud storage

                    $gDisk->makeDirectory($bucket . $client_id);
                    echo "Now we need to upload on gcloucd!" . "\n";
                    $contents = $localDisk->get($bucket . $client_id . "/" . $video_id . ".mp4");

                    $gDisk->put($bucket . $client_id . "/" . $video_id . ".mp4", $contents);
                    echo "Gcloud uploaded!";

                    $ended_time = Carbon::now();
                    $jsonArray['ended_time'] = $ended_time;
                    // now time to update ended time and elapsed time.
                    $jsonArray['elapsed_time'] = $ended_time->diffInSeconds($jsonArray['time_started']);

                    // store into json data
                    $oldJsonData = Storage::disk('public')->get('/json/video_targets.json');
                    $oldJsonData = json_decode($oldJsonData);
                    $oldJsonData = ((array)$oldJsonData);

                    array_push($oldJsonData, $jsonArray);

                    $localDisk->put('/json/video_targets.json', json_encode($oldJsonData));
                    $gDisk->put('video_targets.json', json_encode($oldJsonData));

                } else {
                    echo "already Exists!";
                }


            } catch (\Exception $ex) {
                //if already created
                Log::debug($ex->getMessage());
            }
        }
    }

}