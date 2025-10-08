# pinty
基于PHP的简单探针系统

目前这个探针看起来还不完整，等观察几个版本后再研究...

来源：https://www.nodeloc.com/t/topic/62955
版权归原作者[Crozono](https://www.nodeloc.com/u/synastie)所有

## 安装

将所有文件放在网站根目录，访问 setup/ 打开安装程序。
输入相应的数据库类型和密码，设置管理用户名和密码即可

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
