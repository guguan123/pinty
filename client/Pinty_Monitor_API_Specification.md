# Pinty 监控系统 API 文档

## 概述

该 API 端点用于接收来自 Pinty 监控客户端的服务器状态报告。客户端会定期发送服务器的动态状态数据以及首次运行时的静态硬件信息。

### 1\. API 端点

| 属性 | 值 |
| :--- | :--- |
| **URL** | 配置于客户端脚本的 `API_ENDPOINT` 变量中，默认为 `https://your-domain.com/report.php` |
| **方法** | `POST` |
| **内容类型** | `application/json` |

### 2\. 请求（Request Payload）

请求体是一个 JSON 对象，包含服务器的身份验证信息、动态监控数据，以及（在首次报告时）静态硬件信息。

#### 2.1. 身份验证和基本信息

| 字段 | 类型 | 描述 | 必需性 |
| :--- | :--- | :--- | :--- |
| `server_id` | `string` | 服务器的唯一标识符。 | 是 |
| `secret` | `string` | 服务器特定的密钥，用于身份验证。 | 是 |

#### 2.2. 动态状态数据

| 字段 | 类型 | 描述 | 必需性 | 来源 |
| :--- | :--- | :--- | :--- | :--- |
| `cpu_usage` | `number` | CPU 使用率（百分比），保留两位小数（e.g., `5.45`）。 | 是 | `report_status` 函数 |
| `mem_usage_percent` | `number` | 内存使用率（百分比），保留两位小数（e.g., `25.30`）。 | 是 | `report_status` 函数 |
| `disk_usage_percent` | `number` | 根分区 (`/`) 磁盘使用率（百分比，整数 e.g., `40`）。 | 是 | `report_status` 函数 |
| `uptime` | `string` | 服务器运行时间（格式 e.g., `1d, 5h, 3m`）。 | 是 | `report_status` 函数 |
| `load_avg` | `number` | 过去 1 分钟的系统平均负载。 | 是 | `report_status` 函数 |
| `processes` | `integer` | 当前运行的进程总数。 | 是 | `report_status` 函数 |
| `connections` | `integer` | 当前 TCP/UDP 连接总数。 | 是 | `report_status` 函数 |
| `net_up_speed` | `integer` | 过去 1 秒内的网络上传速度（字节/秒）。 | 是 | `get_network_usage` 函数 |
| `net_down_speed` | `integer` | 过去 1 秒内的网络下载速度（字节/秒）。 | 是 | `get_network_usage` 函数 |
| `total_up` | `integer` | 自上次重启或网络统计重置以来的总上传字节数。 | 是 | `get_network_usage` 函数 |
| `total_down` | `integer` | 自上次重启或网络统计重置以来的总下载字节数。 | 是 | `get_network_usage` 函数 |

#### 2.3. 静态硬件信息（可选）

此字段仅在客户端首次运行时包含，或当 `/tmp/pinty_static_info_done.flag` 文件不存在时包含。

| 字段 | 类型 | 描述 | 必需性 |
| :--- | :--- | :--- | :--- |
| `static_info` | `object` | 包含静态信息的对象。 | 否（仅首次报告时） |

`static_info` 对象的结构：

| 字段 | 类型 | 描述 | 必需性 |
| :--- | :--- | :--- | :--- |
| `cpu_model` | `string` | CPU 型号名称。 | 是 |
| `cpu_cores` | `integer` | CPU 核心总数。 | 是 |
| `mem_total_bytes` | `integer` | 总内存容量（字节）。 | 是 |
| `disk_total_bytes` | `integer` | 根分区 (`/`) 总磁盘容量（字节）。 | 是 |
| `system` | `string` | 操作系统信息（e.g., `Ubuntu 20.04 LTS`）。 | 是 |
| `arch` | `string` | 系统架构（e.g., `x86_64`）。 | 是 |

### 3\. 响应（Response）

服务器端始终返回一个 JSON 响应。

#### 3.1. 成功响应（HTTP 状态码 200）

| 字段 | 类型 | 描述 |
| :--- | :--- | :--- |
| `success` | `boolean` | 始终为 `true`。 |

**示例:**

```json
{"success": true}
```

#### 3.2. 错误响应（HTTP 状态码 400, 401, 500 等）

服务器端会根据错误类型返回相应的 HTTP 状态码，并在 JSON 体中包含错误信息。

| 字段 | 类型 | 描述 |
| :--- | :--- | :--- |
| `error` | `string` | 错误描述。 |

**错误码及示例：**

| 状态码 | 错误原因 | 响应体示例 |
| :--- | :--- | :--- |
| **400** | 无效的 JSON 负载或缺少必需参数 (`server_id`, `secret`)。 | `{"error": "Invalid JSON payload."}` 或 `{"error": "server_id and secret are required."}` |
| **401** | 身份验证失败（密钥不匹配或 IP 地址不匹配）。 | `{"error": "Authentication failed. Secret mismatch or IP change."}` |
| **500** | 服务器内部错误（例如数据库连接问题或 SQL 错误）。 | `{"error": "..."}`（具体的异常消息） |
