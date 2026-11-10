<?php

namespace Min\FileManager;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use League\Flysystem\FilesystemException;
use Min\FileManager\Events\Deleted;
use Min\FileManager\Models\FileSystem;
use Min\FileManager\Services\ConfigService\ConfigRepository;
use Min\FileManager\Services\TransferService\TransferFactory;
use Min\FileManager\Traits\CheckTrait;
use Min\FileManager\Traits\ContentTrait;
use Min\FileManager\Traits\PathTrait;
use Min\FileManager\Traits\SystemDataTrait;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManager
{
    use PathTrait, ContentTrait, CheckTrait, SystemDataTrait;

    /**
     * @var ConfigRepository
     */
    public ConfigRepository $configRepository;

    /**
     * FileManager constructor.
     *
     * @param ConfigRepository $configRepository
     */
    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    /**
     * Initialize App
     *
     * @return array
     */
    public function initialize(): array
    {
        if (!config()->has('file-manager')) {
            return [
                'status' => 'danger',
                'msg' => 'noConfig',
            ];
        }

        $config = [
            'acl' => $this->configRepository->getAcl(),
            'leftDisk' => $this->configRepository->getLeftDisk(),
            'rightDisk' => $this->configRepository->getRightDisk(),
            'leftPath' => $this->configRepository->getLeftPath(),
            'rightPath' => $this->configRepository->getRightPath(),
            'windowsConfig' => $this->configRepository->getWindowsConfig(),
            'hiddenFiles' => $this->configRepository->getHiddenFiles(),
        ];

        // disk list
        foreach ($this->configRepository->getDiskList() as $disk) {
            if (array_key_exists($disk, config('filesystems.disks'))) {
                $config['disks'][$disk] = Arr::only(
                    config('filesystems.disks')[$disk],
                    ['driver']
                );
            }
        }

        // get language
        $config['lang'] = app()->getLocale();

        return [
            'status' => 'success',
            'msg' => null,
            'results' => [
                'config' => $config,
            ],

        ];
    }

    /**
     * Get files and directories for the selected path and disk
     *
     * @param $disk
     * @param $path
     *
     * @return array
     * @throws FilesystemException
     */
    public function content($disk, $path): array
    {
        $content = $this->getContent($disk, $path);
        foreach ($content['files'] as $file) {
            $file['disk'] = $disk;
            $this->SaveToDB($file);
        }
        return [
            'status' => 'success',
            'msg' => null,
            'results' => [
                'directories' => $content['directories'],
                'files' => $content['files'],
            ],

        ];
    }

    /**
     * Get part of the directory tree
     *
     * @param $disk
     * @param $path
     *
     * @return array
     * @throws FilesystemException
     */
    public function tree($disk, $path): array
    {
        $directories = $this->getDirectoriesTree($disk, $path);

        return [
            'status' => 'success',
            'msg' => null,
            'results' => [
                'directories' => $directories,
            ],

        ];
    }

    /**
     * Upload files
     *
     * @param string|null $disk
     * @param string|null $path
     * @param array|mixed $files
     * @param bool        $overwrite
     *
     * @return array
     */
    public function upload($disk, $path, $files, $overwrite): array
    {
        $fileNotUploaded = false;
        $fileInfo = [];
        $path = $path ?? '';
        $files = is_array($files) ? $files : ($files ? [$files] : []);

        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $targetCheckPath = $this->newPath($path, $originalName);

            // skip or overwrite files
            if (!$overwrite && Storage::disk($disk)->exists($targetCheckPath)) {
                continue;
            }
            // check file size
            if (
                $this->configRepository->getMaxUploadFileSize()
                && $file->getSize() / 1024 > $this->configRepository->getMaxUploadFileSize()
            ) {
                $fileNotUploaded = true;
                continue;
            }

            // check file type
            if (
                $this->configRepository->getAllowFileTypes()
                && !in_array(
                    $file->getClientOriginalExtension(),
                    $this->configRepository->getAllowFileTypes()
                )
            ) {
                $fileNotUploaded = true;
                continue;
            }

            $name = $originalName;
            if ($this->configRepository->getSlugifyNames()) {
                $name = Str::slug(
                    Str::replace(
                        '.' . $file->getClientOriginalExtension(),
                        '',
                        $name
                    )
                ) . '.' . $file->getClientOriginalExtension();
            }
            // overwrite or save file
            $pathUpload = Storage::disk($disk)->putFileAs(
                $path,
                $file,
                $name
            );
            $properties = $this->fileProperties($disk, $pathUpload);
            $properties['disk'] = $disk;
            $this->SaveToDB($properties);
            $fileInfo[] = $properties;
        }

        if ($fileNotUploaded) {
            return [
                'status' => 'warning',
                'msg' => 'notAllUploaded',
            ];
        }

        return [
            'status' => 'success',
            'msg' => 'uploaded',
            'results' => [
                'files' => $fileInfo,
            ],

        ];
    }

    /**
     * Delete files and folders
     *
     * @param $disk
     * @param $items
     *
     * @return array
     */
    public function delete($disk, $items): array
    {
        $deletedItems = [];

        foreach ($items as $item) {
            if (!Storage::disk($disk)->exists($item['path'])) {
                continue;
            } else {
                if ($item['type'] === 'dir') {
                    Storage::disk($disk)->deleteDirectory($item['path']);
                    try {
                        FileSystem::where('disk', $disk)
                            ->where(function ($q) use ($item) {
                                $q->where('path', $item['path'])
                                    ->orWhere('path', 'like', $item['path'] . '/%');
                            })
                            ->delete();
                    } catch (\Throwable $e) {
                        // ignore DB cleanup error
                    }
                } else {
                    Storage::disk($disk)->delete($item['path']);
                    try {
                        FileSystem::where('disk', $disk)
                            ->where('path', $item['path'])
                            ->delete();
                    } catch (\Throwable $e) {
                        // ignore DB cleanup error
                    }
                }
            }

            $deletedItems[] = $item;
        }

        event(new Deleted($disk, $deletedItems));

        return [
            'status' => 'success',
            'msg' => 'deleted',
        ];
    }

    /**
     * Copy / Cut - Files and Directories
     *
     * @param $disk
     * @param $path
     * @param $clipboard
     *
     * @return array
     */
    public function paste($disk, $path, $clipboard): array
    {
        // compare disk names
        if ($disk !== $clipboard['disk']) {

            if (!$this->checkDisk($clipboard['disk'])) {
                return $this->notFoundMessage();
            }
        }

        $transferService = TransferFactory::build($disk, $path, $clipboard);

        return $transferService->filesTransfer();
    }

    /**
     * Rename file or folder
     *
     * @param $disk
     * @param $newName
     * @param $oldName
     *
     * @return array
     */
    public function rename($disk, $newName, $oldName): array
    {
        try {
            $rename = Storage::disk($disk)->move($oldName, $newName);
            if ($rename) {
                try {
                    $pathInfo = pathinfo($newName);
                    FileSystem::where('disk', $disk)
                        ->where('path', $oldName)
                        ->update([
                            'path'      => $newName,
                            'basename'  => $pathInfo['basename'] ?? basename($newName),
                            'dirname'   => ($pathInfo['dirname'] ?? '.') === '.' ? '' : $pathInfo['dirname'],
                            'extension' => $pathInfo['extension'] ?? '',
                            'filename'  => $pathInfo['filename'] ?? '',
                        ]);
                } catch (\Throwable $e) {
                    // ignore DB update error
                }
                return [
                    'status' => 'success',
                    'msg' => 'renamed',
                    'code' => 200
                ];
            } else {
                return [
                    'status' => 'error',
                    'msg' => 'not Renamed',
                    'code' => 500
                ];
            }
        } catch (\Exception $e) {
            Log::error('Message :' . $e->getMessage() . '--line: ' . $e->getLine());
            return [
                'status' => 'error',
                'msg' => 'not Renamed',
                'code' => 500
            ];
        }
    }

    /**
     * Download selected file
     *
     * @param $disk
     * @param $path
     *
     * @return StreamedResponse
     */
    public function download($disk, $path): StreamedResponse
    {
        // if file name not in ASCII format
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }

        return Storage::disk($disk)->download($path, $filename);
    }

    /**
     * Create thumbnails
     *
     * @param $disk
     * @param $path
     *
     * @return Response|mixed
     * @throws BindingResolutionException
     */
    public function thumbnails($disk, $path): mixed
    {
        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        $rawContent = Storage::disk($disk)->get($path);

        try {
            return response()->make(
                Image::read($rawContent)
                    ->coverDown(80, 80)
                    ->encode(),
                200,
                ['Content-Type' => $mimeType]
            );
        } catch (\Throwable $e) {
            return response()->make(
                $rawContent,
                200,
                ['Content-Type' => $mimeType]
            );
        }
    }

    /**
     * Image preview
     *
     * @param $disk
     * @param $path
     *
     * @return mixed
     */
    public function preview($disk, $path): mixed
    {
        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        $rawContent = Storage::disk($disk)->get($path);

        try {
            return response()->make(
                Image::read($rawContent)->encode(),
                200,
                ['Content-Type' => $mimeType]
            );
        } catch (\Throwable $e) {
            return response()->make(
                $rawContent,
                200,
                ['Content-Type' => $mimeType]
            );
        }
    }

    /**
     * Get file URL
     *
     * @param $disk
     * @param $path
     *
     * @return array
     */
    public function url($disk, $path): array
    {
        return [
            'status' => 'success',
            'msg' => null,
            'results' => [
                'url' => $this->resolveFileUrl($disk, $path),
            ],
        ];
    }

    /**
     * get information
     * @param $disk
     * @param $path
     * @param $paths
     * @return array
     */
    public function info($disk, $path = null, $paths = null): array
    {
        $targetPaths = [];
        if (is_array($paths)) {
            $targetPaths = $paths;
        } elseif (is_array($path)) {
            $targetPaths = $path;
        } elseif ($path !== null && $path !== '') {
            $targetPaths = [$path];
        }

        $filesList = [];
        foreach ($targetPaths as $itemPath) {
            $info = $this->GetInfo($disk, $itemPath);
            if (!$info && Storage::disk($disk)->exists($itemPath)) {
                try {
                    $props = $this->fileProperties($disk, $itemPath);
                    $props['disk'] = $disk;
                    $saved = $this->SaveToDB($props);
                    $info = $saved ?: $props;
                } catch (\Throwable $e) {
                    $info = null;
                }
            }
            if ($info) {
                $itemData = is_array($info) ? $info : $info->toArray();
                $itemData['url'] = $itemData['url'] ?? $this->resolveFileUrl($disk, $itemPath);
                $filesList[] = $itemData;
            }
        }

        $firstInfo = $filesList[0] ?? false;

        return [
            'status' => 'success',
            'msg' => null,
            'result' => [
                'status' => 'success',
                'info' => $firstInfo,
            ],
            'results' => [
                'files' => $filesList,
            ],
        ];
    }

    /**
     * Create new directory
     *
     * @param $disk
     * @param $path
     * @param $name
     *
     * @return array
     */
    public function createDirectory($disk, $path, $name)
    {
        $directoryName = $this->newPath($path, $name);

        if (Storage::disk($disk)->exists($directoryName)) {
            return [
                'status' => 'warning',
                'msg' => 'dirExist',
            ];
        }

        Storage::disk($disk)->makeDirectory($directoryName);
        $directoryProperties = $this->directoryProperties(
            $disk,
            $directoryName
        );

        // add directory properties for the tree module
        $tree = $directoryProperties;
        $tree['props'] = ['hasSubdirectories' => false];

        return [
            'status' => 'success',
            'msg' => 'dirCreated',
            'results' => [
                'directory' => $directoryProperties,
                'tree' => [$tree],
            ],

        ];
    }
    public function directoryExists($disk, $directoryName)
    {
        if (Storage::disk($disk)->exists($directoryName)) {
            return true;
        } else {
            return false;
        }
    }
    /**
     * Create new file
     *
     * @param $disk
     * @param $path
     * @param $name
     *
     * @return array
     */
    public function createFile($disk, $path, $name): array
    {
        $path = $this->newPath($path, $name);

        if (Storage::disk($disk)->exists($path)) {
            return [
                'status' => 'warning',
                'msg' => 'fileExist',
            ];
        }

        Storage::disk($disk)->put($path, '');
        $fileProperties = $this->fileProperties($disk, $path);
        $fileProperties['disk'] = $disk;
        $this->SaveToDB($fileProperties);

        return [
            'status' => 'success',
            'msg' => 'fileCreated',
            'results' => [
                'file' => $fileProperties,
            ],
        ];
    }

    /**
     * Update file
     *
     * @param $disk
     * @param $path
     * @param $file
     *
     * @return array
     */
    public function updateFile($disk, $path, $file): array
    {
        Storage::disk($disk)->putFileAs(
            $path,
            $file,
            $file->getClientOriginalName()
        );

        $filePath = $this->newPath($path, $file->getClientOriginalName());
        $fileProperties = $this->fileProperties($disk, $filePath);
        $fileProperties['disk'] = $disk;
        $this->SaveToDB($fileProperties);

        return [
            'status' => 'success',
            'msg' => 'fileUpdated',
            'results' => [
                'file' => $fileProperties,
            ],

        ];
    }

    /**
     * Stream file - for audio and video
     *
     * @param $disk
     * @param $path
     *
     * @return StreamedResponse
     */
    public function streamFile($disk, $path): StreamedResponse
    {
        // if file name not in ASCII format
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }

        return Storage::disk($disk)->response($path, $filename, ['Accept-Ranges' => 'bytes']);
    }
}
