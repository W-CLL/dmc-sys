<?php

namespace app\job;

use app\job\Base\BaseGetOptLogJob;


class InsertObjOptLog extends BaseGetOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcObjOptLog';
    }
}
