<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

class ExecuteSubjectPolicy extends Command
{
    protected function configure()
    {
        $this->setName('subject:policy:execute')
            ->setDescription('执行定时主体政策修改');
    }

    protected function execute(Input $input, Output $output)
    {
        $list = Db::table("fa_subject_percentage_change")
            ->where('status', 0)
            ->where('effective_time', '<=', time())
            ->select();

        if (empty($list)) {
            $output->writeln('没有待执行的任务');
            return;
        }

        $success = 0;
        $fail = 0;

        foreach ($list as $item) {
            $company = Db::table("fa_company")->where('company_name', $item['subject_name'])->find();
            if (!$company) {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 2,
                    'msg' => '未找到对应主体',
                    'update_time' => time()
                ]);
                $fail++;
                continue;
            }

            $accountType = $item['subject_type'] == 1 ? 2 : 1;

            $result = Db::table("fa_company")->where('id', $company['id'])->update([
                'discount_percentage' => $item['discount_percentage'],
                'account_type' => $accountType,
                'update_time' => time()
            ]);

            if ($result) {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 1,
                    'msg' => '修改成功',
                    'update_time' => time()
                ]);
                $success++;
            } else {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 2,
                    'msg' => '修改失败',
                    'update_time' => time()
                ]);
                $fail++;
            }
        }

        $output->writeln("执行完成，成功{$success}条，失败{$fail}条");
    }
}
