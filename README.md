# Laravel Huifu (shebaoting 版)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/shebaoting/laravel-huifu.svg?style=flat-square)](https://packagist.org/packages/shebaoting/laravel-huifu)
[![Total Downloads](https://img.shields.io/packagist/dt/shebaoting/laravel-huifu.svg?style=flat-square)](https://packagist.org/packages/shebaoting/laravel-huifu)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Laravel 12](https://img.shields.io/badge/laravel-12.x-red.svg?style=flat-square)](https://laravel.com)

**可能是最懂开发者、最“傻瓜化”的汇付天下（斗拱平台）Laravel 扩展包。**

由 **shebaoting** 倾力打造，专为 Laravel 12 设计。它不仅封装了复杂的汇付 SDK，还彻底解决了 `wx_data` 多层嵌套、金额精度格式化、私有属性解析等所有初学者容易“踩坑”的问题。

## ✨ 特性

- **🚀 零配置初始化**：基于内存配置，无需读写临时 `config.json` 文件，性能更强。
- **🍭 语义化支付**：提供 `miniAppPay` 等方法，自动处理微信小程序支付所需的 AppID 和 OpenID 嵌套逻辑。
- **💰 智能分账**：内置按“比例”或“固定金额”的分账助手，自动计算精度。
- **🛡️ 异常处理**：将汇付返回的错误码直接转化为 PHP 异常，支持 `try-catch` 一键捕获。
- **📝 全链路追踪**：完美适配 Laravel 12 日志上下文，自动在日志中携带汇付流水号，排错无忧。
- **🖼️ 傻瓜式进件**：封装了证照上传、商户余额查询等管理类接口。

## ⚙️ 安装

通过 Composer 安装：

```bash
composer require shebaoting/laravel-huifu
```

发布配置文件：

```bash
php artisan huifu:install
```

## 🛠️ 配置

在你的 `.env` 文件中添加以下配置：

```env
HUIFU_SYS_ID=你的系统号
HUIFU_PRODUCT_ID=PAYUN
HUIFU_MCH_ID=你的商户号
HUIFU_MERCH_PRIVATE_KEY=你的RSA私钥(不含头尾)
HUIFU_PUBLIC_KEY=汇付的RSA公钥(不含头尾)
HUIFU_DEBUG=true
WECHAT_MINI_APP_ID=你的小程序AppID
```

## 🚀 快速上手

### 1. 微信小程序支付（自动处理嵌套）

无需关心汇付文档里复杂的 `method_expand` 或 `wx_data` 字符串，包内自动帮你处理：

```php
use Shebaoting\Huifu\Facades\Huifu;

// 192.00 元下单，自动格式化金额，自动封装微信参数
try {
    $result = Huifu::miniAppPay(
        'GB20260124001',   // 商户订单号
        192,               // 金额 (支持 float/string/int)
        '擦玻璃专项服务',    // 商品描述
        'oVYIxxxxxxxJbXg'  // 用户的 OpenID
    );

    // 直接给前端返回 pay_info 即可唤起支付
    return response()->json($result['pay_info']);
} catch (\Shebaoting\Huifu\Exceptions\HuifuApiException $e) {
    // 获取汇付返回的错误描述
    return $e->getMessage(); 
}
```

### 2. 傻瓜式分账（延迟结算）

在拼团成功或核销服务后，将钱分给商户：

```php
// 场景 A：按比例分成。商家分 90%，剩下的钱自动留给平台
Huifu::splitByRatio('原单号', '原支付日期Ymd', 100.00, [
    '66660001xxxx' => 0.9, 
]);

// 场景 B：按固定金额分成。给师傅分 80 元，剩下的钱留给平台
Huifu::splitByAmount('原单号', '原支付日期Ymd', [
    '66660002xxxx' => 80.00,
]);
```

### 3. 一键处理回调（自动验签）

在你的回调路由中，一行代码完成验签和逻辑处理：

```php
public function notify()
{
    return Huifu::handleCallback(function($data) {
        // $data 已经是验签通过后的业务数据数组
        // 在这里处理你的订单状态更新逻辑
        // ...
        
        return true; // 返回 true 自动向汇付响应 "success"
    });
}
```

### 4. 商户进件：证照上传

```php
// 直接传本地图片路径，自动转为汇付需要的 CURLFile
$fileId = Huifu::uploadImage(storage_path('app/id_card.jpg'), 'F55');
echo "汇付文件ID: " . $fileId;
```

### 5. 查询商户余额

```php
$balance = Huifu::getBalance('6666000xxxxx');
echo "可用余额: ¥" . $balance;
```

## 🏗️ 万能请求工厂

如果你需要调用包内未直接封装的汇付接口，可以使用 `request` 方法，它会自动帮你填充日期、流水号和主商户号：

```php
use BsPaySdk\request\V2UserBasicdataQueryRequest;

$res = Huifu::request(V2UserBasicdataQueryRequest::class, [
    'huifu_id' => '666600012345'
]);
```

## 📖 异常处理

本扩展包会抛出 `Shebaoting\Huifu\Exceptions\HuifuApiException`。
你可以通过 `$e->raw` 获取汇付接口返回的完整原始数据，通过 `$e->getMessage()` 获取汇付给出的中文错误描述。

## 🤝 贡献

如果你在使用过程中发现了 Bug 或有更好的改进建议，欢迎提交 PR。

## 📄 开源协议

本项目遵循 MIT 开源协议。

---
**Maintained by shebaoting**
