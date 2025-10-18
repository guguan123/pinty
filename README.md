# pinty

基于PHP的简单探针系统

目前这个探针看起来还不完整，等观察几个版本后再研究...

来源：<https://www.nodeloc.com/t/topic/62955>

## 安装

将所有文件放在网站根目录，访问 setup/ 打开安装程序。
输入相应的数据库类型和密码，设置管理用户名和密码即可

## 使用

1、打开 admin/ 页面并登录，添加一个新的服务器

2、通过环境变量传参给脚本

```bash
# API端点，用于接收状态报告
PINTY_API_ENDPOINT="https://<你的域名>/report.php"
# 服务器的唯一ID (与管理员后台设置的ID匹配)
PINTY_SERVER_ID="10086"
# 每个服务器独立的密钥 (从管理员后台复制)
PINTY_SECRET="a7d23a5ded98c84c06263e237fea4e48"
```

3、运行脚本

## ToDo

修复节点掉线时间不刷新的Bug

## API

端点： /api.php

### 获取服务器列表

端点： api.php?action=list

返回内容

```json
{
    "nodes": [
        {
            "id": "10086", // 服务器唯一ID
            "name": "Home", // 服务器名称
            "x": 800, // 服务器纬度
            "y": 600, // 服务器经度
            "intro": "",
            "tags": null,
            "mem_total": 4035244032, // 内存大小
            "disk_total": 194713169920, // 硬盘大小
            "expiry_date": null,
            "country_code": "CN", // 服务器国家
            "stats": {
                "timestamp": 1759899120, // 状态数据刷新时间（B）
                "cpu_usage": 7.07, // CPU使用率 (%)
                "mem_usage_percent": 67.14, // 内存使用率（%）
                "disk_usage_percent": 5, // 硬盘使用率（%）
                "net_up_speed": 7490, // 下行网速（B/s）
                "net_down_speed": 7512, // 上行网速（B/s）
                "total_up": 1245566711,
                "total_down": 1721551621,
                "uptime": "1 week, 17 hours, 36 minutes", // 已开机时间
                "load_avg": 0.47, // 负载
                "processes": 46, // 线程数
                "connections": 40 // 网络连接数
            },
            "is_online": true // 服务器是否在线
        },
        {...}
    ]
}
```

## 许可证

除了 index.html、edit_server.php、assets/css/admin-dash.css、assets/css/index.css、admin/logout.php、admin/generate_secret.php、admin/dashboard.php
版权归原作者[Crozono](https://www.nodeloc.com/u/synastie)所有，其余均以 [MIT](https://choosealicense.com/licenses/mit/) 许可证发行
