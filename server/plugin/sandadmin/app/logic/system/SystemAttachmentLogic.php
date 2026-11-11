<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\logic\system;

use Exception;
use plugin\sandadmin\app\model\system\SystemAttachment;
use plugin\sandadmin\app\model\system\SystemCategory;
use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\storage\ChunkUploadService;
use plugin\sandadmin\service\storage\UploadService;
use plugin\sandadmin\utils\Arr;
use plugin\sandadmin\utils\Helper;

/**
 * 附件逻辑层
 */
class SystemAttachmentLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemAttachment();
    }

    /**
     * @param $category_id
     * @param $ids
     * @return mixed
     */
    public function move($category_id, $ids): mixed
    {
        $category = SystemCategory::where('id', $category_id)->findOrEmpty();
        if ($category->isEmpty()) {
            throw new ApiException('目标分类不存在');
        }
        return $this->model->whereIn('id', $ids)->update(['category_id' => $category_id]);
    }

    /**
     * 保存网络图片
     * @param $url
     * @param $config
     * @return array
     * @throws ApiException|Exception
     */
    public function saveNetworkImage($url, $config): array
    {
        $image_data = file_get_contents($url);
        if ($image_data === false) {
            throw new ApiException('获取文件资源失败');
        }
        $image_resource = imagecreatefromstring($image_data);
        if (!$image_resource) {
            throw new ApiException('创建图片资源失败');
        }
        $filename = basename($url);
        $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
        $full_dir = runtime_path() . '/resource/';
        if (!is_dir($full_dir)) {
            mkdir($full_dir, 0777, true);
        }
        $save_path = $full_dir . $filename;
        $mime_type = 'image/';
        switch ($file_extension) {
            case 'jpg':
            case 'jpeg':
                $mime_type = 'image/jpeg';
                $result = imagejpeg($image_resource, $save_path);
                break;
            case 'png':
                $mime_type = 'image/png';
                $result = imagepng($image_resource, $save_path);
                break;
            case 'gif':
                $mime_type = 'image/gif';
                $result = imagegif($image_resource, $save_path);
                break;
            default:
                imagedestroy($image_resource);
                throw new ApiException('文件格式错误');
        }
        imagedestroy($image_resource);
        if (!$result) {
            throw new ApiException('文件保存失败');
        }

        $hash = md5_file($save_path);
        $size = filesize($save_path);

        $model = $this->model->where('hash', $hash)->find();
        if ($model) {
            unlink($save_path);
            return $model->toArray();
        } else {

            $logic = new SystemConfigLogic();
            $uploadConfig = $logic->getGroup('upload_config');

            $root = Arr::getConfigValue($uploadConfig, 'local_root');

            $folder = date('Ymd');
            $full_dir = base_path() . DIRECTORY_SEPARATOR . $root . $folder . DIRECTORY_SEPARATOR;
            if (!is_dir($full_dir)) {
                mkdir($full_dir, 0777, true);
            }
            $object_name = bin2hex(pack('Nn', time(), random_int(1, 65535))) . ".$file_extension";
            $newPath = $full_dir . $object_name;

            copy($save_path, $newPath);
            unlink($save_path);
            $domain = Helper::localStorageDomain(Arr::getConfigValue($uploadConfig, 'local_domain'));
            $uri = Arr::getConfigValue($uploadConfig, 'local_uri');
            $baseUrl = $domain . $uri . $folder . '/';

            $info['storage_mode'] = 1;
            $info['category_id'] = request()->input('category_id', 1);
            $info['origin_name'] = $filename;
            $info['object_name'] = $object_name;
            $info['hash'] = $hash;
            $info['mime_type'] = $mime_type;
            $info['storage_path'] = $root . $folder . '/' . $object_name;
            $info['suffix'] = $file_extension;
            $info['size_byte'] = $size;
            $info['size_info'] = formatBytes($size);
            $info['url'] = $baseUrl . $object_name;
            $this->model->save($info);
            return $info;
        }
    }

    /**
     * 删除数据
     * @param mixed $ids
     * @return bool
     */
    public function destroy($ids): bool
    {
        // 本地存储（storage_mode=1）的附件记录删除时同步删除物理文件，
        // 避免孤儿文件累积；远端对象存储不在此处处理。
        $files = [];
        $list = $this->model->whereIn('id', (array) $ids)->select();
        foreach ($list as $item) {
            if ((int) $item->storage_mode !== 1) {
                continue;
            }
            $file = $this->localFilePath((string) $item->storage_path);
            if ($file !== null) {
                $files[] = $file;
            }
        }
        $result = $this->transaction(function () use ($ids) {
            return $this->model->destroy($ids);
        });
        // 记录删除成功后才清理文件；unlink 失败不回滚记录，残留文件走人工/巡检清理
        if ($result) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        return $result;
    }

    /**
     * 把附件的 storage_path 解析成可安全删除的绝对路径。
     * 只允许删除项目目录内确实存在的文件，路径穿越或文件不存在时返回 null。
     */
    private function localFilePath(string $storagePath): ?string
    {
        if ($storagePath === '') {
            return null;
        }
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $absolute = str_starts_with($storagePath, DIRECTORY_SEPARATOR)
            ? $storagePath
            : $base . $storagePath;
        $resolvedPath = realpath($absolute);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            return null;
        }
        if (!str_starts_with($resolvedPath, $base)) {
            return null;
        }
        return $resolvedPath;
    }

    /**
     * 文件上传
     * @param string $upload
     * @param bool $local
     * @return array
     */
    public function uploadBase(string $upload = 'image', bool $local = false): array
    {
        $logic = new SystemConfigLogic();
        $uploadConfig = $logic->getGroup('upload_config');
        $type = Arr::getConfigValue($uploadConfig, 'upload_mode');
        if ($local === true) {
            $type = 1;
        }
        $result = UploadService::disk($type, $upload)->uploadFile();
        $data = $result[0];
        $hash = $data['unique_id'];
        $hash_check = config('plugin.sandadmin.saithink.file_hash', false);
        if ($hash_check) {
            $model = $this->model->where('hash', $hash)->findOrEmpty();
            if (!$model->isEmpty()) {
                return $model->toArray();
            }
        }
        $url = str_replace('\\', '/', $data['url']);
        $savePath = str_replace('\\', '/', $data['save_path']);
        $info['storage_mode'] = $type;
        $info['category_id'] = request()->input('category_id', 1);
        $info['origin_name'] = $data['origin_name'];
        $info['object_name'] = $data['save_name'];
        $info['hash'] = $data['unique_id'];
        $info['mime_type'] = $data['mime_type'];
        $info['storage_path'] = $savePath;
        $info['suffix'] = $data['extension'];
        $info['size_byte'] = $data['size'];
        $info['size_info'] = formatBytes($data['size']);
        $info['url'] = $url;
        $this->model->save($info);
        return $info;
    }

    /**
     * 切片上传
     * @param $data
     * @return array
     */
    public function chunkUpload($data): array
    {
        $chunkService = new ChunkUploadService();
        if ($data['index'] == 0) {
            $model = $this->model->where('hash', $data['hash'])->findOrEmpty();
            if (!$model->isEmpty()) {
                return $model->toArray();
            } else {
                return $chunkService->checkChunk($data);
            }
        } else {
            return $chunkService->uploadChunk($data);
        }
    }

}

