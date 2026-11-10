<?php

namespace Min\FileManager\Controllers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use League\Flysystem\FilesystemException;
use Min\FileManager\Events\BeforeInitialization;
use Min\FileManager\Events\Deleting;
use Min\FileManager\Events\DirectoryCreated;
use Min\FileManager\Events\DirectoryCreating;
use Min\FileManager\Events\DiskSelected;
use Min\FileManager\Events\Download;
use Min\FileManager\Events\FileCreated;
use Min\FileManager\Events\FileCreating;
use Min\FileManager\Events\FilesUploaded;
use Min\FileManager\Events\FilesUploading;
use Min\FileManager\Events\FileUpdate;
use Min\FileManager\Events\Paste;
use Min\FileManager\Events\Rename;
use Min\FileManager\Events\Unzip as UnzipEvent;
use Min\FileManager\Events\Zip as ZipEvent;
use Min\FileManager\FileManager;
use Min\FileManager\Models\FileSystem;
use Min\FileManager\Requests\RequestValidator;
use Min\FileManager\Services\Share;
use Min\FileManager\Services\Zip;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends Controller
{
    /**
     * @var FileManager
     */
    public $fm;
    public $share;

    /**
     * FileManagerController constructor.
     *
     * @param  FileManager  $fm
     */
    public function __construct(FileManager $fm, Share $share)
    {
        $this->fm = $fm;
        $this->share = $share;
    }

    /**
     * Initialize file manager
     *
     * @return JsonResponse
     */
    public function initialize(): JsonResponse
    {
        event(new BeforeInitialization());

        return response()->json(
            $this->fm->initialize()
        );
    }

    /**
     * Get files and directories for the selected path and disk
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     * @throws FilesystemException
     */
    public function content(RequestValidator $request): JsonResponse
    {
        return response()->json(
            $this->fm->content(
                $request->input('disk'),
                $request->input('path')
            )
        );
    }

    /**
     * Directory tree
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     * @throws FilesystemException
     */
    public function tree(RequestValidator $request): JsonResponse
    {
        return response()->json(
            $this->fm->tree(
                $request->input('disk'),
                $request->input('path')
            )
        );
    }

    /**
     * Check the selected disk
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function selectDisk(RequestValidator $request): JsonResponse
    {
        event(new DiskSelected($request->input('disk')));

        return response()->json([
            'result' => [
                'status' => 'success',
                'msg' => 'diskSelected',
            ],
        ]);
    }

    /**
     * Upload files
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function upload(RequestValidator $request): JsonResponse
    {
        event(new FilesUploading($request));

        $files = $request->file('files') ?? $request->file('file') ?? [];
        $uploadResponse = $this->fm->upload(
            $request->input('disk'),
            $request->input('path'),
            $files,
            $request->boolean('overwrite')
        );
        event(new FilesUploaded($request));
        return response()->json($uploadResponse);
    }

    /**
     * Delete files and folders
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function delete(RequestValidator $request): JsonResponse
    {
        event(new Deleting($request));

        $deleteResponse = $this->fm->delete(
            $request->input('disk'),
            $request->input('items')
        );

        return response()->json($deleteResponse);
    }

    /**
     * Copy / Cut files and folders
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function paste(RequestValidator $request): JsonResponse
    {
        event(new Paste($request));

        return response()->json(
            $this->fm->paste(
                $request->input('disk'),
                $request->input('path'),
                $request->input('clipboard')
            )
        );
    }

    /**
     * Rename
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function rename(RequestValidator $request): JsonResponse
    {
        event(new Rename($request));
        $dataRes = $this->fm->rename(
            $request->input('disk'),
            $request->input('newName'),
            $request->input('oldName')
        );
        return response()->json(
            $dataRes,
            $dataRes['code']
        );
    }

    /**
     * Download file
     *
     * @param  RequestValidator  $request
     *
     * @return StreamedResponse
     */
    public function download(RequestValidator $request): StreamedResponse
    {
        event(new Download($request));

        return $this->fm->download(
            $request->input('disk'),
            $request->input('path')
        );
    }

    /**
     * Create thumbnails
     *
     * @param  RequestValidator  $request
     *
     * @return Response|mixed
     * @throws BindingResolutionException
     */
    public function thumbnails(RequestValidator $request): mixed
    {
        return $this->fm->thumbnails(
            $request->input('disk'),
            $request->input('path')
        );
    }

    /**
     * Image preview
     *
     * @param  RequestValidator  $request
     *
     * @return mixed
     */
    public function preview(RequestValidator $request): mixed
    {
        return $this->fm->preview(
            $request->input('disk'),
            $request->input('path')
        );
    }

    /**
     * File url
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function url(RequestValidator $request): JsonResponse
    {
        return response()->json(
            $this->fm->url(
                $request->input('disk'),
                $request->input('path')
            )
        );
    }
    /**
     * information
     * @param Request $request
     * @return JsonResponse
     */
    public function info(Request $request): JsonResponse
    {
        return response()->json(
            $this->fm->info(
                $request->input('disk'),
                $request->input('path'),
                $request->input('paths')
            )
        );
    }
    /**
     * Get share Url from nextcloud
     * @param RequestValidator $request
     * @return JsonResponse
     */
    public function getshare(Request $request)
    {
        $id = $request->input('id');
        return $this->share->getShare($id);
    }
    /**
     * Get list share from nextcloud
     * @param RequestValidator $request
     * @return JsonResponse
     */
    public function listshare(Request $request)
    {
        $path = $request->input('path');
        $file = FileSystem::where('path', $path)->with('shares')->first();
        if ($file) {
            return response()->json([
                'status' => 'success',
                'content' => $file
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'msg' => trans('res.notfound'),
            ], 404);
        }
    }
    /**
     * create share file nextcloud
     * @param RequestValidator $request
     * @return JsonResponse
     */
    public function share(Request $request)
    {
        try {
            $path = $request->input('path');
            $shareType = $request->input('shareType');
            $expire = $request->input('expire');
            $data = $this->share->createShare($path, $shareType, $expire);
            return response()->json([
                'status' => 'success',
                'msg' => trans('label.shared'),
                'content' => $data['url'],
            ]);
        } catch (\Exception $e) {
            Log::error('Message :' . $e->getMessage() . '--line: ' . $e->getLine());
            return response()->json([
                'msg' => false,
            ], 500);
        }
    }
    /**
     * un-share file nextcloud
     * @param RequestValidator $request
     * @return JsonResponse
     */
    public function unShare(Request $request)
    {
        try {
            $id = $request->input('id');
            $status = $this->share->deleteShare($id);
            if ($status) {
                return response()->json([
                    'status' => 'success',
                    'msg' => trans('res.delete.success')
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'msg' => trans('res.delete.fail')
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Message :' . $e->getMessage() . '--line: ' . $e->getLine());
            return response()->json([
                'status' => 'error',
                'msg' => trans('res.delete.fail'),
            ], 500);
        }
    }
    /**
     * Create new directory
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function createDirectory(RequestValidator $request): JsonResponse
    {
        event(new DirectoryCreating($request));

        $createDirectoryResponse = $this->fm->createDirectory(
            $request->input('disk'),
            $request->input('path'),
            $request->input('name')
        );

        if (($createDirectoryResponse['status'] ?? $createDirectoryResponse['result']['status'] ?? null) === 'success') {
            event(new DirectoryCreated($request));
        }

        return response()->json($createDirectoryResponse);
    }
    /**
     * Check if a directory exists
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function directoryExists(RequestValidator $request): JsonResponse
    {
        $directoryExists = $this->fm->directoryExists(
            $request->input('disk'),
            $request->input('path'),
        );
        return response()->json($directoryExists);
    }

    /**
     * Create new file
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function createFile(RequestValidator $request): JsonResponse
    {
        event(new FileCreating($request));

        $createFileResponse = $this->fm->createFile(
            $request->input('disk'),
            $request->input('path'),
            $request->input('name')
        );

        if ($createFileResponse['status'] === 'success') {
            event(new FileCreated($request));
        }

        return response()->json($createFileResponse);
    }

    /**
     * Update file
     *
     * @param  RequestValidator  $request
     *
     * @return JsonResponse
     */
    public function updateFile(RequestValidator $request): JsonResponse
    {
        event(new FileUpdate($request));

        return response()->json(
            $this->fm->updateFile(
                $request->input('disk'),
                $request->input('path'),
                $request->file('file')
            )
        );
    }

    /**
     * Stream file
     *
     * @param  RequestValidator  $request
     *
     * @return mixed
     */
    public function streamFile(RequestValidator $request): mixed
    {
        return $this->fm->streamFile(
            $request->input('disk'),
            $request->input('path')
        );
    }

    /**
     * Create zip archive
     *
     * @param  RequestValidator  $request
     * @param  Zip  $zip
     *
     * @return array
     */
    public function zip(RequestValidator $request, Zip $zip)
    {
        event(new ZipEvent($request));

        return $zip->create();
    }

    /**
     * Extract zip archive
     *
     * @param  RequestValidator  $request
     * @param  Zip  $zip
     *
     * @return array
     */
    public function unzip(RequestValidator $request, Zip $zip)
    {
        event(new UnzipEvent($request));

        return $zip->extract();
    }

    /**
     * Integration with ckeditor 4
     *
     * @return Factory|View
     */
    public function ckeditor(): Factory | View
    {
        return view('file-manager::ckeditor');
    }

    /**
     * Integration with TinyMCE v4
     *
     * @return Factory|View
     */
    public function tinymce(): Factory | View
    {
        return view('file-manager::tinymce');
    }

    /**
     * Integration with TinyMCE v5
     *
     * @return Factory|View
     */
    public function tinymce5(): Factory | View
    {
        return view('file-manager::tinymce5');
    }

    /**
     * Integration with SummerNote
     *
     * @return Factory|View
     */
    public function summernote(): Factory | View
    {
        return view('file-manager::summernote');
    }

    /**
     * Simple integration with input field
     *
     * @return Factory|View
     */
    public function fmButton(): Factory | View
    {
        return view('file-manager::fmButton');
    }
}
