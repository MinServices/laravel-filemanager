<?php

namespace Min\FileManager\Services\TransferService;

abstract class Transfer
{
    public $disk;
    public $path;
    public $clipboard;

    /**
     * Transfer constructor.
     *
     * @param $disk
     * @param $path
     * @param $clipboard
     */
    public function __construct($disk, $path, $clipboard)
    {
        $this->disk = $disk;
        $this->path = $path;
        $this->clipboard = $clipboard;
    }

    /**
     * Transfer files and folders
     *
     * @return array
     */
    public function filesTransfer(): array
    {
        try {
            // determine the type of operation
            if ($this->clipboard['type'] === 'copy') {
                $this->copy();
            } elseif ($this->clipboard['type'] === 'cut') {
                $this->cut();
            }
        } catch (\Exception $exception) {
            return [
                'status'  => 'error',
                'msg'     => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'status'  => 'success',
            'msg'     => 'copied',
            'message' => 'copied',
        ];
    }

    abstract protected function copy();

    abstract protected function cut();
}
