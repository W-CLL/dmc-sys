import asyncio
import json
import threading
import time
from collections import defaultdict, deque
from datetime import datetime, timedelta
from urllib.parse import urlencode

import aiohttp
import aiomysql
import pymysql
from tqdm import tqdm

# ========== 配置区 ==========
MYSQL_CONFIG = {
    "host": "159.75.167.202",
    "port": 3306,
    "user": "dmc_zebranumber",
    "password": "ZPzGzFJDEcsjcLNy",
    "db": "dmc_zebranumber",
    "charset": "utf8mb4"
}

ACCESS_TOKEN = "9601831ffb7da7ee9fc5789f033227e0223a5db754"
PATH = "/open_api/v1.0/qianchuan/uni_promotion/ad/material/get/"
MAX_RETRIES = 500
THREAD_COUNT = 5
CONCURRENT_PER_THREAD = 10
REQUESTS_PER_SECOND = 400
REQUEST_INTERVAL = 1 / REQUESTS_PER_SECOND
MAX_BACKOFF = 10.0

# 性能优化配置 - 并行版本优化
BATCH_SIZE = 50             # 减小批量大小，增加写入频率
WRITE_BUFFER_SIZE = 200     # 减小缓冲区大小
PREFETCH_SIZE = 20
WRITERS_PER_THREAD = 2      # 每个线程2个写入协程

# 日期配置
DAY_SECONDS = 60 * 60 * 24  # 一天的秒数
START_TIMESTAMP = int(time.time())
# START_TIMESTAMP = int(time.time())   - DAY_SECONDS  # 开始时间戳（减去一天）
END_TIMESTAMP = int(time.time())   # 结束时间戳

# ========== 全局统计和数据结构 ==========
stats = defaultdict(int)
lock = threading.Lock()
running = True

# 使用高性能的双端队列和共享数据结构
task_deque = deque()
task_lock = threading.Lock()

# 并行版本：每个线程独立的写入队列，减少锁竞争
write_queues = {}
write_queue_locks = {}


# ========== 辅助函数 ==========
def build_url(path, query=""):
    scheme, netloc = "http", "api.oceanengine.com"
    from urllib.parse import urlunparse
    return urlunparse((scheme, netloc, path, "", query, ""))


def timestamp_to_date_str(timestamp):
    """时间戳转换为日期字符串"""
    return datetime.fromtimestamp(timestamp).strftime("%Y-%m-%d")


def timestamp_to_datetime_str(timestamp):
    """时间戳转换为日期时间字符串"""
    return datetime.fromtimestamp(timestamp).strftime("%Y-%m-%d %H:%M:%S")

def init_write_queues():
    """初始化写入队列"""
    global write_queues, write_queue_locks
    write_queues.clear()
    write_queue_locks.clear()
    
    for thread_id in range(THREAD_COUNT):
        for writer_id in range(WRITERS_PER_THREAD):
            queue_key = f"{thread_id}-{writer_id}"
            write_queues[queue_key] = deque()
            write_queue_locks[queue_key] = threading.Lock()


# ========== 高性能任务获取 ==========
def get_tasks_batch(batch_size=PREFETCH_SIZE):
    """批量获取任务，减少锁竞争"""
    with task_lock:
        tasks = []
        for _ in range(min(batch_size, len(task_deque))):
            if task_deque:
                tasks.append(task_deque.popleft())
        return tasks


def add_to_write_queue(rows, thread_id, writer_id):
    """添加数据到指定的写入队列"""
    queue_key = f"{thread_id}-{writer_id}"
    with write_queue_locks[queue_key]:
        write_queues[queue_key].extend(rows)
        return len(write_queues[queue_key])

def get_write_queue_data(thread_id, writer_id, max_size=BATCH_SIZE):
    """从指定写入队列获取数据"""
    queue_key = f"{thread_id}-{writer_id}"
    with write_queue_locks[queue_key]:
        if write_queues[queue_key]:
            data = []
            for _ in range(min(max_size, len(write_queues[queue_key]))):
                if write_queues[queue_key]:
                    data.append(write_queues[queue_key].popleft())
            return data
        return []


# ========== 优化的数据获取函数（保持原版本逻辑）==========
async def _get_async_daily(session: aiohttp.ClientSession, json_str: dict, advertiser_id: tuple,
                           rate_limit_sem: asyncio.Semaphore,data_date=""):
    """按天优化的数据获取函数"""
    results = []
    adv_id, obj_id = advertiser_id
    page = 1
    total_page = 100
    global ACCESS_TOKEN

    while page <= total_page:
        retry = 0
        backoff_base = 1.5

        while retry <= MAX_RETRIES:
            try:
                await rate_limit_sem.acquire()
                await asyncio.sleep(REQUEST_INTERVAL)

                # 构建请求参数
                params = {k: v if isinstance(v, str) else json.dumps(v) for k, v in json_str.items()}
                params["page"] = page
                query_string = urlencode(params)
                url = build_url(PATH, query_string)
                headers = {"Access-Token": ACCESS_TOKEN}

                async with session.get(url, headers=headers, timeout=15) as resp:
                    resp_json = await resp.json()

                rate_limit_sem.release()
                _msg = resp_json.get("message")
                if resp_json.get("code") == 40105:
                    async with session.get("https://dmc.zebranumber.cn/index.php/api/demo/getToken") as resp:
                        ACCESS_TOKEN = await resp.text()
                        continue
                ignore = ["OK", "广告主账号已禁用"]
                if _msg not in ignore and "No permission to operate account" not in _msg and "当前广告主账号状态已禁用" not in _msg:
                    retry += 1
                    wait = min(backoff_base ** retry, MAX_BACKOFF)
                    hl = ["Too many requests by this developer. Please retry in some time.",
                          "Internal service timed out. Please retry in sometime.",
                          "Too many requests. Please retry in some time."]
                    if _msg not in hl:
                        print(f"不成功{_msg},data_date:{data_date},json_str:{json_str}")
                    await asyncio.sleep(wait)
                    continue


                data = resp_json.get("data", {})
                if not data:
                    break

                total_page = data.get("page_info", {}).get("total_page", page)
                ad_material_infos = resp_json.get("data").get("ad_material_infos", [])

                # 批量处理数据，减少循环开销
                for data_item in ad_material_infos:
                    stats_info = data_item.get("stats_info", {})
                    material_info = data_item.get("material_info", {})
                    material_info_str = json.dumps(material_info)

                    # 安全获取material_id
                    material_id = None
                    video_material = material_info.get("video_material")
                    if video_material:
                        material_id = video_material.get("material_id")

                    product_info = json.dumps(data_item.get("product_info", []))

                    row = {
                        "adv_id": adv_id,
                        "obj_id": obj_id,
                        "material_id": material_id,
                        "audit_status": data_item.get("audit_status") or 0,
                        "material_status": data_item.get("material_status"),
                        "material_select_type": data_item.get("material_select_type"),
                        "product_info": product_info,
                        "product_show_count_for_roi2": stats_info.get("product_show_count_for_roi2") or 0,
                        "product_click_count_for_roi2": stats_info.get("product_click_count_for_roi2") or 0,
                        "product_cvr_rate_for_roi2": stats_info.get("product_cvr_rate_for_roi2") or 0,
                        "product_convert_rate_for_roi2": stats_info.get("product_convert_rate_for_roi2") or 0,
                        "stat_cost_for_roi2": stats_info.get("stat_cost_for_roi2") or 0,
                        "total_prepay_and_pay_order_roi2": stats_info.get("total_prepay_and_pay_order_roi2") or 0,
                        "total_pay_order_gmv_for_roi2": stats_info.get("total_pay_order_gmv_for_roi2") or 0,
                        "total_pay_order_count_for_roi2": stats_info.get("total_pay_order_count_for_roi2") or 0,
                        "total_cost_per_pay_order_for_roi2": stats_info.get("total_cost_per_pay_order_for_roi2") or 0,
                        "total_pay_order_coupon_amount_for_roi2": stats_info.get(
                            "total_pay_order_coupon_amount_for_roi2") or 0,
                        "total_unfinished_estimate_order_gmv_for_roi2": stats_info.get(
                            "total_unfinished_estimate_order_gmv_for_roi2") or 0,
                        "is_delete": stats_info.get("is_delete") or 0,
                        "material_info": material_info_str,
                        "material_type": material_info.get("material_type"),
                        "create_time": datetime.now().timestamp(),
                        "update_time": datetime.now().timestamp(),
                        # 添加日期字段用于区分，将日期字符串转换为时间戳
                        "cost_date": datetime.strptime(data_date, "%Y-%m-%d").timestamp() if data_date else 0,
                    }
                    results.append(row)

                break  # 成功跳出重试循环

            except Exception as e:
                retry += 1
                wait = min(backoff_base ** retry, MAX_BACKOFF)
                print(f"{adv_id},{obj_id} 第{page}页异常: {e}，重试{retry}次，等待{wait:.2f}s")
                try:
                    rate_limit_sem.release()
                except Exception:
                    pass
                await asyncio.sleep(wait)
        else:
            print(f"[{adv_id}] 第{page}页失败超过最大重试次数，跳过此页")
            page += 1
            continue

        page += 1

    return results


# ========== 并行数据获取协程 ==========
async def fetch_and_process_daily_parallel(session, sem, rate_limit_sem, worker_id, start_time_str, end_time_str,
                                  data_date, thread_id):
    """按天的并行获取和处理协程"""
    processed = 0
    local_tasks = []
    writer_index = 0  # 轮询写入队列

    while running:
        # 批量获取任务，减少锁竞争
        if not local_tasks:
            local_tasks = get_tasks_batch(PREFETCH_SIZE)
            if not local_tasks:
                await asyncio.sleep(0.01)
                continue

        advertiser_id = local_tasks.pop(0)
        if advertiser_id is None:
            break

        async with sem:
            try:
                with lock:
                    stats["processing"] += 1
                fields = ["product_show_count_for_roi2",
                          "product_click_count_for_roi2",
                          "product_cvr_rate_for_roi2",
                          "product_convert_rate_for_roi2",
                          "stat_cost_for_roi2",
                          "total_prepay_and_pay_order_roi2",
                          "total_pay_order_gmv_for_roi2",
                          "total_pay_order_count_for_roi2",
                          "total_cost_per_pay_order_for_roi2",
                          "total_pay_order_coupon_amount_for_roi2",
                          "total_unfinished_estimate_order_gmv_for_roi2",
                          ]
                # 构建当天的请求参数
                filtering = {
                    "material_type": "VIDEO",
                    "start_date": data_date,
                    "end_date": data_date,
                    "material_status": "ALL",
                    "material_select_type": "ALL",
                    "analysis_type": ["FIRST_PUBLISH_MATERIAL", "HIGH_QUALITY_MATERIAL", "LOW_QUALITY_MATERIAL",
                                      "INEFFICIENT_MATERIAL", "CARRY_MATERIAL", "SIMILAR_MATERIAL"]
                }
                json_params = {
                    "advertiser_id": advertiser_id[0],
                    "ad_id": advertiser_id[1],
                    # "start_time": start_time_str,
                    # "end_time": end_time_str,
                    "filtering": filtering,
                    "fields": json.dumps(fields),
                    "page": 1,
                    "page_size": 100,
                }

                rows = await _get_async_daily(session, json_params, advertiser_id, rate_limit_sem,data_date)

                if rows:
                    # 轮询分配到不同的写入队列
                    writer_id_for_queue = writer_index % WRITERS_PER_THREAD
                    add_to_write_queue(rows, thread_id, writer_id_for_queue)
                    writer_index += 1

                with lock:
                    stats["success"] += 1
                    stats["processing"] -= 1

                processed += 1

            except Exception as e:
                print(f"[Worker-{worker_id}] 异常: {e}")
                with lock:
                    stats["fail"] += 1
                    if stats["processing"] > 0:
                        stats["processing"] -= 1

    print(f"[Worker-{worker_id}] 结束，共处理 {processed} 个任务")


# ========== 并行数据库写入协程 ==========
async def database_writer_parallel(pool, thread_id, writer_id):
    """并行数据库写入协程"""
    writer_name = f"Writer-{thread_id}-{writer_id}"
    print(f"[{writer_name}] 启动")
    written_count = 0

    while running:
        try:
            # 从指定队列获取数据
            data_to_write = get_write_queue_data(thread_id, writer_id, BATCH_SIZE)

            if data_to_write:
                await write_batch_to_db_parallel(pool, data_to_write, writer_name)
                written_count += len(data_to_write)
            else:
                await asyncio.sleep(0.1)  # 没有数据时短暂休息

        except Exception as e:
            print(f"[{writer_name}] 异常: {e}")

    # 处理剩余数据
    remaining_data = get_write_queue_data(thread_id, writer_id, 1000)  # 获取所有剩余数据
    if remaining_data:
        await write_batch_to_db_parallel(pool, remaining_data, writer_name)
        written_count += len(remaining_data)

    print(f"[{writer_name}] 结束，共写入 {written_count} 条数据")

async def write_batch_to_db_parallel(pool, batch_data, writer_name):
    """并行批量写入数据库"""
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # 使用批量插入优化
                insert_sql = """
                             INSERT INTO fa_fission_global_obj_material (adv_id,
                                                                               obj_id,
                                                                               material_id,
                                                                               product_show_count_for_roi2,
                                                                               product_click_count_for_roi2,
                                                                               product_cvr_rate_for_roi2,
                                                                               product_convert_rate_for_roi2,
                                                                               stat_cost_for_roi2,
                                                                               total_prepay_and_pay_order_roi2,
                                                                               total_pay_order_gmv_for_roi2,
                                                                               total_pay_order_count_for_roi2,
                                                                               total_cost_per_pay_order_for_roi2,
                                                                               total_pay_order_coupon_amount_for_roi2,
                                                                               total_unfinished_estimate_order_gmv_for_roi2,
                                                                               is_delete,
                                                                               product_info,
                                                                               material_status,
                                                                               material_select_type,
                                                                               material_type,
                                                                               material_info,
                                                                               audit_status,
                                                                               create_time,
                                                                               cost_date,
                                                                               update_time)
                             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                                     %s, %s, %s)
                             ON DUPLICATE KEY UPDATE
                                 product_show_count_for_roi2=VALUES(product_show_count_for_roi2),
                                 product_click_count_for_roi2=VALUES(product_click_count_for_roi2),
                                 product_cvr_rate_for_roi2=VALUES(product_cvr_rate_for_roi2),
                                 product_convert_rate_for_roi2=VALUES(product_convert_rate_for_roi2),
                                 stat_cost_for_roi2=VALUES(stat_cost_for_roi2),
                                 total_prepay_and_pay_order_roi2=VALUES(total_prepay_and_pay_order_roi2),
                                 total_pay_order_gmv_for_roi2=VALUES(total_pay_order_gmv_for_roi2),
                                 total_pay_order_count_for_roi2=VALUES(total_pay_order_count_for_roi2),
                                 total_cost_per_pay_order_for_roi2=VALUES(total_cost_per_pay_order_for_roi2),
                                 total_pay_order_coupon_amount_for_roi2=VALUES(total_pay_order_coupon_amount_for_roi2),
                                 total_unfinished_estimate_order_gmv_for_roi2=VALUES(total_unfinished_estimate_order_gmv_for_roi2),
                                 is_delete=VALUES(is_delete),
                                 material_status=VALUES(material_status),
                                 material_select_type=VALUES(material_select_type),
                                 material_type=VALUES(material_type),
                                 material_info=VALUES(material_info),
                                 audit_status=VALUES(audit_status),
                                 update_time=VALUES(update_time)"""

                # 批量执行
                batch_tuples = []
                for row in batch_data:
                    batch_tuples.append((
                        row["adv_id"], row["obj_id"], row["material_id"],
                        row["product_show_count_for_roi2"], row["product_click_count_for_roi2"],
                        row["product_cvr_rate_for_roi2"], row["product_convert_rate_for_roi2"],
                        row["stat_cost_for_roi2"], row["total_prepay_and_pay_order_roi2"],
                        row["total_pay_order_gmv_for_roi2"], row["total_pay_order_count_for_roi2"],
                        row["total_cost_per_pay_order_for_roi2"], row["total_pay_order_coupon_amount_for_roi2"],
                        row["total_unfinished_estimate_order_gmv_for_roi2"], row["is_delete"],
                        row["product_info"], row["material_status"], row["material_select_type"],
                        row["material_type"], row["material_info"], row["audit_status"],
                        row["create_time"], row["cost_date"], row["update_time"],
                    ))

                await cur.executemany(insert_sql, batch_tuples)
                await conn.commit()

        print(f"[{writer_name}] 批量写入 {len(batch_data)} 条数据")

    except Exception as e:
        error_msg = str(e)
        if "Duplicate entry" in error_msg:
            # 重复键错误，这是正常的，不需要重新放回队列
            print(f"[{writer_name}] 检测到重复数据，已通过ON DUPLICATE KEY UPDATE处理: {len(batch_data)} 条")
        else:
            # 其他错误，重新放回队列
            print(f"[{writer_name}] 数据库写入失败: {e}")
            thread_id_str, writer_id_str = writer_name.split('-')[1], writer_name.split('-')[2]
            add_to_write_queue(batch_data, int(thread_id_str), int(writer_id_str))


# ========== 并行线程工作函数 ==========
def thread_worker_parallel(thread_id, start_time_str, end_time_str, data_date):
    """并行版本的线程工作函数"""
    print(f"[Thread-{thread_id}] 启动处理日期: {data_date}")

    async def main():
        try:
            pool = await aiomysql.create_pool(minsize=10, maxsize=20, **MYSQL_CONFIG)  # 增大连接池
            sem = asyncio.Semaphore(CONCURRENT_PER_THREAD)
            rate_limit_sem = asyncio.Semaphore(max(1, REQUESTS_PER_SECOND // THREAD_COUNT))

            print(f"[Thread-{thread_id}] 连接池创建成功")

            async with aiohttp.ClientSession() as session:
                # 创建数据获取协程
                fetcher_tasks = [
                    fetch_and_process_daily_parallel(session, sem, rate_limit_sem, f"{thread_id}-{i}", start_time_str,
                                            end_time_str, data_date, thread_id)
                    for i in range(CONCURRENT_PER_THREAD)
                ]

                # 创建多个数据库写入协程
                writer_tasks = [
                    database_writer_parallel(pool, thread_id, writer_id)
                    for writer_id in range(WRITERS_PER_THREAD)
                ]

                all_tasks = fetcher_tasks + writer_tasks
                print(f"[Thread-{thread_id}] 启动 {len(fetcher_tasks)} 个获取协程和 {len(writer_tasks)} 个写入协程")

                await asyncio.gather(*all_tasks, return_exceptions=True)

            pool.close()
            await pool.wait_closed()

        except Exception as e:
            print(f"[Thread-{thread_id}] 异常: {e}")

    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    try:
        loop.run_until_complete(main())
    finally:
        loop.close()

    print(f"[Thread-{thread_id}] 结束")


# ========== 并行监控函数 ==========
def monitor_parallel(data_date):
    """并行版本的监控函数"""
    global running
    start_time = time.time()
    last_success = 0

    while running:
        with lock:
            current_stats = dict(stats)

        elapsed = time.time() - start_time
        current_success = current_stats.get('success', 0)

        # 计算瞬时速度
        speed = (current_success - last_success) / 5 if elapsed > 0 else 0
        avg_speed = current_success / elapsed if elapsed > 0 else 0

        with task_lock:
            remaining_tasks = len(task_deque)

        # 统计所有写入队列的数据
        total_buffer_size = 0
        for queue_key in write_queues:
            with write_queue_locks[queue_key]:
                total_buffer_size += len(write_queues[queue_key])

        print(f"\n=== 并行版 | 日期: {data_date} | 运行: {elapsed:.1f}s ===")
        print(f"剩余任务: {remaining_tasks}")
        print(f"写入缓冲: {total_buffer_size} (分布在 {len(write_queues)} 个队列)")
        print(f"成功: {current_success}")
        print(f"失败: {current_stats.get('fail', 0)}")
        print(f"处理中: {current_stats.get('processing', 0)}")
        print(f"瞬时速度: {speed:.2f} 任务/秒")
        print(f"平均速度: {avg_speed:.2f} 任务/秒")
        print("=" * 60)

        last_success = current_success
        time.sleep(5)


# ========== 并行单日处理函数 ==========
def run_single_day_parallel(day_timestamp):
    """并行版本的单日处理函数"""
    global running, stats, task_deque

    # 重置全局状态
    running = True
    stats.clear()
    task_deque.clear()
    init_write_queues()  # 初始化写入队列

    # 计算日期字符串
    data_date = timestamp_to_date_str(day_timestamp)
    start_time_str = timestamp_to_datetime_str(day_timestamp)
    end_time_str = timestamp_to_datetime_str(day_timestamp + DAY_SECONDS - 1)

    print(f"\n🗓️  开始处理日期: {data_date}")
    print(f"时间范围: {start_time_str} ~ {end_time_str}")

    # 获取当天的数据（保持原版本的SQL逻辑）
    conn = pymysql.connect(**MYSQL_CONFIG)
    with conn.cursor() as cur:
        # 添加按天的时间过滤条件
        sql = """
              SELECT adv_id, obj_id
              FROM fa_qc_global_obj as o
                       LEFT JOIN fa_company as com ON o.adv_id = com.advertiser_id
              WHERE com.adv_status = 1
                AND (o.obj_status NOT IN ('DELETE') OR o.opt_status NOT IN ('DELETE'))
                AND o.marketing_goal = 'VIDEO_PROM_GOODS' \
                AND o.obj_create_time BETWEEN %s AND %s \
              """

        # 计算当天的时间戳范围（+1秒就是一天）
        now_timestamp = int(time.time())
        day_start = now_timestamp - (93 * 60 * 60 * 24)
        day_end = now_timestamp

        print(
            f"📊 查询时间戳范围: {day_start} ~ {day_end} ({timestamp_to_datetime_str(day_start)} ~ {timestamp_to_datetime_str(day_end)})")
        print(
            f"📊 请求的总时间范围: {START_TIMESTAMP} ~ {END_TIMESTAMP} ({timestamp_to_datetime_str(START_TIMESTAMP)} ~ {timestamp_to_datetime_str(END_TIMESTAMP)})")

        cur.execute(sql, (day_start, day_end))
        advertiser_ids = [[row[0], row[1]] for row in cur.fetchall()]
    conn.close()

    total_ids = len(advertiser_ids)
    print(f"任务数量: {total_ids}")

    if total_ids == 0:
        print(f"⚠️  日期 {data_date} 没有数据，跳过")
        return True

    # 添加任务到队列
    with task_lock:
        task_deque.extend(advertiser_ids)

    # 启动监控线程
    monitor_thread = threading.Thread(target=monitor_parallel, args=(data_date,), daemon=True)
    monitor_thread.start()

    # 启动工作线程
    threads = []
    start_time = time.time()

    for i in range(THREAD_COUNT):
        t = threading.Thread(target=thread_worker_parallel, args=(i, start_time_str, end_time_str, data_date))
        t.start()
        threads.append(t)

    try:
        # 等待所有任务完成
        while True:
            with task_lock:
                remaining = len(task_deque)

            with lock:
                processing = stats.get('processing', 0)

            # 检查所有写入队列是否为空
            total_buffer_size = 0
            for queue_key in write_queues:
                with write_queue_locks[queue_key]:
                    total_buffer_size += len(write_queues[queue_key])

            if remaining == 0 and processing == 0 and total_buffer_size == 0:
                print(f"✅ 日期 {data_date} 处理完成")
                break

            time.sleep(1)

    except KeyboardInterrupt:
        print(f"\n❌ 日期 {data_date} 被中断")
        return False

    finally:
        # 停止
        running = False

        # 添加停止信号
        with task_lock:
            for _ in range(THREAD_COUNT * CONCURRENT_PER_THREAD):
                task_deque.append(None)

        # 等待线程结束
        for t in threads:
            t.join(timeout=10)

        duration = time.time() - start_time
        print(f"📊 日期 {data_date} 统计:")
        print(f"   耗时: {duration:.2f}秒")
        print(f"   成功: {stats['success']}, 失败: {stats['fail']}")

        if stats['success'] > 0:
            avg_speed = stats['success'] / duration
            print(f"   平均速度: {avg_speed:.2f} 任务/秒")

    return True


# ========== 并行主函数 ==========
def run():
    """并行版本的主函数"""
    print("=== 并行队列优化版本启动 ===")

    # 生成日期范围
    time_range = list(range(START_TIMESTAMP, END_TIMESTAMP+1, DAY_SECONDS))
    total_days = len(time_range)

    print(f"📅 处理日期范围:")
    print(f"   开始: {timestamp_to_date_str(time_range[0])}")
    print(f"   结束: {timestamp_to_date_str(time_range[-1])}")
    print(f"   总天数: {total_days}")
    print(f"🔧 并行配置:")
    print(f"   线程数: {THREAD_COUNT}")
    print(f"   每线程协程数: {CONCURRENT_PER_THREAD}")
    print(f"   每线程写入协程数: {WRITERS_PER_THREAD}")
    print(f"   总写入协程数: {THREAD_COUNT * WRITERS_PER_THREAD}")

    # 按天处理
    success_days = 0
    failed_days = 0
    overall_start_time = time.time()

    for day_index, day_timestamp in enumerate(time_range, 1):
        print(f"\n{'=' * 60}")
        print(f"📅 处理进度: {day_index}/{total_days}")

        try:
            if run_single_day_parallel(day_timestamp):
                success_days += 1
            else:
                failed_days += 1
        except Exception as e:
            print(f"❌ 日期 {timestamp_to_date_str(day_timestamp)} 处理异常: {e}")
            failed_days += 1

        # 短暂休息，避免过度占用资源
        if day_index < total_days:
            print("⏸️  休息 3 秒...")
            time.sleep(3)

    # 总结
    overall_duration = time.time() - overall_start_time
    print(f"\n🎉 所有日期处理完成!")
    print(f"📊 总体统计:")
    print(f"   总耗时: {overall_duration:.2f}秒 ({overall_duration / 3600:.2f}小时)")
    print(f"   成功天数: {success_days}")
    print(f"   失败天数: {failed_days}")
    print(f"   总天数: {total_days}")


if __name__ == "__main__":
    run()
