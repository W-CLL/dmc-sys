const express = require('express');
const app = express();
const multer  = require('multer')
const path = require('path'); // 引入Node.js的path模块
const fs = require('fs');
const puppeteer = require('puppeteer');
process.on('warning', (warning) => {
    if (!warning.message.includes('Headless is going to be deprecated')) {
        // 如果不是我们要忽略的警告，则正常输出
        console.warn(warning.message);
        // 或者使用默认的警告处理方式
        // console.error(warning.stack);
    }
    // 否则，忽略该警告
});


function get_redis() {
  let redis = require('redis');
  let client = redis.createClient({
    // url: 'redis://:@localhost:6379',
    url: 'redis://:s1v5h4d@localhost:6379',
  });
  // 连接到Redis
  client.connect();
  return client
}


// 使用 express.urlencoded({ extended: true }) 来解析URL编码的请求体
app.use(express.urlencoded({ extended: true }));



function timeout(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}
function generateRandomInt(min, max) {
  min = Math.ceil(min);
  max = Math.floor(max);
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function get_transfer_image(cookie,transfer_serial) {

  (async () => {
      const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome', // 替换为你的Chromium实际路径
        // executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', // 替换为你的Chromium实际路径
        args: ['--no-sandbox'],
          // headless: false,
      });

      // 创建一个新页面
      const page = await browser.newPage();
      let cookies = parseCookies(cookie)
      cookies.forEach((cookie, index) => {
          page.setCookie(cookie)
      });

      await page.setViewport({width: 1920, height: 1080});
      await page.setRequestInterception(true); // 开启请求拦截

      page.on('request', async request => { // 监听请求事件，注意这里使用了async

          const headers = await request.headers(); // 获取请求头部，使用await
          headers['Cookie'] = cookie; // 添加token
          if ("https://agent.oceanengine.com/admin/fundModule/flowQuery/transferRecord" === request.url()) {
              headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
              headers['Accept-Encoding'] = 'gzip, deflate, br, zstd';
              headers['Accept-Language'] = 'zh-CN,zh;q=0.9';
              headers['Cache-Control'] = 'max-age=0';
              headers['Accept-Encoding'] = 'gzip, deflate, br, zstd';
              headers['Referer'] = 'https://agent.oceanengine.com/admin/homepage';
              headers['Sec-Ch-Ua'] = '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"';
              headers['Sec-Ch-Ua-Mobile'] = '?0';
              headers['Sec-Ch-Ua-Platform'] = '"Windows"';
              headers['Sec-Fetch-Dest'] = 'document';
              headers['Sec-Fetch-Mode'] = 'navigate';
              headers['Sec-Fetch-Site'] = 'same-origin';
              headers['Sec-Fetch-User'] = '?1';
              headers['Upgrade-Insecure-Requests'] = '1';
              headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
          }

          request.continue({headers}); // 继续请求
      });
      const responses = [];
      try {
          // 访问一个网址
          await page.goto('https://agent.oceanengine.com/admin/fundModule/flowQuery/transferRecord');
          // await page.waitForResponse(response => response.request().url() === 'https://agent.oceanengine.com/agent/transfer/transaction-record/v3',{timeout:60000});

          page.on('response', response => {
              if (response.url() === 'https://agent.oceanengine.com/agent/transfer/transaction-record/v3') {
                  responses.push(response);
              }
          });
          await page.waitForTimeout(5000);
      } catch (error) {
        console.log("cookie失效")
        // console.error('An error occurred:', error);
        await browser.close(); // 确保关闭浏览器
        return
      }


      await page.mouse.click(50, 500);
      // 聚焦到输入框
      await page.waitForSelector('input[placeholder="请输入转账编号"]');
      await page.focus('input[placeholder="请输入转账编号"]');

      // 模拟键盘输入
      await page.keyboard.type(transfer_serial);

      // 点击日期选择器的图标
    //   await page.waitForSelector('span.byted-icon.byted-icon-calendar');
    //   await page.click('span.byted-icon.byted-icon-calendar');

    //   // 等待日期选择器弹出
    //   await page.waitForSelector('.byted-date-picker-popper'); // 假设日期选择器的类名为 byted-date-picker

    //   // 点击该元素
    //   await page.click('div.byted-date-date.byted-date-item.byted-date-grid-end');
    //   // 点击该元素
    //   await page.click('div.byted-date-date.byted-date-item.byted-date-grid-in.byted-date-today');



      const acceptCookiesSelector='span[class="i-icon i-icon-setting-config"]';
      await page.waitForSelector(acceptCookiesSelector);
      await page.click(acceptCookiesSelector);

      await timeout(3000)
      //去掉一些多余的筛选
      // 关闭转出方类型
      const remitterType = 'div[data-rbd-draggable-id="remitterType"] .i-icon-close svg';
      await page.waitForSelector(remitterType);
      await page.click(remitterType);
      //转出方代理商账户
      const remitterFirstAdAgentId = 'div[data-rbd-draggable-id="remitterFirstAdAgentId"] .i-icon-close svg';
      await page.waitForSelector(remitterFirstAdAgentId);
      await page.click(remitterFirstAdAgentId);
      //转出方客户
      const remitterCustomerId = 'div[data-rbd-draggable-id="remitterCustomerId"] .i-icon-close svg';
      await page.waitForSelector(remitterCustomerId);
      await page.click(remitterCustomerId);
        //转入方类型
      const payeeType = 'div[data-rbd-draggable-id="payeeType"] .i-icon-close svg';
      await page.waitForSelector(payeeType);
      await page.click(payeeType);

      const payeeFirstAdAgentId = 'div[data-rbd-draggable-id="payeeFirstAdAgentId"] .i-icon-close svg';
      await page.waitForSelector(payeeFirstAdAgentId);
      await page.click(payeeFirstAdAgentId);

      const payeeCustomerId = 'div[data-rbd-draggable-id="payeeCustomerId"] .i-icon-close svg';
      await page.waitForSelector(payeeCustomerId);
      await page.click(payeeCustomerId);

      const transferOrderSerial = 'div[data-rbd-draggable-id="transferOrderSerial"] .i-icon-close svg';
      await page.waitForSelector(transferOrderSerial);
      await page.click(transferOrderSerial);

      const grants = 'div[data-rbd-draggable-id="grants"] .i-icon-close svg';
      await page.waitForSelector(grants);
      await page.click(grants);

      const amount = 'div[data-rbd-draggable-id="amount"] .i-icon-close svg';
      await page.waitForSelector(amount);
      await page.click(amount);

      const prepayAmount = 'div[data-rbd-draggable-id="prepayAmount"] .i-icon-close svg';
      await page.waitForSelector(prepayAmount);
      await page.click(prepayAmount);

      const creditAmount = 'div[data-rbd-draggable-id="creditAmount"] .i-icon-close svg';
      await page.waitForSelector(creditAmount);
      await page.click(creditAmount);

      const remark = 'div[data-rbd-draggable-id="remark"] .i-icon-close svg';
      await page.waitForSelector(remark);
      await page.click(remark);

        //提交保存
      const byted_confirm_ok = 'button[class="byted-btn byted-btn-size-lg byted-btn-type-primary byted-btn-shape-angle byted-can-input-grouped byted-confirm-ok"]';
      // const byted_confirm_ok = 'button[class="byted-btn byted-btn-size-md byted-btn-type-primary byted-btn-shape-angle byted-can-input-grouped byted-confirm-ok"]';
      await page.waitForSelector(byted_confirm_ok);
      await page.click(byted_confirm_ok);

      await timeout(1000)

        //进行截图
      var folder = create_folder(path.join(__dirname, '/../public/transfer_images/'))
      var image = Date.now() + generateRandomInt(1, 10000) + '.png';
      let url = folder + '/' + image
      // 使用clip选项来指定截图区域
      await page.screenshot({
          path: url, // 截图保存的文件路径
          clip: {
              x: 120,  // 截图区域的左上角x坐标
              y: 390,  // 截图区域的左上角y坐标
              width: 1180,  // 截图区域的宽度
              height: 120  // 截图区域的高度
          }
      });

      const startIndex = url.indexOf("transfer_images/");
      let newString = ""
      if (startIndex !== -1) {
          newString = url.slice(startIndex);
      }
      let client = get_redis()
      await client.set(transfer_serial, newString);
      // 关闭浏览器
      await browser.close();

      await client.quit();


  })();
}

function get_cookie(email, password) {
    (async () => {
        // // 设置启动选项以显示浏览器界面
    // const browserOptions = {
    //   headless: false, // 设置为 false 以显示界面
    //   // 其他选项，如慢动作（slowMo）可用于减慢操作速度以便观察
    //   // slowMo: 250 // 例如，设置为 250 毫秒以减慢速度
    // };
    // // 启动浏览器
    // const browser = await puppeteer.launch(browserOptions);
    const browser = await puppeteer.launch({
      executablePath: '/usr/bin/google-chrome', // 替换为你的Chromium实际路径
      // executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', // 替换为你的Chromium实际路径
      args: ['--no-sandbox'],
      // headless: false, // 设置为 false 以显示界面
    });
    // 创建一个新页面
    let page = await browser.newPage();
    await page.setViewport({width: 1920, height: 1080});
    await page.setRequestInterception(true); // 开启请求拦截
    page.on('request', async request => { // 监听请求事件，注意这里使用了async
      const headers = await request.headers(); // 获取请求头部，使用await
      if ("https://agent.oceanengine.com/login" === request.url()) {
        headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
        headers['Accept-Encoding'] = 'gzip, deflate, br, zstd';
        headers['Accept-Language'] = 'zh-CN,zh;q=0.9';
        headers['Cache-Control'] = 'max-age=0';
        headers['Referer'] = 'https://agent.oceanengine.com/';
        headers['Sec-Ch-Ua'] = '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"';
        headers['Sec-Ch-Ua-Mobile'] = '?0';
        headers['Sec-Ch-Ua-Platform'] = '"Windows"';
        headers['Sec-Fetch-Dest'] = 'document';
        headers['Sec-Fetch-Mode'] = 'navigate';
        headers['Sec-Fetch-Site'] = 'same-origin';
        headers['Sec-Fetch-User'] = '?1';
        headers['Upgrade-Insecure-Requests'] = '1';
        headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
      }
      request.continue({ headers }); // 继续请求
    });
    // 访问一个网址
    try {
      await page.goto('https://agent.oceanengine.com/login');
    } catch (error) {
      console.log("打开登录页面超时")
      // console.error('An error occurred:', error);
      await browser.close(); // 确保关闭浏览器
      return
    }

    await timeout(30000)

      // 选择邮箱登录
      const email_button='div[class="account-center-switch-button switch-switch false email"]';
      const divElement = await page.$(email_button);
      if (divElement) {
          await page.click(email_button);
          await timeout(500)
      }



    // 聚焦到输入框
    await page.focus('input[name="email"]');
    // 模拟键盘输入
    await page.keyboard.type(email);


    await page.focus('input[name="password"]');
    await page.keyboard.type(password);


    const protocol_button='span[class="check-box-icon"]';
    await page.waitForSelector(protocol_button);
    await page.click(protocol_button);



    const login_button='button[class="ace-ui-btn account-center-action-button active ace-ui-btn-primary"]';
    await page.waitForSelector(login_button);
    await page.click(login_button);
    await timeout(30000)
    await page.screenshot({
      path: 'image.png', // 截图保存的文件路径
    });
    const newUrl = await page.url();
    console.log('跳转后的 URL:', newUrl);
    await page.waitForNavigation(); // 等待页面跳转完成

    // 获取当前页面的所有cookies
    const cookies = await page.cookies();
    // 将cookies组合成一个字符串
    const cookiesString = cookies.map(cookie => `${cookie.name}=${cookie.value}`).join('; ');
    // 访问一个网址
    let client = get_redis()
    await client.set('jlfz_cookie', cookiesString);
    await client.quit();
  })();
}

function create_folder(url){
  // 获取当前日期并格式化为字符串
  const currentDate = new Date();
  const formattedDate = currentDate.toISOString().split('T')[0].replace(/-/g, ''); // 格式化为 YYYYMMDD

  // 构建文件夹路径
  const folderPath = path.join( url, `${formattedDate}`);
  // 检查文件夹是否已存在
  if (!fs.existsSync(folderPath)) {
    // 如果文件夹不存在，则创建它
    fs.mkdirSync(folderPath, { recursive: true });
  }
  return folderPath
}

function parseCookies(cookieString) {
    return cookieString.split('; ')
        .filter(Boolean) // 移除可能的空字符串
        .map(cookiePair => {
            const [name, value] = cookiePair.split('=');
            // 假设domain和path为默认值，可根据实际情况调整
            return { name, value , domain: 'agent.oceanengine.com', path: '/' };
        });
}


app.post('/jlfz/get_cookie',multer().none(), (req, res) => {
  var email = req.body.email
  var password = req.body.password

  get_cookie(email,password); // 调用方法获取用户数据
  res.json({code:1,msg:'请求成功'}); // 将数据作为JSON响应发送
});

app.post('/jlfz/get_transfer_image',multer().none(), (req, res) => {
  let cookie = req.body.cookie
  let transfer_serial = req.body.transfer_serial
  get_transfer_image(cookie,transfer_serial); // 调用方法获取用户数据
  res.json({code:1,msg:'请求成功'}); // 将数据作为JSON响应发送
});

app.all('*', function(req, resp) {
  resp.redirect('/error');
});





// 启动服务器
app.listen(3000, () => {
  console.log('鸣潮启动');

});




