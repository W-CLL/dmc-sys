<?php

namespace app\job;


use app\job\Base\BaseGetOptLogJob;

class InsertGlobalObjOptLog extends BaseGetOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcGlobalObjOptLog';
    }
}
