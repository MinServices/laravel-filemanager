<?php

namespace Min\FileManager\Traits;

use Min\FileManager\Models\FileSystem;
use Min\FileManager\Models\ShareFile;
use Illuminate\Support\Facades\Log;
use Min\FileManager\Models\FileShare;

trait SystemDataTrait
{
    /**
     * save to DB
     * @param $data
     * @return ArrayObject
     */
    public function SaveToDB($data)
    {
        try {
            $userId = 1;
            try {
                if (auth()->check()) {
                    $userId = auth()->id();
                } elseif (config('auth.guards.admin') && auth()->guard('admin')->check()) {
                    $userId = auth()->guard('admin')->id();
                }
            } catch (\Throwable $th) {
                $userId = 1;
            }

            $dataCreated = FileSystem::updateOrCreate(
                [
                    "disk" => $data['disk'] ?? 'public',
                    "path" => $data['path'],
                ],
                [
                    "user_id" => $userId ?? 1,
                    "disk" => $data['disk'] ?? 'public',
                    "type" => $data['type'] ?? 'file',
                    "basename" => $data['basename'] ?? basename($data['path']),
                    "dirname" => $data['dirname'] ?? '',
                    "extension" => $data['extension'] ?? '',
                    "filename" => $data['filename'] ?? pathinfo($data['path'], PATHINFO_FILENAME),
                    "size" => $data['size'] ?? 0,
                    "timestamp" => $data['timestamp'] ?? time(),
                    "visibility" => !empty($data['visibility']) && is_string($data['visibility']) ? $data['visibility'] : 'public',
                ]
            );
            return $dataCreated;
        } catch (\Exception $e) {
            Log::error('error save file to DB :' . $e->getMessage() . '--line: ' . $e->getLine());
            return null;
        }
    }
    /**
     * create share
     * @param $path
     * @param $data
     */
    public function addShare($path, $data)
    {
        $file = FileSystem::where('path',$path)->first();
        $dataSync = [
            'share_id' => $data['id'],
            'url' => $data['url'],
        ];
        if($file){
           $file->shares()->create($dataSync);
        }
    }
    /**
     * delete share
     * @param $id
     */
    public function removeShare($id)
    {
        $share = FileShare::where('share_id',$id)->first();
        if($share){
            $share->delete();
        }
        return true;
    }
    /**
     * get info
     * @param $disk
     * @param $path
     */
    public function GetInfo($disk, $path) {
        $info = FileSystem::where('disk',$disk)->where('path', $path)->first();
        if(!$info){
            return false;
        }else{
            return $info;
        }
    }
}
