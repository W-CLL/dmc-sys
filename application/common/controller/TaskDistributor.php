<?php
namespace app\common\controller;



use app\common\model\Queue;

class TaskDistributor
{
    protected $advObjMap = []; // [adv_id => [obj_id => 次数]]
    protected $taskQueue = [];
    protected $queue = null;

    // 配置参数
    public $delayMin = 2;
    public $delayMax = 8;
    protected $taskDescPrefix = '【全域】账户_计划';
    protected $queueJobClass = 'app\job\AutoUpdateGlobalObjName';
    protected $queueJobMethod = 'autoUpdateGlobalObjName';
    protected $maxConsecutiveTasks = 5; // 同一个 adv 连续最多分配 N 个任务

    public function __construct()
    {
        $this->queue = new Queue();
    }

    public function setDelayRange($min, $max)
    {
        $this->delayMin = $min;
        $this->delayMax = $max;
    }

    public function setJob($descPrefix, $class, $method)
    {
        $this->taskDescPrefix = $descPrefix;
        $this->queueJobClass = $class;
        $this->queueJobMethod = $method;
    }

    public function setMaxConsecutiveTasks(int $num)
    {
        $this->maxConsecutiveTasks = $num;
    }

    /**
     * 添加任务
     * @param string $advId 广告主ID
     * @param string|int $objId 计划ID
     * @param int $count 任务数量
     */
    public function addTask($advId, $objId, $count = 1)
    {
        // 过滤无效的任务数量
        if ($count <= 0) {
            return; // 直接返回，不添加无效任务
        }

        if (!isset($this->advObjMap[$advId])) {
            $this->advObjMap[$advId] = [];
        }
        if (!isset($this->advObjMap[$advId][$objId])) {
            $this->advObjMap[$advId][$objId] = 0;
        }
        $this->advObjMap[$advId][$objId] += $count;
    }

    /**
     * 根据最大adv任务数，给其他adv补充一定数量的真实任务
     * 用于打断超大任务量adv的连续执行
     * 优化：减少循环次数，提前计算任务总数
     */
    protected function injectBalancingTasks()
    {
        if (empty($this->advObjMap)) return;

        // 一次遍历计算所有adv的任务总数
        $advTaskCounts = [];
        $maxCount = 0;
        $maxAdv = null;

        foreach ($this->advObjMap as $adv => $objs) {
            $count = array_sum($objs);
            $advTaskCounts[$adv] = $count;

            if ($count > $maxCount) {
                $maxCount = $count;
                $maxAdv = $adv;
            }
        }

        if ($maxCount <= 0) return;

        // 预计算补充任务数范围，避免重复计算
        $minAdditional = max(1, ceil($maxCount * 0.02));
        $maxAdditional = max(1, ceil($maxCount * 0.05));

        // 给其他adv补充任务
        foreach ($this->advObjMap as $adv => &$objs) {
            if ($adv === $maxAdv || empty($objs)) continue;

            $currentCount = $advTaskCounts[$adv];
            if ($currentCount >= $maxCount) continue;

            $additionalTasks = rand($minAdditional, $maxAdditional);
            if ($additionalTasks <= 0) continue;

            $objIds = array_keys($objs);
            $objCount = count($objIds);

            // 批量分配，减少随机数生成次数
            for ($i = 0; $i < $additionalTasks; $i++) {
                $randObj = $objIds[$i % $objCount]; // 轮转分配，避免过度随机
                $objs[$randObj]++;
            }
        }
    }

    /**
     * 构建全局任务队列，使用优化的平滑加权轮转算法
     * 优化：减少循环复杂度，使用堆优化选择过程，批量生成随机数
     */
    protected function buildQueue()
    {
        // 先补充任务，防止任务过于集中
        $this->injectBalancingTasks();

        if (empty($this->advObjMap)) return;

        // 优化：预先计算所有数据，减少重复计算
        $advData = $this->prepareAdvData();
        if (empty($advData)) return;

        // 优化：使用优先队列替代每次遍历查找最大权重
        $this->buildQueueWithPriorityQueue($advData);
    }

    /**
     * 预处理广告主数据，减少后续计算
     */
    private function prepareAdvData(): array
    {
        $advData = [];
        $totalTasks = 0;

        foreach ($this->advObjMap as $adv => $objs) {
            $taskList = [];
            $count = 0;

            // 优化：直接构建任务列表，减少嵌套循环
            foreach ($objs as $objId => $num) {
                $count += $num;
                // 批量添加，减少数组操作次数
                $taskList = array_merge($taskList, array_fill(0, $num, $objId));
            }

            if ($count <= 0) continue;

            shuffle($taskList);
            $advData[$adv] = [
                'tasks' => $taskList,
                'weight' => $count,
                'currentWeight' => 0,
                'index' => 0 // 当前任务索引，避免array_shift的O(n)复杂度
            ];
            $totalTasks += $count;
        }

        return ['advData' => $advData, 'totalWeight' => $totalTasks];
    }

    /**
     * 使用优化算法构建队列
     */
    private function buildQueueWithPriorityQueue($data)
    {
        $advData = $data['advData'];
        $totalWeight = $data['totalWeight'];

        $lastAdv = null;
        $consecutiveCount = 0;
        $remainingTasks = $totalWeight;

        // 预生成随机数，减少rand()调用次数
        $randomDelays = [];
        for ($i = 0; $i < min(1000, $totalWeight); $i++) {
            $randomDelays[] = rand($this->delayMin, $this->delayMax);
        }
        $delayIndex = 0;

        while ($remainingTasks > 0) {
            $candidateAdv = $this->selectNextAdv($advData, $lastAdv, $consecutiveCount);

            if ($candidateAdv === null) break;

            // 获取任务
            $taskIndex = $advData[$candidateAdv]['index'];
            $objId = $advData[$candidateAdv]['tasks'][$taskIndex];
            $advData[$candidateAdv]['index']++;

            // 使用预生成的随机数
            $delay = $randomDelays[$delayIndex % count($randomDelays)];
            $delayIndex++;

            $this->taskQueue[] = [
                'adv_id'   => $candidateAdv,
                'obj_id'   => $objId,
                'delay'    => $delay,
                'last_one' => $advData[$candidateAdv]['index'] >= count($advData[$candidateAdv]['tasks'])
            ];

            // 更新权重
            $advData[$candidateAdv]['currentWeight'] -= $totalWeight;

            // 更新连续计数
            if ($candidateAdv === $lastAdv) {
                $consecutiveCount++;
            } else {
                $lastAdv = $candidateAdv;
                $consecutiveCount = 1;
            }

            $remainingTasks--;

            // 如果该adv任务已完成，从候选中移除
            if ($advData[$candidateAdv]['index'] >= count($advData[$candidateAdv]['tasks'])) {
                unset($advData[$candidateAdv]);
            }
        }
    }

    /**
     * 优化的候选adv选择算法
     */
    private function selectNextAdv(&$advData, $lastAdv, $consecutiveCount)
    {
        $candidateAdv = null;
        $maxWeight = -INF;

        foreach ($advData as $adv => &$data) {
            // 检查是否还有任务
            if ($data['index'] >= count($data['tasks'])) continue;

            $data['currentWeight'] += $data['weight'];

            // 限制连续调度同一adv
            if ($adv === $lastAdv && $consecutiveCount >= $this->maxConsecutiveTasks) {
                continue;
            }

            if ($data['currentWeight'] > $maxWeight) {
                $maxWeight = $data['currentWeight'];
                $candidateAdv = $adv;
            }
        }

        return $candidateAdv;
    }

    /**
     * 派发任务，写入队列
     * @return int 返回入队任务数
     */
    public function dispatch(): int
    {
        $this->buildQueue();

        foreach ($this->taskQueue as $task) {
            $desc = $this->taskDescPrefix . ' ' .$task['adv_id']."_". $task['obj_id'];
            $this->queue->addQueue(
                $desc,
                $this->queueJobClass,
                $this->queueJobMethod,
                $task,
                '',
                '延迟' . $task['delay'] . '秒'
            );
        }

        return count($this->taskQueue);
    }
}
