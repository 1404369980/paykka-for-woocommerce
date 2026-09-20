# PayKKa for WooCommerce — ID / Session 设计说明

文档版本：1.0  
适用插件：PayKKa for WooCommerce（Hosted）  
硬约束：**一个** `trans_id` **只能创建一个 Session**

---

## 1. 背景与目标

### 1.1 业务背景

- **Hosted**：跳转 PayKKa 托管收银台。

PayKKa 将 `trans_id` 视为商户侧幂等键：**同一** `trans_id` **只会对应一个 Session**。因此不能把「Woo 订单号」当作可反复 Create Session 的终身 ID。

### 1.2 设计目标

1. Hosted 使用统一的 ID / meta 契约。
2. 在「一 id 一 session」下支持安全重建 Session。
3. Webhook / Callback / 查单 / 退款能稳定定位到 Woo 订单。
4. **用户与客服只用 Woo 订单号查询**；PayKKa 侧 ID 由 meta 映射，不要求人工记忆。

---

## 2. 概念分层


| 标识                   | 生成方         | 基数                     | 含义                        | 谁使用                |
| -------------------- | ----------- | ---------------------- | ------------------------- | ------------------ |
| Woo `order_id`（如 93） | WooCommerce | 1 笔订单                  | 本站业务主键；Callback URL 参数    | 顾客、客服、商户后台         |
| `trans_id`           | 商户插件        | **1 次 Create Session** | PayKKa 幂等键；一 id 一 session | API / 对账 / 商户后台搜流水 |
| `session_id`（CS…）    | PayKKa      | 1 次收银台意向               | 前端 `PayKKaCheckout` 初始化   | 前端组件、排障            |
| `order_id`（GW…）      | PayKKa      | 0～1 次成功交易              | 支付终态、退款、对账                | 查单、退款、客服复制         |
| `refund_trans_id`    | 商户插件        | 1 次退款请求                | 与支付 `trans_id` 命名空间分离     | 退款 API / Webhook   |


**原则：**

```
1 个 Woo 订单
  → N 次 Attempt（每次一个 trans_id + 一个 session_id）
  → 最多 1 个成功的 GW order_id
```

```
Woo Order #93
  ├─ Attempt #1  trans_id=…  session=CS…A  (已废弃/过期)
  └─ Attempt #2  trans_id=…  session=CS…B  → 成功后 GW…
```

---



## 3. `trans_id` 规范



### 3.1 硬约束

- 每个 `trans_id` **最多创建一次** Session。
- 需要新 Session（刷新、过期、force_new）时，必须生成 **新的** `trans_id`。
- **禁止**用纯 Woo 订单号反复调用 Create Session。



### 3.2 长度

- PayKKa 侧 `trans_id` 常见限制约为 **0～64 字符**（以 OpenAPI 为准）。
- 不宜使用过长可读串（如 `93-card-20260902154501-482`）；订单号很大时易顶满。



### 3.3 推荐格式（短、唯一）

关联订单靠 **meta**，不靠从 `trans_id` 反解析订单号。

推荐其一：

```
{orderId}c{HHmmss}{rand4}
例：93c1545014821
```

或：

```
c{orderId}{uniq8}
例：c93a7f3k2m9
```

生成后校验：`strlen(trans_id) <= 64`。

### 3.4 Hosted 与 Card


| 通道     | 规则                                         |
| ------ | ------------------------------------------ |
| Hosted | 每次发起托管支付生成新 `trans_id`（取消/失败重试不撞旧 Session） |
| Card   | 每次 force_new / 过期重建生成新 `trans_id`          |


通道信息写入 meta `_paykka_sub_method`（`hosted` | `card`），不必塞进 `trans_id`。

---



## 4. 订单 Meta 契约



### 4.1 当前活跃（必写）


| Meta key                      | 何时写入                | 说明                         |
| ----------------------------- | ------------------- | -------------------------- |
| `_paykka_trans_id`            | Create Session 前/成功 | **当前** Attempt 的商户流水       |
| `_paykka_session_id`          | Create 成功           | **当前** CS…                 |
| `_paykka_session_fingerprint` | Create 成功           | 账单/购物车指纹；未变可前端复用，不调 Create |
| `_paykka_sub_method`          | 进入通道时               | `hosted` / `card`          |
| `_paykka_order_id`            | 支付成功同步              | PayKKa GW…                 |
| `_paykka_component_paid`      | Card 成功同步           | `yes`；防止未付款走成功分支           |
| `_paykka_paid_trans_id`       | 成功同步（建议）            | 记录哪一次 Attempt 付成功          |




### 4.2 历史（建议）


| Meta key                  | 说明                     |
| ------------------------- | ---------------------- |
| `_paykka_attempt_history` | JSON 数组，归档被替代的 Attempt |


单条示例：

```json
{
  "trans_id": "93c1400000101",
  "session_id": "CS…A",
  "channel": "card",
  "created_at": "2026-09-02T14:00:00+08:00",
  "status": "superseded"
}
```

新建 Attempt 时：先归档旧 `trans_id`/`session_id`，再覆盖当前字段。  
若订单已支付（已有有效 `_paykka_order_id`）：**禁止**再建 Attempt。

### 4.3 WooCommerce Session（结账过程）


| Key                      | 说明        |
| ------------------------ | --------- |
| `order_awaiting_payment` | WC 标准待付订单 |

支付成功后应清空。

---



## 5. 用户如何用「订单 93」查 PayKKa 信息

**入口永远是 Woo 订单号，不是 PayKKa** `trans_id`**。**

```
用户/客服提供：订单 #93
    → Woo 后台打开该订单
    → 查看 meta / 订单备注：
         _paykka_order_id   → GW…     （已支付，最准）
         _paykka_trans_id   → 商户流水
         _paykka_session_id → CS…
    → 用上述 ID 在 PayKKa 商户后台或 Query API 查询
```


| 角色  | 怎么查                                     |
| --- | --------------------------------------- |
| 顾客  | 「我的账户」/ 邮件中的订单 **#93**                  |
| 客服  | WP → 订单 #93 → 复制 GW / trans_id          |
| 开发  | `queryPaymentForOrder(订单93)` 按 meta 自动查 |


**不要**假设 PayKKa 开放平台用「93」能搜到 Card 支付（多次 Attempt 时 93 ≠ 唯一 `trans_id`）。

产品建议：订单详情页固定展示「PayKKa 交易号 / 商户流水 / Session」。

---



## 6. 查单顺序（`queryPaymentForOrder`）

1. `_paykka_order_id`（GW）
2. `_paykka_trans_id`（当前 Attempt）
3. `_paykka_session_id`
4. （可选）history 中近期 `trans_id`
5. Legacy：纯数字订单号（仅兼容旧 Hosted 数据，打日志）

以 **Query Transaction** 结果同步订单状态；Webhook 触发后仍应 Query 再写状态。

---



## 7. Webhook / Callback



### 7.1 解析 Woo 订单（唯一入口）

1. `payload.order_id`（GW）→ meta `_paykka_order_id`
2. `payload.trans_id` → meta `_paykka_trans_id`
3. `payload.session_id` → meta `_paykka_session_id`
4. Legacy：纯数字 `trans_id` == Woo 订单号

收到通知后：先回写 meta，再 `queryPaymentForOrder` → `syncOrderByQueryResult`。

### 7.2 Callback（浏览器回跳）


| 结果                   | 行为                       |
| -------------------- | ------------------------ |
| SUCCESS / AUTHORIZED | 清空购物车 → 感谢页              |
| FAILURE / CANCELED   | 回结账页 + 错误提示              |
| 查不到 / 异常             | 回结账页 +「结果确认中」，**禁止假感谢页** |




### 7.3 验签

配置 PayKKa 平台公钥后，校验 `x-paykka-sign`（SHA256_WITH_RSA）。生产环境应强制开启。

---



## 8. 主流程摘要



### 8.1 Hosted

下单 → 新 `trans_id` → 写 meta → Create Session(HOSTED) → 跳转 → Callback/Webhook → 查单同步 → 写 GW。

### 8.2 Credit Card（Blocks）

选中 Card → 预建/复用 pending 订单 →（指纹未变且前端缓存可用则 remount，不 Create）→ 否则新 `trans_id` + Create(COMPONENT) → 前端 init/mount → `payment()` → 成功跳 Callback → 查单同步。

**约定：** 调 Create API = 必须新 `trans_id`；前端复用缓存 = 不调 Create。

### 8.3 退款

必须先有 GW（`_paykka_order_id`）→ 独立 `refund_trans_id` → Refund API → Query Refund / Webhook。

---



## 9. 状态说明



### Attempt

`CREATING` → `ACTIVE` → `SUPERSEDED` | `EXPIRED` | `CONSUMED`（已绑定 GW）

### Woo 订单（支付相关）


| PayKKa status           | Woo（参考）                      |
| ----------------------- | ---------------------------- |
| SUCCESS                 | processing（payment_complete） |
| AUTHORIZED / PROCESSING | on-hold                      |
| FAILURE                 | failed                       |
| CANCELED                | cancelled                    |


---



## 10. 实现清单（参考）

- [x] 统一 `new_trans_id($order)`（短格式 + ≤64）
- [x] Hosted / Card Create 成功后统一写 meta；force_new 归档 history
- [ ] `resolve_order_from_payload` 单入口
- [ ] `queryPaymentForOrder` 以 meta 为主
- [x] 订单后台展示 GW（列表列 + 详情）；trans_id / session_id 仍可按需扩展
- [ ] （可选）订单后台一并展示 trans_id / session_id
- [ ] 生产开启 Webhook 验签
- [ ] （可选）过期 pending / Attempt 清理

---



## 11. 相关文档

- [Component Web](https://docs.paykka.com/zh-hans/payments/docs/transaction/web/component-web)
- [Create Session API](https://docs.paykka.com/zh-hans/payments/apis/payments/openapi/%E6%94%B6%E9%93%B6%E5%8F%B0/session-opl_1)
- [Webhook](https://docs.paykka.com/payments/docs/developer-resources/webhook)
- [API 认证 / 验签](https://docs.paykka.com/payments/apis/introduction/api-certification)

---



## 12. 一句话总结

**Woo 订单号给人用；**`trans_id` **是一次性建 Session 的票据；**`session_id` **是收银台；**`GW order_id` **是成交交易号。三者用订单 meta 绑定，查单与客服一律从订单 #93 进入。**