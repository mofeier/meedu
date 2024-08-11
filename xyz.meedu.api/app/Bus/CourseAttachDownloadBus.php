<?php

/*
 * This file is part of the MeEdu.
 *
 * (c) 杭州白书科技有限公司
 */

namespace App\Bus;

use Illuminate\Support\Str;
use App\Businesses\BusinessState;
use App\Exceptions\ServiceException;
use App\Events\CourseAttachDownloadEvent;
use App\Meedu\Cache\Impl\CourseAttachDownloadCache;
use App\Meedu\ServiceV2\Services\CourseServiceInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

class CourseAttachDownloadBus
{

    private $businessState;
    private $courseService;

    private $cache;

    private const LIMIT_TIMES_PER_HOURS = 60;

    public function __construct(
        BusinessState             $businessState,
        CourseServiceInterface    $courseService,
        CourseAttachDownloadCache $cache
    ) {
        $this->businessState = $businessState;
        $this->courseService = $courseService;
        $this->cache = $cache;
    }

    public function generateDownloadSignature(int $userId, int $attachId, int $courseId): string
    {
        if (!$this->businessState->isBuyCourse($userId, $courseId)) {
            throw new ServiceException(__('请购买课程'));
        }

        $attach = $this->courseService->findAttachByIdAndCourseId($attachId, $courseId);
        if (!$attach) {
            throw new ModelNotFoundException(__('附件不存在'));
        }

        if ($this->cache->getTimes($userId) >= self::LIMIT_TIMES_PER_HOURS) {
            throw new ThrottleRequestsException();
        }

        $this->cache->incTimes($userId);

        $id = Str::random(20);
        $this->cache->put($id, ['user_id' => $userId, 'attach_id' => $attach['id']]);

        $extra = ['ip' => request()->getClientIp()];
        event(new CourseAttachDownloadEvent($userId, $courseId, $attach['id'], $extra));

        return $id;
    }

    public function download(string $signature)
    {
        $data = $this->cache->get($signature);
        if (!$data) {
            throw new ServiceException(__('参数错误'));
        }

        ['attach_id' => $attachId] = $data;

        $this->cache->destroy($signature);

        $attach = $this->courseService->findAttachById($attachId);
        if (!$attach) {
            throw new ModelNotFoundException(__('附件不存在'));
        }

        $path = storage_path('app/attach/' . $attach['path']);
        if (!file_exists($path)) {
            throw new ServiceException(__('源文件不存在'));
        }

        return response()->download($path);
    }

}